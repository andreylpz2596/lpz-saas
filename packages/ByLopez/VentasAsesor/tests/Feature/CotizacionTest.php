<?php

use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Exceptions\CotizacionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\Cotizacion;
use ByLopez\VentasAsesor\Services\Cotizaciones\CotizacionService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Checkout\Models\CartItem;
use Webkul\Core\Repositories\CoreConfigRepository;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| Cotizaciones de una venta en curso. Solo lpz_saas_testing. Una cotizacion es una FOTO: no crea pedido, no reserva stock ni toca el
| carrito; el total cotizado excluye el domicilio; no se edita (cambios = version siguiente); solo la ve su propietario o quien ve todo.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $this->otro = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $this->cliente = f03b_cliente(f03b_grupo('G'), ['first_name' => 'Maria', 'last_name' => 'Ruiz']);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();

    foreach ($this->variantes as $v) {
        f03b_stock($v, 50);
    }

    [$this->m, $this->l] = $this->variantes->groupBy(fn ($v) => (int) $v->color)->first(fn ($g) => $g->count() >= 2)->take(2)->values()->all();

    $this->nv = app(NuevaVentaService::class);
    $this->cot = app(CotizacionService::class);
});

function cot_venta($t, array $tallas): int
{
    $b = $t->nv->iniciar($t->cliente->id, $t->asesor, 'tienda_fisica')->borrador->id;

    if ($tallas !== []) {
        $t->nv->aplicarTallas($b, $t->configurable->id, $tallas, $t->asesor);
    }

    return $b;
}

function cot_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

function cot_config(string $ruta, ?string $valor): void
{
    // Por la MISMA ruta que el formulario de Configuracion de Bagisto (anidado por clave): ademas invalida su cache de lectura.
    [$grupo, $campo] = explode('.', $ruta);

    app(CoreConfigRepository::class)->create([
        'locale' => 'es', 'channel' => 'default',
        'ventas_asesor' => ['cotizacion' => [$grupo => [$campo => $valor]]],
    ]);
}

test('crea la foto con el total de PRODUCTOS y no toca pedido, stock ni carrito', function () {
    $b = cot_venta($this, [$this->m->id => 2, $this->l->id => 1]);
    $carrito = BorradorVenta::query()->find($b)->cart_id;
    $antes = [
        'pedidos' => Order::query()->count(),
        'stock' => (int) DB::table('product_inventories')->whereIn('product_id', $this->variantes->pluck('id'))->sum('qty'),
        'items' => CartItem::query()->where('cart_id', $carrito)->count(),
    ];

    $c = $this->cot->crear($b, $this->asesor);

    expect($c->version)->toBe(1)
        ->and($c->numero())->toBe(sprintf('COT-%06d', $b))
        ->and($c->totales['total_productos'])->toEqual(30000.0)
        ->and($c->totales['domicilio_estimado'])->toBeNull()
        ->and($c->lineas)->toHaveCount(2)
        ->and($c->cliente['nombre'])->toBe('Maria Ruiz')
        ->and($c->valida_hasta->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($this->cot->estadoDe($c))->toBe(CotizacionService::VIGENTE)
        ->and(Order::query()->count())->toBe($antes['pedidos'])
        ->and((int) DB::table('product_inventories')->whereIn('product_id', $this->variantes->pluck('id'))->sum('qty'))->toBe($antes['stock'])
        ->and(CartItem::query()->where('cart_id', $carrito)->count())->toBe($antes['items'])
        ->and(BorradorVenta::query()->find($b)->estado)->toBe(EstadoBorradorVenta::ACTIVO);
});

test('el domicilio no entra en el total: se resta del gran total del carrito y solo queda como estimacion', function () {
    $b = cot_venta($this, [$this->m->id => 2]);
    // Simula un envio ya calculado por Bagisto en el carrito (10.000 de domicilio sobre 20.000 de productos).
    $carrito = \Webkul\Checkout\Models\Cart::query()->find(BorradorVenta::query()->find($b)->cart_id);
    $carrito->update(['shipping_amount' => 12000, 'base_shipping_amount' => 12000, 'grand_total' => (float) $carrito->grand_total + 12000, 'base_grand_total' => (float) $carrito->base_grand_total + 12000]);

    $c = $this->cot->crear($b, $this->asesor);

    expect($c->totales['total_productos'])->toEqual(20000.0)
        ->and($c->totales['domicilio_estimado'])->toEqual(12000.0);
});

test('pedirla otra vez sin cambios devuelve la misma; si la venta cambia nace la version siguiente y la anterior queda reemplazada', function () {
    $b = cot_venta($this, [$this->m->id => 2]);

    $v1 = $this->cot->crear($b, $this->asesor);
    $igual = $this->cot->crear($b, $this->asesor);
    expect($igual->id)->toBe($v1->id)->and(Cotizacion::query()->where('borrador_id', $b)->count())->toBe(1);

    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2, $this->l->id => 3], $this->asesor);
    $v2 = $this->cot->crear($b, $this->asesor);

    expect($v2->version)->toBe(2)
        ->and($v2->numero())->toBe(sprintf('COT-%06d-2', $b))
        ->and($v2->totales['total_productos'])->toEqual(50000.0)
        ->and($this->cot->estadoDe($v1->fresh()))->toBe(CotizacionService::REEMPLAZADA)
        ->and($v1->fresh()->totales['total_productos'])->toEqual(20000.0)
        ->and(array_column($this->cot->deLaVenta($b, $this->asesor), 'version'))->toBe([2, 1]);
});

test('una cotizacion no se edita ni se borra', function () {
    $c = $this->cot->crear(cot_venta($this, [$this->m->id => 1]), $this->asesor);

    expect(fn () => $c->update(['notas' => 'x']))->toThrow(Invalida::class)
        ->and(fn () => $c->delete())->toThrow(Invalida::class);
});

test('estados: vencida por fecha y convertida cuando la venta ya tiene pedido', function () {
    $b = cot_venta($this, [$this->m->id => 1]);
    $c = $this->cot->crear($b, $this->asesor);

    DB::table('bylopez_va_cotizaciones')->where('id', $c->id)->update(['valida_hasta' => now()->subDay()->toDateString()]);
    expect($this->cot->estadoDe($c->fresh()))->toBe(CotizacionService::VENCIDA);

    // Vencida y sin cambios: pedirla de nuevo crea otra vigente (no reutiliza una vencida).
    $nueva = $this->cot->crear($b, $this->asesor);
    expect($nueva->id)->not->toBe($c->id)->and($nueva->version)->toBe(2);

    $pedido = Order::factory()->create();
    DB::table('bylopez_va_borradores_venta')->where('id', $b)->update(['order_id' => $pedido->id]);
    expect($this->cot->estadoDe($nueva->fresh()))->toBe(CotizacionService::CONVERTIDA);
});

test('rechazos: sin lineas, venta que no es tuya, venta descartada e inexistente', function () {
    $vacia = cot_venta($this, []);
    $conLineas = cot_venta($this, [$this->m->id => 1]);

    expect(cot_codigo(fn () => $this->cot->crear($vacia, $this->asesor)))->toBe(Invalida::SIN_LINEAS)
        ->and(cot_codigo(fn () => $this->cot->crear($conLineas, $this->otro)))->toBe(Invalida::SIN_PERMISO)
        ->and(cot_codigo(fn () => $this->cot->crear(99999999, $this->asesor)))->toBe(Invalida::BORRADOR_INEXISTENTE)
        ->and(Cotizacion::query()->count())->toBe(0);

    app(BorradorVentaService::class)->descartar($conLineas, $this->asesor);
    expect(cot_codigo(fn () => $this->cot->crear($conLineas, $this->asesor)))->toBe(Invalida::BORRADOR_NO_ACTIVO);
});

test('quien ve todas las ventas puede abrirla; otro asesor no', function () {
    $c = $this->cot->crear(cot_venta($this, [$this->m->id => 1]), $this->asesor);
    $supervisor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS]);

    expect($this->cot->obtener($c->id, $supervisor)->id)->toBe($c->id)
        ->and(cot_codigo(fn () => $this->cot->obtener($c->id, $this->otro)))->toBe(Invalida::SIN_PERMISO)
        ->and(cot_codigo(fn () => $this->cot->obtener(99999999, $this->asesor)))->toBe(Invalida::COTIZACION_INEXISTENTE);
});

test('datos de pago: por defecto solo Nequi; el administrador los edita y un medio sin su dato principal no se imprime', function () {
    $a = app(AjustesCotizacion::class);

    expect($a->vigenciaDias())->toBe(7)
        ->and($a->mediosDePago())->toBe([['medio' => 'Nequi / Daviplata', 'lineas' => [['Número', '3157941278']]]]);

    // Banco sin numero de cuenta: no sale. Con numero: sale con su banco y sus campos.
    cot_config('pago.banco_titular', 'ByLopez S.A.S.');
    expect(app(AjustesCotizacion::class)->mediosDePago())->toHaveCount(1);

    cot_config('pago.banco_numero', '123-456789-00');
    cot_config('pago.banco_tipo_cuenta', 'Ahorros');
    cot_config('pago.llaves', '@bylopez');
    cot_config('pago.nequi_numero', '');
    cot_config('condiciones.vigencia_dias', '10');

    $medios = app(AjustesCotizacion::class)->mediosDePago();

    expect(array_column($medios, 'medio'))->toBe(['Bancolombia', 'Llaves'])
        ->and($medios[0]['lineas'])->toBe([['Tipo', 'Ahorros'], ['Número', '123-456789-00'], ['Titular', 'ByLopez S.A.S.']])
        ->and(app(AjustesCotizacion::class)->vigenciaDias())->toBe(10);
});

test('la foto congela los datos de pago: cambiarlos despues no altera la cotizacion ya enviada y obliga a una version nueva', function () {
    $b = cot_venta($this, [$this->m->id => 1]);
    $v1 = $this->cot->crear($b, $this->asesor);

    cot_config('pago.nequi_numero', '3000000000');

    expect($v1->fresh()->datos_pago['medios'][0]['lineas'][0][1])->toBe('3157941278')
        ->and($this->cot->crear($b, $this->asesor)->version)->toBe(2);
});

test('HTTP: el asesor crea y abre el PDF; otro asesor recibe 403; sin la clave de Nueva venta el ACL corta; el PDF sale de la foto', function () {
    $b = cot_venta($this, [$this->m->id => 2, $this->l->id => 1]);

    $r = $this->actingAs($this->asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.cotizaciones.store', $b))
        ->assertOk()
        ->assertJsonPath('numero', sprintf('COT-%06d', $b))
        ->assertJsonPath('estado', 'vigente')
        ->assertJsonPath('total', 30000);

    expect($r->json('texto'))->toContain('Hola Maria')->toContain('$ 30.000');

    $pdf = $this->actingAs($this->asesor, 'admin')->get($r->json('url_pdf'))->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($pdf->getContent(), 0, 5))->toBe('%PDF-');

    $this->actingAs($this->otro, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.cotizaciones.store', $b))->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
    $this->actingAs($this->otro, 'admin')->get($r->json('url_pdf'))->assertStatus(403);

    $sinClave = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO]);
    $this->actingAs($sinClave, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.cotizaciones.store', $b))->assertStatus(401);
    $this->actingAs($sinClave, 'admin')->get($r->json('url_pdf'))->assertStatus(401);

    $this->actingAs($this->asesor, 'admin')->getJson(route('admin.ventas_asesor.nueva_venta.cotizaciones.index', $b))
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.numero', sprintf('COT-%06d', $b));
});

test('filas del PDF: una por producto y color con las tallas juntas', function () {
    $c = $this->cot->crear(cot_venta($this, [$this->m->id => 2, $this->l->id => 1]), $this->asesor);
    $filas = $this->cot->filasPdf($c);

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['cantidad'])->toBe(3)
        ->and($filas[0]['subtotal'])->toBe(30000.0)
        ->and($filas[0]['detalle'])->toContain('Tallas:')->toContain('×2')->toContain('×1');
});
