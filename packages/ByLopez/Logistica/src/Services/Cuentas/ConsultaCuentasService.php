<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\LiquidacionTransportadora as Liquidacion;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\Moneda;
use ByLopez\Logistica\Support\SaldoCuenta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * LECTURA de las cuentas corrientes (Bodega y Admin con `logistica.cuentas`). Todo sale del libro de movimientos: el saldo
 * es Σ importe de los movimientos ABIERTOS (cada liquidacion cerrada suma 0, asi que es tambien el saldo historico total).
 * Ningun saldo se guarda ni se acepta del navegador.
 */
class ConsultaCuentasService
{
    public const MAX_MOVIMIENTOS = 300;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected GeneradorMovimientosCuenta $generador,
        protected LiquidarCuentaService $liquidar,
        protected ConciliacionCuenta $conciliacion,
        protected CobroReportadoService $cobros,
    ) {}

    public function exigirVer(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    /**
     * Listado de cuentas: transportadoras que liquidan cuenta corriente (por CAPACIDAD) y las que ya no liquidan pero aun
     * tienen movimientos (para poder cerrarlas). Una plataforma sin cuenta (PICAP) no aparece.
     *
     * @return array{cuentas: list<array<string, mixed>>, kpis: array<string, mixed>}
     */
    public function listado(): array
    {
        $conMovimientos = MovimientoCuenta::query()->distinct()->pluck('transportadora_id')->all();
        $transportadoras = Transportadora::query()
            ->where(fn ($q) => $q->where('liquida_cuenta_corriente', true)->orWhereIn('id', $conMovimientos ?: [0]))
            ->orderBy('nombre')->get();

        $ids = $transportadoras->pluck('id')->all();
        $abiertos = MovimientoCuenta::query()->whereIn('transportadora_id', $ids ?: [0])->where('estado', EstadoMovimientoCuenta::ABIERTO->value)
            ->groupBy('transportadora_id', 'concepto')->selectRaw('transportadora_id, concepto, SUM(importe) AS total, COUNT(*) AS n')->get()
            ->groupBy('transportadora_id');
        $ultimo = MovimientoCuenta::query()->whereIn('transportadora_id', $ids ?: [0])->groupBy('transportadora_id')
            ->selectRaw('transportadora_id, MAX(fecha_operativa) AS f')->pluck('f', 'transportadora_id');
        $revisiones = collect($this->generador->revisiones($ids))->countBy('transportadora_id');

        $cuentas = [];
        $kpis = ['con_saldo' => 0, 'por_entregar' => 0.0, 'por_pagar' => 0.0, 'pendientes' => 0, 'revision' => 0];

        foreach ($transportadoras as $t) {
            $filas = $abiertos->get($t->id, collect());
            $suma = fn (array $conceptos) => round((float) $filas->whereIn('concepto', $conceptos)->sum('total'), 2);
            $saldo = round((float) $filas->sum('total'), 2);
            $pendientes = (int) $filas->sum('n');
            $d = SaldoCuenta::describir($saldo, (string) $t->nombre);

            $cuentas[] = [
                'id' => (int) $t->id,
                'nombre' => $t->nombre,
                'liquida' => (bool) $t->liquida_cuenta_corriente,
                'activo' => (bool) $t->activo,
                'ultimo' => isset($ultimo[$t->id]) ? CarbonImmutable::parse($ultimo[$t->id])->toDateString() : null,
                'recaudado' => $suma([Concepto::RECAUDO->value]),
                'servicios' => abs($suma([Concepto::SERVICIO->value, Concepto::COSTO_DEVOLUCION->value])),
                'saldo' => $d,
                'pendientes' => $pendientes,
                'revision' => (int) ($revisiones[$t->id] ?? 0),
            ];

            $kpis['con_saldo'] += $d['sentido'] === SaldoCuenta::AL_DIA ? 0 : 1;
            $kpis['por_entregar'] += $saldo > 0 ? $saldo : 0;
            $kpis['por_pagar'] += $saldo < 0 ? -$saldo : 0;
            $kpis['pendientes'] += $pendientes;
            $kpis['revision'] += (int) ($revisiones[$t->id] ?? 0);
        }

        return ['cuentas' => $cuentas, 'kpis' => $kpis];
    }

    /**
     * Todo lo que el drawer necesita de UNA cuenta: saldo en lenguaje humano, resumen, movimientos (filtrados), lo que se
     * liquidaria (todos los abiertos), envios por revisar, historico de cierres y permisos.
     *
     * @param  array{estado?: mixed, concepto?: mixed, desde?: mixed, hasta?: mixed, q?: mixed}  $filtros
     * @return array<string, mixed>
     */
    public function contexto(?Admin $actor, int $transportadoraId, array $filtros = []): array
    {
        $this->exigirVer($actor);

        $t = Transportadora::query()->find($transportadoraId) ?? throw new Invalida(Invalida::CUENTA_INEXISTENTE);

        if (! $t->liquida_cuenta_corriente && ! MovimientoCuenta::query()->where('transportadora_id', $t->id)->exists()) {
            throw new Invalida(Invalida::CUENTA_INEXISTENTE);
        }

        $abiertos = MovimientoCuenta::query()->where('transportadora_id', $t->id)->where('estado', EstadoMovimientoCuenta::ABIERTO->value)->orderBy('id')->get();
        $saldo = round((float) $abiertos->sum('importe'), 2);
        $totales = $this->liquidar->totales($abiertos);
        $f = $this->filtros($filtros);

        return [
            'transportadora' => ['id' => (int) $t->id, 'nombre' => $t->nombre, 'liquida' => (bool) $t->liquida_cuenta_corriente],
            'saldo' => SaldoCuenta::describir($saldo, (string) $t->nombre),
            'periodo' => [
                'desde' => $abiertos->isEmpty() ? null : $this->fechaTexto($abiertos->min(fn ($m) => $m->fecha_operativa->toDateString())),
                'hasta' => $this->fechaTexto(now()->toDateString()),
            ],
            'resumen' => [
                'recaudos' => $totales['recaudos'],
                'servicios' => $totales['servicios'],
                'ajustes' => $totales['ajustes'],
                'saldo' => $saldo,
                'pendientes' => $abiertos->count(),
            ],
            // Lo que se liquidaria: TODOS los abiertos (no los filtrados). El servidor vuelve a calcular todo al liquidar.
            'liquidable' => ['ids' => $abiertos->pluck('id')->map(fn ($id) => (int) $id)->all(), 'n' => $abiertos->count()],
            'movimientos' => $this->movimientos($t->id, $f),
            'filtros' => $f,
            'revisiones' => $this->revisionesDe((int) $t->id),
            'guias_devolucion' => $this->guiasConDevolucion((string) $t->codigo),
            // C6: guias entregadas a las que se puede reportar un cobro de la transportadora (solo quien puede liquidar).
            'guias_cobro' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_LIQUIDAR) ? $this->cobros->guiasParaCobro($t) : [],
            'liquidaciones' => $this->historial((int) $t->id),
            'permisos' => [
                'liquidar' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_LIQUIDAR),
                'ajustar' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_AJUSTAR),
                'cobrar' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_LIQUIDAR),
            ],
            'hoy' => now()->toDateString(),
        ];
    }

    /**
     * C6 — vista previa de lo que haria la liquidacion de TODOS los movimientos abiertos con `valor` de efectivo (no escribe nada):
     * recaudos en custodia, servicios a compensar, efectivo sugerido, resumen (cobrado / compensado / efectivo / conciliado / pendiente del
     * transportador) y la atribucion por movimiento (FIFO o la reasignacion del Administrador). El servidor la vuelve a calcular al confirmar.
     *
     * @param  array{valor?: mixed, atribucion?: mixed}  $datos
     * @return array<string, mixed>
     */
    public function propuesta(?Admin $actor, int $transportadoraId, array $datos): array
    {
        $this->exigirVer($actor);

        $t = Transportadora::query()->find($transportadoraId) ?? throw new Invalida(Invalida::CUENTA_INEXISTENTE);
        $abiertos = MovimientoCuenta::query()->where('transportadora_id', $t->id)->where('estado', EstadoMovimientoCuenta::ABIERTO->value)->orderBy('id')->get();
        $saldo = round((float) $abiertos->sum('importe'), 2);
        $texto = trim((string) ($datos['valor'] ?? ''));
        $valor = preg_match('/^\d{1,8}(\.\d{1,2})?$/', $texto) ? round((float) $texto, 2) : 0.0;
        $atribucion = is_array($datos['atribucion'] ?? null) && $datos['atribucion'] !== [] ? array_values($datos['atribucion']) : null;
        $p = $this->conciliacion->propuesta($abiertos, $valor, $atribucion);
        $sentido = $saldo > 0.004 ? 'transportador_entrega' : ($saldo < -0.004 ? 'bylopez_paga' : 'al_dia');
        $diferencia = round($sentido === 'bylopez_paga' ? $saldo + $valor : $saldo - $valor, 2);

        return [
            'sentido' => $sentido,
            'saldo' => $saldo,
            'valor' => $valor,
            'diferencia' => $diferencia,
            'manual' => $p['manual'],
            'totales' => $p['totales'],
            'filas' => array_map(fn ($h) => [
                'movimiento_id' => $h['id'],
                'tipo' => $h['signo'] > 0 ? 'obligacion' : 'credito',
                'concepto_texto' => (string) trans('logistica::app.cuentas.conceptos.'.$h['concepto']),
                'pedido' => $h['pedido'],
                'guia' => $h['guia'],
                'restante' => $h['restante'] / 100,
                'compensado' => $h['comp'] / 100,
                'efectivo' => $h['efe'] / 100,
                'pendiente' => $h['pendiente'] / 100,
            ], $p['hojas']),
        ];
    }

    /** Detalle de SOLO LECTURA de una liquidacion cerrada, con todos sus movimientos. @return array<string, mixed> */
    public function liquidacion(?Admin $actor, int $liquidacionId): array
    {
        $this->exigirVer($actor);

        $l = Liquidacion::query()->with('transportadora')->find($liquidacionId) ?? throw new Invalida(Invalida::LIQUIDACION_INEXISTENTE);

        return [
            'liquidacion' => $this->filaLiquidacion($l),
            'movimientos' => $this->movimientos((int) $l->transportadora_id, ['liquidacion' => (int) $l->id]),
        ];
    }

    /** @return array{estado: string, concepto: ?string, desde: ?string, hasta: ?string, q: ?string} */
    private function filtros(array $filtros): array
    {
        $estado = in_array($filtros['estado'] ?? null, ['abiertos', 'liquidados', 'todos'], true) ? $filtros['estado'] : 'abiertos';
        $concepto = Concepto::tryFrom((string) ($filtros['concepto'] ?? ''))?->value;
        $fecha = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $q = trim((string) ($filtros['q'] ?? ''));

        return ['estado' => $estado, 'concepto' => $concepto, 'desde' => $fecha($filtros['desde'] ?? null), 'hasta' => $fecha($filtros['hasta'] ?? null), 'q' => $q === '' ? null : mb_substr($q, 0, 60)];
    }

    /** @return array{filas: list<array<string, mixed>>, truncado: bool} */
    private function movimientos(int $transportadoraId, array $f): array
    {
        $q = DB::table('bylopez_lg_cuenta_movimientos as m')
            ->leftJoin('orders as o', 'o.id', '=', 'm.order_id')
            ->leftJoin('bylopez_lg_guias as g', 'g.id', '=', 'm.guia_id')
            ->leftJoin('bylopez_lg_liquidaciones_transportadora as l', 'l.id', '=', 'm.liquidacion_id')
            ->where('m.transportadora_id', $transportadoraId)
            ->select('m.*', 'o.increment_id', 'g.numero_guia', 'l.consecutivo');

        if (isset($f['liquidacion'])) {
            $q->where('m.liquidacion_id', $f['liquidacion']);
        } else {
            $q->when($f['estado'] === 'abiertos', fn ($x) => $x->where('m.estado', EstadoMovimientoCuenta::ABIERTO->value))
                ->when($f['estado'] === 'liquidados', fn ($x) => $x->where('m.estado', EstadoMovimientoCuenta::LIQUIDADO->value))
                ->when($f['concepto'], fn ($x, $c) => $x->where('m.concepto', $c))
                ->when($f['desde'], fn ($x, $d) => $x->where('m.fecha_operativa', '>=', $d))
                ->when($f['hasta'], fn ($x, $d) => $x->where('m.fecha_operativa', '<=', $d))
                ->when($f['q'], fn ($x, $texto) => $x->where(fn ($w) => $w->where('o.increment_id', 'like', '%'.addcslashes($texto, '%_\\').'%')
                    ->orWhere('g.numero_guia', 'like', '%'.addcslashes($texto, '%_\\').'%')));
        }

        $filas = $q->orderByDesc('m.fecha_operativa')->orderByDesc('m.id')->limit(self::MAX_MOVIMIENTOS + 1)->get();
        $truncado = $filas->count() > self::MAX_MOVIMIENTOS;

        return [
            'filas' => $filas->take(self::MAX_MOVIMIENTOS)->map(fn ($m) => $this->filaMovimiento($m))->values()->all(),
            'truncado' => $truncado,
        ];
    }

    /** @return array<string, mixed> */
    private function filaMovimiento(object $m): array
    {
        $importe = round((float) $m->importe, 2);

        return [
            'id' => (int) $m->id,
            'fecha' => substr((string) $m->fecha_operativa, 0, 10),
            'fecha_texto' => $this->fechaTexto((string) $m->fecha_operativa),
            'pedido' => $m->increment_id,
            'order_id' => $m->order_id === null ? null : (int) $m->order_id,
            'shipment_id' => $m->shipment_id === null ? null : (int) $m->shipment_id,
            'guia' => $m->numero_guia,
            'concepto' => $m->concepto,
            'concepto_texto' => (string) trans('logistica::app.cuentas.conceptos.'.$m->concepto),
            'reverso' => $m->origen_tipo === MovimientoCuenta::ORIGEN_REVERSO,
            'revision' => $m->origen_tipo === MovimientoCuenta::ORIGEN_REVISION,
            'importe' => $importe,
            'importe_texto' => Moneda::formatear($importe, true),
            'efecto' => SaldoCuenta::sentido($importe),
            'descripcion' => $m->descripcion,
            'referencia' => $m->referencia,
            'estado' => $m->estado,
            'liquidacion' => $m->consecutivo === null ? null : Liquidacion::formatear((int) $m->consecutivo),
            'actor' => $m->actor_name_snapshot ?: (string) trans('logistica::app.cuentas.sistema'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function revisionesDe(int $transportadoraId): array
    {
        $filas = $this->generador->revisiones([$transportadoraId]);

        if ($filas === []) {
            return [];
        }

        $pedidos = DB::table('orders')->whereIn('id', array_column($filas, 'order_id'))->pluck('increment_id', 'id');
        $guias = DB::table('bylopez_lg_guias')->whereIn('id', array_column($filas, 'guia_id'))->pluck('numero_guia', 'id');

        return array_map(fn ($r) => $r + [
            'pedido' => $pedidos[$r['order_id']] ?? null,
            'guia' => $guias[$r['guia_id']] ?? null,
            'motivo_texto' => (string) trans('logistica::app.cuentas.revision.'.$r['motivo']),
            'concepto_texto' => (string) trans('logistica::app.cuentas.conceptos.'.$r['concepto']),
            'recaudo_texto' => Moneda::formatear($r['recaudo_esperado']),
            'flete_texto' => $r['valor_flete'] === null ? null : Moneda::formatear($r['valor_flete']),
        ], $filas);
    }

    /** Guias de la transportadora con una devolucion vigente: las unicas a las que se puede ligar un costo de devolucion. */
    private function guiasConDevolucion(string $codigo): array
    {
        return DB::table('bylopez_lg_guias as g')
            ->join('orders as o', 'o.id', '=', 'g.order_id')
            ->where('g.transportadora_codigo', $codigo)
            ->whereExists(fn ($q) => $q->from('bylopez_lg_retornos as r')->whereColumn('r.shipment_id', 'g.shipment_id')->where('r.estado', Estado::CONFIRMADO->value))
            ->orderByDesc('g.id')->limit(50)
            ->get(['g.id', 'g.numero_guia', 'o.increment_id'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'texto' => '#'.$g->increment_id.' · '.$g->numero_guia])->all();
    }

    /** @return list<array<string, mixed>> */
    private function historial(int $transportadoraId): array
    {
        return Liquidacion::query()->where('transportadora_id', $transportadoraId)->orderByDesc('consecutivo')->limit(60)->get()
            ->map(fn (Liquidacion $l) => $this->filaLiquidacion($l))->all();
    }

    /** @return array<string, mixed> */
    private function filaLiquidacion(Liquidacion $l): array
    {
        $nombre = (string) ($l->resumen['transportadora']['nombre'] ?? $l->transportadora?->nombre ?? '');

        return [
            'id' => (int) $l->id,
            'numero' => $l->numero(),
            'transportadora' => $nombre,
            'desde' => $l->periodo_desde ? $this->fechaTexto($l->periodo_desde->toDateString()) : null,
            'hasta' => $l->periodo_hasta ? $this->fechaTexto($l->periodo_hasta->toDateString()) : null,
            'movimientos' => (int) $l->movimientos_count,
            'recaudos' => (float) $l->total_recaudos,
            'servicios' => (float) $l->total_servicios,
            'ajustes' => (float) $l->total_ajustes,
            'saldo' => SaldoCuenta::describir((float) $l->saldo_esperado, $nombre),
            'sentido' => $l->sentido,
            'valor' => (float) $l->valor_liquidado,
            'diferencia' => (float) $l->diferencia,
            'diferencia_texto' => abs((float) $l->diferencia) < 0.005 ? null : SaldoCuenta::describir((float) $l->diferencia, $nombre)['texto'],
            'observacion' => $l->observacion,
            'referencia' => $l->referencia,
            'actor' => $l->actor_name_snapshot,
            'cerrada_en' => $l->cerrada_en?->format('Y-m-d H:i'),
            'cerrada_texto' => $l->cerrada_en ? $this->fechaTexto($l->cerrada_en->toDateString()).' '.$l->cerrada_en->format('H:i') : null,
        ];
    }

    private function fechaTexto(?string $fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return CarbonImmutable::parse($fecha)->locale(app()->getLocale())->translatedFormat('j M Y');
    }
}
