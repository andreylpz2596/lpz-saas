<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Support\ValorDevolucionesEnvio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Genera, de forma IDEMPOTENTE, los movimientos automaticos (recaudo y servicio) de la cuenta corriente a partir de los
 * hechos REALES de un envio: guia (snapshot del despacho) + entregas y devoluciones vigentes. Lo llaman, dentro de su
 * propia transaccion, los servicios que cambian esos hechos (registrar entrega/devolucion, anularlas, corregir la guia).
 *
 * Es una CONCILIACION, no un contador de eventos: calcula lo que el envio debe aportar (ReglaCuentaEnvio) y lo compara
 * con lo que ya hay en el libro. Si coincide, no hace nada (refresh, doble clic, reintento → cero movimientos nuevos).
 * Si cambio (p. ej. se anulo una entrega), REVIERTE lo vigente (movimiento opuesto, nunca edita ni borra) y crea el
 * nuevo. Claves deterministas: `envio:{shipment}:{concepto}:{n}` y `reverso:{movimiento}`, ambas UNIQUE; el envio se
 * bloquea (FOR UPDATE), asi que dos operaciones sobre el mismo envio se serializan.
 *
 * Participa en cuenta corriente lo que dice la CAPACIDAD, nunca el nombre: la guia guarda `liquida_cuenta_corriente`
 * al despachar (un cambio posterior del catalogo no reinterpreta el despacho). Si una correccion cambio la transportadora
 * de la guia, manda la capacidad de la transportadora nueva y lo de la anterior se revierte.
 */
class GeneradorMovimientosCuenta
{
    private const CONCEPTOS = [Concepto::RECAUDO, Concepto::SERVICIO];

    public function __construct(
        protected ReglaCuentaEnvio $regla,
        protected ValorDevolucionesEnvio $devoluciones,
        protected ModoRecaudo $modo,
    ) {}

    public function sincronizarEnvio(int $shipmentId, ?Admin $actor = null): void
    {
        DB::transaction(function () use ($shipmentId, $actor) {
            if (! DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->exists()) {
                return;
            }

            $guia = Guia::query()->where('shipment_id', $shipmentId)->first();

            if ($guia === null || ($guia->liquida_cuenta_corriente !== true && ! $this->tieneMovimientos($shipmentId))) {
                return;
            }

            $estado = $this->estadoEnvio($guia, $this->unidadesPorEnvio([$shipmentId])[$shipmentId] ?? null);
            $fecha = $this->fechaEntrega($shipmentId);

            $c6 = $this->modo->esC6($guia);

            foreach (self::CONCEPTOS as $concepto) {
                // C6: el recaudo de una guia C6 nace del COBRO real (RecaudoDesdeCobro), nunca del valor esperado: aqui no se toca.
                if ($concepto === Concepto::RECAUDO && $c6) {
                    continue;
                }

                $this->aplicar($guia, $concepto, $estado['participa'] ? $estado[$concepto->value] : 0.0, $estado['transportadora_id'], $fecha, $actor);
            }
        });
    }

    /**
     * Lo que el envio DEBERIA aportar hoy (sin escribir nada).
     *
     * @param  array{despachadas: int, entregadas: int, retornadas: int, pendientes: int}|null  $unidades
     * @return array{participa: bool, transportadora_id: ?int, recaudo: ?float, servicio: ?float, revision: array<string, string>, unidades: array}
     */
    public function estadoEnvio(Guia $guia, ?array $unidades, ?Transportadora $transportadora = null, ?bool $corregida = null, ?float $total = null, bool $c6 = false): array
    {
        $unidades ??= ['despachadas' => 0, 'entregadas' => 0, 'retornadas' => 0, 'pendientes' => 0];
        $transportadora ??= Transportadora::query()->where('codigo', $guia->transportadora_codigo)->first();
        $corregida ??= $this->transportadoraCorregida((int) $guia->id);
        $total ??= (float) DB::table('orders')->where('id', $guia->order_id)->value('base_grand_total');

        // Sin correccion manda el snapshot del despacho; si la guia se corrigio a otra transportadora, su capacidad actual.
        $participa = $guia->liquida_cuenta_corriente === true && $transportadora !== null
            && (! $corregida || (bool) $transportadora->liquida_cuenta_corriente);

        $e = $this->regla->evaluar((float) $guia->recaudo_esperado, $guia->valor_flete === null ? null : (float) $guia->valor_flete, $guia->flete_pagador, $total, $unidades);

        // C6: el recaudo de una guia C6 no sale de lo esperado; tampoco hay nada que revisar por ese concepto.
        if ($c6) {
            $e['recaudo'] = 0.0;
            unset($e['revision'][Concepto::RECAUDO->value]);
        }

        return [
            'participa' => $participa,
            'transportadora_id' => $participa ? (int) $transportadora->id : null,
            'recaudo' => $e['recaudo'],
            'servicio' => $e['servicio'],
            'revision' => $participa ? $e['revision'] : [],
            'unidades' => $unidades,
        ];
    }

    /**
     * Envios «Requiere revision» de las transportadoras dadas: ambiguos y SIN una decision registrada (un movimiento vigente
     * de ese concepto, automatico o confirmado por el Administrador).
     *
     * Entrega parcial con devolucion: trae el valor de las prendas devueltas (precio historico de cada linea del pedido) y
     * el RECAUDO SUGERIDO = recaudo esperado − ese valor. Es solo una sugerencia: el Administrador siempre confirma.
     *
     * @param  list<int>|null  $transportadoraIds
     * @return list<array{shipment_id: int, order_id: int, guia_id: int, transportadora_id: int, concepto: string, motivo: string, recaudo_esperado: float, valor_flete: ?float, unidades: array, devoluciones: ?array, recaudo_sugerido: ?float}>
     */
    public function revisiones(?array $transportadoraIds = null): array
    {
        $transportadoras = Transportadora::query()
            ->when($transportadoraIds !== null, fn ($q) => $q->whereIn('id', $transportadoraIds ?: [0]))
            ->get()->keyBy('codigo');

        $guias = Guia::query()->where('liquida_cuenta_corriente', true)
            ->whereIn('transportadora_codigo', $transportadoras->keys()->all() ?: [''])
            ->orderBy('id')->get();

        if ($guias->isEmpty()) {
            return [];
        }

        $shipments = $guias->pluck('shipment_id')->map(fn ($id) => (int) $id)->all();
        $unidades = $this->unidadesPorEnvio($shipments);
        $corregidas = DB::table('bylopez_lg_guia_correcciones')->whereIn('guia_id', $guias->pluck('id')->all())
            ->whereColumn('transportadora_anterior', '<>', 'transportadora_nueva')->pluck('guia_id')->map(fn ($id) => (int) $id)->flip();
        $vivos = $this->vivosPorEnvio($shipments);
        $totales = DB::table('orders')->whereIn('id', $guias->pluck('order_id')->all())->pluck('base_grand_total', 'id');
        $salida = [];
        $devoluciones = null;
        $c6 = $this->modo->guiasC6($guias);

        foreach ($guias as $g) {
            $estado = $this->estadoEnvio($g, $unidades[(int) $g->shipment_id] ?? null, $transportadoras[$g->transportadora_codigo] ?? null,
                $corregidas->has((int) $g->id), (float) ($totales[$g->order_id] ?? 0), isset($c6[(int) $g->id]));

            foreach ($estado['revision'] as $concepto => $motivo) {
                if (isset($vivos[(int) $g->shipment_id][$concepto])) {
                    continue;
                }

                $devolucion = null;

                if ($concepto === Concepto::RECAUDO->value && $motivo === ReglaCuentaEnvio::MOTIVO_ENTREGA_CON_DEVOLUCION) {
                    $devoluciones ??= $this->devoluciones->deEnvios($shipments);
                    $devolucion = $devoluciones[(int) $g->shipment_id] ?? ['valor' => 0.0, 'lineas' => []];
                }

                $salida[] = [
                    'shipment_id' => (int) $g->shipment_id,
                    'order_id' => (int) $g->order_id,
                    'guia_id' => (int) $g->id,
                    'transportadora_id' => (int) $estado['transportadora_id'],
                    'concepto' => $concepto,
                    'motivo' => $motivo,
                    'recaudo_esperado' => (float) $g->recaudo_esperado,
                    'valor_flete' => $g->valor_flete === null ? null : (float) $g->valor_flete,
                    'unidades' => $estado['unidades'],
                    'devoluciones' => $devolucion,
                    'recaudo_sugerido' => $devolucion === null ? null : round(max(0.0, (float) $g->recaudo_esperado - $devolucion['valor']), 2),
                ];
            }
        }

        return $salida;
    }

    /**
     * Unidades por envio, derivadas de los eventos VIGENTES (misma definicion que ConsultaEntregaService).
     *
     * @param  list<int>  $shipmentIds
     * @return array<int, array{despachadas: int, entregadas: int, retornadas: int, pendientes: int}>
     */
    public function unidadesPorEnvio(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $despachadas = DB::table('shipment_items')->whereIn('shipment_id', $shipmentIds)->groupBy('shipment_id')
            ->selectRaw('shipment_id, SUM(qty) AS n')->pluck('n', 'shipment_id');
        $suma = fn (string $items, string $eventos, string $fk) => DB::table($items.' as i')->join($eventos.' as e', 'e.id', '=', 'i.'.$fk)
            ->whereIn('e.shipment_id', $shipmentIds)->where('e.estado', Estado::CONFIRMADO->value)
            ->groupBy('e.shipment_id')->selectRaw('e.shipment_id, SUM(i.cantidad) AS n')->pluck('n', 'e.shipment_id');
        $entregadas = $suma('bylopez_lg_entrega_items', 'bylopez_lg_entregas', 'entrega_id');
        $retornadas = $suma('bylopez_lg_retorno_items', 'bylopez_lg_retornos', 'retorno_id');

        $salida = [];

        foreach ($shipmentIds as $id) {
            $d = (int) ($despachadas[$id] ?? 0);
            $e = (int) ($entregadas[$id] ?? 0);
            $r = (int) ($retornadas[$id] ?? 0);
            $salida[$id] = ['despachadas' => $d, 'entregadas' => $e, 'retornadas' => $r, 'pendientes' => max(0, $d - $e - $r)];
        }

        return $salida;
    }

    /**
     * Movimientos VIGENTES (ni reversos ni revertidos) por envio y concepto.
     *
     * @param  list<int>  $shipmentIds
     * @return array<int, array<string, true>>
     */
    private function vivosPorEnvio(array $shipmentIds): array
    {
        $salida = [];

        foreach ($this->vivos(MovimientoCuenta::query()->whereIn('shipment_id', $shipmentIds)->get()) as $m) {
            $salida[(int) $m->shipment_id][$m->concepto->value] = true;
        }

        return $salida;
    }

    /** @return Collection<int, MovimientoCuenta> */
    private function vivos(Collection $movimientos): Collection
    {
        $revertidos = $movimientos->pluck('revierte_id')->filter()->map(fn ($id) => (int) $id)->flip();

        return $movimientos->filter(fn (MovimientoCuenta $m) => $m->origen_tipo !== MovimientoCuenta::ORIGEN_REVERSO && ! $revertidos->has((int) $m->id))->values();
    }

    private function aplicar(Guia $guia, Concepto $concepto, ?float $deseado, ?int $transportadoraId, string $fecha, ?Admin $actor): void
    {
        $movimientos = MovimientoCuenta::query()->where('shipment_id', $guia->shipment_id)->where('concepto', $concepto->value)->orderBy('id')->get();
        $vivos = $this->vivos($movimientos);

        // Lo que quedo a nombre de OTRA transportadora (la guia se corrigio o dejo de participar) se revierte.
        foreach ($vivos->filter(fn ($m) => $m->transportadora_id !== $transportadoraId) as $m) {
            $this->revertir($m, trans('logistica::app.cuentas.desc.reverso-transportadora'), $actor);
        }

        // Ambiguo: se conserva lo que haya (automatico previo o decision del Administrador) y se pide revision.
        if ($deseado === null || $transportadoraId === null) {
            return;
        }

        $actuales = $vivos->filter(fn ($m) => $m->transportadora_id === $transportadoraId);

        if (abs(round((float) $actuales->sum('importe'), 2) - $deseado) < 0.005) {
            return;
        }

        foreach ($actuales as $m) {
            $this->revertir($m, trans('logistica::app.cuentas.desc.reverso-envio'), $actor);
        }

        if (abs($deseado) < 0.005) {
            return;
        }

        $n = $movimientos->where('origen_tipo', '!=', MovimientoCuenta::ORIGEN_REVERSO)->count() + 1;

        MovimientoCuenta::query()->create([
            'transportadora_id' => $transportadoraId,
            'order_id' => (int) $guia->order_id,
            'shipment_id' => (int) $guia->shipment_id,
            'guia_id' => (int) $guia->id,
            'concepto' => $concepto,
            'importe' => $deseado,
            'origen_tipo' => MovimientoCuenta::ORIGEN_ENVIO,
            'origen_id' => (int) $guia->shipment_id,
            'fecha_operativa' => $fecha,
            'descripcion' => trans('logistica::app.cuentas.desc.'.$concepto->value, ['envio' => $guia->shipment_id]),
            'estado' => EstadoMovimientoCuenta::ABIERTO,
            'idempotency_key' => self::clave((int) $guia->shipment_id, $concepto, $n),
            'actor_admin_id' => $actor?->id,
            'actor_name_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
        ]);
    }

    public static function clave(int $shipmentId, Concepto $concepto, int $n): string
    {
        return 'envio:'.$shipmentId.':'.$concepto->value.':'.$n;
    }

    /** Siguiente numero de la clave del envio para un concepto (los reversos no cuentan). */
    public static function siguienteNumero(int $shipmentId, Concepto $concepto): int
    {
        return MovimientoCuenta::query()->where('shipment_id', $shipmentId)->where('concepto', $concepto->value)
            ->where('origen_tipo', '!=', MovimientoCuenta::ORIGEN_REVERSO)->count() + 1;
    }

    /** Movimiento opuesto (nunca se edita el original). Si el original ya esta liquidado, el reverso entra en la cuenta siguiente. */
    public function revertir(MovimientoCuenta $m, string $motivo, ?Admin $actor): MovimientoCuenta
    {
        return MovimientoCuenta::query()->create([
            'transportadora_id' => $m->transportadora_id,
            'order_id' => $m->order_id,
            'shipment_id' => $m->shipment_id,
            'guia_id' => $m->guia_id,
            'concepto' => $m->concepto,
            'importe' => -1 * (float) $m->importe,
            'origen_tipo' => MovimientoCuenta::ORIGEN_REVERSO,
            'origen_id' => $m->id,
            'revierte_id' => $m->id,
            'fecha_operativa' => now()->toDateString(),
            'descripcion' => mb_substr($motivo, 0, 255),
            'estado' => EstadoMovimientoCuenta::ABIERTO,
            'idempotency_key' => 'reverso:'.$m->id,
            'actor_admin_id' => $actor?->id,
            'actor_name_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
        ]);
    }

    private function tieneMovimientos(int $shipmentId): bool
    {
        return MovimientoCuenta::query()->where('shipment_id', $shipmentId)->exists();
    }

    private function transportadoraCorregida(int $guiaId): bool
    {
        return DB::table('bylopez_lg_guia_correcciones')->where('guia_id', $guiaId)->whereColumn('transportadora_anterior', '<>', 'transportadora_nueva')->exists();
    }

    /** Fecha operativa = la ultima entrega vigente del envio (el hecho que origina el dinero); sin entregas, hoy. */
    private function fechaEntrega(int $shipmentId): string
    {
        $fecha = DB::table('bylopez_lg_entregas')->where('shipment_id', $shipmentId)->where('estado', Estado::CONFIRMADO->value)->max('fecha_entrega');

        return $fecha ? substr((string) $fecha, 0, 10) : now()->toDateString();
    }
}
