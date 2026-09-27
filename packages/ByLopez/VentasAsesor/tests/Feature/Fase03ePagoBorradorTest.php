<?php

use ByLopez\VentasAsesor\DataObjects\MetodoPagoDisponible;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\PagoBorradorInvalidoException as PagoInvalido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Models\CartPayment;
use Webkul\Payment\Payment\Payment as MetodoPagoAbstracto;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| Fase 03E — PagoBorradorService sobre el Cart real de Bagisto. Solo lpz_saas_testing.
|
| NO registra dinero: sin Transaction, sin Invoice, sin Order (D-50/D-53 quedan fuera).
*/

/** Metodo de pago de prueba: disponible en Bagisto pero fuera de la allowlist (03E-15). */
class F03eGatewayLatente extends MetodoPagoAbstracto
{
    protected $code = 'f03e_gateway_latente';

    public function getRedirectUrl() {}
}

function f03e_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido|PagoInvalido $e) {
        return $e->codigo;
    }

    return null;
}

/**
 * `lpz_saas_testing` no trae filas de sales.payment_methods.*: sin ellas, generate_invoice
 * usa el default de Bagisto (false para los dos metodos) y las pruebas no representarian
 * nada real. Se fija aqui la configuracion AUDITADA de produccion (Doc. 05 §32.5): asi las
 * pruebas de estadoInicialEsperado reflejan el D-48 real (moneytransfer aun sin desplegar).
 */
function f03e_configurarPago(string $codigo, string $valor): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => $codigo, 'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null],
        ['value' => $valor],
    );

    // CoreConfigRepository cachea getConfigData() (config/repository.php) y solo se limpia
    // via los eventos del repositorio Prettus; un UPDATE por SQL directo no los dispara. En
    // produccion esto lo limpia el panel (CoreConfigRepository::create -> parent::update);
    // aqui basta vaciar la cache para que la siguiente lectura vea el valor nuevo.
    Cache::flush();
}

beforeEach(function () {
    $this->borradores = app(BorradorVentaService::class);
    $this->solicitudes = app(SolicitudBorradorService::class);
    $this->lineas = app(LineasBorradorService::class);
    $this->pagos = app(PagoBorradorService::class);
    $this->asesor = f03b_admin();
    $this->otroAsesor = f03b_admin();
    $this->grupo = f03b_grupo('A');
    $this->cliente = f03b_cliente($this->grupo);

    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);

    // Configuracion auditada de produccion (Doc. 05 §32.5): cashondelivery pendiente,
    // moneytransfer aun confirmado al crear (D-48 sin desplegar). 03E-04/14/18b la ajustan.
    f03e_configurarPago('sales.payment_methods.cashondelivery.generate_invoice', '1');
    f03e_configurarPago('sales.payment_methods.cashondelivery.invoice_status', 'pending');
    f03e_configurarPago('sales.payment_methods.moneytransfer.generate_invoice', '1');
    f03e_configurarPago('sales.payment_methods.moneytransfer.invoice_status', 'paid');

    $this->borrador = $this->borradores->crear($this->cliente->id, $this->asesor, CanalVenta::WHATSAPP);
    $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->variante->id, 5, $this->asesor);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 5, $this->asesor);
});

// ------------------------------------------------------------------ 03E-01, 03E-09..12: sin dinero, sin efectos ajenos

test('03E-01 lista SOLO permitidos ∩ disponibles: contraentrega y transferencia, en ese orden por sort', function () {
    $vista = $this->pagos->vista($this->borrador->id);
    $codigos = collect($vista->metodosDisponibles)->pluck('codigo')->all();

    expect($codigos)->toBe(['cashondelivery', 'moneytransfer'])
        ->and($vista->metodoSeleccionado)->toBeNull();
});

test('03E-01b la allowlist reducida a un solo metodo limita lo ofrecido, aunque Bagisto tenga mas activos', function () {
    config(['ventasasesor.pagos.metodos_permitidos' => ['moneytransfer']]);

    $vista = $this->pagos->vista($this->borrador->id);

    expect(collect($vista->metodosDisponibles)->pluck('codigo')->all())->toBe(['moneytransfer']);
});

test('03E-02 metodo permitido y disponible se selecciona y persiste en cart_payment', function () {
    $vista = $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);

    expect($vista->metodoSeleccionado->codigo)->toBe('cashondelivery')
        ->and(CartPayment::query()->where('cart_id', $this->borrador->cart_id)->value('method'))->toBe('cashondelivery');
});

test('03E-03 metodo disponible en Bagisto pero fuera de la allowlist se rechaza (no persiste)', function () {
    config(['payment_methods.f03e_gateway_latente' => [
        'class' => F03eGatewayLatente::class, 'code' => 'f03e_gateway_latente',
        'title' => 'Gateway latente', 'active' => true, 'sort' => 99,
    ]]);

    expect(f03e_codigo(fn () => $this->pagos->seleccionar($this->borrador->id, 'f03e_gateway_latente', $this->asesor)))
        ->toBe(PagoInvalido::METODO_NO_DISPONIBLE)
        ->and(CartPayment::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0);
});

test('03E-04 metodo permitido pero NO disponible ahora (desactivado) se rechaza', function () {
    f03e_configurarPago('sales.payment_methods.moneytransfer.active', '0');

    expect(f03e_codigo(fn () => $this->pagos->seleccionar($this->borrador->id, 'moneytransfer', $this->asesor)))
        ->toBe(PagoInvalido::METODO_NO_DISPONIBLE);
});

test('03E-05 metodo inexistente en Bagisto se rechaza con codigo distinto al de "no disponible"', function () {
    expect(f03e_codigo(fn () => $this->pagos->seleccionar($this->borrador->id, 'xyz', $this->asesor)))
        ->toBe(PagoInvalido::METODO_INEXISTENTE)
        ->and(CartPayment::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0);
});

test('03E-06 cambio de metodo: contraentrega → transferencia, sin duplicar cart_payment', function () {
    $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);
    $vista = $this->pagos->seleccionar($this->borrador->id, 'moneytransfer', $this->asesor);

    expect($vista->metodoSeleccionado->codigo)->toBe('moneytransfer')
        ->and(CartPayment::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(1);
});

test('03E-07 persistencia correcta: cart_payment.method y method_title vienen de la configuracion de Bagisto', function () {
    $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);
    $pago = CartPayment::query()->where('cart_id', $this->borrador->cart_id)->first();

    expect($pago->method)->toBe('cashondelivery')
        ->and($pago->method_title)->toBe(core()->getConfigData('sales.payment_methods.cashondelivery.title'));
});

test('03E-08..10 seleccionar pago NO crea Transaction, Invoice ni Order', function () {
    $transacciones = DB::table('order_transactions')->count();
    $facturas = DB::table('invoices')->count();
    $pedidos = DB::table('orders')->count();

    $this->pagos->seleccionar($this->borrador->id, 'moneytransfer', $this->asesor);

    expect(DB::table('order_transactions')->count())->toBe($transacciones)
        ->and(DB::table('invoices')->count())->toBe($facturas)
        ->and(DB::table('orders')->count())->toBe($pedidos);
});

test('03E-11 seleccionar pago NO modifica precios de las lineas ni custom_price', function () {
    $antes = $this->lineas->listar($this->borrador->id)->lineas[0]->precioUnitario;

    $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);
    $linea = $this->lineas->listar($this->borrador->id)->lineas[0];

    expect($linea->precioUnitario)->toBe($antes)
        ->and(DB::table('cart_items')->where('cart_id', $this->borrador->cart_id)->pluck('custom_price')->filter()->all())->toBe([]);
});

test('03E-12 seleccionar pago NO modifica el stock vendible ni el indice de inventario', function () {
    $stockAntes = $this->variante->fresh()->getTypeInstance()->totalQuantity();

    $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);

    expect($this->variante->fresh()->getTypeInstance()->totalQuantity())->toBe($stockAntes);
});

// ------------------------------------------------------------------ cliente, estado, propiedad

test('03E-13 cambiar el cliente del borrador limpia el pago (comportamiento ya existente de 03B, sin duplicar)', function () {
    $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor);

    $this->borradores->cambiarCliente($this->borrador->id, f03b_cliente($this->grupo)->id, $this->asesor);

    expect($this->pagos->vista($this->borrador->id)->metodoSeleccionado)->toBeNull()
        ->and(CartPayment::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0);
});

test('03E-14 un metodo previamente seleccionado que deja de estar disponible se detecta como tal en la vista', function () {
    $this->pagos->seleccionar($this->borrador->id, 'moneytransfer', $this->asesor);

    f03e_configurarPago('sales.payment_methods.moneytransfer.active', '0');

    $vista = $this->pagos->vista($this->borrador->id);

    expect($vista->metodoSeleccionado->codigo)->toBe('moneytransfer')
        ->and(collect($vista->metodosDisponibles)->pluck('codigo'))->not->toContain('moneytransfer');
});

test('03E-15 un gateway de Bagisto activo (p. ej. con credenciales futuras) NO aparece si no esta en la allowlist', function () {
    config(['payment_methods.f03e_gateway_latente' => [
        'class' => F03eGatewayLatente::class, 'code' => 'f03e_gateway_latente',
        'title' => 'Gateway latente', 'active' => true, 'sort' => 99,
    ]]);

    $vista = $this->pagos->vista($this->borrador->id);

    expect(collect($vista->metodosDisponibles)->pluck('codigo'))->not->toContain('f03e_gateway_latente');
});

test('03E-16 borrador no editable (confirmando/confirmado/descartado) rechaza seleccionar pago', function () {
    BorradorVenta::query()->whereKey($this->borrador->id)->update(['estado' => 'confirmado']);

    expect(f03e_codigo(fn () => $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE);
});

test('03E-17 un asesor sin propiedad del borrador no puede seleccionar pago; tras reclamarlo si puede', function () {
    $sinPropiedad = f03e_codigo(fn () => $this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->otroAsesor));

    BorradorVenta::query()->whereKey($this->borrador->id)->update(['asignado_hasta' => now()->subMinute()]);
    $this->borradores->reclamar($this->borrador->id, $this->otroAsesor);

    expect($sinPropiedad)->toBe(Invalido::NO_ES_PROPIETARIO)
        ->and($this->pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->otroAsesor)->metodoSeleccionado->codigo)
        ->toBe('cashondelivery');
});

// ------------------------------------------------------------------ 03E-18: contrato para la venta rapida

test('03E-18 el DTO informa titulo y estado inicial esperado por metodo, derivado de la configuracion vigente (D-48 sin desplegar)', function () {
    $vista = $this->pagos->vista($this->borrador->id);
    $porCodigo = collect($vista->metodosDisponibles)->keyBy('codigo');

    expect($porCodigo['cashondelivery']->titulo)->toBe(core()->getConfigData('sales.payment_methods.cashondelivery.title'))
        ->and($porCodigo['cashondelivery']->estadoInicialEsperado)->toBe(MetodoPagoDisponible::ESTADO_PAGO_PENDIENTE)
        // moneytransfer en lpz_saas_testing sigue con invoice_status = paid (D-48 aun no desplegado):
        // el DTO NO miente sobre eso, solo informa lo que Bagisto haria HOY.
        ->and($porCodigo['moneytransfer']->estadoInicialEsperado)->toBe(MetodoPagoDisponible::ESTADO_PAGO_CONFIRMADO)
        ->and($vista->toArray()['metodos_disponibles'][0])->toBeArray();
});

test('03E-18b sin factura automatica (generate_invoice=0) el DTO lo distingue de pago pendiente/confirmado', function () {
    f03e_configurarPago('sales.payment_methods.cashondelivery.generate_invoice', '0');

    $vista = $this->pagos->vista($this->borrador->id);

    expect(collect($vista->metodosDisponibles)->firstWhere('codigo', 'cashondelivery')->estadoInicialEsperado)
        ->toBe(MetodoPagoDisponible::ESTADO_SIN_FACTURA_AUTOMATICA);
});
