<?php

/*
| Demo: Administracion CONFIRMA el costo estandar de las referencias de venta de la demo (familias de los productos 215 y 425), por el servicio
| oficial de Rentabilidad. Sin este estado ninguna comision puede habilitarse (alerta «costo_sin_estado»). Solo corre en lpz_saas_demo_*.
*/

use ByLopez\VentasAsesor\Services\Rentabilidad\ConfiguracionRentabilidadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_starts_with((string) DB::selectOne('SELECT DATABASE() AS b')->b, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: solo en lpz_saas_demo_*.\n");
    exit(1);
}

$admin = Admin::query()->where('email', 'demo.admin@bylopez.test')->firstOrFail();
auth()->guard('admin')->setUser($admin);

foreach (DB::table('bylopez_va_comision_lineas')->whereIn('order_item_id', DB::table('order_items')->whereIn('product_id', [215, 425])->pluck('id'))->distinct()->pluck('familia_id') as $familia) {
    if (DB::table('bylopez_va_costo_estados')->where('familia_id', $familia)->whereNull('vigente_hasta')->exists()) {
        echo "Familia {$familia}: ya tiene estado.\n";

        continue;
    }

    app(ConfiguracionRentabilidadService::class)->programarEstadoCosto($admin, ['familia_id' => $familia, 'estado' => 'confirmado', 'revisar_hasta' => date('Y-12-31'), 'vigente_desde' => null, 'motivo' => 'DEMO: costo estandar confirmado por Administracion']);
    echo "Familia {$familia}: costo confirmado.\n";
}
