<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * R3 — EVALUACION IDEMPOTENTE de las comisiones de un pedido. Se puede repetir cuantas veces se quiera y en cualquier
 * orden de eventos (validar antes o despues de cobrar, liquidar el recaudo despues...): evalua el ESTADO, no el evento.
 *
 * Por pedido, en una transaccion:
 *  0. CERROJOS ANTES DE LEER: pedido, y el mes de cada asesor implicado (FOR UPDATE, orden estable). Solo se hacen lecturas
 *     con bloqueo hasta tenerlos: asi la foto de la transaccion (REPEATABLE READ) se toma DESPUES de esperar a otra
 *     evaluacion del mismo mes, y el acumulado del umbral nunca se calcula con datos viejos.
 *  1. sincroniza lineas y libro del umbral (LineasComisionService) y anota que meses cambiaron (para la cascada);
 *  2. evalua las condiciones A-04 (ElegibilidadComisionService), la suficiencia del costo y la distribucion del umbral;
 *  3. estimada → HABILITADA si todo se cumple y la linea no tiene una politica pendiente: congela unidades y monto;
 *     habilitada sin liquidacion → vuelve a estimada si deja de cumplir (con motivo). Si SOLO se desplazo su tramo del
 *     umbral (D5 pendiente) no se recalcula: conserva lo congelado y queda en revision (no liquidable);
 *     habilitada EN una liquidacion → nunca se recalcula: si deja de cumplir o se desplaza se abre una INCIDENCIA que
 *     retiene lo no pagado (IncidenciasComisionService); nunca se descuenta sola.
 */
class EvaluarComisionesService
{
    /** Estado del costo (snapshot al confirmar) que NO alcanza para habilitar: falta la confirmacion del costo. */
    private const COSTO_INSUFICIENTE = ['vencido' => 'costo_vencido', 'sin_estado' => 'costo_sin_estado'];

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected LineasComisionService $lineas,
        protected ElegibilidadComisionService $elegibilidad,
        protected UmbralMensualService $umbral,
        protected IncidenciasComisionService $incidencias,
    ) {}

    /** Evaluacion pedida desde el panel: exige `comisiones.calcular`. */
    public function evaluarComoActor(?Admin $actor, ?int $orderId = null): array
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_COMISIONES_CALCULAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para recalcular comisiones.');
        }

        return $orderId === null ? $this->evaluarPendientes((int) $actor->id) : [$orderId => $this->evaluarPedido($orderId, (int) $actor->id)];
    }

    /**
     * Recuperacion MANUAL: pedidos con lineas no anuladas que aun pueden cambiar, y pedidos con snapshot y asesor que aun no
     * tienen lineas. En orden de pedido (≈ orden de confirmacion), para que un cambio de un pedido anterior ya este aplicado
     * cuando se evalua uno posterior del mismo mes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluarPendientes(?int $actorId = null): array
    {
        $ids = ComisionLinea::query()->where('estado', '!=', ComisionLinea::ANULADA)->distinct()->orderBy('order_id')->pluck('order_id');

        $sinLineas = DB::table('bylopez_va_rentabilidad_evaluaciones as e')
            ->join('bylopez_va_order_attributions as a', 'a.order_id', '=', 'e.order_id')
            ->where('a.commercial_owner_type', 'advisor')
            ->whereNotExists(fn ($q) => $q->from('bylopez_va_comision_lineas as l')->whereColumn('l.order_id', 'e.order_id')->where('l.estado', '!=', ComisionLinea::ANULADA))
            ->pluck('e.order_id');

        $resultado = [];

        foreach ($ids->merge($sinLineas)->unique()->sort() as $id) {
            $resultado[(int) $id] = $this->evaluarPedido((int) $id, $actorId);
        }

        return $resultado;
    }

    /** @return array{lineas: int, habilitadas: int, deshabilitadas: int, ajustes: int, incidencias: int, motivos: list<string>, meses_cambiados: list<string>, meses_pedido: list<string>} */
    public function evaluarPedido(int $orderId, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($orderId, $actorId) {
            $resumen = ['lineas' => 0, 'habilitadas' => 0, 'deshabilitadas' => 0, 'ajustes' => 0, 'incidencias' => 0, 'motivos' => [], 'meses_cambiados' => [], 'meses_pedido' => []];

            // 0. Cerrojos con lecturas BLOQUEANTES (no fijan la foto de la transaccion).
            $pedido = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first(['id', 'created_at']);

            if ($pedido === null) {
                return $resumen;
            }

            $meses = $this->mesesImplicados($orderId, (string) $pedido->created_at);

            foreach ($meses as $clave) {
                [$a, $m] = explode('|', $clave, 2);
                $this->umbral->bloquearMes((int) $a, $m);
            }

            // 1. Sincronizacion y deteccion de cambios del libro (cascada del umbral).
            $antes = $this->huellaLibro($orderId);
            $vivas = $this->lineas->sincronizar($orderId, $actorId);
            $despues = $this->huellaLibro($orderId);

            foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $clave) {
                if (($antes[$clave] ?? null) !== ($despues[$clave] ?? null)) {
                    $resumen['meses_cambiados'][] = $clave;
                }
            }

            $resumen['lineas'] = $vivas->count();
            $resumen['meses_pedido'] = $vivas->map(fn (ComisionLinea $l) => $l->asesor_admin_id.'|'.$l->mes_comercial)->unique()->values()->all();

            if ($vivas->isEmpty()) {
                return $resumen;
            }

            // 2. Condiciones del pedido y suficiencia del costo de cada linea (snapshot al confirmar).
            $eleg = $this->elegibilidad->dePedido($orderId);
            $resumen['motivos'] = $eleg['motivos'];
            $estadosCosto = DB::table('bylopez_va_rentabilidad_evaluacion_lineas as l')
                ->join('bylopez_va_rentabilidad_evaluaciones as e', 'e.id', '=', 'l.evaluacion_id')
                ->where('e.order_id', $orderId)->pluck('l.estado_costo', 'l.order_item_id');

            foreach ($vivas->groupBy(fn (ComisionLinea $l) => $l->asesor_admin_id.'|'.$l->mes_comercial)->sortKeys() as $grupo) {
                $primera = $grupo->first();
                $mes = $this->umbral->bloquearMes($primera->asesor_admin_id, $primera->mes_comercial);
                $dist = $this->umbral->distribucion($primera->asesor_admin_id, $primera->mes_comercial);

                foreach ($grupo as $linea) {
                    $linea->refresh();
                    $d = $dist['lineas'][$linea->id] ?? null;
                    $motivosLinea = $eleg['motivos'];

                    if ($linea->base_unitaria === null) {
                        $motivosLinea[] = 'costo_sin_autoridad';
                    } elseif (isset(self::COSTO_INSUFICIENTE[$estadosCosto[$linea->order_item_id] ?? 'sin_estado'])) {
                        $motivosLinea[] = self::COSTO_INSUFICIENTE[$estadosCosto[$linea->order_item_id] ?? 'sin_estado'];
                    }

                    if ($linea->porcentaje === null) {
                        $motivosLinea[] = 'sin_acuerdo_al_confirmar';
                    }

                    $marcas = $d['marcas'] ?? ['sin_distribucion'];
                    $habilitable = $motivosLinea === [] && $marcas === [] && $d !== null && $d['comisionables'] !== null;

                    $this->aplicar($linea, $habilitable, $d, array_values(array_unique([...$motivosLinea, ...$marcas])), $eleg['evidencia'], $actorId, $resumen);

                    if ($habilitable && $mes->umbral_aplicado === null) {
                        $mes->update(['umbral_aplicado' => $dist['umbral'], 'acuerdo_umbral_id' => $dist['acuerdo_umbral_id'], 'umbral_fijado_en' => now()]);
                    }
                }
            }

            return $resumen;
        });
    }

    /**
     * Claves «asesor|mes» que la evaluacion puede tocar: las de sus lineas vivas y la del asesor vigente (reasignacion).
     * Solo lecturas bloqueantes (FOR UPDATE / LOCK IN SHARE MODE).
     *
     * @return list<string>
     */
    protected function mesesImplicados(int $orderId, string $creadoEn): array
    {
        $claves = ComisionLinea::query()->where('order_id', $orderId)->where('estado', '!=', ComisionLinea::ANULADA)->lockForUpdate()
            ->get(['asesor_admin_id', 'mes_comercial'])->map(fn ($l) => $l->asesor_admin_id.'|'.$l->mes_comercial)->all();

        $atribucion = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->sharedLock()->first(['commercial_owner_type', 'advisor_id']);
        $tipo = DB::table('bylopez_va_clasificaciones_pedido')->where('order_id', $orderId)->sharedLock()->value('tipo_operacion');

        if ($atribucion?->commercial_owner_type === TipoPropiedadComercial::ASESOR->value && $atribucion->advisor_id !== null
            && $tipo === TipoOperacionPedido::VENTA_NORMAL->value) {
            $claves[] = (int) $atribucion->advisor_id.'|'.MesComercial::calendario((int) $atribucion->advisor_id)->clave($creadoEn);
        }

        $claves = array_values(array_unique($claves));
        sort($claves, SORT_STRING);

        return $claves;
    }

    /** @return array<string, string> «asesor|mes» → huella (movimientos y unidades) del libro del pedido */
    protected function huellaLibro(int $orderId): array
    {
        return DB::table('bylopez_va_umbral_movimientos')->where('order_id', $orderId)->groupBy('asesor_admin_id', 'mes')
            ->selectRaw('asesor_admin_id, mes, COUNT(*) AS n, SUM(unidades) AS u')->get()
            ->mapWithKeys(fn ($f) => [$f->asesor_admin_id.'|'.$f->mes => $f->n.':'.$f->u])->all();
    }

    /** @param  list<string>  $motivos */
    protected function aplicar(ComisionLinea $linea, bool $habilitable, ?array $d, array $motivos, array $evidencia, ?int $actorId, array &$resumen): void
    {
        $alerta = $motivos === [] ? null : mb_substr($motivos[0], 0, 40);

        if ($linea->liquidacion_id !== null) {
            $this->vigilarLiquidada($linea, $habilitable, $d, $motivos, $actorId, $resumen);

            return;
        }

        if ($habilitable) {
            $monto = Dinero::texto($this->umbral->monto($linea, $d['comisionables']));

            if ($linea->estado === ComisionLinea::ESTIMADA || $linea->unidades_comisionables !== $d['comisionables'] || (string) $linea->monto !== $monto) {
                $antes = $linea->estado === ComisionLinea::HABILITADA ? ['unidades' => $linea->unidades_comisionables, 'monto' => $linea->monto] : null;

                $linea->update([
                    'estado' => ComisionLinea::HABILITADA,
                    'unidades_comisionables' => $d['comisionables'],
                    'monto' => $monto,
                    'habilitada_en' => $linea->habilitada_en ?? now(),
                    'evidencia' => $evidencia + ['computables' => $d['computables']],
                    'alerta' => null,
                ]);

                // D5: el evento deja la traza del calculo (umbral, acumulado previo y unidades netas del pedido) usada para recalcular.
                $traza = ['umbral' => $d['umbral'] ?? null, 'acumulado_previo' => $d['acumulado_previo'] ?? null, 'unidades_pedido' => $d['unidades_pedido'] ?? null, 'computables' => $d['computables']];
                $this->lineas->evento($linea, $antes === null ? 'habilitada' : 'recalculada', ['unidades' => $d['comisionables'], 'monto' => $monto, 'antes' => $antes, 'traza' => $traza], $actorId);
                $resumen['habilitadas']++;
            } elseif ($linea->alerta !== null) {
                $linea->update(['alerta' => null]);
                $this->lineas->evento($linea, 'revision_superada', [], $actorId);
            }

            return;
        }

        // Habilitada cuyo UNICO problema es que su tramo del umbral se movio (D5 sin aprobar): lo congelado no se recalcula
        // ni se pierde; queda en revision y fuera de toda liquidacion hasta decidir la politica.
        if ($linea->estado === ComisionLinea::HABILITADA && $motivos === [UmbralMensualService::POLITICA_DESPLAZAMIENTO]) {
            if ($linea->alerta !== $alerta) {
                $linea->update(['alerta' => $alerta]);
                $this->lineas->evento($linea, 'marcada_politica_pendiente', ['motivo' => $alerta, 'propuesta' => $d['comisionables'] ?? null], $actorId);
            }

            return;
        }

        if ($linea->estado === ComisionLinea::HABILITADA) {
            $linea->update(['estado' => ComisionLinea::ESTIMADA, 'unidades_comisionables' => null, 'monto' => null, 'habilitada_en' => null, 'alerta' => $alerta]);
            $this->lineas->evento($linea, 'deshabilitada', ['motivos' => $motivos], $actorId);
            $resumen['deshabilitadas']++;

            return;
        }

        if ($linea->alerta !== $alerta) {
            $linea->update(['alerta' => $alerta]);
        }
    }

    /**
     * Linea ya incluida en una liquidacion: nunca se recalcula. Si pierde condiciones o se desplaza su tramo, se abre una
     * INCIDENCIA (retiene lo no pagado; propone ajuste solo por lo ya pagado). Si vuelve a cumplir, la alerta se quita pero
     * la incidencia abierta sigue hasta que una persona la resuelva.
     */
    protected function vigilarLiquidada(ComisionLinea $linea, bool $habilitable, ?array $d, array $motivos, ?int $actorId, array &$resumen): void
    {
        if ($habilitable) {
            if ($linea->alerta !== null) {
                $linea->update(['alerta' => null]);
                $this->lineas->evento($linea, 'condiciones_recuperadas', [], $actorId);
            }

            return;
        }

        $soloDesplazamiento = $motivos === [UmbralMensualService::POLITICA_DESPLAZAMIENTO];
        $alerta = $soloDesplazamiento ? UmbralMensualService::POLITICA_DESPLAZAMIENTO : ComisionIncidencia::PERDIO_CONDICIONES;

        // D6: comision correcta HOY (con las unidades netas actuales; 0 si perdio condiciones) frente a lo pagado: solo el EXCESO se ajusta.
        $correcta = $soloDesplazamiento && isset($d['comisionables']) ? ($this->umbral->monto($linea, $d['comisionables']) ?? \Brick\Math\BigDecimal::zero()) : \Brick\Math\BigDecimal::zero();
        $abierta = $this->incidencias->abrir($linea, $alerta, [
            'motivos' => $motivos, 'propuesta_unidades' => $d['comisionables'] ?? null, 'correcta_actual' => Dinero::texto($correcta),
            'traza' => ['umbral' => $d['umbral'] ?? null, 'acumulado_previo' => $d['acumulado_previo'] ?? null, 'unidades_pedido' => $d['unidades_pedido'] ?? null],
        ], $actorId);

        if ($abierta !== null) {
            $resumen['incidencias']++;
            $abierta->ajuste_id !== null && $resumen['ajustes']++;
        }

        if ($linea->alerta !== $alerta) {
            $linea->update(['alerta' => $alerta]);
            $this->lineas->evento($linea, 'marcada_en_liquidacion', ['motivo' => $alerta, 'motivos' => $motivos], $actorId);
        }
    }
}
