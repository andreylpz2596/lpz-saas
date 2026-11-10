<?php

/*
| Medicion de volumen del Consolidado + sugerencia por stock (Doc. 09 §12). SOLO bases aisladas lpz_saas_cc_val_*.
|   DB_DATABASE=lpz_saas_cc_val_xxx php medir_consolidado.php sembrar [variantes] [talleres] [ordenes] [detalles_por_orden]
|   DB_DATABASE=lpz_saas_cc_val_xxx php medir_consolidado.php medir
| sembrar: inserta datos sinteticos (prefijo PERF-) por SQL directo: variantes simples con indice de inventario y minimos
| variados (incluidos negativos, sin indice y sin minimo), talleres, ordenes de corte y confeccion con detalles pendientes,
| prioridades por cantidad y reportes pendientes de bodega. medir: consultas y tiempos del servicio y de la pagina completa
| (kernel HTTP real) en varios escenarios, y coherencia de indicadores, filtros y paginacion.
*/

use ByLopez\CorteConfeccion\Services\ConsolidadoConfeccionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS base')->base;

if (! str_starts_with($base, 'lpz_saas_cc_val_')) {
    fwrite(STDERR, "BLOQUEADO: '$base' no es una base de validacion aislada (lpz_saas_cc_val_*).\n");
    exit(2);
}

$accion = $argv[1] ?? 'medir';

if ($accion === 'sembrar') {
    [$nVariantes, $nTalleres, $nOrdenes, $nDetalles] = [(int) ($argv[2] ?? 1000), (int) ($argv[3] ?? 40), (int) ($argv[4] ?? 500), (int) ($argv[5] ?? 10)];
    mt_srand(20261103);
    $ahora = now()->toDateTimeString();
    $locale = app()->getLocale();
    $canal = core()->getDefaultChannel()->id;
    $familia = (int) DB::table('attribute_families')->min('id');

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    $primerProducto = (int) DB::table('products')->max('id') + 1;
    foreach (array_chunk(range(0, $nVariantes - 1), 500) as $lote) {
        DB::table('products')->insert(array_map(fn ($i) => ['id' => $primerProducto + $i, 'sku' => sprintf('PERF-%05d', $i), 'type' => 'simple', 'attribute_family_id' => $familia, 'created_at' => $ahora, 'updated_at' => $ahora], $lote));
        DB::table('product_flat')->insert(array_map(fn ($i) => ['product_id' => $primerProducto + $i, 'sku' => sprintf('PERF-%05d', $i), 'name' => 'Prenda perf '.intdiv($i, 8).' var '.($i % 8), 'locale' => $locale, 'channel' => 'default', 'status' => 1, 'type' => 'simple', 'created_at' => $ahora, 'updated_at' => $ahora], $lote));

        // 10 % sin indice (sin informacion); el resto entre -5 y 60. Minimo en 2/3 de las variantes (0..40).
        DB::table('product_inventory_indices')->insert(array_values(array_filter(array_map(fn ($i) => $i % 10 === 9 ? null : ['product_id' => $primerProducto + $i, 'channel_id' => $canal, 'qty' => mt_rand(-5, 60), 'created_at' => $ahora, 'updated_at' => $ahora], $lote))));
        DB::table('bylopez_cc_stock_minimos')->insert(array_values(array_filter(array_map(fn ($i) => $i % 3 === 2 ? null : ['product_id' => $primerProducto + $i, 'minimo' => mt_rand(0, 40), 'created_at' => $ahora, 'updated_at' => $ahora], $lote))));
    }

    $talleres = [];
    for ($t = 0; $t < $nTalleres; $t++) {
        $talleres[] = DB::table('bylopez_cc_talleres')->insertGetId(['nombre' => sprintf('PERF Taller %02d', $t), 'estado' => 'activo', 'created_at' => $ahora, 'updated_at' => $ahora]);
    }

    $cortes = [];
    for ($c = 0; $c < max(1, intdiv($nOrdenes, 25)); $c++) {
        $cortes[] = DB::table('bylopez_cc_ordenes_corte')->insertGetId(['codigo' => sprintf('PERF-OC-%04d', $c), 'rollo_id' => 0, 'color' => 'Varios', 'genero_linea' => 'Perf', 'estado' => 'recibida', 'created_at' => $ahora, 'updated_at' => $ahora]);
    }

    $detalles = 0;
    $reportes = 0;
    for ($o = 0; $o < $nOrdenes; $o++) {
        $tallerId = $talleres[$o % $nTalleres];
        $ordenId = DB::table('bylopez_cc_ordenes_confeccion')->insertGetId(['codigo' => sprintf('PERF-OF-%05d', $o), 'orden_corte_id' => $cortes[$o % count($cortes)], 'taller_id' => $tallerId, 'estado' => 'activa', 'fecha_entrega' => now()->subDays(mt_rand(0, 40))->toDateString(), 'created_at' => $ahora, 'updated_at' => $ahora]);
        $filas = [];
        for ($d = 0; $d < $nDetalles; $d++) {
            $producto = $primerProducto + mt_rand(0, $nVariantes - 1);
            $cantidad = mt_rand(10, 120);
            $u = mt_rand(0, 4) === 0 ? mt_rand(0, $cantidad) : 0;
            $a = mt_rand(0, 3) === 0 ? mt_rand(0, $cantidad - $u) : 0;
            $filas[] = ['orden_confeccion_id' => $ordenId, 'product_id' => $producto, 'producto_sku' => 'PERF', 'producto_nombre' => 'Perf', 'talla' => (string) (($producto % 5) * 2 + 6), 'cantidad_entregada' => $cantidad, 'prioridad' => 'normal', 'cantidad_urgente' => $u, 'cantidad_alta' => $a, 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        DB::table('bylopez_cc_ordenes_confeccion_detalles')->insert($filas);
        $detalles += count($filas);

        // Una de cada 5 ordenes ya reporto parte de su primer detalle y bodega no lo ha recibido.
        if ($o % 5 === 0) {
            $detalle = DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('orden_confeccion_id', $ordenId)->orderBy('id')->first();
            $retornoId = DB::table('bylopez_cc_ordenes_confeccion_retornos')->insertGetId(['codigo' => sprintf('PERF-OF-%05d-ENT-001', $o), 'orden_confeccion_id' => $ordenId, 'taller_id' => $tallerId, 'numero' => 1, 'fecha_reporte_taller' => $ahora, 'estado' => 'pendiente_bodega', 'created_at' => $ahora, 'updated_at' => $ahora]);
            DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->insert(['retorno_id' => $retornoId, 'orden_confeccion_detalle_id' => $detalle->id, 'product_id' => $detalle->product_id, 'cantidad_reportada' => min(5, $detalle->cantidad_entregada), 'created_at' => $ahora, 'updated_at' => $ahora]);
            $reportes++;
        }
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    echo "sembrado variantes=$nVariantes talleres=$nTalleres ordenes=$nOrdenes detalles=$detalles reportes_pendientes=$reportes\n";
    exit(0);
}

// --- medir ---
$admin = Admin::query()->whereHas('role', fn ($q) => $q->where('permission_type', 'all'))->whereNotIn('id', DB::table('bylopez_cc_talleres')->whereNotNull('usuario_id')->pluck('usuario_id'))->firstOrFail();
Auth::guard('admin')->setUser($admin);
$servicio = app(ConsolidadoConfeccionService::class);
$http = $app->make(HttpKernel::class);
$tallerEjemplo = (int) DB::table('bylopez_cc_talleres')->where('nombre', 'like', 'PERF%')->min('id');

$medir = function (callable $fn) {
    $consultas = 0;
    $msSql = 0.0;
    DB::listen(function ($q) use (&$consultas, &$msSql) {
        $consultas++;
        $msSql += $q->time;
    });
    $t0 = hrtime(true);
    $r = $fn();
    $ms = (hrtime(true) - $t0) / 1e6;
    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return [$r, $consultas, round($msSql, 1), round($ms, 1)];
};

$escenarios = [
    'servicio sin filtros (admin)' => fn () => $servicio->consultar(null, []),
    'servicio filtro taller' => fn () => $servicio->consultar(null, ['taller' => $tallerEjemplo]),
    'servicio sugerencia=urgente' => fn () => $servicio->consultar(null, ['sugerencia' => 'urgente']),
    'servicio texto "perf 12"' => fn () => $servicio->consultar(null, ['q' => 'perf 12']),
    'servicio alcance 1 taller' => fn () => $servicio->consultar([$tallerEjemplo], []),
];

$resultados = [];
$servicio->consultar(null, []); // calentamiento (opcache, primera conexion)

foreach ($escenarios as $nombre => $fn) {
    [$r, $q, $sql, $ms] = $medir($fn);
    $resultados[] = ['escenario' => $nombre, 'consultas' => $q, 'ms_sql' => $sql, 'ms_total' => $ms, 'filas' => $r['filas']->count(), 'variantes' => $r['totales']['variantes']];
}

foreach (['pagina 1' => [], 'pagina 20' => ['page' => 20], 'sugerencia=alta' => ['sugerencia' => 'alta'], 'taller + talla 8' => ['taller' => $tallerEjemplo, 'tallas' => ['8']]] as $nombre => $query) {
    [$respuesta, $q, $sql, $ms] = $medir(fn () => $http->handle(Request::create(route('admin.corte_confeccion.ordenes_confeccion.consolidado', $query, false), 'GET')));
    $resultados[] = ['escenario' => 'HTTP '.$nombre, 'consultas' => $q, 'ms_sql' => $sql, 'ms_total' => $ms, 'status' => $respuesta->getStatusCode(), 'kb' => round(strlen((string) $respuesta->getContent()) / 1024)];
}

// Coherencia: indicadores = suma de filas filtradas; paginacion cubre exactamente las variantes; filtros parten el total.
$todo = $servicio->consultar(null, []);
$porPaginas = 0;
for ($p = 1; $p <= (int) ceil($todo['grupos']->count() / ConsolidadoConfeccionService::POR_PAGINA); $p++) {
    $porPaginas += $todo['grupos']->forPage($p, ConsolidadoConfeccionService::POR_PAGINA)->count();
}
$sumaNiveles = 0;
foreach (['urgente', 'alta', 'normal', 'sin_minimo', 'sin_informacion'] as $nivel) {
    $sumaNiveles += $servicio->consultar(null, ['sugerencia' => $nivel])['totales']['por_confeccionar'];
}
$coherencia = [
    'indicador_igual_suma_filas' => $todo['totales']['por_confeccionar'] === (int) $todo['filas']->sum('por_confeccionar'),
    'indicador_igual_suma_grupos' => $todo['totales']['por_confeccionar'] === (int) $todo['grupos']->sum('por_confeccionar'),
    'paginas_cubren_variantes' => $porPaginas === $todo['totales']['variantes'],
    'filtros_sugerencia_suman_el_total' => $sumaNiveles === $todo['totales']['por_confeccionar'],
    'total_por_confeccionar' => $todo['totales']['por_confeccionar'],
    'variantes' => $todo['totales']['variantes'],
    'filas_detalle' => $todo['filas']->count(),
];

echo json_encode(['base' => $base, 'php' => PHP_VERSION, 'mysql' => DB::selectOne('select version() v')->v, 'resultados' => $resultados, 'coherencia' => $coherencia], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
