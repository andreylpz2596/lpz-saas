<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\DataObjects\ResultadoCapturaCosto;
use ByLopez\VentasAsesor\Enums\FuenteCostoVenta;
use ByLopez\VentasAsesor\Models\CostoVenta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * COM-01 — congela el costo unitario de cada linea de un pedido (Doc. 16 §4, Doc. 17 §7.1, COM-D-02).
 *
 * Que se congela, por linea PADRE (`order_items.parent_id IS NULL`, la que lleva precio y cantidad):
 *  - simple: el producto de la propia linea;
 *  - configurable: la VARIANTE real, tomada de la fila hija (`product_id`, `sku`) o, si faltara, de
 *    `additional.selected_configurable_option`. El costo del configurable padre nunca se usa;
 *  - otros tipos (bundle, grouped, booking...): no los vende ByLopez; quedan `sin_costo` con su tipo anotado.
 * Una sola fila por unidad vendida: la hija no genera fila propia.
 *
 * Fuente (D-COST-02): el atributo `cost` de Bagisto de ese producto es el COSTO COMISIONABLE OFICIAL
 * (`cost_bagisto_comisionable`, `autoridad_comision = true`). Sin valor positivo: `costo_unitario` NULL, `sin_costo` y
 * sin autoridad; nunca 0. Bagisto guarda un precio vacio como NULL (ProductAttributeValueRepository::saveValues).
 *
 * Idempotente: una linea con snapshot no se toca (UNIQUE `order_item_id` + lectura previa); un reintento es un
 * no-op. Inmutable: cambiar despues el `cost` del catalogo no altera snapshots existentes.
 *
 * Por lote: un numero constante de lecturas por pedido (pedido, lineas, snapshots existentes, hijas, atributo,
 * canal y valores de `cost`), sin N+1; un INSERT por linea nueva.
 * No lanza por falta de costo: un costo faltante nunca bloquea una venta.
 */
class CapturarCostoVentaService
{
    public const ATRIBUTO_COSTO = 'cost';

    public function capturarPedido(int $orderId): ResultadoCapturaCosto
    {
        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'channel_id', 'base_currency_code']);

        if ($pedido === null) {
            return new ResultadoCapturaCosto($orderId, 0, 0, 0);
        }

        $lineas = DB::table('order_items')
            ->where('order_id', $orderId)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get(['id', 'sku', 'type', 'product_id', 'additional']);

        if ($lineas->isEmpty()) {
            return new ResultadoCapturaCosto($orderId, 0, 0, 0);
        }

        $existentes = CostoVenta::query()
            ->whereIn('order_item_id', $lineas->pluck('id'))
            ->pluck('order_item_id')
            ->flip();

        $pendientes = $lineas->reject(fn (object $linea) => $existentes->has($linea->id));

        if ($pendientes->isEmpty()) {
            return new ResultadoCapturaCosto($orderId, 0, $existentes->count(), 0);
        }

        $hijas = DB::table('order_items')
            ->whereIn('parent_id', $pendientes->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'parent_id', 'sku', 'product_id'])
            ->groupBy('parent_id')
            ->map(fn (Collection $grupo) => $grupo->first());

        $resueltas = $pendientes->map(fn (object $linea) => $this->resolverVariante($linea, $hijas->get($linea->id)));

        $atributoId = DB::table('attributes')->where('code', self::ATRIBUTO_COSTO)->value('id');
        $costos = $this->costosDe($atributoId, $resueltas->pluck('producto_costo')->filter()->unique()->values(), $pedido);

        $creados = 0;
        $sinCosto = 0;
        $capturadoEn = now();

        foreach ($resueltas as $r) {
            [$costo, $fuente, $referencia] = $this->clasificar($r, $costos, $atributoId);

            CostoVenta::query()->create([
                'order_id' => $orderId,
                'order_item_id' => $r['order_item_id'],
                'variante_order_item_id' => $r['variante_order_item_id'],
                'product_id' => $r['product_id'],
                'variant_product_id' => $r['producto_costo'],
                'sku_snapshot' => $r['sku'],
                'tipo_producto' => $r['tipo'],
                'costo_unitario' => $costo,
                'moneda' => $pedido->base_currency_code,
                'fuente' => $fuente,
                'fuente_referencia' => $referencia,
                'autoridad_comision' => $fuente->otorgaAutoridadComision(),
                'capturado_en' => $capturadoEn,
            ]);

            $creados++;

            if ($costo === null) {
                $sinCosto++;
            }
        }

        return new ResultadoCapturaCosto($orderId, $creados, $existentes->count(), $sinCosto);
    }

    /**
     * Producto cuyo costo representa la unidad vendida.
     *
     * @return array{order_item_id: int, variante_order_item_id: ?int, product_id: ?int, producto_costo: ?int, sku: ?string, tipo: string, soportado: bool}
     */
    protected function resolverVariante(object $linea, ?object $hija): array
    {
        $tipo = (string) $linea->type;
        $productId = $linea->product_id === null ? null : (int) $linea->product_id;

        if ($tipo === 'configurable') {
            $varianteId = $hija?->product_id ?? (json_decode((string) $linea->additional, true)['selected_configurable_option'] ?? null);

            return [
                'order_item_id' => (int) $linea->id,
                'variante_order_item_id' => $hija === null ? null : (int) $hija->id,
                'product_id' => $productId,
                'producto_costo' => $varianteId === null ? null : (int) $varianteId,
                'sku' => $hija->sku ?? $linea->sku,
                'tipo' => $tipo,
                'soportado' => true,
            ];
        }

        return [
            'order_item_id' => (int) $linea->id,
            'variante_order_item_id' => null,
            'product_id' => $productId,
            // En un simple, el producto vendido ES la variante.
            'producto_costo' => $tipo === 'simple' ? $productId : null,
            'sku' => $linea->sku,
            'tipo' => $tipo,
            'soportado' => $tipo === 'simple',
        ];
    }

    /**
     * Costo comisionable VIGENTE hoy de un producto (la misma lectura del atributo `cost` que usa el congelado de cada venta, en el
     * canal principal). Solo para SIMULAR: no escribe ni congela nada. Sin valor positivo: null (nunca 0).
     */
    public function costoVigenteDe(int $productId): ?float
    {
        $atributoId = DB::table('attributes')->where('code', self::ATRIBUTO_COSTO)->value('id');
        $canal = DB::table('channels')->orderBy('id')->value('id');
        $costo = $this->costosDe($atributoId === null ? null : (int) $atributoId, collect([$productId]), (object) ['channel_id' => $canal])[$productId] ?? null;

        return $costo !== null && $costo > 0 ? $costo : null;
    }

    /**
     * Valor del atributo `cost` por producto. Si el atributo fuera por canal, se prefiere el valor del canal del
     * pedido sobre el global.
     *
     * @param  Collection<int, int>  $productos
     * @return array<int, float|null>
     */
    protected function costosDe(?int $atributoId, Collection $productos, object $pedido): array
    {
        if ($atributoId === null || $productos->isEmpty()) {
            return [];
        }

        $canal = DB::table('channels')->where('id', $pedido->channel_id)->value('code');
        $costos = [];

        $filas = DB::table('product_attribute_values')
            ->where('attribute_id', $atributoId)
            ->whereIn('product_id', $productos)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $canal))
            ->get(['product_id', 'channel', 'float_value']);

        foreach ($filas->sortBy(fn (object $f) => $f->channel === null ? 0 : 1) as $fila) {
            // Ordenado global → canal: el valor del canal, si existe, queda encima.
            $costos[(int) $fila->product_id] = $fila->float_value === null ? null : (float) $fila->float_value;
        }

        return $costos;
    }

    /**
     * @param  array<int, float|null>  $costos
     * @return array{0: ?string, 1: FuenteCostoVenta, 2: string}
     */
    protected function clasificar(array $r, array $costos, ?int $atributoId): array
    {
        if (! $r['soportado']) {
            return [null, FuenteCostoVenta::SIN_COSTO, 'tipo_no_soportado:'.$r['tipo']];
        }

        if ($atributoId === null) {
            return [null, FuenteCostoVenta::SIN_COSTO, 'atributo_cost_inexistente'];
        }

        if ($r['producto_costo'] === null) {
            return [null, FuenteCostoVenta::SIN_COSTO, 'variante_no_resuelta'];
        }

        $valor = $costos[$r['producto_costo']] ?? null;

        if ($valor === null) {
            return [null, FuenteCostoVenta::SIN_COSTO, 'cost_ausente'];
        }

        // Un 0 en un atributo de precio de Bagisto significa «no cargado», no «costo cero».
        if ($valor <= 0) {
            return [null, FuenteCostoVenta::SIN_COSTO, 'cost_no_positivo'];
        }

        return [number_format($valor, 4, '.', ''), FuenteCostoVenta::COST_BAGISTO_COMISIONABLE, 'attribute:cost#'.$atributoId];
    }
}
