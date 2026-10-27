<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use Illuminate\Support\Facades\DB;

/**
 * VALOR COMERCIAL de las unidades DEVUELTAS de un envio, tomado SOLO del pedido historico (nunca del catalogo, del precio
 * por grupo actual ni de un promedio del total).
 *
 * Por cada linea devuelta (retornos VIGENTES, `order_item_id` de la linea vendida; en un configurable, la linea PADRE,
 * que es la que guarda el precio):
 *
 *   valor_unitario = (base_total − base_discount_amount + base_tax_amount) / qty_ordered
 *   valor_devuelto = valor_unitario × cantidad devuelta
 *
 * `base_total` ya trae el precio efectivo que pago ESE cliente (grupo de cliente / precio especial, congelado por Bagisto
 * al crear el pedido); `base_discount_amount` es la promocion repartida a esa linea y `base_tax_amount` su impuesto. Es el
 * mismo precio neto que usa el margen comisionable (COM-02: base_price − base_discount_amount / qty_ordered) mas el
 * impuesto de la linea, porque el recaudo es lo que paga el cliente. El envio no se descuenta: el domicilio se presto.
 */
class ValorDevolucionesEnvio
{
    /** Valor de UNA unidad vendida de una linea del pedido (precio efectivo historico − descuento + impuesto de la linea). */
    public static function valorUnitario(float $baseTotal, float $descuento, float $impuesto, float $vendidas): float
    {
        return $vendidas > 0 ? ($baseTotal - $descuento + $impuesto) / $vendidas : 0.0;
    }

    /**
     * C5: valor HISTORICO de UNA unidad de la linea vendida `$orderItemId` (si es la hija de un configurable, el de la linea
     * PADRE, que es la que guarda el precio). Misma formula que `deEnvios()` y `deUnidades()`.
     */
    public function unitarioDeLinea(int $orderItemId): float
    {
        $l = DB::table('order_items as hija')
            ->join('order_items as oi', 'oi.id', '=', DB::raw('COALESCE(hija.parent_id, hija.id)'))
            ->where('hija.id', $orderItemId)
            ->first(['oi.qty_ordered', 'oi.base_total', 'oi.base_discount_amount', 'oi.base_tax_amount']);

        return $l ? self::valorUnitario((float) $l->base_total, (float) $l->base_discount_amount, (float) $l->base_tax_amount, (float) $l->qty_ordered) : 0.0;
    }

    /**
     * C1: valor comercial HISTORICO de unidades por despachar (mismo precio efectivo que las devoluciones). Se lee de la linea
     * PADRE del pedido (`order_item_id` => cantidad); nunca del catalogo ni de un promedio del total.
     *
     * @param  array<int, int>  $unidades
     * @return array{valor: float, lineas: list<array{order_item_id: int, nombre: string, sku: string, cantidad: int, valor_unitario: float, valor: float}>}
     */
    public function deUnidades(array $unidades): array
    {
        $salida = ['valor' => 0.0, 'lineas' => []];

        if ($unidades === []) {
            return $salida;
        }

        foreach (DB::table('order_items')->whereIn('id', array_keys($unidades))->orderBy('id')->get(['id', 'name', 'sku', 'qty_ordered', 'base_total', 'base_discount_amount', 'base_tax_amount']) as $l) {
            $cantidad = (int) $unidades[(int) $l->id];
            $unitario = self::valorUnitario((float) $l->base_total, (float) $l->base_discount_amount, (float) $l->base_tax_amount, (float) $l->qty_ordered);
            $valor = round($unitario * $cantidad, 2);

            $salida['valor'] = round($salida['valor'] + $valor, 2);
            $salida['lineas'][] = [
                'order_item_id' => (int) $l->id, 'nombre' => (string) $l->name, 'sku' => (string) $l->sku,
                'cantidad' => $cantidad, 'valor_unitario' => $unitario, 'valor' => $valor,
            ];
        }

        return $salida;
    }

    /**
     * @param  list<int>  $shipmentIds
     * @return array<int, array{valor: float, lineas: list<array{order_item_id: int, nombre: string, sku: string, cantidad: int, valor_unitario: float, valor: float}>}>
     */
    public function deEnvios(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $filas = DB::table('bylopez_lg_retorno_items as i')
            ->join('bylopez_lg_retornos as r', 'r.id', '=', 'i.retorno_id')
            ->join('order_items as hija', 'hija.id', '=', 'i.order_item_id')
            ->join('order_items as oi', 'oi.id', '=', DB::raw('COALESCE(hija.parent_id, hija.id)'))
            ->whereIn('r.shipment_id', $shipmentIds)
            ->where('r.estado', Estado::CONFIRMADO->value)
            ->groupBy('r.shipment_id', 'oi.id', 'oi.name', 'oi.sku', 'oi.qty_ordered', 'oi.base_total', 'oi.base_discount_amount', 'oi.base_tax_amount')
            ->orderBy('oi.id')
            ->get([
                'r.shipment_id', 'oi.id', 'oi.name', 'oi.sku', 'oi.qty_ordered', 'oi.base_total', 'oi.base_discount_amount', 'oi.base_tax_amount',
                DB::raw('SUM(i.cantidad) AS cantidad'),
            ]);

        $salida = [];

        foreach ($filas as $f) {
            $unitario = self::valorUnitario((float) $f->base_total, (float) $f->base_discount_amount, (float) $f->base_tax_amount, (float) $f->qty_ordered);
            $cantidad = (int) $f->cantidad;
            $valor = round($unitario * $cantidad, 2);
            $envio = (int) $f->shipment_id;

            $salida[$envio] ??= ['valor' => 0.0, 'lineas' => []];
            $salida[$envio]['valor'] = round($salida[$envio]['valor'] + $valor, 2);
            $salida[$envio]['lineas'][] = [
                'order_item_id' => (int) $f->id,
                'nombre' => (string) $f->name,
                'sku' => (string) $f->sku,
                'cantidad' => $cantidad,
                'valor_unitario' => round($unitario, 2),
                'valor' => $valor,
            ];
        }

        return $salida;
    }
}
