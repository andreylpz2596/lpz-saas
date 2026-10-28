<?php

/*
| DEMOSTRACION 2026-10-27 — prepara datos FICTICIOS en una base AISLADA (nombre `lpz_saas_demo_*`). Reproducible: crear la base
| (estructura + catalogo, sin pedidos ni clientes) y correr este script. Nunca corre contra otra base.
|
|   DB_DATABASE=lpz_saas_demo_20261027 DEMO_PASSWORD=... php packages/ByLopez/VentasAsesor/tests/Demo/preparar_demo.php
|
| Que crea (todo marcado «DEMO»):
|  - Usuarios: DEMO Administracion (rol Administrador), DEMO Asesora y DEMO Asesora Umbral (rol ASESOR VENTAS + ficha) y DEMO Bodega (rol
|    BODEGA). La clave sale de DEMO_PASSWORD (no se guarda en ningun archivo versionado). Los usuarios reales copiados quedan
|    INACTIVOS solo en esta base.
|  - Clientes DEMO con telefono y direccion en Bogota (municipio 11001, zona local).
|  - Tres productos del catalogo copiado renombrados «DEMO · ...» con precio 10.000 y costo 6.000 por unidad (valores
|    ficticios), sin precios por grupo, y stock de prueba.
|  - Acuerdo de comision FICTICIO para la DEMO Asesora: 10 % y umbral mensual de 1.000 unidades (no toca acuerdos reales: esta
|    base no tiene ninguno).
|  - Fixture del umbral: UN pedido real (servicios del sistema, no SQL) de 998 unidades de «DEMO · Fixture umbral», confirmado
|    para la DEMO Asesora. Asi el Recorrido A cruza el umbral de 1.000. No se despacha.
| Correos: MAIL_MAILER=log. Sin integraciones de pago reales (transferencia/contra entrega son metodos offline).
*/

use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Services\Venta\EntregaBorradorService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Helpers\Indexers\Price;
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

if (config('mail.default') !== 'log' || config('queue.default') !== 'sync') {
    fwrite(STDERR, "Bloqueado: la demo exige MAIL_MAILER=log y QUEUE_CONNECTION=sync.\n");
    exit(1);
}

$clave = (string) getenv('DEMO_PASSWORD');

if (strlen($clave) < 10) {
    fwrite(STDERR, "Falta DEMO_PASSWORD (10+ caracteres).\n");
    exit(1);
}

// Re-ejecutable: cada paso reutiliza lo que ya exista (por email, nombre o marca DEMO) y no lo duplica.
$log = fn (string $m) => fwrite(STDOUT, $m."\n");

// ---------------------------------------------------------------- usuarios
DB::table('admins')->where('email', 'not like', 'demo.%@bylopez.test')->update(['status' => 0]);

$usuario = function (string $email, string $nombre, string $rol) use ($clave): Admin {
    $rolId = (int) DB::table('roles')->where('name', $rol)->orderBy('id')->value('id');

    return Admin::query()->updateOrCreate(['email' => $email], ['name' => $nombre, 'password' => Hash::make($clave), 'role_id' => $rolId, 'status' => 1]);
};

$admin = $usuario('demo.admin@bylopez.test', 'DEMO Administracion', 'Administrador');
$asesora = $usuario('demo.asesora@bylopez.test', 'DEMO Asesora', 'ASESOR VENTAS');
$bodega = $usuario('demo.bodega@bylopez.test', 'DEMO Bodega', 'BODEGA');
// Asesora EXCLUSIVA del ejemplo del umbral (998 + 5): ninguna otra venta de la demo mueve su acumulado.
$umbral = $usuario('demo.umbral@bylopez.test', 'DEMO Asesora Umbral', 'ASESOR VENTAS');
Asesor::query()->firstOrCreate(['admin_id' => $asesora->id], ['admin_id' => $asesora->id, 'nombre' => 'DEMO Asesora', 'telefono' => '3009990001', 'activo' => true, 'creado_por_admin_id' => $admin->id]);
Asesor::query()->firstOrCreate(['admin_id' => $umbral->id], ['admin_id' => $umbral->id, 'nombre' => 'DEMO Asesora Umbral', 'telefono' => '3009990002', 'activo' => true, 'creado_por_admin_id' => $admin->id]);
auth()->guard('admin')->setUser($admin);
$log("Usuarios: admin #{$admin->id}, asesora #{$asesora->id}, umbral #{$umbral->id}, bodega #{$bodega->id}");

// ---------------------------------------------------------------- productos DEMO
$productos = [
    215 => 'DEMO · Camiseta Caballero Blanco',
    425 => 'DEMO · Camiseta Dama Blanco',
    405 => 'DEMO · Fixture umbral',
];
$precio = 10000.0;
$costo = 6000.0;
$atributo = fn (string $codigo) => (int) DB::table('attributes')->where('code', $codigo)->value('id');

foreach ($productos as $id => $nombre) {
    $variantes = DB::table('products')->where('parent_id', $id)->pluck('id')->all();
    $primera = DB::table('product_flat')->where('product_id', $id)->value('name') !== $nombre;
    DB::table('product_flat')->where('product_id', $id)->update(['name' => $nombre]);
    DB::table('product_attribute_values')->where('product_id', $id)->where('attribute_id', $atributo('name'))->update(['text_value' => $nombre]);

    foreach ($variantes as $v) {
        DB::table('product_attribute_values')->where('product_id', $v)->where('attribute_id', $atributo('price'))->update(['float_value' => $precio]);
        DB::table('product_attribute_values')->where('product_id', $v)->where('attribute_id', $atributo('cost'))->update(['float_value' => $costo]);
        DB::table('product_flat')->where('product_id', $v)->update(['price' => $precio]);
        DB::table('product_customer_group_prices')->where('product_id', $v)->delete();
        // El stock se SIEMBRA solo la primera vez (producto aun sin renombrar): volver a correr el script no devuelve al inventario lo ya despachado.
        if ($primera) {
            DB::table('product_inventories')->updateOrInsert(['product_id' => $v, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => $id === 405 ? 1000 : 40]);
        }
    }

    $todos = Product::query()->whereIn('id', [$id, ...$variantes])->get();
    app(Price::class)->reindexBatch($todos);
    app(Inventory::class)->reindexBatch($todos);
}
$log('Productos DEMO: '.implode(', ', $productos).' (precio 10.000, costo 6.000)');

// ---------------------------------------------------------------- clientes DEMO
$grupo = (int) DB::table('customer_groups')->where('code', 'general')->value('id');
$clientes = [];

foreach ([['Ana', 'Demo Contraentrega', '3009990101'], ['Bruno', 'Demo Transferencia', '3009990102'], ['Carla', 'Demo Devolucion', '3009990103'], ['Fixture', 'Demo Umbral', '3009990199']] as [$n, $a, $t]) {
    $existente = DB::table('customers')->where('first_name', $n)->where('last_name', $a)->value('id');

    if ($existente !== null) {
        $clientes[$n] = Customer::query()->findOrFail($existente);

        continue;
    }

    $c = app(CrearClienteComercialService::class)->crear(new DatosClienteComercial($n, $a, $t, $grupo));
    app(GuardarDireccionClienteComercialService::class)->guardar((int) $c->id, new DireccionComercial(
        nombre: $n, apellido: $a, lineas: ['Calle 100 # 15-20 (DEMO)'], ciudad: 'Bogotá', departamento: 'DC', pais: 'co',
        codigoPostal: null, telefono: $t, predeterminada: true, municipioCodigo: '11001',
    ));
    $clientes[$n] = $c;
}
$log('Clientes DEMO: '.implode(', ', array_map(fn ($c) => '#'.$c->id.' '.$c->first_name.' '.$c->last_name, $clientes)));

// ---------------------------------------------------------------- acuerdo de comision FICTICIO
// La vigencia no puede empezar en el pasado (proteccion real: no cambia ventas hechas): empieza AHORA (null).
foreach ([$asesora, $umbral] as $a) {
    if (! DB::table('bylopez_va_comision_acuerdos')->where('asesor_admin_id', $a->id)->exists()) {
        app(AcuerdosComisionService::class)->programar($admin, (int) $a->id, [
            'porcentaje' => '10', 'umbral' => '1000', 'vigente_desde' => null, 'motivo' => 'DEMO: acuerdo ficticio para la presentacion',
        ]);
    }
}
// FIXTURE (dato de configuracion de la demo, no un estado financiero): el servicio impide por diseno las vigencias retroactivas, asi que
// un acuerdo programado «ahora» deja el mes con DOS tramos de umbral y las comisiones quedan bloqueadas por
// `politica_pendiente_umbral_cambio_mes` (politica de negocio aun sin aprobar; ver el guion). Para que el ejemplo 998 + 5 corra en un mes
// con UN solo umbral, el acuerdo ficticio se fecha desde el primer dia del mes comercial. Solo toca acuerdos marcados «DEMO:».
[$inicioMes] = ByLopez\VentasAsesor\Support\Comisiones\MesComercial::rango(date('Y-m'));
DB::table('bylopez_va_comision_acuerdos')->whereIn('asesor_admin_id', [$asesora->id, $umbral->id])->where('motivo', 'like', 'DEMO:%')
    ->where('vigente_desde', '>', $inicioMes->toDateTimeString())->update(['vigente_desde' => $inicioMes->toDateTimeString()]);
$log('Acuerdo DEMO (fixture): 10 %, umbral 1.000 unidades/mes, vigente desde el inicio del mes comercial ('.$inicioMes->toDateString().')');

// ---------------------------------------------------------------- fixture del umbral: 998 unidades (pedido real)
$previo = DB::table('orders as o')->join('bylopez_va_order_attributions as at', 'at.order_id', '=', 'o.id')
    ->where('o.customer_id', $clientes['Fixture']->id)->where('at.advisor_id', $umbral->id)->value('o.id');

if ($previo !== null) {
    $log("Fixture umbral: ya existe el pedido #{$previo}.");
    exit(0);
}

$fixture = Product::query()->findOrFail(405);
$variante = $fixture->variants()->orderBy('id')->first();
$b = app(BorradorVentaService::class)->crear((int) $clientes['Fixture']->id, $umbral, CanalVenta::WHATSAPP);
app(SolicitudBorradorService::class)->crear($b->id, $fixture->id, $variante->id, 998, $umbral);
app(LineasBorradorService::class)->agregar($b->id, $fixture->id, $variante->id, 998, $umbral);
$entregas = app(EntregaBorradorService::class);
$entregas->establecerFacturacion($b->id, OrigenDireccion::puntual(new DireccionComercial(nombre: 'Fixture', apellido: 'Demo Umbral', lineas: ['Calle 100 # 15-20 (DEMO)'], ciudad: 'Bogotá', departamento: 'DC', pais: 'co', codigoPostal: null, telefono: '3009990199')), $umbral);
$entregas->establecerEnvio($b->id, OrigenDireccion::puntoRecogida(), $umbral);
$entregas->seleccionarMetodo($b->id, PuntosRecogida::METODO, $umbral);
app(PagoBorradorService::class)->seleccionar($b->id, 'moneytransfer', $umbral);
$total = round((float) CartModel::query()->whereKey($b->fresh()->cart_id)->value('grand_total'), 2);
$r = app(ConfirmarVentaService::class)->confirmar($b->id, $umbral, $total);
$log("Fixture umbral: pedido #{$r->orderId} de 998 unidades (total {$total}), confirmado para la DEMO Asesora Umbral; queda sin despachar.");

$log('Listo. Ver el guion en VentasAsesor/docs/26_JORNADA_F3_F6_DEMO.md');
