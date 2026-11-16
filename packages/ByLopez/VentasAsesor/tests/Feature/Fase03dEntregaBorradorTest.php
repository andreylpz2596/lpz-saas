<?php

use ByLopez\Logistica\Carriers\MetodoEntrega;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\DataObjects\VistaEntregaBorrador;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Exceptions\EntregaBorradorInvalidaException as EntregaInvalida;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\EntregaBorradorService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\CustomerAddress;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\ProductOrderedInventory;

require_once __DIR__.'/../helpersFase03b.php';
require_once __DIR__.'/../helpersDomicilioLocal.php';

uses(TestCase::class);

/*
| Fase 03D — direccion y metodo de entrega sobre Bagisto. Solo lpz_saas_testing.
*/

/** Carrier de prueba: un metodo que VentasAsesor no conoce (extensibilidad). */
class F03dCarrierExpress extends MetodoEntrega
{
    protected $code = 'f03d_express';

    protected $method = 'f03d_express';

    public function calculate()
    {
        return $this->isAvailable() ? $this->tarifa(15000) : false;
    }
}

function f03d_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (EntregaInvalida|Invalido $e) {
        return $e->codigo;
    }

    return null;
}

function f03d_direccion(array $extra = []): DireccionComercial
{
    $d = array_merge([
        'nombre' => 'Ana', 'apellido' => 'Prueba', 'lineas' => ['Calle 1 # 2-3'], 'ciudad' => 'Bogotá',
        'departamento' => 'DC', 'pais' => 'co', 'codigoPostal' => null, 'telefono' => '3001234567', 'email' => null,
    ], $extra);

    return new DireccionComercial(...$d);
}

/**
 * Activa los carriers de ByLopez en la prueba (por defecto nacen inactivos). La tarifa del domicilio local es la del
 * domiciliario en la zona local del destino (helpersDomicilioLocal.php); `default_rate` ya no se usa (2026-10-12).
 */
function f03d_activarCarriers(?string $tarifaDomicilio = '8000'): void
{
    config([
        'carriers.pickup.active' => true,
        'carriers.domicilio_local.active' => true,
        'carriers.envio_nacional.active' => true,
    ]);

    $valor = $tarifaDomicilio === null ? null : (float) $tarifaDomicilio;

    app()->bound('pruebas.domicilio_local') ? dl_tarifaDomicilio($valor) : dl_domicilioLocal($valor);
}

function f03d_codigos($vista): array
{
    return collect($vista->metodosDisponibles)->pluck('codigo')->sort()->values()->all();
}

beforeEach(function () {
    $this->borradores = app(BorradorVentaService::class);
    $this->solicitudes = app(SolicitudBorradorService::class);
    $this->lineas = app(LineasBorradorService::class);
    $this->entrega = app(EntregaBorradorService::class);
    $this->asesor = f03b_admin();
    $this->grupo = f03b_grupo('A');
    $this->cliente = f03b_cliente($this->grupo);

    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);

    $this->borrador = $this->borradores->crear($this->cliente->id, $this->asesor, CanalVenta::WHATSAPP);
    $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->variante->id, 100, $this->asesor);
    $this->itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)->lineas[0]->cartItemId;

    $this->bodega = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first();

    f03d_activarCarriers();
});

/** Facturacion puntual + envio puntual (domicilio). */
function f03d_conDomicilio($test)
{
    $test->entrega->establecerFacturacion($test->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $test->asesor);

    return $test->entrega->establecerEnvio($test->borrador->id, OrigenDireccion::puntual(f03d_direccion(['lineas' => ['Carrera 9 # 8-7']])), $test->asesor);
}

// ------------------------------------------------------------------ direcciones

test('facturacion GUARDADA: se copia al carrito con parent_address_id; no crea direcciones nuevas', function () {
    $guardada = app(GuardarDireccionClienteComercialService::class)->guardar($this->cliente->id, f03d_direccion(['codigoPostal' => '110111']));
    $antes = CustomerAddress::query()->where('customer_id', $this->cliente->id)->count();

    $vista = $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::guardada($guardada->id), $this->asesor);

    expect($vista->facturacion->origen)->toBe('guardada')
        ->and($vista->facturacion->customerAddressId)->toBe($guardada->id)
        ->and((int) CartAddress::query()->where('cart_id', $this->borrador->cart_id)->where('address_type', 'cart_billing')->value('parent_address_id'))->toBe($guardada->id)
        ->and(CustomerAddress::query()->where('customer_id', $this->cliente->id)->count())->toBe($antes);
});

test('facturacion PUNTUAL con email NULL y sin codigo postal: va al carrito y NO se guarda en el cliente', function () {
    $antes = CustomerAddress::query()->where('customer_id', $this->cliente->id)->count();

    $vista = $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    expect($vista->facturacion->origen)->toBe('puntual')
        ->and($vista->facturacion->customerAddressId)->toBeNull()
        ->and($vista->facturacion->email)->toBeNull()
        ->and($vista->facturacion->codigoPostal)->toBeNull()
        ->and($vista->facturacion->telefono)->toBe('+573001234567')
        ->and(CustomerAddress::query()->where('customer_id', $this->cliente->id)->count())->toBe($antes);
});

test('cliente RT-04 sin email: la facturacion puntual sin email funciona y el carrito queda con email NULL', function () {
    $sinEmail = f03b_cliente($this->grupo, ['email' => null]);
    $borrador = $this->borradores->crear($sinEmail->id, $this->asesor, CanalVenta::TELEFONO);

    $vista = $this->entrega->establecerFacturacion($borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    expect($vista->facturacion->email)->toBeNull()
        ->and(CartModel::query()->find($borrador->cart_id)->customer_email)->toBeNull();
});

test('la direccion puntual usa las reglas de RT-04 (faltan campos → datos invalidos, nada se guarda)', function () {
    expect(fn () => $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion(['ciudad' => ' ', 'lineas' => []])), $this->asesor))
        ->toThrow(DatosClienteComercialInvalidosException::class)
        ->and(CartAddress::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0);
});

test('una direccion guardada de OTRO cliente se rechaza', function () {
    $ajena = app(GuardarDireccionClienteComercialService::class)->guardar(f03b_cliente($this->grupo)->id, f03d_direccion(['codigoPostal' => '110111']));

    expect(f03d_codigo(fn () => $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::guardada($ajena->id), $this->asesor)))
        ->toBe(EntregaInvalida::DIRECCION_NO_PERTENECE_AL_CLIENTE);
});

test('envio: exige facturacion primero; envio guardado, puntual e igual a facturacion', function () {
    $sinFacturacion = f03d_codigo(fn () => $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::igualFacturacion(), $this->asesor));

    $guardada = app(GuardarDireccionClienteComercialService::class)->guardar($this->cliente->id, f03d_direccion(['lineas' => ['Av 5 # 6-7'], 'codigoPostal' => '110111']));
    $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    $conGuardada = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::guardada($guardada->id), $this->asesor);
    $conPuntual = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntual(f03d_direccion(['lineas' => ['Diagonal 4']])), $this->asesor);
    $igual = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::igualFacturacion(), $this->asesor);

    expect($sinFacturacion)->toBe(EntregaInvalida::FACTURACION_REQUERIDA)
        ->and($conGuardada->envio->origen)->toBe('guardada')
        ->and($conGuardada->envio->lineas)->toBe(['Av 5 # 6-7'])
        ->and($conPuntual->envio->origen)->toBe('puntual')
        ->and($conPuntual->envio->lineas)->toBe(['Diagonal 4'])
        ->and($conPuntual->envioIgualFacturacion)->toBeFalse()
        ->and($igual->envioIgualFacturacion)->toBeTrue()
        ->and($igual->envio->lineas)->toBe(['Calle 1 # 2-3']);
});

// ------------------------------------------------------------------ recogida

test('RECOGIDA: el lugar es la bodega REAL (inventory_sources) y la persona, quien recibe; solo se ofrece recogida, a $0', function () {
    $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    $vista = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntoRecogida(), $this->asesor);
    $recogida = collect($vista->metodosDisponibles)->firstWhere('codigo', 'pickup');

    expect($vista->envio->origen)->toBe('punto_recogida')
        ->and($vista->envio->inventorySourceId)->toBe($this->bodega->id)
        ->and($vista->envio->lineas)->toBe([$this->bodega->street])
        ->and($vista->envio->ciudad)->toBe($this->bodega->city)
        ->and($vista->envio->empresa)->toBe($this->bodega->name)
        ->and($vista->envio->nombre)->toBe('Ana')
        ->and($vista->envio->telefono)->toBe('+573001234567')
        ->and(f03d_codigos($vista))->toContain('pickup')
        ->and(f03d_codigos($vista))->not->toContain('domicilio_local')
        ->and(f03d_codigos($vista))->not->toContain('envio_nacional')
        ->and($recogida->precio)->toBe(0.0)
        ->and($recogida->descuento)->toBe(0.0);

    $elegido = $this->entrega->seleccionarMetodo($this->borrador->id, 'pickup', $this->asesor);

    expect($elegido->metodoSeleccionado->codigo)->toBe('pickup')
        ->and($elegido->entregaCompleta)->toBeTrue()
        ->and($elegido->borrador->totales['shipping_amount'])->toBe(0.0);
});

test('recogida: bodega inexistente o ambigua (varias activas) se rechaza; la recogida no se ofrece a un domicilio', function () {
    $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    $inexistente = f03d_codigo(fn () => $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntoRecogida(99_999_999), $this->asesor));

    $otra = $this->bodega->replicate();
    $otra->code = 'F03D'.uniqid();
    $otra->save();
    core()->getDefaultChannel()->inventory_sources()->attach($otra->id);
    $ambigua = f03d_codigo(fn () => $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntoRecogida(), $this->asesor));

    $domicilio = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);

    expect($inexistente)->toBe(EntregaInvalida::PUNTO_RECOGIDA_INEXISTENTE)
        ->and($ambigua)->toBe(EntregaInvalida::PUNTO_RECOGIDA_AMBIGUO)
        ->and(f03d_codigos($domicilio))->not->toContain('pickup')
        ->and(f03d_codigo(fn () => $this->entrega->seleccionarMetodo($this->borrador->id, 'pickup', $this->asesor)))
        ->toBe(EntregaInvalida::METODO_NO_DISPONIBLE);
});

// ------------------------------------------------------------------ domicilio y nacional

test('DOMICILIO LOCAL con tarifa configurada: Bagisto la cobra y entra en los totales', function () {
    $vista = f03d_conDomicilio($this);

    expect(collect($vista->metodosDisponibles)->firstWhere('codigo', 'domicilio_local')->precio)->toBe(8000.0);

    $elegido = $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);
    $t = $elegido->borrador->totales;

    expect($elegido->metodoSeleccionado->codigo)->toBe('domicilio_local')
        ->and($t['shipping_amount'])->toBe(8000.0)
        ->and($t['grand_total'])->toBe(round($t['sub_total'] + $t['tax_total'] - $t['discount_amount'] + 8000, 2))
        ->and($elegido->entregaCompleta)->toBeTrue();
});

test('tarifa de domicilio: manda la configurada en el PANEL de Logistica (domiciliario + zona); el default_rate de core_config ya no cuenta', function () {
    // Regla vigente (2026-10-12): el valor generico del carrier en core_config nunca se usa como tarifa.
    DB::table('core_config')->insert([
        'code' => 'sales.carriers.domicilio_local.default_rate', 'value' => '99000',
        'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null,
    ]);
    dl_tarifaDomicilio(12000.0);

    $vista = f03d_conDomicilio($this);

    expect(collect($vista->metodosDisponibles)->firstWhere('codigo', 'domicilio_local')->precio)->toBe(12000.0);
});

test('sin tarifa de domicilio configurada el metodo NO se ofrece (la tarifa no se inventa)', function () {
    f03d_activarCarriers(null);

    $vista = f03d_conDomicilio($this);

    expect(f03d_codigos($vista))->not->toContain('domicilio_local')
        ->and(f03d_codigos($vista))->toContain('envio_nacional');
});

test('ENVIO NACIONAL: flete por pagar → ByLopez cobra 0, sin descuento ni regla (no es envio gratis)', function () {
    f03d_conDomicilio($this);

    $vista = $this->entrega->seleccionarMetodo($this->borrador->id, 'envio_nacional', $this->asesor);

    expect($vista->metodoSeleccionado->precio)->toBe(0.0)
        ->and($vista->metodoSeleccionado->descuento)->toBe(0.0)
        ->and($vista->metodoSeleccionado->descripcion)->toContain('por pagar')
        ->and((string) CartModel::query()->find($this->borrador->cart_id)->applied_cart_rule_ids)->toBe('');
});

test('ENVIO GRATIS por promocion (CartRule free_shipping): el metodo sigue siendo domicilio/nacional; cambia el precio', function (string $metodo) {
    $reglaId = DB::table('cart_rules')->insertGetId([
        'name' => 'F03D envio gratis', 'status' => 1, 'coupon_type' => 0, 'condition_type' => 1,
        'action_type' => 'by_percent', 'discount_amount' => 0, 'free_shipping' => 1,
    ]);
    DB::table('cart_rule_customer_groups')->insert(['cart_rule_id' => $reglaId, 'customer_group_id' => $this->grupo->id]);
    DB::table('cart_rule_channels')->insert(['cart_rule_id' => $reglaId, 'channel_id' => core()->getDefaultChannel()->id]);

    f03d_conDomicilio($this);
    $vista = $this->entrega->seleccionarMetodo($this->borrador->id, $metodo, $this->asesor);

    expect($vista->metodoSeleccionado->codigo)->toBe($metodo)
        ->and($vista->metodoSeleccionado->precio)->toBe(0.0)
        ->and($vista->borrador->totales['shipping_amount'])->toBe(0.0)
        ->and(explode(',', (string) CartModel::query()->find($this->borrador->cart_id)->applied_cart_rule_ids))->toContain((string) $reglaId);
})->with(['domicilio_local', 'envio_nacional']);

test('metodo inexistente o sin destino se rechaza; sin productos fisicos no hay envio', function () {
    $sinDestino = f03d_codigo(fn () => $this->entrega->seleccionarMetodo($this->borrador->id, 'envio_nacional', $this->asesor));
    f03d_conDomicilio($this);
    $inexistente = f03d_codigo(fn () => $this->entrega->seleccionarMetodo($this->borrador->id, 'teletransporte', $this->asesor));

    $vacio = $this->borradores->crear(f03b_cliente($this->grupo)->id, $this->asesor, CanalVenta::WHATSAPP);
    $this->entrega->establecerFacturacion($vacio->id, OrigenDireccion::puntual(f03d_direccion()), $this->asesor);
    $sinFisicos = f03d_codigo(fn () => $this->entrega->establecerEnvio($vacio->id, OrigenDireccion::igualFacturacion(), $this->asesor));

    expect($sinDestino)->toBe(EntregaInvalida::FACTURACION_REQUERIDA)
        ->and($inexistente)->toBe(EntregaInvalida::METODO_NO_DISPONIBLE)
        ->and($sinFisicos)->toBe(EntregaInvalida::SIN_PRODUCTOS_FISICOS)
        ->and(CartModel::query()->find($vacio->cart_id))->not->toBeNull();
});

test('los carriers de ByLopez nacen INACTIVOS: sin activarlos, Bagisto no los ofrece', function () {
    config(['carriers.pickup.active' => false, 'carriers.domicilio_local.active' => false, 'carriers.envio_nacional.active' => false]);

    $vista = f03d_conDomicilio($this);

    expect(array_intersect(f03d_codigos($vista), ['pickup', 'domicilio_local', 'envio_nacional']))->toBe([]);
});

test('EXTENSIBILIDAD: un carrier nuevo registrado en Bagisto aparece y se puede elegir sin tocar VentasAsesor', function () {
    config(['carriers.f03d_express' => [
        'code' => 'f03d_express', 'title' => 'Express prueba', 'active' => true, 'class' => F03dCarrierExpress::class,
    ]]);

    $vista = f03d_conDomicilio($this);
    $elegido = $this->entrega->seleccionarMetodo($this->borrador->id, 'f03d_express', $this->asesor);

    expect(f03d_codigos($vista))->toContain('f03d_express')
        ->and($elegido->metodoSeleccionado->precio)->toBe(15000.0)
        ->and($elegido->borrador->totales['shipping_amount'])->toBe(15000.0);
});

// ------------------------------------------------------------------ invalidacion

test('cambiar la direccion de envio INVALIDA el metodo elegido y recalcula las tarifas para el nuevo destino', function () {
    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);

    $vista = $this->entrega->establecerEnvio($this->borrador->id, OrigenDireccion::puntoRecogida(), $this->asesor);

    expect($vista->metodoSeleccionado)->toBeNull()
        ->and(CartModel::query()->find($this->borrador->cart_id)->shipping_method)->toBeNull()
        ->and(f03d_codigos($vista))->toContain('pickup')
        ->and(f03d_codigos($vista))->not->toContain('domicilio_local')
        ->and($vista->entregaCompleta)->toBeFalse();
});

test('agregar linea, cambiar cantidad y eliminar linea INVALIDAN el metodo (conservan direcciones); los totales ya no cuentan el envio', function (string $operacion) {
    $otra = $this->configurable->variants()->get()->firstWhere('id', '!=', $this->variante->id);
    f03b_stock($otra, 50);
    $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $otra->id, 10, $this->asesor);
    $otraItem = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $otra->id, 1, $this->asesor)->lineas[1]->cartItemId;

    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);

    match ($operacion) {
        'agregar' => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor),
        'cambiar_cantidad' => $this->lineas->cambiarCantidad($this->borrador->id, $this->itemId, 3, $this->asesor),
        'eliminar' => $this->lineas->eliminar($this->borrador->id, $otraItem, $this->asesor),
    };

    $vista = $this->entrega->vista($this->borrador->id);

    expect($vista->metodoSeleccionado)->toBeNull()
        ->and($vista->envio)->not->toBeNull()
        ->and($vista->facturacion)->not->toBeNull()
        ->and($vista->metodosDisponibles)->toBe([])
        ->and($vista->avisos)->toContain(VistaEntregaBorrador::AVISO_METODOS_POR_CALCULAR)
        ->and($vista->borrador->totales['shipping_amount'])->toBe(0.0)
        ->and($vista->entregaCompleta)->toBeFalse()
        ->and(f03d_codigos($this->entrega->calcularMetodos($this->borrador->id, $this->asesor)))->toContain('domicilio_local');
})->with(['agregar', 'cambiar_cantidad', 'eliminar']);

test('quitar la ULTIMA linea tambien invalida el metodo y deja el carrito vivo', function () {
    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);

    $this->lineas->eliminar($this->borrador->id, $this->itemId, $this->asesor);
    $carrito = CartModel::query()->find($this->borrador->cart_id);

    expect($carrito)->not->toBeNull()
        ->and($carrito->shipping_method)->toBeNull()
        ->and((float) $carrito->shipping_amount)->toBe(0.0)
        ->and($carrito->shipping_rates()->count())->toBe(0);
});

test('una SOLICITUD que no toca el Cart NO invalida el metodo', function () {
    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);
    $solicitudId = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id)->solicitudId;

    $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitudId, 80, $this->asesor);

    expect($this->entrega->vista($this->borrador->id)->metodoSeleccionado?->codigo)->toBe('domicilio_local');
});

test('cambiar el cliente del borrador limpia direcciones y metodo (03B, sin duplicar)', function () {
    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);

    $this->borradores->cambiarCliente($this->borrador->id, f03b_cliente($this->grupo)->id, $this->asesor);
    $vista = $this->entrega->vista($this->borrador->id);

    expect($vista->facturacion)->toBeNull()
        ->and($vista->envio)->toBeNull()
        ->and($vista->metodoSeleccionado)->toBeNull();
});

// ------------------------------------------------------------------ stock, propiedad y limites

test('STOCK: si una linea supera el stock actual, guardar entrega se rechaza con las lineas afectadas; nada cambia', function () {
    f03d_conDomicilio($this);
    ProductOrderedInventory::query()->updateOrCreate(['product_id' => $this->variante->id, 'channel_id' => core()->getDefaultChannel()->id], ['qty' => 49]);
    app(Inventory::class)->reindexBatch([$this->variante->fresh()]);

    try {
        $this->entrega->seleccionarMetodo($this->borrador->id, 'domicilio_local', $this->asesor);
        $error = null;
    } catch (EntregaInvalida $e) {
        $error = $e;
    }

    expect($error?->codigo)->toBe(EntregaInvalida::STOCK_INSUFICIENTE)
        ->and($error->lineasAfectadas)->toBe([[
            'cart_item_id' => $this->itemId, 'variante_id' => $this->variante->id, 'sku' => $this->variante->sku,
            'cantidad' => 2, 'stock_vendible' => 1,
        ]])
        ->and(CartModel::query()->find($this->borrador->cart_id)->shipping_method)->toBeNull()
        ->and($this->entrega->vista($this->borrador->id)->avisos)->toContain(VistaEntregaBorrador::AVISO_STOCK_INSUFICIENTE)
        ->and((int) CartItem::query()->find($this->itemId)->quantity)->toBe(2);
});

test('solo el propietario opera la entrega', function () {
    expect(f03d_codigo(fn () => $this->entrega->establecerFacturacion($this->borrador->id, OrigenDireccion::puntual(f03d_direccion()), f03b_admin())))
        ->toBe(Invalido::NO_ES_PROPIETARIO);
});

test('LIMITES: sin custom_price, sin pedido, sin Shipment y sin transportadora', function () {
    $pedidos = DB::table('orders')->count();
    $envios = DB::table('shipments')->count();

    f03d_conDomicilio($this);
    $this->entrega->seleccionarMetodo($this->borrador->id, 'envio_nacional', $this->asesor);

    expect(CartItem::query()->where('cart_id', $this->borrador->cart_id)->get()->every(fn ($i) => $i->custom_price === null))->toBeTrue()
        ->and(DB::table('orders')->count())->toBe($pedidos)
        ->and(DB::table('shipments')->count())->toBe($envios)
        ->and(CartModel::query()->find($this->borrador->cart_id)->shipping_method)->toBe('envio_nacional')
        ->and(Cart::getCart())->toBeNull();
});
