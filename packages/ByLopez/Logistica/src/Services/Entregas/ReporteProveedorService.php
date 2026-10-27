<?php

namespace ByLopez\Logistica\Services\Entregas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura (SOLO LECTURA) del estado que un PROVEEDOR intermediario (hoy 99 Envios) reporto para una guia, tal como lo dejo
 * persistido el cargue «Envios completos» (`bylopez_lg_guia_estados_proveedor` + su historial). NO es la entrega:
 *
 *   - «Entregada segun 99 Envios» NO es una entrega registrada ni validada por ByLopez (eso son los eventos de Logistica);
 *   - tampoco es un pago: Cartera no se entera de este dato;
 *   - la guia queda «cruzada» solo si existe un reporte APLICADO y persistido (el procedimiento lo escribe en la misma
 *     transaccion que confirma el cargue; una ejecucion revertida no deja nada).
 *
 * Fechas: `fecha_estado` es la que informa el proveedor; `ultimo_cruce` es CUANDO ByLopez lo aplico (otra cosa).
 */
class ReporteProveedorService
{
    public const PROVEEDOR = '99envios';

    /**
     * Resumen compacto para el listado: envio => ['cruzada' => bool, ...]. Los envios sin guia o sin reporte no aparecen cruzados.
     *
     * @param  list<int>  $shipmentIds
     * `esperada` = la guia se registro con origen 99 Envios (aunque aun no tenga reporte): solo entonces el listado dice «Sin reporte».
     *
     * @return array<int, array{cruzada: bool, esperada: bool, estado: ?string, fecha_estado: ?string, discrepancia: bool}>
     */
    public function resumenPorEnvios(array $shipmentIds): array
    {
        $salida = [];

        foreach ($shipmentIds as $id) {
            $salida[(int) $id] = ['cruzada' => false, 'esperada' => false, 'estado' => null, 'fecha_estado' => null, 'discrepancia' => false];
        }

        if ($shipmentIds === [] || ! $this->disponible()) {
            return $salida;
        }

        $filas = DB::table('bylopez_lg_guias as g')
            ->join('bylopez_lg_guia_estados_proveedor as e', function ($j) {
                $j->on('e.guia_id', '=', 'g.id')->where('e.proveedor', '=', self::PROVEEDOR);
            })
            ->whereIn('g.shipment_id', $shipmentIds)->orderBy('e.fecha_estado_proveedor')
            ->get(['g.shipment_id', 'e.estado_proveedor', 'e.fecha_estado_proveedor', 'e.conciliacion_recaudo']);

        foreach (DB::table('bylopez_lg_guias')->whereIn('shipment_id', $shipmentIds)->where('origen_guia', self::PROVEEDOR)->pluck('shipment_id') as $id) {
            $salida[(int) $id]['esperada'] = true;
        }

        foreach ($filas as $f) {
            $salida[(int) $f->shipment_id] = [
                'cruzada' => true, 'esperada' => true, 'estado' => $f->estado_proveedor, 'fecha_estado' => $f->fecha_estado_proveedor,
                'discrepancia' => $f->conciliacion_recaudo === 'difiere',
            ];
        }

        return $salida;
    }

    /**
     * Detalle para «Ver»: null si el envio no tiene reporte aplicado.
     *
     * @return array<string, mixed>|null
     */
    public function detalleDeEnvio(int $shipmentId): ?array
    {
        if (! $this->disponible()) {
            return null;
        }

        $fila = DB::table('bylopez_lg_guias as g')
            ->join('bylopez_lg_guia_estados_proveedor as e', function ($j) {
                $j->on('e.guia_id', '=', 'g.id')->where('e.proveedor', '=', self::PROVEEDOR);
            })
            ->where('g.shipment_id', $shipmentId)->orderByDesc('e.updated_at')
            ->first(['g.id as guia_id', 'g.numero_guia', 'g.origen_guia', 'g.transportadora_codigo as guia_transportadora', 'e.*']);

        if (! $fila) {
            return null;
        }

        $historial = DB::table('bylopez_lg_guia_estado_proveedor_eventos')->where('guia_id', $fila->guia_id)->where('proveedor', self::PROVEEDOR)
            ->orderByDesc('fecha_estado_proveedor')->orderByDesc('id')->limit(50)
            ->get(['id', 'estado_proveedor', 'fecha_estado_proveedor', 'estado_anterior', 'fecha_estado_anterior', 'conciliacion_recaudo', 'carga_id', 'fila_origen', 'created_at'])
            ->map(fn ($h) => (array) $h)->all();

        return [
            'guia_id' => (int) $fila->guia_id,
            'numero_guia' => $fila->numero_guia,
            'proveedor' => self::PROVEEDOR,
            'transportadora_codigo' => $fila->transportadora_codigo,
            'estado' => $fila->estado_proveedor,
            'fecha_estado' => $fila->fecha_estado_proveedor,
            'fecha_envio' => $fila->fecha_envio_proveedor,
            'ultimo_cruce' => $fila->updated_at,
            'primer_cruce' => $fila->created_at,
            'reportes' => (int) $fila->reportes,
            'carga_id' => $fila->carga_id === null ? null : (int) $fila->carga_id,
            'fila_origen' => $fila->fila_origen === null ? null : (int) $fila->fila_origen,
            'recaudo' => [
                'reportado' => $fila->recaudo_reportado, 'interno' => $fila->recaudo_interno, 'diferencia' => $fila->recaudo_diferencia,
                'conciliacion' => $fila->conciliacion_recaudo, 'aplica_contrapago' => $fila->aplica_contrapago_reportado === null ? null : (bool) $fila->aplica_contrapago_reportado,
            ],
            // Cargos tal como los informa el proveedor: sin llamarlos «flete» (la equivalencia de valor_servicio no esta confirmada).
            'valor_comercial' => $fila->valor_comercial_reportado,
            'valor_servicio' => $fila->valor_servicio_reportado,
            'valor_seguro' => $fila->valor_seguro_reportado,
            'seguro' => $fila->seguro_reportado,
            'historial' => $historial,
        ];
    }

    protected function disponible(): bool
    {
        return Schema::hasTable('bylopez_lg_guia_estados_proveedor') && Schema::hasTable('bylopez_lg_guia_estado_proveedor_eventos');
    }
}
