<?php

/*
| Comparacion del disponible por variante (Doc. 09 §9.1). SOLO LECTURA: no escribe en ninguna tabla; se puede correr en
| cualquier base, incluida produccion.
|   php comparar_disponible.php            (base del .env)
|   DB_DATABASE=otra_base php comparar_disponible.php
| Compara, para cada variante simple del canal por defecto:
| - Nueva venta: TypeInstance::totalQuantity() de Bagisto (lo que ve el asesor);
| - Sugerencia: VarianteConsultaService::stockVendible() (lo que usa Corte y Confeccion);
| - Indexador: Σ product_inventories de fuentes activas del canal − product_ordered_inventories (indice al dia);
| - Cerco al confirmar: indexador − pendiente de pedidos anteriores a F5 sin reserva (CercoInventario::pendienteSinReserva).
| Informa los pedidos afectados por F5 y cada variante donde alguna cifra difiere.
*/

use ByLopez\CorteConfeccion\Services\VarianteConsultaService;
use ByLopez\VentasAsesor\Services\Venta\VentasSimultaneasService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$canal = (int) core()->getDefaultChannel()->id;

// Mismo criterio que CercoInventario::pendienteSinReserva (hija de configurable con qty_ordered 0, pedido despachable).
$afectadas = DB::table('order_items as h')
    ->join('order_items as l', 'l.id', '=', 'h.parent_id')
    ->join('orders as o', 'o.id', '=', 'l.order_id')
    ->where('l.type', 'configurable')
    ->where('h.qty_ordered', 0)
    ->where('o.channel_id', $canal)
    ->whereNotIn('o.status', VentasSimultaneasService::ESTADOS_SIN_DESPACHO)
    ->whereRaw('l.qty_ordered - l.qty_shipped - l.qty_refunded - l.qty_canceled > 0')
    ->selectRaw('o.increment_id AS pedido, o.status, h.product_id, h.sku, l.qty_ordered - l.qty_shipped - l.qty_refunded - l.qty_canceled AS pendiente')
    ->orderBy('o.id')
    ->get();

$legado = $afectadas->groupBy('product_id')->map(fn ($g) => (int) $g->sum('pendiente'));

$fuentes = DB::table('channel_inventory_sources')
    ->join('inventory_sources', 'inventory_sources.id', '=', 'channel_inventory_sources.inventory_source_id')
    ->where('channel_inventory_sources.channel_id', $canal)
    ->where('inventory_sources.status', 1)
    ->pluck('inventory_sources.id');

$fisico = DB::table('product_inventories')->whereIn('inventory_source_id', $fuentes)
    ->groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');
$reservado = DB::table('product_ordered_inventories')->where('channel_id', $canal)->pluck('qty', 'product_id');

$variantes = ProductProxy::modelClass()::query()->where('type', 'simple')->orderBy('id')->get();
$sugerencia = app(VarianteConsultaService::class)->stockVendible($variantes->pluck('id'), $canal);

$diferencias = [];
$conteo = ['nueva_venta_vs_sugerencia' => 0, 'indice_desactualizado' => 0, 'cerco_vs_nueva_venta' => 0, 'sin_indice' => 0];

foreach ($variantes as $variante) {
    $id = (int) $variante->id;
    $nuevaVenta = (int) $variante->getTypeInstance()->totalQuantity();
    $sug = $sugerencia[$id] ?? null;
    $indexador = (int) ($fisico[$id] ?? 0) - (int) ($reservado[$id] ?? 0);
    $cerco = $indexador - (int) ($legado[$id] ?? 0);

    // Sin fila de indice: Nueva venta muestra 0 y la sugerencia «Sin informacion» (no asume cero). Se cuenta aparte.
    if ($sug === null) {
        $conteo['sin_indice']++;

        continue;
    }

    $motivos = array_keys(array_filter([
        'nueva_venta_vs_sugerencia' => $nuevaVenta !== $sug,
        // El indexador guarda el valor tal cual (puede ser negativo); totalQuantity lo devuelve igual.
        'indice_desactualizado' => $sug !== $indexador,
        'cerco_vs_nueva_venta' => $cerco !== $nuevaVenta,
    ]));

    foreach ($motivos as $motivo) {
        $conteo[$motivo]++;
    }

    if ($motivos !== []) {
        $diferencias[] = ['sku' => $variante->sku, 'nueva_venta' => $nuevaVenta, 'sugerencia' => $sug, 'indexador' => $indexador, 'pendiente_sin_reserva' => (int) ($legado[$id] ?? 0), 'cerco' => $cerco, 'motivos' => $motivos];
    }
}

echo json_encode([
    'base' => (string) DB::selectOne('SELECT DATABASE() AS base')->base,
    'canal' => $canal,
    'variantes' => $variantes->count(),
    'pedidos_f5_afectados' => $afectadas->pluck('pedido')->unique()->count(),
    'unidades_f5_sin_reserva' => $legado->sum(),
    'lineas_f5' => $afectadas,
    'conteo' => $conteo,
    'diferencias' => $diferencias,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
