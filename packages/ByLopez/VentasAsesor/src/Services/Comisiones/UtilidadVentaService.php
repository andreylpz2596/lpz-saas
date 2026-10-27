<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\DataObjects\LineaUtilidad;
use ByLopez\VentasAsesor\DataObjects\UtilidadPedido;
use ByLopez\VentasAsesor\Enums\EstadoUtilidadLinea;
use ByLopez\VentasAsesor\Models\CostoVenta;
use Illuminate\Support\Facades\DB;

/**
 * COM-02 — MARGEN COMISIONABLE por linea y por pedido (D-COST-01: en el codigo se llama «utilidad», pero NO es la
 * utilidad real de la empresa ni un margen contable). SOLO LECTURA: no persiste nada y no calcula comisiones.
 *
 * Por linea PADRE (`parent_id IS NULL`; en un configurable la hija no cuenta, asi no hay doble conteo):
 *
 *   precio_neto_unitario = base_price − base_discount_amount / qty_ordered      (moneda base; sin IVA ni envio)
 *   cantidad             = qty_ordered − qty_canceled
 *   utilidad_unitaria    = max(0, precio_neto_unitario − costo_unitario_congelado)   (por unidad, COM-D-07)
 *   utilidad_total       = utilidad_unitaria × cantidad
 *
 * Nunca usa `orders.grand_total` (incluye envio) ni el `cost` actual del catalogo: solo el snapshot de COM-01.
 * Dos resultados: DIAGNOSTICO (con cualquier costo capturado) y AUTORIZADO (solo si el snapshot tiene
 * `autoridad_comision`: desde D-COST-02, todo `cost` de Bagisto > 0). Sin costo o sin snapshot, el margen es null y la
 * linea queda fuera de los totales.
 *
 * Reembolsos y devoluciones no se descuentan aqui: son ajustes de COM-08.
 */
class UtilidadVentaService
{
    public function dePedido(int $orderId): UtilidadPedido
    {
        return $this->dePedidos([$orderId])[$orderId] ?? new UtilidadPedido($orderId, []);
    }

    /**
     * Por lote, dos consultas en total.
     *
     * @param  list<int>  $orderIds
     * @return array<int, UtilidadPedido>
     */
    public function dePedidos(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        $items = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->whereNull('parent_id')
            ->orderBy('order_id')->orderBy('id')
            ->get(['id', 'order_id', 'sku', 'qty_ordered', 'qty_canceled', 'base_price', 'base_discount_amount']);

        $snapshots = CostoVenta::query()->whereIn('order_item_id', $items->pluck('id'))->get()->keyBy('order_item_id');

        $lineas = [];

        foreach ($items as $item) {
            $lineas[(int) $item->order_id][] = $this->linea($item, $snapshots->get($item->id));
        }

        $resultado = [];

        foreach ($orderIds as $id) {
            $resultado[$id] = new UtilidadPedido($id, $lineas[$id] ?? []);
        }

        return $resultado;
    }

    protected function linea(object $item, ?CostoVenta $snapshot): LineaUtilidad
    {
        $qtyPedida = (int) $item->qty_ordered;
        $cantidad = max(0, $qtyPedida - (int) $item->qty_canceled);

        $precioNeto = $qtyPedida > 0
            ? round((float) $item->base_price - ((float) $item->base_discount_amount / $qtyPedida), 4)
            : null;

        $costo = $snapshot?->costo_unitario === null ? null : (float) $snapshot->costo_unitario;
        $autoridad = (bool) ($snapshot?->autoridad_comision ?? false);

        $estado = match (true) {
            $precioNeto === null || $cantidad === 0 => EstadoUtilidadLinea::SIN_CANTIDAD,
            $snapshot === null => EstadoUtilidadLinea::SIN_SNAPSHOT,
            $costo === null => EstadoUtilidadLinea::SIN_COSTO,
            $autoridad => EstadoUtilidadLinea::AUTORIZADA,
            default => EstadoUtilidadLinea::DIAGNOSTICA,
        };

        $unitaria = null;
        $total = null;
        $negativo = false;

        if ($estado->tieneUtilidad()) {
            $margen = $precioNeto - $costo;
            $negativo = $margen < 0;
            $unitaria = round(max(0.0, $margen), 4);
            $total = round($unitaria * $cantidad, 2);
        }

        $autorizada = $estado === EstadoUtilidadLinea::AUTORIZADA;

        return new LineaUtilidad(
            orderId: (int) $item->order_id,
            orderItemId: (int) $item->id,
            sku: $snapshot?->sku_snapshot ?? $item->sku,
            cantidad: $cantidad,
            precioNetoUnitario: $precioNeto,
            costoUnitario: $costo,
            fuenteCosto: $snapshot?->fuente?->value,
            autoridadComision: $autoridad,
            utilidadUnitariaDiagnostica: $unitaria,
            utilidadTotalDiagnostica: $total,
            utilidadUnitariaAutorizada: $autorizada ? $unitaria : null,
            utilidadTotalAutorizada: $autorizada ? $total : null,
            margenNegativo: $negativo,
            estado: $estado,
        );
    }
}
