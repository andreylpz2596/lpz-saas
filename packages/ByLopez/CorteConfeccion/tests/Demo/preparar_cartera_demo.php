<?php

/*
| Demo de la CARTERA en la base DEMO aislada: el ejemplo de Jairo del plan (600 buenas recibidas + 100 pendientes de recibir,
| a $ 1.450), con un abono de $ 70.000 aplicado, un prestamo de $ 1.500.000 y un abono consolidado de 15 transferencias.
| Deja ademas un usuario prestador (Jairo, solo consulta) y uno de contabilidad.
|
| Uso:  DB_DATABASE=lpz_saas_demo_xxx DEMO_CC_PASSWORD=... php packages/ByLopez/CorteConfeccion/tests/Demo/preparar_cartera_demo.php
| Falla si la base no empieza por lpz_saas_demo_ o coincide con la del .env compartido. NO toca datos reales.
*/

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

require $raiz.'/packages/ByLopez/CorteConfeccion/tests/Support/QaCartera.php';

DB::beginTransaction();

try {
    $usuario = fn (string $email, string $nombre, Role $rol) => Admin::query()->updateOrCreate(['email' => $email], ['name' => $nombre, 'password' => Hash::make($clave), 'status' => 1, 'role_id' => $rol->id]);

    $rolTaller = Role::query()->where('name', 'TALLER CONFECCION')->firstOrFail();
    $rolTaller->update(['permissions' => array_values(array_unique(array_merge((array) $rolTaller->permissions, ['corte_confeccion', 'corte_confeccion.mi_cuenta'])))]);

    $rolConta = Role::query()->updateOrCreate(['name' => 'CONTABILIDAD DEMO'], ['description' => 'Cartera', 'permission_type' => 'custom', 'permissions' => [
        'corte_confeccion', 'corte_confeccion.cartera', 'corte_confeccion.cartera.registrar', 'corte_confeccion.cartera.prestamos', 'corte_confeccion.cartera.corte',
        'corte_confeccion.cartera.reversar', 'corte_confeccion.talleres', 'corte_confeccion.ordenes_confeccion',
    ]]);

    $admin = $usuario('cc.admin@bylopez.test', 'CC Admin Demo', Role::query()->where('permission_type', 'all')->firstOrFail());
    $usuario('cc.conta@bylopez.test', 'CC Contabilidad Demo', $rolConta);
    $usuarioJairo = $usuario('cc.jairo@bylopez.test', 'Jairo Demo', $rolTaller);

    Auth::guard('admin')->setUser($admin);

    $e = cc_escenario();
    $e['jairo']->update(['nombre' => 'Taller Jairo (cartera)', 'usuario_id' => $usuarioJairo->id]);

    cc_mov($e['jairo'], 'abono', 70000, ['modo_detalle' => 'total', 'num_transferencias' => 15, 'observacion' => 'Consolidado de la semana', 'aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);
    cc_mov($e['jairo'], 'prestamo', 1500000, ['observacion' => 'Préstamo personal', 'referencia' => 'Nequi 88412']);

    DB::commit();
} catch (Throwable $err) {
    DB::rollBack();
    fwrite(STDERR, 'ERROR: '.$err->getMessage()."\n".$err->getTraceAsString()."\n");
    exit(1);
}

echo json_encode(['base' => $base, 'taller' => $e['jairo']->id, 'orden_a' => $e['ordA']->id, 'orden_b' => $e['ordB']->id, 'usuarios' => ['admin' => 'cc.admin@bylopez.test', 'conta' => 'cc.conta@bylopez.test', 'jairo' => 'cc.jairo@bylopez.test']], JSON_UNESCAPED_UNICODE), "\n";
