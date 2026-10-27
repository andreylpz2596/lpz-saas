<?php

use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryTranslation;
use Webkul\Product\Models\Product;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| Nueva venta · disponibilidad por PRODUCTO (linea x color) y por talla. Una combinacion sin disponibilidad (sin stock o
| inactiva) se excluye con su motivo y NUNCA impide que otra combinacion valida llegue a la modal. Los colores anticipan la
| misma regla (variante activa y con stock). Solo lpz_saas_testing.
*/

beforeEach(function () {
    $this->asesor = f03b_admin();
    $this->cliente = f03b_cliente(f03b_grupo('G'));
    $this->catalogo = app(CatalogoVentaService::class);
});

function f03gbd_categoria(string $slug, string $nombre): Category
{
    $categoria = Category::factory()->create(['parent_id' => 1, 'status' => 1]);
    CategoryTranslation::query()->where('category_id', $categoria->id)->delete();
    CategoryTranslation::factory()->create([
        'category_id' => $categoria->id, 'slug' => $slug, 'name' => $nombre,
        'locale' => 'es', 'locale_id' => core()->getAllLocales()->firstWhere('code', 'es')?->id ?? 1,
    ]);

    return $categoria->fresh();
}

/** Producto (linea x color) con stock `$stock` en TODAS sus tallas, o por talla si es un arreglo. */
function f03gbd_producto(float $precio, int|array $stock): Product
{
    $producto = f03b_configurable($precio);

    foreach ($producto->variants()->get()->values() as $i => $variante) {
        f03b_stock($variante, is_array($stock) ? ($stock[$i] ?? 0) : $stock);
    }

    return $producto->fresh();
}

/** Lo desactiva como lo hace el panel de Bagisto (atributo `status`), sin tocar su copia plana. */
function f03gbd_desactivar(Product $producto): void
{
    DB::table('product_attribute_values')
        ->where('product_id', $producto->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'status')->value('id'))
        ->update(['boolean_value' => 0]);
}

/** La matriz sin la hora de la consulta (dos llamadas seguidas pueden diferir en 1 s). */
function f03gbd_sin_hora(array $matriz): array
{
    array_walk_recursive($matriz, function (&$valor, $clave) {
        if ($clave === 'consultado_en') {
            $valor = null;
        }
    });

    return $matriz;
}

function f03gbd_ids(array $r): array
{
    return array_map('intval', array_keys($r['matrices']));
}

function f03gbd_motivos(array $r): array
{
    return collect($r['excluidas'])->pluck('motivo', 'producto_id')->all();
}

test('D-1 Caballero/Rojo sin stock en ninguna talla: se excluye con motivo claro, sin codigo tecnico ni excepcion', function () {
    $caballero = f03gbd_producto(10000.0, 0);

    $r = $this->catalogo->matrices([$caballero->id], $this->cliente->id, $this->asesor);

    expect($r['matrices'])->toBe([])
        ->and($r['excluidas'])->toBe([['producto_id' => $caballero->id, 'motivo' => 'sin_stock']]);

    // Por la API: 200 con el resultado, nunca un 422 «Variante no valida: producto_no_vendible».
    $this->actingAs($this->asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.matrices', ['productos' => [$caballero->id], 'customer_id' => $this->cliente->id]))
        ->assertOk()
        ->assertJsonPath('excluidas.0.motivo', 'sin_stock')
        ->assertJsonMissing(['codigo' => 'producto_no_vendible']);
});

test('D-2 Caballero sin stock + Dama con stock: Dama llega a la modal, Caballero se excluye con aviso', function () {
    $caballero = f03gbd_producto(10000.0, 0);
    $dama = f03gbd_producto(10000.0, 20);

    foreach ([[$caballero->id, $dama->id], [$dama->id, $caballero->id]] as $orden) {
        $r = $this->catalogo->matrices($orden, $this->cliente->id, $this->asesor);

        expect(f03gbd_ids($r))->toBe([$dama->id])
            ->and(f03gbd_motivos($r))->toBe([$caballero->id => 'sin_stock'])
            // La matriz de Dama es la de siempre.
            ->and(f03gbd_sin_hora($r['matrices'][$dama->id]))->toBe(f03gbd_sin_hora($this->catalogo->matriz($dama->id, $this->cliente->id, $this->asesor)->toArray()));
    }

    // Un producto INACTIVO (la causa real de producto_no_vendible en dev: atributo status = 0 con copia plana aun en 1) tampoco bloquea.
    $inactivo = f03gbd_producto(10000.0, 50);
    f03gbd_desactivar($inactivo);

    $r = $this->catalogo->matrices([$inactivo->id, $dama->id, 999999999], $this->cliente->id, $this->asesor);

    expect(f03gbd_ids($r))->toBe([$dama->id])
        ->and(f03gbd_motivos($r))->toBe([$inactivo->id => 'no_disponible', 999999999 => 'no_disponible']);
});

test('D-3 dos productos y dos colores, mezcla con y sin stock: aparecen todas las combinaciones vendibles', function () {
    $caballeroRojo = f03gbd_producto(10000.0, 10);   // con stock
    $caballeroAzul = f03gbd_producto(10000.0, 0);    // sin stock
    $damaRojo = f03gbd_producto(10000.0, 0);         // sin stock
    $damaAzul = f03gbd_producto(10000.0, 7);         // con stock
    $damaVerde = f03gbd_producto(10000.0, 3);        // con stock pero inactiva
    f03gbd_desactivar($damaVerde);

    $r = $this->catalogo->matrices(
        [$caballeroRojo->id, $caballeroAzul->id, $damaRojo->id, $damaAzul->id, $damaVerde->id],
        $this->cliente->id, $this->asesor,
    );

    expect(f03gbd_ids($r))->toBe([$caballeroRojo->id, $damaAzul->id])
        ->and(f03gbd_motivos($r))->toBe([$caballeroAzul->id => 'sin_stock', $damaRojo->id => 'sin_stock', $damaVerde->id => 'no_disponible']);
});

test('D-4 ninguna combinacion disponible: no hay matrices (la modal no abre) y todo viene excluido con su motivo', function () {
    $a = f03gbd_producto(10000.0, 0);
    $b = f03gbd_producto(10000.0, 0);

    $r = $this->catalogo->matrices([$a->id, $b->id], $this->cliente->id, $this->asesor);

    expect($r['matrices'])->toBe([])->and(f03gbd_motivos($r))->toBe([$a->id => 'sin_stock', $b->id => 'sin_stock']);
});

test('D-5 todas disponibles: mismas matrices de siempre, sin exclusiones', function () {
    $uno = f03gbd_producto(10000.0, 12);
    $dos = f03gbd_producto(12000.0, 4);

    $r = $this->catalogo->matrices([$uno->id, $dos->id], $this->cliente->id, $this->asesor);

    expect($r['excluidas'])->toBe([])
        ->and(f03gbd_ids($r))->toBe([$uno->id, $dos->id])
        ->and(f03gbd_sin_hora($r['matrices'][$uno->id]))->toBe(f03gbd_sin_hora($this->catalogo->matriz($uno->id, $this->cliente->id, $this->asesor)->toArray()))
        ->and(f03gbd_sin_hora($r['matrices'][$dos->id]))->toBe(f03gbd_sin_hora($this->catalogo->matriz($dos->id, $this->cliente->id, $this->asesor)->toArray()));
});

test('D-6 una talla sin stock y otras con stock: el producto es vendible; la talla agotada queda marcada y no se puede agregar', function () {
    $producto = f03gbd_producto(10000.0, [0, 9, 9, 9]);
    $tallas = $producto->variants()->count();

    $d = $this->catalogo->disponibilidadDe([$producto->id])[$producto->id];

    expect($d)->toBe(['vendible' => true, 'tallas_total' => $tallas, 'tallas_con_stock' => $tallas - 1]);

    $r = $this->catalogo->matrices([$producto->id], $this->cliente->id, $this->asesor);
    $celdas = collect($r['matrices'][$producto->id]['celdas']);

    // Regla actual de la modal: la fila de una talla agotada se muestra deshabilitada («No disponible»); solo hay filas
    // habilitables donde la variante esta activa y con stock.
    expect($r['excluidas'])->toBe([])
        ->and($celdas->count())->toBe($tallas)
        ->and($celdas->filter(fn ($c) => $c['variante']['stockVendible'] === 0)->count())->toBe(1)
        ->and($celdas->filter(fn ($c) => $c['variante']['activa'] && $c['variante']['stockVendible'] > 0)->count())->toBe($tallas - 1);
});

test('D-7 el color anticipa la disponibilidad con la MISMA regla (servidor): sin stock, parcial por tallas e inactivo', function () {
    $caballero = f03gb_categoria_d('camiseta-caballero', 'CAMISETA CABALLERO');
    $dama = f03gb_categoria_d('camisetas-dama', 'CAMISETAS DAMA');

    $agotado = f03gbd_producto(10000.0, 0);
    $parcial = f03gbd_producto(10000.0, [0, 0, 5, 0]);
    $completo = f03gbd_producto(10000.0, 8);
    $inactivo = f03gbd_producto(10000.0, 8);
    f03gbd_desactivar($inactivo);

    $agotado->categories()->sync([$caballero->id]);
    $inactivo->categories()->sync([$caballero->id]);
    $parcial->categories()->sync([$dama->id]);
    $completo->categories()->sync([$dama->id]);

    $porProducto = fn (string $clave) => collect($this->catalogo->coloresDeAcceso($clave))->keyBy('productId');
    $cab = $porProducto('camiseta_caballero');
    $dam = $porProducto('camiseta_dama');

    expect($cab[$agotado->id]->disponible)->toBeFalse()
        ->and($cab[$agotado->id]->tallasConStock)->toBe(0)
        ->and($cab[$inactivo->id]->disponible)->toBeFalse()   // inactivo: no se ofrece aunque tenga stock
        ->and($dam[$parcial->id]->disponible)->toBeTrue()     // una sola talla con stock basta
        ->and($dam[$parcial->id]->tallasConStock)->toBe(1)
        ->and($dam[$completo->id]->disponible)->toBeTrue()
        ->and($dam[$completo->id]->tallasConStock)->toBe($dam[$completo->id]->tallasTotal);

    // La API devuelve esos campos tal cual (los usa el boton del color).
    $this->actingAs($this->asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.lineas.colores', ['clave' => 'camiseta_caballero']))
        ->assertOk()
        ->assertJsonFragment(['productId' => $agotado->id, 'disponible' => false, 'tallasConStock' => 0]);

    // Coherencia con la matriz: lo que el color anuncia como disponible es exactamente lo que la matriz deja agregar.
    foreach ([$agotado, $parcial, $completo] as $producto) {
        $conStock = collect($this->catalogo->matriz($producto->id, $this->cliente->id, $this->asesor)->toArray()['celdas'])
            ->filter(fn ($c) => $c['variante']['activa'] && $c['variante']['stockVendible'] > 0)->count();

        expect($conStock)->toBe($this->catalogo->disponibilidadDe([$producto->id])[$producto->id]['tallas_con_stock']);
    }
});

test('D-8 editar lo que ya esta en la venta no se limita por stock (hay que poder bajarlo o quitarlo)', function () {
    $agotado = f03gbd_producto(10000.0, 0);

    $r = $this->catalogo->matrices([$agotado->id], $this->cliente->id, $this->asesor, soloVendibles: false);

    expect($r['excluidas'])->toBe([])->and(f03gbd_ids($r))->toBe([$agotado->id]);
});

test('D-9 la matriz individual de siempre no cambia: un producto no vendible sigue rechazandose con su codigo (ahora el asesor lo ve traducido)', function () {
    $inactivo = f03gbd_producto(10000.0, 5);
    f03gbd_desactivar($inactivo);

    $codigo = null;

    try {
        $this->catalogo->matriz($inactivo->id, $this->cliente->id, $this->asesor);
    } catch (VarianteInvalidaException $e) {
        $codigo = $e->codigo;
    }

    expect($codigo)->toBe('producto_no_vendible');
});

test('D-10 comportamiento real del JavaScript de Nueva venta: estado de los colores, lote parcial, ninguna combinacion y mensajes', function () {
    exec('node -v 2>&1', $version, $codigo);

    if ($codigo !== 0) {
        $this->markTestSkipped('Node no esta disponible en este entorno.');
    }

    $salida = [];
    exec(
        'node '.escapeshellarg(base_path('packages/ByLopez/VentasAsesor/tests/js/nueva_venta_disponibilidad.mjs')).' '
        .escapeshellarg(base_path('packages/ByLopez/VentasAsesor/src/Resources/views/admin/nueva-venta/index.blade.php')).' 2>&1',
        $salida,
        $codigo,
    );

    expect(implode("\n", $salida))->toContain('JS-OK')->and($codigo)->toBe(0);
});

function f03gb_categoria_d(string $slug, string $nombre): Category
{
    return f03gbd_categoria($slug, $nombre);
}
