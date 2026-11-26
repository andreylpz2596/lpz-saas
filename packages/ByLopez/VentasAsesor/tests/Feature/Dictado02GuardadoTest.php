<?php

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Product\Models\Product;

require_once __DIR__.'/../helpersFase03b.php';
require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| DIC-02 — Doc. 34 · Guardado seguro del dictado (jornada 2026-11-26). Por HTTP real (controlador, ACL, servicio):
|  - todo el dictado es UNA operacion: o se agrega completo o no se agrega nada (no solo por producto);
|  - el servidor suma sobre lo que HAY en la venta (leido con el borrador bloqueado), no sobre lo que la pantalla creia;
|  - la misma `operacion` no suma dos veces (doble clic, respuesta perdida); otra operacion si (solicitud intencional);
|  - cada linea se vuelve a resolver con el catalogo: no se confia en un SKU oculto de la pantalla.
| La concurrencia con procesos reales esta en tests/Concurrencia/DictadoConcurrenteTest.php.
*/

function dic02_opcion(string $texto): int
{
    $aid = DB::table('attributes')->where('code', 'color')->value('id');
    $id = DB::table('attribute_options')->where('attribute_id', $aid)->where('admin_name', $texto)->value('id');

    return (int) ($id ?: DB::table('attribute_options')->insertGetId(['attribute_id' => $aid, 'admin_name' => $texto, 'sort_order' => 999]));
}

/** Configurable con SKU del patron y stock por variante. @return array<string, int> talla → id de variante (+ 'padre') */
function dic02_producto(string $base, array $tallasStock, string $nombre): array
{
    $padre = f03b_configurable(10000.0);
    DB::table('products')->where('id', $padre->id)->update(['sku' => $base]);
    DB::table('product_flat')->where('product_id', $padre->id)->update(['name' => $nombre, 'status' => 1]);
    $ids = ['padre' => (int) $padre->id];
    $tallas = array_keys($tallasStock);

    foreach ($padre->variants()->orderBy('id')->get()->values() as $i => $variante) {
        if (! isset($tallas[$i])) {
            break;
        }
        DB::table('products')->where('id', $variante->id)->update(['sku' => $base.'-'.$tallas[$i]]);
        f03b_stock(Product::find($variante->id), $tallasStock[$tallas[$i]]);
        $ids[$tallas[$i]] = (int) $variante->id;
    }

    return $ids;
}

function dic02_cantidades(int $borradorId): array
{
    $cartId = BorradorVenta::query()->whereKey($borradorId)->value('cart_id');

    return DB::table('cart_items')->where('cart_id', $cartId)->whereNotNull('parent_id')->pluck('quantity', 'product_id')
        ->map(fn ($q) => (int) $q)->all()
        ?: DB::table('cart_items')->where('cart_id', $cartId)->pluck('quantity', 'product_id')->map(fn ($q) => (int) $q)->all();
}

/** Cantidad en la venta de una variante (lo que ve el resumen: la linea del configurable por su variante). */
function dic02_en_venta(int $borradorId, int $varianteId): int
{
    $cartId = BorradorVenta::query()->whereKey($borradorId)->value('cart_id');
    $padreItem = DB::table('cart_items as hijo')->join('cart_items as padre', 'padre.id', '=', 'hijo.parent_id')
        ->where('hijo.cart_id', $cartId)->where('hijo.product_id', $varianteId)->value('padre.quantity');

    return (int) ($padreItem ?? DB::table('cart_items')->where('cart_id', $cartId)->where('product_id', $varianteId)->value('quantity') ?? 0);
}

function dic02_linea(string $color, ?string $genero, string $talla, int $cantidad, string $linea = 'DZ'): array
{
    return ['color' => $color, 'genero' => $genero, 'linea' => $linea, 'talla' => $talla, 'cantidad' => $cantidad];
}

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    DB::table(CodigoSkuService::TABLA_COLORES)->delete();
    foreach (['Rojo' => 920, 'Negro' => 921] as $color => $codigo) {
        DB::table(CodigoSkuService::TABLA_COLORES)->insert(['attribute_option_id' => dic02_opcion($color), 'codigo' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->rojoDama = dic02_producto('920D-DZ', ['S' => 50, 'M' => 50], 'Camiseta Piel Durazno Rojo Mujer');
    $this->negroCaballero = dic02_producto('921H-DZ', ['M' => 50, 'L' => 3], 'Camiseta Piel Durazno Negro Hombre');
    $this->cliente = f03b_cliente(f03b_grupo('D2'));
    $this->asesor = f03b_admin();
    $this->url = route('admin.ventas_asesor.nueva_venta.dictado.agregar');
});

test('DIC02-1 sin venta todavia: el dictado completo crea la venta con todas sus lineas en una operacion', function () {
    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-1-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 2), dic02_linea('Negro', 'H', 'M', 4)],
    ])->assertOk()->json();

    expect($r['repetida'])->toBeFalse()
        ->and(dic02_en_venta($r['borrador_id'], $this->rojoDama['M']))->toBe(2)
        ->and(dic02_en_venta($r['borrador_id'], $this->negroCaballero['M']))->toBe(4)
        ->and(array_column($r['resumen'], 'sku'))->toBe(['920D-DZ-M', '921H-DZ-M'])
        ->and(DB::table('bylopez_va_dictado_operaciones')->where('admin_id', $this->asesor->id)->value('estado'))->toBe('aplicada');
});

test('DIC02-2 ya hay 3 + dos lineas del mismo SKU (2 y 1) = 6; el resumen dice antes, agregado y despues', function () {
    $r1 = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-2a-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'S', 3)],
    ])->assertOk()->json();

    $r2 = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-2b-'.uniqid(), 'borrador_id' => $r1['borrador_id'],
        'lineas' => [dic02_linea('Rojo', 'D', 'S', 2), dic02_linea('Rojo', 'D', 'S', 1)],
    ])->assertOk()->json();

    expect(dic02_en_venta($r1['borrador_id'], $this->rojoDama['S']))->toBe(6)
        ->and($r2['resumen'])->toHaveCount(1)
        ->and([$r2['resumen'][0]['antes'], $r2['resumen'][0]['agregado'], $r2['resumen'][0]['despues']])->toBe([3, 3, 6]);
});

test('DIC02-3 la misma operacion dos veces (doble clic o respuesta perdida) no vuelve a sumar; otra operacion si', function () {
    $base = ['customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(), 'lineas' => [dic02_linea('Negro', 'H', 'M', 5)]];
    $op = 'op-dic02-3-'.uniqid();

    $primera = $this->actingAs($this->asesor, 'admin')->postJson($this->url, ['operacion' => $op] + $base)->assertOk()->json();
    $segunda = $this->actingAs($this->asesor, 'admin')->postJson($this->url, ['operacion' => $op] + $base)->assertOk()->json();

    expect($segunda['repetida'])->toBeTrue()
        ->and($segunda['borrador_id'])->toBe($primera['borrador_id'])
        ->and($segunda['resumen'])->toBe($primera['resumen'])
        ->and(dic02_en_venta($primera['borrador_id'], $this->negroCaballero['M']))->toBe(5);

    // Una solicitud nueva e intencional del mismo SKU (otro identificador) si suma.
    $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-3b-'.uniqid(), 'borrador_id' => $primera['borrador_id'], 'lineas' => [dic02_linea('Negro', 'H', 'M', 5)],
    ])->assertOk();
    expect(dic02_en_venta($primera['borrador_id'], $this->negroCaballero['M']))->toBe(10);
});

test('DIC02-4 todo o nada para TODO el dictado: sin stock en una linea no se agrega ninguna (ni la de otro producto)', function () {
    $r1 = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-4a-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)],
    ])->assertOk()->json();

    $error = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-4b-'.uniqid(), 'borrador_id' => $r1['borrador_id'],
        // Rojo M (hay stock) + Negro L x9 (solo hay 3): se rechaza todo.
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 4), dic02_linea('Negro', 'H', 'L', 9)],
    ])->assertStatus(422)->json();

    expect($error['codigo'])->toBe('lote_rechazado')
        ->and($error['variante_id'])->toBe($this->negroCaballero['L'])
        ->and(dic02_en_venta($r1['borrador_id'], $this->rojoDama['M']))->toBe(1)
        ->and(dic02_en_venta($r1['borrador_id'], $this->negroCaballero['L']))->toBe(0)
        // La operacion fallida no queda registrada: se puede reintentar (cambiando lo que falló).
        ->and(DB::table('bylopez_va_dictado_operaciones')->where('operacion', 'like', 'op-dic02-4b-%')->count())->toBe(0);
});

test('DIC02-5 el servidor suma sobre lo que HAY en la venta (no sobre lo que la pantalla creia)', function () {
    $r1 = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-5a-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'S', 2)],
    ])->assertOk()->json();

    // Otra pestaña cambia la talla a 5 con la matriz (cantidad final) despues de que la pantalla del dictado leyo «2».
    $this->actingAs($this->asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $r1['borrador_id']]), [
        'producto_id' => $this->rojoDama['padre'], 'cantidades' => [$this->rojoDama['S'] => 5],
    ])->assertOk();

    $r2 = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-5b-'.uniqid(), 'borrador_id' => $r1['borrador_id'], 'lineas' => [dic02_linea('Rojo', 'D', 'S', 1)],
    ])->assertOk()->json();

    expect(dic02_en_venta($r1['borrador_id'], $this->rojoDama['S']))->toBe(6)
        ->and([$r2['resumen'][0]['antes'], $r2['resumen'][0]['despues']])->toBe([5, 6]);
});

test('DIC02-6 cada linea se resuelve otra vez con el catalogo: una variante que no existe rechaza todo el dictado', function () {
    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-6-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 2), dic02_linea('Rojo', 'D', 'XL', 1)],
    ])->assertStatus(422)->json();

    expect($r['codigo'])->toBe('dictado_invalido')
        ->and($r['errores'][0]['indice'])->toBe(1)
        ->and($r['errores'][0]['tipo_error'])->toBe('catalogo')
        ->and(DB::table('bylopez_va_borradores_venta')->where('propietario_admin_id', $this->asesor->id)->count())->toBe(0);
});

test('DIC02-7 permisos y contrato: sin permiso 401, venta ajena 409, sin operacion o sin tipo 422', function () {
    $sinPermiso = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.pedidos']);
    $this->actingAs($sinPermiso, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-7-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'r', 'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)],
    ])->assertUnauthorized();

    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-7a-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)],
    ])->assertOk()->json();

    $otro = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $ajeno = $this->actingAs($otro, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-7b-'.uniqid(), 'borrador_id' => $r['borrador_id'], 'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)],
    ])->assertStatus(409)->json();
    expect($ajeno['causa'] ?? $ajeno['codigo'])->toContain('propietario')
        ->and(dic02_en_venta($r['borrador_id'], $this->rojoDama['M']))->toBe(1);

    $this->actingAs($this->asesor, 'admin')->postJson($this->url, ['borrador_id' => $r['borrador_id'], 'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)]])
        ->assertUnprocessable()->assertJsonValidationErrors('operacion');
    $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-7c-'.uniqid(), 'borrador_id' => $r['borrador_id'], 'lineas' => [dic02_linea('Rojo', null, 'M', 1)],
    ])->assertUnprocessable()->assertJsonValidationErrors('lineas.0.genero');
    $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-7d-'.uniqid(), 'borrador_id' => $r['borrador_id'], 'lineas' => [dic02_linea('Rojo', 'D', 'M', 0)],
    ])->assertUnprocessable()->assertJsonValidationErrors('lineas.0.cantidad');
});

test('DIC02-8 interpretar y corregir nunca escriben en la venta', function () {
    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->url, [
        'operacion' => 'op-dic02-8-'.uniqid(), 'customer_id' => $this->cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-'.uniqid(),
        'lineas' => [dic02_linea('Rojo', 'D', 'M', 1)],
    ])->assertOk()->json();
    $antes = DB::table('cart_items')->count();
    $operaciones = DB::table('bylopez_va_dictado_operaciones')->count();

    $this->actingAs($this->asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.interpretar'), ['texto' => 'roja dama M 5, negro caballero M 2'])->assertOk();
    $this->actingAs($this->asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.dictado.corregir'), dic02_linea('Rojo', 'D', 'S', 3))->assertOk();

    expect(DB::table('cart_items')->count())->toBe($antes)
        ->and(DB::table('bylopez_va_dictado_operaciones')->count())->toBe($operaciones)
        ->and(dic02_en_venta($r['borrador_id'], $this->rojoDama['M']))->toBe(1);
});
