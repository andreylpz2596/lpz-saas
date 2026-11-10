<?php

/*
| Caso de demo para Safari en iPhone (cierre de validacion 2026-10-31). Crea en la base DEMO aislada un escenario limpio
| (1.000 aptas, Jairo 700 / Andrea 300) con talleres rotulados «iPhone» y deja dos usuarios con clave nueva:
|   - cc.bodega@bylopez.test  (BODEGA con la ACL reasignar, como se asigna en Configuracion > Roles)
|   - cc.bodega.sin@bylopez.test (BODEGA SIN la ACL: el boton NO debe aparecer)
| Ademas deja una reasignacion EN TRANSITO con salida fisica, para probar «Cancelar traslado» con constancia.
|
| Uso:  DB_DATABASE=lpz_saas_demo_cc_20261029 DEMO_CC_PASSWORD=... php packages/ByLopez/CorteConfeccion/tests/Demo/preparar_demo_iphone.php
| Falla si la base no empieza por lpz_saas_demo_ o coincide con la del .env compartido.
*/

use ByLopez\CorteConfeccion\Services\ReasignacionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS base')->base;
$compartida = '';

foreach (file($raiz.'/.env') as $linea) {
    if (str_starts_with($linea, 'DB_DATABASE=')) {
        $compartida = trim(substr($linea, 12));
    }
}

if (! str_starts_with($base, 'lpz_saas_demo_') || $base === $compartida) {
    fwrite(STDERR, "BLOQUEADO: la base '$base' no es una base demo aislada.\n");
    exit(2);
}

$clave = getenv('DEMO_CC_PASSWORD');

if (! $clave) {
    fwrite(STDERR, "Falta DEMO_CC_PASSWORD.\n");
    exit(2);
}

if (! function_exists('cc_test_color_option')) {
    function cc_test_color_option(string $adminName = 'Blanco'): AttributeOption
    {
        $attribute = Attribute::firstWhere('code', 'color') ?? Attribute::factory()->create(['code' => 'color', 'type' => 'select', 'admin_name' => 'Color']);

        return AttributeOption::factory()->for($attribute, 'attribute')->create(['admin_name' => $adminName]);
    }
}

require $raiz.'/packages/ByLopez/CorteConfeccion/tests/Support/QaFlujo.php';

DB::beginTransaction();

try {
    $base_permisos = ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.create', 'corte_confeccion.ordenes_confeccion.operar', 'corte_confeccion.ordenes_confeccion.recibir_bodega', 'corte_confeccion.talleres', 'corte_confeccion.ordenes_corte', 'corte_confeccion.rollos', 'corte_confeccion.compras_tela', 'corte_confeccion.proveedores_tela', 'corte_confeccion.inventario_movimientos'];

    $rolCon = Role::query()->updateOrCreate(['name' => 'BODEGA'], ['description' => 'Bodega', 'permission_type' => 'custom', 'permissions' => array_merge($base_permisos, ['corte_confeccion.ordenes_confeccion.reasignar'])]);
    $rolSin = Role::query()->updateOrCreate(['name' => 'BODEGA SIN REASIGNAR'], ['description' => 'Bodega sin la ACL reasignar', 'permission_type' => 'custom', 'permissions' => $base_permisos]);

    $usuario = fn (string $email, string $nombre, Role $rol) => Admin::query()->updateOrCreate(['email' => $email], ['name' => $nombre, 'password' => Hash::make($clave), 'status' => 1, 'role_id' => $rol->id]);

    $bodega = $usuario('cc.bodega@bylopez.test', 'CC Bodega Demo', $rolCon);
    $usuario('cc.bodega.sin@bylopez.test', 'CC Bodega SIN ACL', $rolSin);
    $usuarioTaller = Role::query()->where('name', 'TALLER CONFECCION')->firstOrFail();
    $usuarioJairo = $usuario('cc.jairo@bylopez.test', 'Jairo Demo', $usuarioTaller);
    $usuarioAndrea = $usuario('cc.andrea@bylopez.test', 'Andrea Demo', $usuarioTaller);

    Auth::guard('admin')->setUser($bodega);

    $e = qa_escenario_maestro();
    $e['jairo']->update(['nombre' => 'Taller Jairo (iPhone)', 'usuario_id' => $usuarioJairo->id]);
    $e['andrea']->update(['nombre' => 'Taller Andrea (iPhone)', 'usuario_id' => $usuarioAndrea->id]);

    // Una reasignacion en transito con salida fisica (para «Cancelar traslado» con constancia de devolucion).
    $transito = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['S'])->id, 50]], ['situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito', 'motivo' => 'Traslado en camino a Andrea']));

    DB::commit();
} catch (Throwable $err) {
    DB::rollBack();
    fwrite(STDERR, 'ERROR: '.$err->getMessage()."\n");
    exit(1);
}

echo json_encode([
    'base' => $base,
    'orden_jairo' => $e['ordJ']->id,
    'orden_andrea' => $e['ordA']->id,
    'reasignacion_en_transito' => $transito->codigo,
    'url_ficha' => '/admin/corte-confeccion/ordenes-confeccion/view/'.$e['ordJ']->id,
    'usuarios' => ['con_acl' => 'cc.bodega@bylopez.test', 'sin_acl' => 'cc.bodega.sin@bylopez.test'],
], JSON_UNESCAPED_UNICODE), "\n";
