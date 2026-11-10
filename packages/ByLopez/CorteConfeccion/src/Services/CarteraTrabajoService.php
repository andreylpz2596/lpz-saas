<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Support\Dinero;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trabajo de un prestador por orden: lo RECONOCIDO (recibido por Bodega) y lo PENDIENTE DE RECIBIR (estimado), cada uno
 * con la tarifa que la orden conserva por combinacion tela + referencia (Doc. 05).
 *
 *  - Taller: reconocido = buenas aceptadas por Bodega en cada entrega; pendiente = unidades de entregas reportadas
 *    cuyo recibo aun no confirma Bodega. Las solo asignadas no cuentan. Al recibirse una entrega sus unidades pasan de
 *    pendientes a reconocidas UNA sola vez porque ambas salen del mismo reporte.
 *  - Cortador: reconocido = recepcion validada (incluye defectuosas); pendiente = lo que reporto el cortador mientras
 *    Bodega no recibe (estimado rotulado «reportado por el cortador»; vale lo recibido cuando llega).
 *  - Arranque: los eventos de recepcion anteriores a la fecha de arranque ya estan cubiertos por el saldo inicial y no se
 *    vuelven a contar; lo posterior si, aunque la orden sea anterior. Lo pendiente de recibir siempre cuenta.
 *  - Una combinacion «Sin tarifa» no vale cero: queda fuera de los importes y se lista como faltante por valorar.
 */
class CarteraTrabajoService
{
    public function __construct(protected OrdenTarifaService $ordenTarifaService) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function ordenes(Taller|Cortador $prestador, ?CarbonInterface $arranque): Collection
    {
        return $prestador instanceof Taller
            ? $this->ordenesConfeccion($prestador, $arranque)
            : $this->ordenesCorte($prestador, $arranque);
    }

    protected function ordenesConfeccion(Taller $taller, ?CarbonInterface $arranque): Collection
    {
        $ordenes = DB::table('bylopez_cc_ordenes_confeccion')->where('taller_id', $taller->id)->orderBy('id')->get(['id', 'codigo', 'estado', 'orden_corte_id']);

        if ($ordenes->isEmpty()) {
            return collect();
        }

        $filas = DB::table('bylopez_cc_ordenes_confeccion_retornos as r')
            ->join('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.retorno_id', '=', 'r.id')
            ->join('bylopez_cc_ordenes_confeccion_detalles as d', 'd.id', '=', 'rd.orden_confeccion_detalle_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->whereIn('r.orden_confeccion_id', $ordenes->pluck('id'))
            ->get([
                'r.orden_confeccion_id as orden_id', 'r.estado', 'r.fecha_recepcion_bodega',
                DB::raw('COALESCE(d.attribute_family_id, p.attribute_family_id) as familia'),
                'rd.cantidad_reportada', 'rd.cantidad_recibida',
            ]);

        $tarifas = DB::table('bylopez_cc_orden_tarifas')->whereIn('orden_confeccion_id', $ordenes->pluck('id'))->get()->groupBy('orden_confeccion_id');

        return $ordenes->map(function ($orden) use ($filas, $tarifas, $arranque) {
            $reconocida = [];
            $pendiente = [];

            foreach ($filas->where('orden_id', $orden->id) as $fila) {
                $familia = (int) $fila->familia;

                if ($fila->estado === 'pendiente_bodega') {
                    $pendiente[$familia] = ($pendiente[$familia] ?? 0) + (int) $fila->cantidad_reportada;
                } elseif ($fila->estado === 'recibida_bodega' && $this->cuentaDespuesDelArranque($fila->fecha_recepcion_bodega, $arranque)) {
                    $reconocida[$familia] = ($reconocida[$familia] ?? 0) + (int) $fila->cantidad_recibida;
                }
            }

            return $this->armar('confeccion', $orden, $reconocida, $pendiente, $tarifas->get($orden->id, collect()));
        })->filter(fn ($o) => $o['cantidad_reconocida'] > 0 || $o['cantidad_pendiente'] > 0)->values();
    }

    protected function ordenesCorte(Cortador $cortador, ?CarbonInterface $arranque): Collection
    {
        if (! $cortador->usuario_id) {
            return collect();
        }

        $ordenes = DB::table('bylopez_cc_ordenes_corte')->where('cortador_id', $cortador->usuario_id)->where('estado', '!=', 'anulado')->orderBy('id')->get(['id', 'codigo', 'estado']);

        if ($ordenes->isEmpty()) {
            return collect();
        }

        $recibidas = DB::table('bylopez_cc_recepciones_corte as rc')
            ->join('bylopez_cc_recepciones_corte_detalles as rcd', 'rcd.recepcion_corte_id', '=', 'rc.id')
            ->join('bylopez_cc_ordenes_corte_detalles as d', 'd.id', '=', 'rcd.orden_corte_detalle_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->whereIn('rc.orden_corte_id', $ordenes->pluck('id'))
            ->get(['rc.orden_corte_id as orden_id', 'rc.fecha_recepcion', DB::raw('COALESCE(d.attribute_family_id, p.attribute_family_id) as familia'), 'rcd.cantidad_recibida']);

        $reportadas = DB::table('bylopez_cc_ordenes_corte_detalles as d')
            ->join('bylopez_cc_ordenes_corte as oc', 'oc.id', '=', 'd.orden_corte_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->whereIn('d.orden_corte_id', $ordenes->pluck('id'))
            ->where('oc.estado', 'reportado_por_cortador')
            ->get(['d.orden_corte_id as orden_id', DB::raw('COALESCE(d.attribute_family_id, p.attribute_family_id) as familia'), 'd.cantidad_real_cortada']);

        $tarifas = DB::table('bylopez_cc_orden_tarifas')->whereIn('orden_corte_id', $ordenes->pluck('id'))->get()->groupBy('orden_corte_id');

        return $ordenes->map(function ($orden) use ($recibidas, $reportadas, $tarifas, $arranque) {
            $reconocida = [];
            $pendiente = [];

            foreach ($recibidas->where('orden_id', $orden->id) as $fila) {
                if ($this->cuentaDespuesDelArranque($fila->fecha_recepcion, $arranque)) {
                    $reconocida[(int) $fila->familia] = ($reconocida[(int) $fila->familia] ?? 0) + (int) $fila->cantidad_recibida;
                }
            }

            foreach ($reportadas->where('orden_id', $orden->id) as $fila) {
                $pendiente[(int) $fila->familia] = ($pendiente[(int) $fila->familia] ?? 0) + (int) $fila->cantidad_real_cortada;
            }

            return $this->armar('corte', $orden, $reconocida, $pendiente, $tarifas->get($orden->id, collect()));
        })->filter(fn ($o) => $o['cantidad_reconocida'] > 0 || $o['cantidad_pendiente'] > 0)->values();
    }

    /**
     * @param  array<int, int>  $reconocida  familia => cantidad
     * @param  array<int, int>  $pendiente  familia => cantidad
     * @return array<string, mixed>
     */
    protected function armar(string $tipo, object $orden, array $reconocida, array $pendiente, Collection $tarifas): array
    {
        $porFamilia = $tarifas->keyBy(fn ($t) => (int) $t->attribute_family_id);
        $nombres = DB::table('attribute_families')->whereIn('id', array_unique(array_merge(array_keys($reconocida), array_keys($pendiente))))->pluck('name', 'id');

        $valorReconocido = Dinero::cero();
        $valorPendiente = Dinero::cero();
        $sinTarifa = [];
        $lineas = [];

        foreach (array_unique(array_merge(array_keys($reconocida), array_keys($pendiente))) as $familia) {
            $tarifa = $porFamilia->get($familia)?->tarifa_unitaria;
            $cantRec = $reconocida[$familia] ?? 0;
            $cantPen = $pendiente[$familia] ?? 0;

            if ($tarifa === null) {
                $sinTarifa[] = ($nombres->get($familia) ?? 'Referencia '.$familia);
            }

            $vr = Dinero::por($tarifa, $cantRec);
            $vp = Dinero::por($tarifa, $cantPen);
            $valorReconocido = Dinero::sumar($valorReconocido, $vr);
            $valorPendiente = Dinero::sumar($valorPendiente, $vp);

            $lineas[] = [
                'familia_id' => $familia,
                'referencia' => $nombres->get($familia) ?? 'Referencia '.$familia,
                'tarifa' => $tarifa !== null ? Dinero::normalizar((string) $tarifa) : null,
                'cantidad_reconocida' => $cantRec,
                'cantidad_pendiente' => $cantPen,
                'valor_reconocido' => $vr,
                'valor_pendiente' => $vp,
            ];
        }

        return [
            'tipo' => $tipo,
            'orden_id' => (int) $orden->id,
            'codigo' => $orden->codigo,
            'estado_produccion' => $orden->estado,
            'cantidad_reconocida' => array_sum($reconocida),
            'cantidad_pendiente' => array_sum($pendiente),
            'valor_reconocido' => $valorReconocido,
            'valor_pendiente' => $valorPendiente,
            'sin_tarifa' => array_values(array_unique($sinTarifa)),
            'lineas' => $lineas,
        ];
    }

    protected function cuentaDespuesDelArranque(mixed $fecha, ?CarbonInterface $arranque): bool
    {
        if ($arranque === null) {
            return true;
        }

        if (! $fecha) {
            return false;
        }

        return Carbon::parse($fecha)->startOfDay()->greaterThanOrEqualTo($arranque->copy()->startOfDay());
    }
}
