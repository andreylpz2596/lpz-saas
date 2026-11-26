<?php

/*
| Doc. 34 · Demo LOCAL del dictado (jornada 2026-11-26). Prepara una base AISLADA (copia de la base limpia de preproduccion,
| sin clientes) para probar el flujo en el navegador con autenticacion real.
|
| Uso (la base y el archivo de la clave van por variables/argumento; nada de credenciales en el repositorio):
|   DB_DATABASE=lpz_dictado_demo_AAAAMMDD php preparar_demo_dictado.php <archivo-fuera-del-repo-para-la-clave>
|
| Solo actua sobre bases que empiezan por «lpz_dictado_demo_». Deja:
|  - stock 20 en las variantes del codigo unico (124H-DZ-L con 2, para probar «stock insuficiente»; crop top 10);
|  - 124H-DZ-XXL (copia de la XL, como se creo en produccion) para ver XXL en los selectores;
|  - cliente «Cliente Demo Dictado», rol «QA Dictado Demo» (solo Nueva venta) y asesora «Asesora Demo Dictado» con perfil;
|  - la clave de la asesora, aleatoria, escrita SOLO en el archivo indicado.
*/

use ByLopez\Cargues\Extensiones\ProductosCamisetas\CreadorCamisetas;
use ByLopez\VentasAsesor\Models\Asesor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Webkul\Product\Helpers\Indexers\Inventory as IndexadorDeInventario;
use Webkul\Product\Models\Product;

$raiz = dirname(__DIR__, 6);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;
if (! str_starts_with($base, 'lpz_dictado_demo_')) {
    fwrite(STDERR, "Base {$base}: este script solo prepara bases lpz_dictado_demo_*\n");
    exit(1);
}
$archivoClave = $argv[1] ?? null;
if ($archivoClave === null || str_starts_with(realpath(dirname($archivoClave)) ?: '', realpath($raiz))) {
    fwrite(STDERR, "Indica un archivo FUERA del repositorio para guardar la clave de la asesora.\n");
    exit(1);
}

// 1. XXL de caballero (copia de la XL, igual que en produccion el 2026-11-23).
$xl = Product::query()->where('sku', '124H-DZ-XL')->first();
if ($xl && ! Product::query()->where('sku', '124H-DZ-XXL')->exists()) {
    $atributo = DB::table('attributes')->where('id', DB::table('product_super_attributes')->where('product_id', $xl->parent_id)
        ->whereNotIn('attribute_id', DB::table('attributes')->where('code', 'color')->pluck('id'))->value('attribute_id'))->first();
    $opcion = DB::table('attribute_options')->where('attribute_id', $atributo->id)->where('admin_name', 'XXL')->value('id')
        ?: DB::table('attribute_options')->insertGetId(['attribute_id' => $atributo->id, 'admin_name' => 'XXL', 'sort_order' => 99]);
    $color = DB::table('product_attribute_values')->where('product_id', $xl->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'color')->value('id'))->value('integer_value');
    $creador = app(CreadorCamisetas::class);
    $id = $creador->crearVariante((int) $xl->parent_id, (int) $xl->attribute_family_id, [
        'sku' => '124H-DZ-XXL', 'nombre' => 'Camiseta Piel Durazno Blanco Hombre XXL', 'precio' => 12000, 'costo' => 8500, 'peso' => 0.2,
        'activo' => 1, 'color' => $color, 'talla' => $opcion, 'eje_talla' => $atributo->code, 'descripcion_corta' => null, 'descripcion' => null,
        'atributos' => [], 'categorias' => $xl->categories()->pluck('id')->all(),
    ]);
    $creador->reindexar((int) $xl->parent_id);
    echo "XXL creada: variante {$id}\n";
}

// 2. Stock (inventario del origen por defecto + reindexado de Bagisto).
$origen = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first();
$variantes = Product::query()->whereNotNull('parent_id')->where('sku', 'regexp', '^[0-9]{3}[DHN]-(DZ|TP)-')->get();
foreach ($variantes as $v) {
    $qty = $v->sku === '124H-DZ-L' ? 2 : (str_contains($v->sku, '-TP-') ? 10 : 20);
    $v->inventories()->updateOrCreate(['inventory_source_id' => $origen->id, 'vendor_id' => 0], ['qty' => $qty]);
}
app(IndexadorDeInventario::class)->reindexBatch($variantes->map->fresh());
echo 'Stock en '.$variantes->count()." variantes\n";

// 3. Cliente, rol, asesora y su perfil.
$grupo = DB::table('customer_groups')->where('code', 'general')->value('id') ?: DB::table('customer_groups')->where('code', '!=', 'guest')->value('id');
$clienteId = DB::table('customers')->where('email', 'cliente.demo.dictado@demo.local')->value('id') ?: DB::table('customers')->insertGetId([
    'first_name' => 'Cliente Demo', 'last_name' => 'Dictado', 'email' => 'cliente.demo.dictado@demo.local', 'phone' => '+573000000126',
    'customer_group_id' => $grupo, 'channel_id' => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1,
    'password' => Hash::make(Str::random(40)), 'created_at' => now(), 'updated_at' => now(),
]);
$rolId = DB::table('roles')->where('name', 'QA Dictado Demo')->value('id') ?: DB::table('roles')->insertGetId([
    'name' => 'QA Dictado Demo', 'description' => 'Solo demo local del dictado (Doc. 34)', 'permission_type' => 'custom',
    'permissions' => json_encode(['ventas_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create', 'ventas_asesor.operar_como_asesor']),
    'created_at' => now(), 'updated_at' => now(),
]);
$clave = Str::password(20, symbols: false);
$adminId = DB::table('admins')->where('email', 'asesora.demo.dictado@demo.local')->value('id');
if ($adminId) {
    DB::table('admins')->where('id', $adminId)->update(['password' => Hash::make($clave), 'status' => 1, 'role_id' => $rolId]);
} else {
    $adminId = DB::table('admins')->insertGetId([
        'name' => 'Asesora Demo Dictado', 'email' => 'asesora.demo.dictado@demo.local', 'password' => Hash::make($clave), 'status' => 1,
        'role_id' => $rolId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
if (! Asesor::query()->where('admin_id', $adminId)->exists()) {
    Asesor::query()->create(['admin_id' => $adminId, 'nombre' => 'Asesora Demo Dictado', 'activo' => true]);
}

file_put_contents($archivoClave, "usuario=asesora.demo.dictado@demo.local\nclave={$clave}\ncliente_id={$clienteId}\ncliente_telefono=3000000126\n");
echo "Cliente {$clienteId}, asesora {$adminId}, rol {$rolId}. Clave escrita en el archivo indicado (fuera del repositorio).\n";
