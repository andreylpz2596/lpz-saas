<?php

/*
| TESTING POR ASESORA (2026-11-04) — prepara en una base `lpz_saas_demo_*` TRES asesoras FICTICIAS con acuerdos de comision distintos
| (para ver el umbral en cada caso), clientes propios y stock suficiente. Se corre DESPUES de preparar_demo.php. Idempotente.
|
|   bash packages/ByLopez/VentasAsesor/tests/Demo/demo.sh php packages/ByLopez/VentasAsesor/tests/Demo/preparar_asesores.php
|
|   DEMO Karen  10 % · umbral 6 unidades/mes     DEMO Wendy   8 % · umbral 10 unidades/mes     DEMO Laura  12 % · umbral 4 unidades/mes
|
| Nunca corre contra otra base. Precio 10.000 y costo 6.000 por unidad (ficticios, los deja preparar_demo.php).
*/

use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: solo corre en una base lpz_saas_demo_* (conectada: {$base}).\n");
    exit(1);
}

$clave = (string) getenv('DEMO_PASSWORD');

if (strlen($clave) < 10) {
    fwrite(STDERR, "Falta DEMO_PASSWORD (10+ caracteres).\n");
    exit(1);
}

$admin = Admin::query()->where('email', 'demo.admin@bylopez.test')->firstOrFail();
auth()->guard('admin')->setUser($admin);
$rolId = (int) DB::table('roles')->where('name', 'ASESOR VENTAS')->orderBy('id')->value('id');
$grupo = (int) DB::table('customer_groups')->where('code', 'general')->value('id');
[$inicioMes] = MesComercial::rango(date('Y-m'));

$asesoras = [
    ['demo.karen', 'DEMO Karen', '3009990011', '10', '6', 'Karen'],
    ['demo.wendy', 'DEMO Wendy', '3009990012', '8', '10', 'Wendy'],
    ['demo.laura', 'DEMO Laura', '3009990013', '12', '4', 'Laura'],
];

foreach ($asesoras as [$usuario, $nombre, $telefono, $porcentaje, $umbral, $marca]) {
    $a = Admin::query()->updateOrCreate(['email' => $usuario.'@bylopez.test'], ['name' => $nombre, 'password' => Hash::make($clave), 'role_id' => $rolId, 'status' => 1]);
    Asesor::query()->firstOrCreate(['admin_id' => $a->id], ['admin_id' => $a->id, 'nombre' => $nombre, 'telefono' => $telefono, 'activo' => true, 'creado_por_admin_id' => $admin->id]);

    if (! DB::table('bylopez_va_comision_acuerdos')->where('asesor_admin_id', $a->id)->exists()) {
        app(AcuerdosComisionService::class)->programar($admin, (int) $a->id, ['porcentaje' => $porcentaje, 'umbral' => $umbral, 'vigente_desde' => null, 'motivo' => 'DEMO: acuerdo ficticio del testing por asesora']);
        // Un solo tramo de umbral en el mes (el servicio no permite vigencias retroactivas): se fecha desde el inicio del mes comercial, solo acuerdos «DEMO:».
        DB::table('bylopez_va_comision_acuerdos')->where('asesor_admin_id', $a->id)->where('motivo', 'like', 'DEMO:%')->update(['vigente_desde' => $inicioMes->toDateTimeString()]);
    }

    // Seis clientes por asesora, con direccion en Bogota (zona local); los envios nacionales usan direccion puntual en cada venta.
    foreach (range(1, 6) as $n) {
        $apellido = "Cliente {$n}";

        if (DB::table('customers')->where('first_name', $marca)->where('last_name', $apellido)->exists()) {
            continue;
        }

        $tel = '30099'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $c = app(CrearClienteComercialService::class)->crear(new DatosClienteComercial($marca, $apellido, $tel, $grupo));
        app(GuardarDireccionClienteComercialService::class)->guardar((int) $c->id, new DireccionComercial(
            nombre: $marca, apellido: $apellido, lineas: ['Calle 100 # 15-20 (DEMO)'], ciudad: 'Bogotá', departamento: 'DC', pais: 'co',
            codigoPostal: null, telefono: $tel, predeterminada: true, municipioCodigo: '11001',
        ));
    }

    echo "Asesora {$nombre}: {$porcentaje} % · umbral {$umbral} (admin #{$a->id})\n";
}

// Stock holgado en las variantes de los dos productos de venta (solo esta base demo).
foreach ([215, 425] as $id) {
    $variantes = DB::table('products')->where('parent_id', $id)->pluck('id')->all();

    foreach ($variantes as $v) {
        DB::table('product_inventories')->updateOrInsert(['product_id' => $v, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => 300]);
    }

    app(Inventory::class)->reindexBatch(Product::query()->whereIn('id', [$id, ...$variantes])->get());
}

echo "Stock 300 por variante en los productos 215 y 425.\nListo.\n";
