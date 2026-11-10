<?php

/*
| Ayudante del recorrido de navegador del Consolidado y las prioridades (SOLO base demo aislada lpz_saas_demo_*).
|   DB_DATABASE=lpz_saas_demo_xxx DEMO_PRIO_PASSWORD=... php demo_prioridades.php preparar <taller_id>
|   DB_DATABASE=lpz_saas_demo_xxx php demo_prioridades.php estado
|   DB_DATABASE=lpz_saas_demo_xxx php demo_prioridades.php restaurar
| preparar: crea cc.prio.admin (rol Administrador) y cc.prio.satelite (rol «Satélite demo», nombre distinto a TALLER CONFECCION)
| y vincula el taller indicado al satelite, guardando el vinculo original. restaurar: deshace TODO lo anterior, incluidos los
| cambios de prioridad y avisos creados por cc.prio.admin. La clave sale del entorno; no se imprime.
|   ... php demo_prioridades.php stock <product_id> <cantidad|null>      (disponible de la demo; restaurar lo devuelve)
|   ... php demo_prioridades.php cambiar <detalle_id> <cantidad> <desde> <hacia>   (cambio «desde otra pantalla»)
| restaurar tambien devuelve el disponible, borra los minimos y los reportes creados por el recorrido.
*/

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\PrioridadConfeccionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS base')->base;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "BLOQUEADO: '$base' no es una base demo aislada.\n");
    exit(2);
}

$archivo = storage_path('app/demo_prioridades_'.$base.'.json');
$accion = $argv[1] ?? 'estado';

if ($accion === 'preparar') {
    $clave = (string) getenv('DEMO_PRIO_PASSWORD');
    $tallerId = (int) ($argv[2] ?? 0);

    if (strlen($clave) < 12 || ! $tallerId || file_exists($archivo)) {
        fwrite(STDERR, "Falta DEMO_PRIO_PASSWORD (>=12), taller o ya estaba preparado ($archivo).\n");
        exit(2);
    }

    $rolSatelite = Role::query()->create([
        'name' => 'Satélite demo', 'description' => 'Recorrido consolidado', 'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'],
    ]);
    $admin = Admin::query()->create(['name' => 'Prio Admin Demo', 'email' => 'cc.prio.admin@bylopez.test', 'password' => Hash::make($clave), 'status' => 1, 'role_id' => Role::query()->where('permission_type', 'all')->orderBy('id')->value('id')]);
    $satelite = Admin::query()->create(['name' => 'Satélite Demo', 'email' => 'cc.prio.satelite@bylopez.test', 'password' => Hash::make($clave), 'status' => 1, 'role_id' => $rolSatelite->id]);

    file_put_contents($archivo, json_encode([
        'taller_id' => $tallerId,
        'usuario_original' => DB::table('bylopez_cc_talleres')->where('id', $tallerId)->value('usuario_id'),
        'admin_id' => $admin->id, 'satelite_id' => $satelite->id, 'rol_id' => $rolSatelite->id,
    ]));
    DB::table('bylopez_cc_talleres')->where('id', $tallerId)->update(['usuario_id' => $satelite->id]);
    echo "preparado taller=$tallerId\n";
} elseif ($accion === 'restaurar') {
    $d = json_decode((string) @file_get_contents($archivo), true);

    if (! $d) {
        fwrite(STDERR, "Nada que restaurar.\n");
        exit(2);
    }

    $ops = DB::table('bylopez_cc_prioridad_operaciones')->where('usuario_id', $d['admin_id'])->pluck('id');
    $detalles = DB::table('bylopez_cc_prioridad_cambios')->whereIn('operacion_id', $ops)->pluck('orden_confeccion_detalle_id')->unique();
    DB::table('bylopez_cc_notificaciones')->whereIn('prioridad_operacion_id', $ops)->delete();
    DB::table('bylopez_cc_prioridad_cambios')->whereIn('operacion_id', $ops)->delete();
    DB::table('bylopez_cc_prioridad_operaciones')->whereIn('id', $ops)->delete();
    // La demo nacio toda en Normal: los detalles tocados por el recorrido vuelven a Normal.
    DB::table('bylopez_cc_ordenes_confeccion_detalles')->whereIn('id', $detalles)->update(['cantidad_urgente' => 0, 'cantidad_alta' => 0]);
    // Disponible fijado con «stock», minimos y reportes del recorrido.
    foreach ($d['stock'] ?? [] as $productId => $original) {
        $original === null
            ? DB::table('product_inventory_indices')->where('product_id', $productId)->where('channel_id', core()->getDefaultChannel()->id)->delete()
            : DB::table('product_inventory_indices')->where('product_id', $productId)->where('channel_id', core()->getDefaultChannel()->id)->update(['qty' => $original]);
    }
    DB::table('bylopez_cc_stock_minimos')->where('actualizado_por', $d['admin_id'])->delete();
    DB::table('bylopez_cc_stock_minimo_cambios')->where('admin_id', $d['admin_id'])->delete();
    $retornos = DB::table('bylopez_cc_ordenes_confeccion_retornos')->where('reportado_por', $d['satelite_id'])->get(['id', 'orden_confeccion_id']);
    DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->whereIn('retorno_id', $retornos->pluck('id'))->delete();
    DB::table('bylopez_cc_ordenes_confeccion_retornos')->whereIn('id', $retornos->pluck('id'))->delete();
    foreach ($retornos->pluck('orden_confeccion_id')->unique() as $ordenId) {
        app(OrdenConfeccionService::class)->recalcularEstado(OrdenConfeccion::query()->findOrFail($ordenId));
    }
    DB::table('bylopez_cc_talleres')->where('id', $d['taller_id'])->update(['usuario_id' => $d['usuario_original']]);
    DB::table('admins')->whereIn('id', [$d['admin_id'], $d['satelite_id']])->delete();
    DB::table('roles')->where('id', $d['rol_id'])->delete();
    unlink($archivo);
    echo 'restaurado operaciones='.count($ops)."\n";
} elseif ($accion === 'stock') {
    // stock <product_id> <cantidad|null>: fija el disponible (indice de Bagisto) en la demo; guarda el original una vez.
    $d = json_decode((string) @file_get_contents($archivo), true) ?: exit("Primero «preparar».\n");
    $productId = (int) ($argv[2] ?? 0);
    $canal = core()->getDefaultChannel()->id;
    $actual = DB::table('product_inventory_indices')->where('product_id', $productId)->where('channel_id', $canal)->value('qty');
    $d['stock'] ??= [];
    $d['stock'][$productId] = array_key_exists($productId, $d['stock']) ? $d['stock'][$productId] : $actual;
    file_put_contents($archivo, json_encode($d));
    ($argv[3] ?? 'null') === 'null'
        ? DB::table('product_inventory_indices')->where('product_id', $productId)->where('channel_id', $canal)->delete()
        : DB::table('product_inventory_indices')->updateOrInsert(['product_id' => $productId, 'channel_id' => $canal], ['qty' => (int) $argv[3], 'created_at' => now(), 'updated_at' => now()]);
    echo "stock $productId=".($argv[3] ?? 'null')."\n";
} elseif ($accion === 'cambiar') {
    // cambiar <detalle_id> <cantidad> <desde> <hacia>: el administrador del recorrido cambia una prioridad «desde otra pantalla».
    $d = json_decode((string) @file_get_contents($archivo), true) ?: exit("Primero «preparar».\n");
    Auth::guard('admin')->setUser(Admin::query()->findOrFail($d['admin_id']));
    app(PrioridadConfeccionService::class)->cambiar(['idempotency_key' => 'demo-'.uniqid(), 'cambios' => [[
        'detalle_id' => (int) $argv[2], 'cantidad' => (int) $argv[3], 'desde' => $argv[4], 'hacia' => $argv[5],
    ]]]);
    echo "cambiado\n";
} else {
    echo json_encode([
        'preparado' => json_decode((string) @file_get_contents($archivo), true),
        'operaciones' => DB::table('bylopez_cc_prioridad_operaciones')->get(),
        'cambios' => DB::table('bylopez_cc_prioridad_cambios')->get(),
        'notificaciones' => DB::table('bylopez_cc_notificaciones')->get(['id', 'admin_id', 'prioridad_operacion_id', 'leida_en']),
    ], JSON_UNESCAPED_UNICODE), "\n";
}
