<?php

/*
| Prepara la DEMO aislada de Corte y Confeccion (plan de validacion, secciones 4 y 6): escenario maestro de 1.000 aptas
| (S 300 / M 400 / L 300), Jairo 700 y Andrea 300, creado por los SERVICIOS reales (proveedor -> compra -> corte -> reporte ->
| recepcion -> distribucion). Ademas deja un segundo corte con una reasignacion aplicada y otra en transito para ver el historial.
|
| Uso (desde la raiz del proyecto):
|   DB_DATABASE=lpz_saas_demo_xxx DEMO_CC_PASSWORD=... php packages/ByLopez/CorteConfeccion/tests/Demo/preparar_corte_demo.php
| Falla si la base no empieza por lpz_saas_demo_ o coincide con la del .env compartido. La clave sale del entorno; no se imprime.
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
    // Usuarios de la demo: admin (todo), bodega (ACL de reasignar anadida a su rol, como lo haria Settings > Roles).
    $rolBodega = Role::query()->where('name', 'BODEGA')->first();
    $permisosBodega = array_values(array_unique(array_merge((array) $rolBodega->permissions, ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.create', 'corte_confeccion.ordenes_confeccion.recibir_bodega', 'corte_confeccion.ordenes_confeccion.reasignar', 'corte_confeccion.talleres', 'corte_confeccion.ordenes_corte', 'corte_confeccion.rollos', 'corte_confeccion.compras_tela', 'corte_confeccion.proveedores_tela', 'corte_confeccion.inventario_movimientos'])));
    $rolBodega->update(['permissions' => $permisosBodega, 'permission_type' => 'custom']);

    $usuario = function (string $email, string $nombre, Role $rol) use ($clave) {
        return Admin::query()->updateOrCreate(['email' => $email], ['name' => $nombre, 'password' => Hash::make($clave), 'status' => 1, 'role_id' => $rol->id]);
    };

    $admin = $usuario('cc.admin@bylopez.test', 'CC Admin Demo', Role::query()->where('permission_type', 'all')->firstOrFail());
    $bodega = $usuario('cc.bodega@bylopez.test', 'CC Bodega Demo', $rolBodega);
    $rolTaller = Role::query()->where('name', 'TALLER CONFECCION')->firstOrFail();
    $usuarioJairo = $usuario('cc.jairo@bylopez.test', 'Jairo Demo', $rolTaller);
    $usuarioAndrea = $usuario('cc.andrea@bylopez.test', 'Andrea Demo', $rolTaller);

    Auth::guard('admin')->setUser($admin);

    // --- Corte 1: escenario maestro limpio (la reasignacion se hace en la pantalla) ---
    $e = qa_escenario_maestro();
    $e['jairo']->update(['nombre' => 'Taller Jairo (demo)', 'usuario_id' => $usuarioJairo->id]);
    $e['andrea']->update(['nombre' => 'Taller Andrea (demo)', 'usuario_id' => $usuarioAndrea->id]);

    // --- Corte 2: historial (una aplicada y una en transito) ---
    $e2 = qa_escenario_maestro();
    $e2['jairo']->update(['nombre' => 'Taller Jairo 2 (demo)', 'usuario_id' => $usuarioJairo->id]);
    $e2['andrea']->update(['nombre' => 'Taller Andrea 2 (demo)', 'usuario_id' => $usuarioAndrea->id]);
    Auth::guard('admin')->setUser($admin);
    $servicio = app(ReasignacionService::class);
    $servicio->reasignar(qa_payload_reasignar($e2['ordJ'], $e2['andrea'], [[qa_detalle($e2['ordJ'], $e2['M'])->id, 100]]));
    $servicio->reasignar(qa_payload_reasignar($e2['ordJ'], $e2['andrea'], [[qa_detalle($e2['ordJ'], $e2['S'])->id, 50]], ['situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito', 'motivo' => 'Traslado en camino a Andrea']));

    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n");
    exit(1);
}

echo json_encode([
    'base' => $base,
    'corte1' => $e['corte']->id,
    'orden_jairo' => $e['ordJ']->id,
    'orden_andrea' => $e['ordA']->id,
    'taller_jairo' => $e['jairo']->id,
    'taller_andrea' => $e['andrea']->id,
    'corte2' => $e2['corte']->id,
    'orden_jairo2' => $e2['ordJ']->id,
    'taller_jairo2' => $e2['jairo']->id,
    'usuarios' => ['admin' => 'cc.admin@bylopez.test', 'bodega' => 'cc.bodega@bylopez.test', 'jairo' => 'cc.jairo@bylopez.test', 'andrea' => 'cc.andrea@bylopez.test'],
], JSON_UNESCAPED_UNICODE), "\n";
