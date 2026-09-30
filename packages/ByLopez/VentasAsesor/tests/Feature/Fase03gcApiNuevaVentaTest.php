<?php

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryTranslation;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| 03G-C: API HTTP de "Nueva venta rapida" (NuevaVentaController). Solo lpz_saas_testing.
| El middleware 'admin' (Bouncer real), el ACL real y NuevaVentaService/CatalogoVentaService
| reales estan en juego: estas pruebas pasan por el stack HTTP completo, no solo el servicio.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->grupo = f03b_grupo('G');
    $this->cliente = f03b_cliente($this->grupo, ['first_name' => 'Rita', 'last_name' => 'Compradora', 'phone' => '3'.random_int(100000000, 199999999)]);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();
    foreach ($this->variantes as $v) {
        f03b_stock($v, 50);
    }
    $porColor = $this->variantes->groupBy(fn ($v) => (int) $v->color)->first(fn ($g) => $g->count() >= 2);
    [$this->m, $this->l] = $porColor->take(2)->values()->all();

    $categoria = f03gc_categoria('camiseta-caballero', 'CAMISETA CABALLERO');
    $this->configurable->categories()->sync([$categoria->id]);
});

function f03gc_categoria(string $slug, string $nombre, ?int $padre = 1): Category
{
    $categoria = Category::factory()->create(['parent_id' => $padre, 'status' => 1]);
    CategoryTranslation::query()->where('category_id', $categoria->id)->delete();
    CategoryTranslation::factory()->create([
        'category_id' => $categoria->id, 'slug' => $slug, 'name' => $nombre,
        'locale' => 'es', 'locale_id' => core()->getAllLocales()->firstWhere('code', 'es')?->id ?? 1,
    ]);

    return $categoria->fresh();
}

/** Admin real con SOLO el permiso operativo del modulo (nunca 'all'): ejercita el Bouncer real. */
function f03gc_asesor(): Admin
{
    return f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
}

function f03gc_direccion(): array
{
    return [
        'nombre' => 'Ana', 'apellido' => 'Prueba', 'lineas' => ['Calle 1 # 2-3'],
        'ciudad' => 'Bogotá', 'departamento' => 'DC', 'pais' => 'co', 'telefono' => '3001234567',
    ];
}

/* ---------------------------------------------------------------- E2E: cliente existente */

test('E2E cliente existente: iniciar -> acceso rapido -> color -> matriz -> M6/L6 -> recogida -> pago -> confirmar -> Order real', function () {
    $asesor = f03gc_asesor();

    // A. Bootstrap.
    $inicial = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado_inicial'))
        ->assertOk()
        ->json();

    expect($inicial['canales'])->toContain('tienda_fisica')
        ->and(collect($inicial['accesos_rapidos'])->pluck('clave'))->toContain('camiseta_caballero');

    // B. Buscar cliente por telefono: existe.
    $telefono = DB::table('customers')->where('id', $this->cliente->id)->value('phone');
    $busqueda = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.clientes.buscar'), ['telefono' => $telefono])
        ->assertOk()
        ->json();

    expect($busqueda['tipo'])->toBe('telefono_exacto')
        ->and($busqueda['cliente']['customerId'])->toBe($this->cliente->id);

    // D. Iniciar venta.
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), [
            'customer_id' => $this->cliente->id, 'canal' => 'tienda_fisica',
        ])
        ->assertOk()
        ->json();

    $borradorId = $estado['borrador']['id'];
    expect($borradorId)->toBeInt()->and($estado['lineas'])->toBe([]);

    // E. Colores del acceso rapido.
    $colores = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.lineas.colores', ['clave' => 'camiseta_caballero']))
        ->assertOk()
        ->json();

    expect(collect($colores)->pluck('productId'))->toContain($this->configurable->id);

    // G. Matriz del color elegido.
    $matriz = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.productos.matriz', ['producto' => $this->configurable->id]).'?customer_id='.$this->cliente->id.'&borrador_id='.$borradorId)
        ->assertOk()
        ->json();

    expect($matriz['celdas'])->toHaveCount($this->variantes->count())
        ->and(collect($matriz['celdas'])->pluck('variante.productId'))->toContain($this->m->id, $this->l->id);

    // H. Lote M6/L6, todo o nada.
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), [
            'producto_id' => $this->configurable->id,
            'cantidades' => [$this->m->id => 6, $this->l->id => 6],
        ])
        ->assertOk()
        ->json();

    expect($estado['lineas'])->toHaveCount(2)
        ->and((float) $estado['total_esperado'])->toBe(120000.0);

    // I. Recogida en bodega (facturacion = direccion real del cliente: D-30).
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.entrega', ['borrador' => $borradorId]), [
            'envio' => ['tipo' => 'punto_recogida'],
            'facturacion' => ['tipo' => 'puntual', 'direccion' => f03gc_direccion()],
            'metodo' => 'recogida_bodega',
        ])
        ->assertOk()
        ->json();

    expect($estado['entrega']['metodo_seleccionado']['codigo'])->toBe('recogida_bodega')
        ->and($estado['entrega']['entrega_completa'])->toBeTrue();

    // J. Pago.
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.pago', ['borrador' => $borradorId]), ['metodo' => 'cashondelivery'])
        ->assertOk()
        ->json();

    expect($estado['pago']['metodo_seleccionado']['codigo'])->toBe('cashondelivery')
        ->and($estado['confirmable'])->toBeTrue();

    // K. Estado/resumen.
    $resumen = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado', ['borrador' => $borradorId]))
        ->assertOk()
        ->json();

    expect((float) $resumen['total_esperado'])->toBe(120000.0);

    // L. Confirmar. M. Resultado: Order real en testing.
    $confirmacion = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.confirmar', ['borrador' => $borradorId]), [
            'total_esperado' => $resumen['total_esperado'],
        ])
        ->assertOk()
        ->json();

    expect($confirmacion['order_id'])->toBeInt()
        ->and($confirmacion['increment_id'])->toBeString()
        ->and(Order::query()->whereKey($confirmacion['order_id'])->exists())->toBeTrue();
});

/* ---------------------------------------------------------------- E2E: cliente nuevo */

test('E2E telefono no encontrado: crear cliente minimo (RT-04) -> continuar la venta', function () {
    $asesor = f03gc_asesor();
    $telefono = '3'.random_int(100000000, 199999999);

    $busqueda = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.clientes.buscar'), ['telefono' => $telefono])
        ->assertOk()
        ->json();

    expect($busqueda['tipo'])->toBe('sin_resultados');

    // C+D en una transaccion: alta minima (email NULL permitido, grupo explicito) + venta nueva.
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar_cliente_nuevo'), [
            'nombre' => 'Cliente', 'apellido' => 'Nuevo', 'telefono' => $telefono,
            'customer_group_id' => $this->grupo->id, 'canal' => 'whatsapp',
        ])
        ->assertOk()
        ->json();

    $borradorId = $estado['borrador']['id'];
    $customerId = $estado['borrador']['cliente']['customer_id'];

    expect(Customer::query()->whereKey($customerId)->value('email'))->toBeNull()
        ->and((int) Customer::query()->whereKey($customerId)->value('customer_group_id'))->toBe($this->grupo->id);

    // Continua la venta: aplica una talla real.
    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), [
            'producto_id' => $this->configurable->id,
            'cantidades' => [$this->m->id => 2],
        ])
        ->assertOk()
        ->json();

    expect($estado['lineas'])->toHaveCount(1);
});

/* ---------------------------------------------------------------- ACL, propiedad y errores */

test('sin autenticar: 302 al login; sin el permiso del modulo: 401 del Bouncer real', function () {
    $this->getJson(route('admin.ventas_asesor.nueva_venta.estado_inicial'))
        ->assertRedirect(route('admin.session.create'));

    $sinPermiso = f03b_admin('custom', ['dashboard']);

    $this->actingAs($sinPermiso, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado_inicial'))
        ->assertUnauthorized();
});

test('borrador ajeno: otro asesor con el mismo permiso NO puede operarlo (409, no_es_propietario)', function () {
    $dueno = f03gc_asesor();
    $otro = f03gc_asesor();

    $borradorId = $this->actingAs($dueno, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->cliente->id, 'canal' => 'whatsapp'])
        ->json('borrador.id');

    $respuesta = $this->actingAs($otro, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), [
            'producto_id' => $this->configurable->id,
            'cantidades' => [$this->m->id => 1],
        ])
        ->assertStatus(409)
        ->json();

    // aplicarTallas (03G-B) envuelve toda falla de una talla en LOTE_RECHAZADO; la causa REAL
    // (no_es_propietario) es la que decide el 409 y la que identifica el motivo.
    expect($respuesta['codigo'])->toBe('lote_rechazado')
        ->and($respuesta['causa'])->toBe('no_es_propietario');

    // El dueño SI puede.
    $this->actingAs($dueno, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), [
            'producto_id' => $this->configurable->id,
            'cantidades' => [$this->m->id => 1],
        ])
        ->assertOk();
});

test('lote todo o nada por HTTP: una talla sin stock revierte todo y devuelve LOTE_RECHAZADO', function () {
    f03b_stock($this->l, 2);
    $asesor = f03gc_asesor();

    $borradorId = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->cliente->id, 'canal' => 'whatsapp'])
        ->json('borrador.id');

    $respuesta = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), [
            'producto_id' => $this->configurable->id,
            'cantidades' => [$this->m->id => 5, $this->l->id => 9],
        ])
        ->assertStatus(422)
        ->json();

    expect($respuesta['codigo'])->toBe('lote_rechazado')
        ->and($respuesta['variante_id'])->toBe($this->l->id)
        ->and(DB::table('cart_items')->where('cart_id', BorradorVenta::query()->whereKey($borradorId)->value('cart_id'))->count())->toBe(0);
});

test('validacion estructural: sin criterio de busqueda, lote vacio y customer_id faltante devuelven 422 (no llegan al dominio)', function () {
    $asesor = f03gc_asesor();

    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.clientes.buscar'), [])
        ->assertStatus(422);

    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['canal' => 'whatsapp'])
        ->assertStatus(422);

    $borradorId = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->cliente->id, 'canal' => 'whatsapp'])
        ->json('borrador.id');

    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), ['producto_id' => $this->configurable->id, 'cantidades' => []])
        ->assertStatus(422);
});

test('PII: el estado de la venta y la matriz nunca exponen password, remember_token ni datos crudos de admin', function () {
    $asesor = f03gc_asesor();

    $borradorId = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->cliente->id, 'canal' => 'whatsapp'])
        ->json('borrador.id');

    $estadoJson = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado', ['borrador' => $borradorId]))
        ->assertOk()
        ->getContent();

    $matrizJson = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.productos.matriz', ['producto' => $this->configurable->id]).'?customer_id='.$this->cliente->id)
        ->assertOk()
        ->getContent();

    foreach ([$estadoJson, $matrizJson] as $json) {
        expect($json)->not->toContain('password')->not->toContain('remember_token')->not->toContain('api_token');
    }
});
