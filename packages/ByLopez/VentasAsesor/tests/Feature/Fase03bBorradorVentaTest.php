<?php

use ByLopez\VentasAsesor\Contracts\CalendarioLaboral;
use ByLopez\VentasAsesor\DataObjects\VistaBorradorVenta as Vista;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Checkout\Models\CartItem;
use Webkul\Checkout\Models\CartPayment;
use Webkul\Customer\Models\Customer;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| Fase 03B — BorradorVentaService alrededor del Cart real de Bagisto. Solo lpz_saas_testing.
*/

function f03b_codigoBorrador(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido|GrupoComercialInvalidoException $e) {
        return $e->codigo;
    }

    return null;
}

/** Agrega una linea al carrito del borrador como lo hara 03C: Cart::addProduct dentro de RT-03. */
function f03b_agregarLinea(BorradorVenta $borrador, $configurable, $variante, int $qty): void
{
    $carrito = CartModel::query()->findOrFail($borrador->cart_id);

    app(ContextoPrecioComercial::class)->durante($carrito->id, (int) $carrito->customer_id, function () use ($carrito, $configurable, $variante, $qty) {
        Cart::setCart($carrito);
        Cart::addProduct($configurable, ['selected_configurable_option' => $variante->id, 'quantity' => $qty]);
        Cart::resetCart();
    });
}

function f03b_precioLinea(BorradorVenta $borrador): float
{
    return (float) CartItem::query()->where('cart_id', $borrador->fresh()->cart_id)->whereNull('parent_id')->value('price');
}

beforeEach(function () {
    $this->servicio = app(BorradorVentaService::class);
    $this->asesor = f03b_admin();
    $this->otroAsesor = f03b_admin();
    $this->grupoA = f03b_grupo('A');
    $this->grupoB = f03b_grupo('B');
    $this->clienteA = f03b_cliente($this->grupoA);
    $this->clienteB = f03b_cliente($this->grupoB);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

// ---------------------------------------------------------------- crear

test('crear: carrito REAL de Bagisto inactivo con el cliente y sus datos; borrador activo del asesor', function () {
    CarbonImmutable::setTestNow('2026-09-28 10:00:00');   // lunes

    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $carrito = CartModel::query()->findOrFail($borrador->cart_id);

    expect($borrador->estado)->toBe(EstadoBorradorVenta::ACTIVO)
        ->and($borrador->propietario_admin_id)->toBe($this->asesor->id)
        ->and($borrador->creado_por_admin_id)->toBe($this->asesor->id)
        ->and($borrador->mecanismo_creacion)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and($borrador->asignado_hasta->format('Y-m-d H:i'))->toBe('2026-09-29 10:00')
        ->and((int) $carrito->customer_id)->toBe($this->clienteA->id)
        ->and((bool) $carrito->is_active)->toBeFalse()
        ->and((int) $carrito->is_guest)->toBe(0)
        ->and($carrito->customer_first_name)->toBe($this->clienteA->first_name);

    // Aislado de la sesion de la tienda del cliente (Cart::initCart solo usa is_active = 1).
    Cart::initCart($this->clienteA);
    expect(Cart::getCart()?->id)->not->toBe($carrito->id);
    Cart::resetCart();
});

test('crear: sin permiso, cliente invitado, sin grupo o inexistente se rechazan sin crear nada', function () {
    $antes = BorradorVenta::query()->count();
    $sinPermiso = f03b_admin('custom', ['ventas_asesor']);

    expect(f03b_codigoBorrador(fn () => $this->servicio->crear($this->clienteA->id, $sinPermiso, CanalVenta::WHATSAPP)))->toBe(Invalido::SIN_PERMISO)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->crear(f03b_cliente(core()->getGuestCustomerGroup())->id, $this->asesor, CanalVenta::WHATSAPP)))
        ->toBe(GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->crear(Customer::factory()->create(['customer_group_id' => null])->id, $this->asesor, CanalVenta::WHATSAPP)))
        ->toBe(GrupoComercialInvalidoException::GRUPO_REQUERIDO)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->crear(99_999_999, $this->asesor, CanalVenta::WHATSAPP)))->toBe(Invalido::CLIENTE_INEXISTENTE)
        ->and(BorradorVenta::query()->count())->toBe($antes);
});

// ---------------------------------------------------------------- idempotencia

test('external_reference: la misma solicitud devuelve el MISMO borrador (un solo carrito)', function () {
    $primero = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, ' wa-conv-123 ');
    $carritos = CartModel::query()->count();
    $segundo = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, 'wa-conv-123');

    expect($segundo->id)->toBe($primero->id)
        ->and($primero->external_reference)->toBe('wa-conv-123')
        ->and(CartModel::query()->count())->toBe($carritos);
});

test('external_reference: es por mecanismo; de otro cliente se rechaza; vacia se rechaza', function () {
    $n8n = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, 'ref-1');
    $api = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::API, 'ref-1');

    expect($api->id)->not->toBe($n8n->id)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->crear($this->clienteB->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, 'ref-1')))
        ->toBe(Invalido::REFERENCIA_EN_USO)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, '   ')))
        ->toBe(Invalido::REFERENCIA_INVALIDA);
});

test('carrera de idempotencia: la base impide dos borradores con la misma referencia y mecanismo', function () {
    $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP, MecanismoCreacion::N8N, 'ref-carrera');

    expect(fn () => BorradorVenta::query()->create([
        'propietario_admin_id' => $this->asesor->id, 'creado_por_admin_id' => $this->asesor->id,
        'estado' => 'activo', 'canal_venta' => 'whatsapp', 'mecanismo_creacion' => 'n8n', 'external_reference' => 'ref-carrera',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// ---------------------------------------------------------------- obtener / listar

test('obtener y listar: solo los activos del propietario, con cliente y totales leidos del carrito', function () {
    $mio = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $ajeno = $this->servicio->crear($this->clienteB->id, $this->otroAsesor, CanalVenta::TELEFONO);
    $descartado = $this->servicio->crear($this->clienteB->id, $this->asesor, CanalVenta::WHATSAPP);
    $this->servicio->descartar($descartado->id, $this->asesor);

    $lista = $this->servicio->listarDelPropietario($this->asesor->id);
    $vista = $this->servicio->obtener($mio->id);

    expect(collect($lista)->pluck('id')->all())->toBe([$mio->id])
        ->and($vista->cliente['customer_id'])->toBe($this->clienteA->id)
        ->and($vista->cliente['grupo_id'])->toBe($this->grupoA->id)
        ->and($vista->totales)->toHaveKey('grand_total')
        ->and($vista->clienteConfirmable)->toBeTrue()
        ->and($vista->avisos)->toBe([])
        ->and(collect($this->servicio->listarDelPropietario($this->otroAsesor->id))->pluck('id')->all())->toBe([$ajeno->id]);
});

// ---------------------------------------------------------------- cambio de cliente

test('cambiar cliente: identidad del carrito, direcciones/pago/envio retirados y lineas REPRECIADAS con el grupo del cliente nuevo', function () {
    $configurable = f03b_configurable();
    $variante = $configurable->variants()->first();
    $base = (float) $variante->price;
    f03b_stock($variante, 50);
    f03b_descuentoDeGrupo($variante, $this->grupoA, 10, $configurable);
    f03b_descuentoDeGrupo($variante, $this->grupoB, 30, $configurable);

    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    f03b_agregarLinea($borrador, $configurable, $variante, 2);
    CartAddress::factory()->create(['cart_id' => $borrador->cart_id, 'customer_id' => $this->clienteA->id, 'address_type' => CartAddress::ADDRESS_TYPE_BILLING]);
    CartPayment::factory()->create(['cart_id' => $borrador->cart_id, 'method' => 'cashondelivery', 'method_title' => 'COD']);
    CartModel::query()->whereKey($borrador->cart_id)->update(['shipping_method' => 'flatrate_flatrate']);

    expect(round(f03b_precioLinea($borrador), 2))->toBe(round($base * 0.9, 2));

    $vista = $this->servicio->cambiarCliente($borrador->id, $this->clienteB->id, $this->asesor);
    $carrito = CartModel::query()->findOrFail($borrador->cart_id);

    expect((int) $carrito->customer_id)->toBe($this->clienteB->id)
        ->and($carrito->customer_first_name)->toBe($this->clienteB->first_name)
        ->and($carrito->customer_email)->toBe($this->clienteB->email)
        ->and($carrito->shipping_method)->toBeNull()
        ->and(CartAddress::query()->where('cart_id', $carrito->id)->count())->toBe(0)
        ->and(CartPayment::query()->where('cart_id', $carrito->id)->count())->toBe(0)
        ->and(round(f03b_precioLinea($borrador), 2))->toBe(round($base * 0.7, 2))
        ->and(CartItem::query()->where('cart_id', $carrito->id)->whereNotNull('custom_price')->count())->toBe(0)
        ->and($vista->cliente['customer_id'])->toBe($this->clienteB->id);
});

test('cambiar a un cliente invitado se rechaza y el carrito queda intacto', function () {
    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $invitado = f03b_cliente(core()->getGuestCustomerGroup());

    expect(f03b_codigoBorrador(fn () => $this->servicio->cambiarCliente($borrador->id, $invitado->id, $this->asesor)))
        ->toBe(GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO)
        ->and((int) CartModel::query()->whereKey($borrador->cart_id)->value('customer_id'))->toBe($this->clienteA->id);
});

test('grupo cambiado despues de crear el borrador: recalcular aplica el grupo ACTUAL (no se congela el historico)', function () {
    $configurable = f03b_configurable();
    $variante = $configurable->variants()->first();
    $base = (float) $variante->price;
    f03b_stock($variante, 50);
    f03b_descuentoDeGrupo($variante, $this->grupoA, 10, $configurable);
    f03b_descuentoDeGrupo($variante, $this->grupoB, 30, $configurable);

    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    f03b_agregarLinea($borrador, $configurable, $variante, 1);
    $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);

    $this->servicio->recalcular($borrador->id, $this->asesor);

    expect(round(f03b_precioLinea($borrador), 2))->toBe(round($base * 0.7, 2));
});

// ---------------------------------------------------------------- estados

test('descartar: estado final, carrito borrado (cascada) y ninguna operacion posterior', function () {
    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $cartId = $borrador->cart_id;

    $vista = $this->servicio->descartar($borrador->id, $this->asesor);

    expect($vista->estado)->toBe('descartado')
        ->and($vista->cartId)->toBeNull()
        ->and(CartModel::query()->whereKey($cartId)->exists())->toBeFalse()
        ->and(f03b_codigoBorrador(fn () => $this->servicio->cambiarCliente($borrador->id, $this->clienteB->id, $this->asesor)))->toBe(Invalido::NO_EDITABLE)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->descartar($borrador->id, $this->asesor)))->toBe(Invalido::NO_EDITABLE)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->reclamar($borrador->id, $this->otroAsesor)))->toBe(Invalido::NO_EDITABLE);
});

test('un borrador en "confirmando" (candado de la confirmacion futura) no se puede editar ni descartar', function () {
    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $borrador->update(['estado' => EstadoBorradorVenta::CONFIRMANDO]);

    expect(f03b_codigoBorrador(fn () => $this->servicio->recalcular($borrador->id, $this->asesor)))->toBe(Invalido::NO_EDITABLE)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->descartar($borrador->id, $this->asesor)))->toBe(Invalido::NO_EDITABLE);
});

// ---------------------------------------------------------------- propiedad

test('propiedad (D-35, lunes a sabado): otro asesor no edita ni reclama dentro de la ventana; tras 1 dia habil si puede reclamar', function () {
    CarbonImmutable::setTestNow('2026-10-02 10:00:00');   // viernes
    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);

    expect($borrador->asignado_hasta->format('Y-m-d H:i'))->toBe('2026-10-03 10:00')   // sabado (laborable)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->cambiarCliente($borrador->id, $this->clienteB->id, $this->otroAsesor)))->toBe(Invalido::NO_ES_PROPIETARIO)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->reclamar($borrador->id, $this->otroAsesor)))->toBe(Invalido::AUN_ASIGNADO);

    CarbonImmutable::setTestNow('2026-10-03 10:00:01');   // sabado, vencida la ventana
    $vista = $this->servicio->reclamar($borrador->id, $this->otroAsesor);

    expect($vista->propietarioAdminId)->toBe($this->otroAsesor->id)
        ->and($vista->creadoPorAdminId)->toBe($this->asesor->id)
        ->and($vista->asignadoHasta->format('Y-m-d H:i'))->toBe('2026-10-05 10:00')   // sabado + 1 = lunes (domingo no laborable)
        ->and(f03b_codigoBorrador(fn () => $this->servicio->descartar($borrador->id, $this->asesor)))->toBe(Invalido::NO_ES_PROPIETARIO);
});

test('D-35 calendario: viernes 10:00 → sabado 10:00', function () {
    expect(app(CalendarioLaboral::class)->sumarDiasHabiles(CarbonImmutable::parse('2026-10-02 10:00'), 1)->format('Y-m-d H:i'))
        ->toBe('2026-10-03 10:00');
});

test('D-35 calendario: sabado 10:00 → lunes 10:00 (el domingo no es laboral)', function () {
    $calendario = app(CalendarioLaboral::class);

    expect($calendario->sumarDiasHabiles(CarbonImmutable::parse('2026-10-03 10:00'), 1)->format('Y-m-d H:i'))->toBe('2026-10-05 10:00')
        ->and($calendario->esLaborable(CarbonImmutable::parse('2026-10-03')))->toBeTrue()
        ->and($calendario->esLaborable(CarbonImmutable::parse('2026-10-04')))->toBeFalse();
});

test('calendario: desde domingo cuenta desde el inicio del lunes; los festivos configurados se saltan (sin festivos en el codigo)', function () {
    $calendario = app(CalendarioLaboral::class);

    expect(config('ventasasesor.calendario_laboral.festivos'))->toBe([])
        ->and($calendario->sumarDiasHabiles(CarbonImmutable::parse('2026-10-04 15:00'), 1)->format('Y-m-d H:i'))->toBe('2026-10-06 00:00');

    config(['ventasasesor.calendario_laboral.festivos' => ['2026-10-12']]);   // festivo de ejemplo (lunes)

    expect($calendario->sumarDiasHabiles(CarbonImmutable::parse('2026-10-10 10:00'), 1)->format('Y-m-d H:i'))->toBe('2026-10-13 10:00')
        ->and($calendario->esLaborable(CarbonImmutable::parse('2026-10-12')))->toBeFalse();
});

// ---------------------------------------------------------------- P-09

test('P-09: cliente inactivo o suspendido SI admite borrador, con avisos y sin ser confirmable', function () {
    $suspendido = f03b_cliente($this->grupoA, ['is_suspended' => 1, 'status' => 0]);

    $vista = $this->servicio->obtener($this->servicio->crear($suspendido->id, $this->asesor, CanalVenta::WHATSAPP)->id);

    expect($vista->avisos)->toBe([Vista::AVISO_CLIENTE_INACTIVO, Vista::AVISO_CLIENTE_SUSPENDIDO])
        ->and($vista->clienteConfirmable)->toBeFalse()
        ->and($vista->estado)->toBe('activo');
});

// ---------------------------------------------------------------- limites de 03B

test('03B no crea pedidos ni reserva stock', function () {
    $pedidos = DB::table('orders')->count();
    $reservas = DB::table('product_ordered_inventories')->sum('qty');

    $borrador = $this->servicio->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    $this->servicio->cambiarCliente($borrador->id, $this->clienteB->id, $this->asesor);

    expect(DB::table('orders')->count())->toBe($pedidos)
        ->and((float) DB::table('product_ordered_inventories')->sum('qty'))->toBe((float) $reservas);
});
