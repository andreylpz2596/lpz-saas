<?php

use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\ConfirmacionVenta;
use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\DataObjects\ReconciliacionConfirmacion;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Enums\EstadoReconciliacionConfirmacion as Clasificacion;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\LineaBorradorInvalidaException;
use ByLopez\VentasAsesor\Exceptions\PagoBorradorInvalidoException;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as RechazoDelCerco;
use ByLopez\VentasAsesor\Listeners\AtribuirPedidoCreado;
use ByLopez\VentasAsesor\Listeners\CercoCreacionPedido;
use ByLopez\VentasAsesor\Listeners\ClasificarOperacionPedido;
use ByLopez\VentasAsesor\Listeners\EnlazarPedidoConBorrador;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\IncidenciaAtribucion;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\Support\Venta\CercoInventario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Models\ProductOrderedInventory;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| Fase 03F — ConfirmarVentaService sobre OrderRepository REAL de Bagisto. Solo
| lpz_saas_testing. La concurrencia con procesos reales esta en tests/Concurrencia.
|
| Los rechazos que ocurren DENTRO de OrderRepository se ejecutan con
| f03f_sinCommitPrematuro(): el core hace DB::commit() en el finally de cada intento y, sin
| esa defensa, confirmaria la transaccion de la prueba (Doc. 05 §37.1).
*/

/** Atribucion que falla con un contexto invalido (03F-18): el listener debe conservar el pedido. */
class F03fRegistrarAtribucionQueFalla extends RegistrarAtribucionService
{
    public function registrar(int $orderId, ContextoComercial $contexto, ?string $motivo = null): AtribucionPedido
    {
        // Falla la atribucion normal; el camino de fallo_tecnico (que tambien pasa por aqui) sigue.
        if (! $contexto->esFalloTecnico()) {
            throw new AtribucionInvalidaException('Fallo simulado de atribucion (03F-18).');
        }

        return parent::registrar($orderId, $contexto, $motivo);
    }
}

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->confirmacion = app(ConfirmarVentaService::class);
    $this->asesor = f03b_admin();
    $this->grupo = f03b_grupo('A');
    $this->cliente = f03b_cliente($this->grupo);

    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);

    // 03F-25: solicitud 100, carrito 30.
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 30, 100]]);
    $this->cartId = $this->borrador->cart_id;
});

function f03f_confirmar($test, ?float $total = null, ?SeleccionPropiedadComercial $seleccion = null, $actor = null): ConfirmacionVenta
{
    return f03f_sinCommitPrematuro(fn () => $test->confirmacion->confirmar(
        $test->borrador->id, $actor ?? $test->asesor, $total ?? f03f_total($test->borrador), $seleccion));
}

/** Rechazo de dominio de confirmar(), con total esperado = el que ve el asesor ahora. */
function f03f_rechazo($test, ?float $total = null, $actor = null): Rechazo|BorradorInvalido|RechazoDelCerco|null
{
    $total ??= f03f_total($test->borrador);

    return f03f_error(fn () => $test->confirmacion->confirmar($test->borrador->id, $actor ?? $test->asesor, $total));
}

/** Tras un rechazo antes del pedido: activo, sin token ni lease, sin pedido, carrito intacto. */
function f03f_esperarLiberado($test): void
{
    $b = BorradorVenta::query()->find($test->borrador->id);

    expect($b->estado)->toBe(Estado::ACTIVO)
        ->and($b->order_id)->toBeNull()
        ->and($b->getRawOriginal('confirmation_token'))->toBeNull()
        ->and($b->confirmacion_iniciada_en)->toBeNull()
        ->and($b->cart_id)->toBe($test->cartId)
        ->and(Order::query()->where('cart_id', $test->cartId)->count())->toBe(0);
}

function f03f_estado($test, array $cambios): void
{
    DB::table('bylopez_va_borradores_venta')->where('id', $test->borrador->id)->update($cambios);
}

// ------------------------------------------------------------------ 03F-01, 19, 21, 25, 27, 31, 32, 33

test('03F-01 confirmacion normal: pedido real, borrador confirmado y enlazado, token limpio, DTO de dominio', function () {
    $total = f03f_total($this->borrador);
    $r = f03f_confirmar($this, $total);
    $b = BorradorVenta::query()->find($this->borrador->id);
    $pedido = Order::query()->find($r->orderId);

    expect($r->yaExistia)->toBeFalse()
        ->and($r->borradorId)->toBe($this->borrador->id)
        ->and($r->incrementId)->toBe((string) $pedido->increment_id)
        ->and($r->cliente['customer_id'])->toBe($this->cliente->id)
        ->and($r->total)->toBe($total)
        ->and($r->moneda)->toBe((string) $pedido->order_currency_code)
        // Recogida (pickup de bagisto-eu/pickup): la extension quita contraentrega → transferencia.
        ->and($r->metodoPago['codigo'])->toBe('moneytransfer')
        ->and($r->entrega['codigo'])->toBe('pickup')
        ->and($b->estado)->toBe(Estado::CONFIRMADO)
        ->and($b->order_id)->toBe($pedido->id)
        ->and($b->getRawOriginal('confirmation_token'))->toBeNull()
        ->and($b->confirmacion_iniciada_en)->toBeNull()
        ->and($pedido->status)->not->toBe('canceled') // el estado lo decide la configuracion de Bagisto
        ->and((int) $pedido->customer_id)->toBe($this->cliente->id)
        ->and((float) $pedido->grand_total)->toBe($total);
});

test('03F-19/21 contra entrega: factura automatica PENDIENTE, estado de pago derivado pendiente y saldo = total', function () {
    // Contraentrega no existe con recogida (la extension la quita): envio nacional, tarifa 0.
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 2]], 'envio_nacional', 'cashondelivery');

    $r = f03f_confirmar($this);
    $factura = Invoice::query()->where('order_id', $r->orderId)->sole();

    expect($r->factura)->toBe(['invoice_id' => $factura->id, 'estado' => 'pending'])
        ->and($r->estadoPago)->toBe(ConfirmacionVenta::PAGO_PENDIENTE)
        ->and($r->saldo)->toBe($r->total);
});

test('03F-20 transferencia con la configuracion ACTUAL (D-48 sin desplegar): factura paid sin transaccion → «revisar», nunca cobro verificado', function () {
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 2]], 'pickup', 'moneytransfer');

    $r = f03f_confirmar($this);

    expect($r->metodoPago['codigo'])->toBe('moneytransfer')
        ->and($r->factura['estado'])->toBe('paid')
        ->and($r->estadoPago)->toBe(ConfirmacionVenta::PAGO_REVISAR)
        ->and($r->saldo)->toBe(0.0)
        ->and(DB::table('order_transactions')->where('order_id', $r->orderId)->count())->toBe(0);
});

test('03F-25 solicitud 100, carrito 30 → pedido de 30; la solicitud sigue ligada al borrador y no se crea otro pedido', function () {
    $r = f03f_confirmar($this);

    $item = OrderItem::query()->where('order_id', $r->orderId)->whereNull('parent_id')->sole();

    expect((int) $item->qty_ordered)->toBe(30)
        ->and(Order::query()->where('customer_id', $this->cliente->id)->count())->toBe(1)
        ->and(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->sole()->cantidad_solicitada)->toBe(100);
});

test('03F-27 el Cart se borra como en el panel: cart_id NULL (FK), orders.cart_id conservado; el borrador conserva su historia', function () {
    $r = f03f_confirmar($this);
    $b = BorradorVenta::query()->find($this->borrador->id);

    expect(CartModel::query()->find($this->cartId))->toBeNull()
        ->and($b->cart_id)->toBeNull()
        ->and((int) Order::query()->find($r->orderId)->cart_id)->toBe($this->cartId)
        ->and($b->estado)->toBe(Estado::CONFIRMADO)
        ->and($b->order_id)->toBe($r->orderId)
        ->and($b->propietario_admin_id)->toBe($this->asesor->id)
        ->and($b->creado_por_admin_id)->toBe($this->asesor->id)
        ->and($b->canal_venta->value)->toBe('whatsapp')
        ->and($b->mecanismo_creacion->value)->toBe('ventas_asesor')
        ->and($b->created_at)->not->toBeNull()
        ->and(SolicitudBorrador::query()->where('borrador_id', $b->id)->count())->toBe(1);
});

test('03F-31/32/33 sin Transaction de dinero, sin custom_price, precio de Bagisto; reserva NATIVA sin descuento de stock fisico ni Shipment', function () {
    // Producto simple: la reserva nativa de Bagisto funciona (ver la prueba LIMITE para configurables).
    $simple = f03f_simple(10000.0);
    f03b_stock($simple, 50);
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$simple, $simple, 30, 100]]);

    expect(CartItem::query()->where('cart_id', $this->borrador->cart_id)->whereNotNull('custom_price')->count())->toBe(0);

    $r = f03f_confirmar($this);
    $item = OrderItem::query()->where('order_id', $r->orderId)->whereNull('parent_id')->sole();

    expect(DB::table('order_transactions')->where('order_id', $r->orderId)->count())->toBe(0)
        ->and((float) $item->price)->toBe(10000.0)
        ->and((int) DB::table('product_inventories')->where('product_id', $simple->id)->sum('qty'))->toBe(50)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(30)
        ->and(DB::table('shipments')->where('order_id', $r->orderId)->count())->toBe(0);
});

test('LIMITE Bagisto 2.4.9 (preexistente, no causado por 03F): la variante de un configurable se reserva con 0; el producto simple, con su cantidad', function () {
    // Configurable (fixture base: 30 de 50). Bagisto guarda el hijo con quantity 0 y reserva 0.
    $r = f03f_confirmar($this);
    $hijo = OrderItem::query()->where('order_id', $r->orderId)->whereNotNull('parent_id')->sole();

    expect((int) $hijo->qty_ordered)->toBe(0)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $this->variante->id)->sum('qty'))->toBe(0);

    // Consecuencia: otro borrador de 30 sobre las MISMAS 50 unidades tambien confirma (60 > 50).
    // El cerco serializa, pero no puede ver una reserva que Bagisto no hizo. Decision pendiente (Doc. 05 §39).
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 30]]);
    $segundo = f03f_confirmar($this);

    // Simple: 30 de 50 reserva 30; el vendible baja a 20 y Bagisto ya no deja llevar otras 30.
    $simple = f03f_simple();
    f03b_stock($simple, 50);
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$simple, $simple, 30]]);
    f03f_confirmar($this);

    $otro = app(BorradorVentaService::class)->crear($this->cliente->id, $this->asesor, CanalVenta::WHATSAPP);
    app(SolicitudBorradorService::class)->crear($otro->id, $simple->id, $simple->id, 30, $this->asesor);

    expect($segundo->yaExistia)->toBeFalse()
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(30)
        ->and(fn () => app(LineasBorradorService::class)->agregar($otro->id, $simple->id, $simple->id, 30, $this->asesor))
        ->toThrow(LineaBorradorInvalidaException::class);
});

// ------------------------------------------------------------------ idempotencia: 03F-02, 03, 04, 46

test('03F-02/03 doble clic y reintento: el segundo llamado devuelve el MISMO pedido con ya_existia = true', function () {
    $primero = f03f_confirmar($this);
    $segundo = f03f_confirmar($this, 1.0); // payload distinto: los snapshots del pedido son finales

    expect($segundo->yaExistia)->toBeTrue()
        ->and($segundo->orderId)->toBe($primero->orderId)
        ->and(Order::query()->where('cart_id', $this->cartId)->count())->toBe(1)
        ->and(Invoice::query()->where('order_id', $primero->orderId)->count())->toBe(1);
});

test('03F-04/46 respuesta perdida: el pedido hizo commit y el cliente no recibio nada → el reintento devuelve el mismo pedido', function () {
    $total = f03f_total($this->borrador);
    f03f_confirmar($this, $total); // la respuesta se "pierde"
    $orderId = BorradorVenta::query()->find($this->borrador->id)->order_id;

    $reintento = f03f_confirmar($this, $total);

    expect($reintento->orderId)->toBe($orderId)
        ->and($reintento->yaExistia)->toBeTrue()
        ->and(Order::query()->where('customer_id', $this->cliente->id)->count())->toBe(1);
});

test('03F-05 segundo request mientras el primero tiene el lease: confirmacion_en_proceso, sin tocar el intento vigente', function () {
    f03f_estado($this, ['estado' => 'confirmando', 'confirmation_token' => 'token-vigente', 'confirmacion_iniciada_en' => now()]);

    $e = f03f_rechazo($this);
    $b = BorradorVenta::query()->find($this->borrador->id);

    expect($e->codigo)->toBe(Rechazo::CONFIRMACION_EN_PROCESO)
        ->and($b->estado)->toBe(Estado::CONFIRMANDO)
        ->and($b->getRawOriginal('confirmation_token'))->toBe('token-vigente')
        ->and(Order::query()->where('cart_id', $this->cartId)->count())->toBe(0);
});

// ------------------------------------------------------------------ lease y recuperacion: 03F-30

test('03F-30 lease vencido y sin pedido: recuperable; confirmar toma con token NUEVO y crea un solo pedido', function () {
    config(['ventasasesor.confirmacion.lease_segundos' => 60]);
    f03f_estado($this, ['estado' => 'confirmando', 'confirmation_token' => 'token-viejo', 'confirmacion_iniciada_en' => now()->subSeconds(61)]);

    $antes = $this->confirmacion->reconciliar($this->borrador->id);
    $r = f03f_confirmar($this);

    expect($antes->estado)->toBe(Clasificacion::RECUPERABLE)
        ->and($r->yaExistia)->toBeFalse()
        ->and(BorradorVenta::query()->find($this->borrador->id)->estado)->toBe(Estado::CONFIRMADO)
        ->and(Order::query()->where('cart_id', $this->cartId)->count())->toBe(1);
});

test('lease configurable: el mismo intento es «en proceso» con lease largo y «recuperable» con lease corto', function () {
    f03f_estado($this, ['estado' => 'confirmando', 'confirmation_token' => 't', 'confirmacion_iniciada_en' => now()->subSeconds(30)]);

    config(['ventasasesor.confirmacion.lease_segundos' => 300]);
    $largo = $this->confirmacion->reconciliar($this->borrador->id)->estado;

    config(['ventasasesor.confirmacion.lease_segundos' => 10]);
    $corto = $this->confirmacion->reconciliar($this->borrador->id)->estado;

    expect($largo)->toBe(Clasificacion::CONFIRMACION_EN_PROCESO)
        ->and($corto)->toBe(Clasificacion::RECUPERABLE);
});

// ------------------------------------------------------------------ reconciliacion A-G: 03F-43

test('reconciliacion A: confirmado + order_id → ya_confirmado con ese pedido', function () {
    $r = f03f_confirmar($this);
    $rec = $this->confirmacion->reconciliar($this->borrador->id);

    expect($rec->estado)->toBe(Clasificacion::YA_CONFIRMADO)->and($rec->orderId)->toBe($r->orderId);
});

test('reconciliacion B: confirmando + order_id → inconsistente; confirmar no lo repara', function () {
    $ajeno = Order::factory()->create(['cart_id' => 0]);
    f03f_estado($this, ['estado' => 'confirmando', 'order_id' => $ajeno->id, 'confirmacion_iniciada_en' => now()->subDay()]);

    $e = f03f_rechazo($this);

    expect($this->confirmacion->reconciliar($this->borrador->id)->motivo)->toBe(ReconciliacionConfirmacion::MOTIVO_CONFIRMANDO_CON_PEDIDO)
        ->and($e->codigo)->toBe(Rechazo::CONFIRMACION_INCONSISTENTE)
        ->and(BorradorVenta::query()->find($this->borrador->id)->order_id)->toBe($ajeno->id);
});

test('reconciliacion C/D (03F-43): uno o varios pedidos con el cart_id del borrador → inconsistente (grave con 2+); nunca se enlaza ni se elige', function (string $estado) {
    f03f_estado($this, ['estado' => $estado, 'confirmation_token' => $estado === 'confirmando' ? 't' : null, 'confirmacion_iniciada_en' => $estado === 'confirmando' ? now()->subDay() : null]);
    Order::factory()->create(['cart_id' => $this->cartId]);

    $uno = $this->confirmacion->reconciliar($this->borrador->id);
    $eUno = f03f_rechazo($this);

    Order::factory()->create(['cart_id' => $this->cartId]);
    $dos = $this->confirmacion->reconciliar($this->borrador->id);
    $eDos = f03f_rechazo($this);

    expect($uno->estado)->toBe(Clasificacion::INCONSISTENTE)
        ->and($uno->motivo)->toBe(ReconciliacionConfirmacion::MOTIVO_PEDIDO_DEL_CARRITO_SIN_ENLACE)
        ->and($uno->esGrave())->toBeFalse()
        ->and($dos->motivo)->toBe(ReconciliacionConfirmacion::MOTIVO_PEDIDOS_DUPLICADOS_DEL_CARRITO)
        ->and($dos->esGrave())->toBeTrue()
        ->and($dos->pedidosDelCarrito)->toBe(2)
        ->and($eUno->codigo)->toBe(Rechazo::CONFIRMACION_INCONSISTENTE)
        ->and($eDos->detalles['grave'])->toBeTrue()
        ->and(BorradorVenta::query()->find($this->borrador->id)->order_id)->toBeNull()
        ->and(BorradorVenta::query()->find($this->borrador->id)->estado->value)->toBe($estado);
})->with(['confirmando', 'activo']);

test('reconciliacion E/F: sin pedido, lease vigente → en proceso; vencido → recuperable', function () {
    config(['ventasasesor.confirmacion.lease_segundos' => 120]);

    f03f_estado($this, ['estado' => 'confirmando', 'confirmation_token' => 't', 'confirmacion_iniciada_en' => now()]);
    $vigente = $this->confirmacion->reconciliar($this->borrador->id)->estado;

    f03f_estado($this, ['confirmacion_iniciada_en' => now()->subSeconds(121)]);
    $vencido = $this->confirmacion->reconciliar($this->borrador->id)->estado;

    expect($vigente)->toBe(Clasificacion::CONFIRMACION_EN_PROCESO)->and($vencido)->toBe(Clasificacion::RECUPERABLE);
});

test('reconciliacion G: activo o confirmando sin carrito (borrado fuera del flujo) → inconsistente', function (string $estado) {
    f03f_estado($this, ['estado' => $estado, 'confirmacion_iniciada_en' => now()->subDay()]);
    CartModel::query()->whereKey($this->cartId)->delete();

    $rec = $this->confirmacion->reconciliar($this->borrador->id);

    expect($rec->estado)->toBe(Clasificacion::INCONSISTENTE)
        ->and($rec->motivo)->toBe(ReconciliacionConfirmacion::MOTIVO_SIN_CARRITO)
        ->and(f03f_rechazo($this, 1.0)->codigo)->toBe(Rechazo::CONFIRMACION_INCONSISTENTE);
})->with(['activo', 'confirmando']);

test('descartado → borrador_no_confirmable; sin permiso o no propietario → errores de 03B', function () {
    $otro = f03b_admin();
    $sinPermiso = f03b_admin('custom', ['ventas_asesor']);

    expect(f03f_rechazo($this, null, $otro)->codigo)->toBe(BorradorInvalido::NO_ES_PROPIETARIO)
        ->and(f03f_rechazo($this, null, $sinPermiso)->codigo)->toBe(BorradorInvalido::SIN_PERMISO);

    app(BorradorVentaService::class)->descartar($this->borrador->id, $this->asesor);

    expect(f03f_rechazo($this, 1.0)->codigo)->toBe(Rechazo::BORRADOR_NO_CONFIRMABLE);
});

// ------------------------------------------------------------------ cliente y grupo: 03F-08, 09

test('03F-08 cliente suspendido o inactivo → cliente_no_confirmable; el borrador vuelve a activo', function (array $cambio, string $motivo) {
    $this->cliente->update($cambio);

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::CLIENTE_NO_CONFIRMABLE)->and($e->detalles['motivo'])->toBe($motivo);
    f03f_esperarLiberado($this);
})->with([
    'suspendido' => [['is_suspended' => 1], 'cliente_suspendido'],
    'inactivo' => [['status' => 0], 'cliente_inactivo'],
]);

test('03F-09 grupo cambiado: a otro grupo comercial reprecia (RT-03) → totales_cambiaron; al invitado → cliente_no_confirmable', function () {
    $mayorista = f03b_grupo('M');
    f03b_descuentoDeGrupo($this->variante, $mayorista, 10, $this->configurable);
    $this->cliente->update(['customer_group_id' => $mayorista->id]);

    $cambio = f03f_rechazo($this);

    $invitado = CustomerGroup::query()->where('code', 'guest')->value('id');
    $this->cliente->update(['customer_group_id' => $invitado]);
    $noApto = f03f_rechazo($this);

    expect($cambio->codigo)->toBe(Rechazo::TOTALES_CAMBIARON)
        ->and($cambio->detalles['total_nuevo'])->toBe(270000.0)
        ->and($noApto->codigo)->toBe(Rechazo::CLIENTE_NO_CONFIRMABLE)
        ->and($noApto->detalles['motivo'])->toBe('grupo_no_apto');
    f03f_esperarLiberado($this);
});

// ------------------------------------------------------------------ total_esperado: 03F-10, 44

test('03F-10/44 precio cambiado → totales_cambiaron con el total nuevo; activo sin token; al aceptar el total nuevo confirma con el precio ACTUAL', function () {
    $totalVisto = f03f_total($this->borrador);
    f03b_descuentoDeGrupo($this->variante, $this->grupo, 10, $this->configurable);

    $e = f03f_rechazo($this, $totalVisto);

    expect($e->codigo)->toBe(Rechazo::TOTALES_CAMBIARON)
        ->and($e->detalles['total_esperado'])->toBe($totalVisto)
        ->and($e->detalles['total_nuevo'])->toBe(270000.0)
        ->and($e->detalles['totales']['grand_total'])->toBe(270000.0);
    f03f_esperarLiberado($this);

    $r = f03f_confirmar($this, $e->detalles['total_nuevo']);

    expect($r->total)->toBe(270000.0)
        ->and((float) OrderItem::query()->where('order_id', $r->orderId)->whereNull('parent_id')->value('price'))->toBe(9000.0);
});

test('total_esperado invalido se rechaza antes de tocar el borrador', function () {
    expect(f03f_rechazo($this, -1.0)->detalles['motivo'])->toBe('total_esperado_invalido')
        ->and(f03f_error(fn () => $this->confirmacion->confirmar($this->borrador->id, $this->asesor, 'mil'))->codigo)->toBe(Rechazo::BORRADOR_NO_CONFIRMABLE)
        ->and(BorradorVenta::query()->find($this->borrador->id)->estado)->toBe(Estado::ACTIVO);
});

// ------------------------------------------------------------------ entrega: 03F-11, 22, 23, 24

test('03F-11 tarifa de envio cambiada → totales_cambiaron; metodo que deja de existir → entrega_invalida', function () {
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 1]], 'domicilio_local');
    $this->cartId = $this->borrador->cart_id;
    $visto = f03f_total($this->borrador);

    f03f_activarCarriers('12000');
    $tarifa = f03f_rechazo($this, $visto);

    config(['carriers.domicilio_local.active' => false]);
    $metodo = f03f_rechazo($this, $visto);

    expect($tarifa->codigo)->toBe(Rechazo::TOTALES_CAMBIARON)
        ->and($tarifa->detalles['total_nuevo'])->toBe(round($visto + 4000, 2))
        ->and($metodo->codigo)->toBe(Rechazo::ENTREGA_INVALIDA)
        ->and($metodo->detalles['motivo'])->toBe('metodo_entrega_no_disponible');
    f03f_esperarLiberado($this);
});

test('03F-22 recogida: el pedido lleva la direccion REAL de la bodega y tarifa 0', function () {
    $bodega = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first();

    $r = f03f_confirmar($this);
    $pedido = Order::query()->find($r->orderId);

    expect($pedido->shipping_method)->toBe('pickup')
        ->and((float) $pedido->shipping_amount)->toBe(0.0)
        ->and($pedido->shipping_address->address)->toBe($bodega->street)
        ->and($pedido->shipping_address->city)->toBe($bodega->city);
});

test('03F-23/24 domicilio local (tarifa de Bagisto) y envio nacional (flete por pagar = 0)', function (string $metodo, float $envio) {
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 1]], $metodo);

    $r = f03f_confirmar($this);
    $pedido = Order::query()->find($r->orderId);

    expect($pedido->shipping_method)->toBe($metodo)
        ->and((float) $pedido->shipping_amount)->toBe($envio)
        ->and($pedido->shipping_address->address)->toBe('Carrera 9 # 8-7');
})->with([['domicilio_local', 8000.0], ['envio_nacional', 0.0]]);

test('envio gratis por CartRule: el metodo sigue siendo domicilio y el envio del pedido es 0 (no se convierte en metodo)', function () {
    $reglaId = DB::table('cart_rules')->insertGetId([
        'name' => 'F03F envio gratis', 'status' => 1, 'coupon_type' => 0, 'condition_type' => 1,
        'action_type' => 'by_percent', 'discount_amount' => 0, 'free_shipping' => 1,
    ]);
    DB::table('cart_rule_customer_groups')->insert(['cart_rule_id' => $reglaId, 'customer_group_id' => $this->grupo->id]);
    DB::table('cart_rule_channels')->insert(['cart_rule_id' => $reglaId, 'channel_id' => core()->getDefaultChannel()->id]);
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 1]], 'domicilio_local');

    $pedido = Order::query()->find(f03f_confirmar($this)->orderId);

    expect($pedido->shipping_method)->toBe('domicilio_local')
        ->and((float) $pedido->shipping_amount - (float) $pedido->shipping_discount_amount)->toBe(0.0)
        ->and(explode(',', (string) $pedido->applied_cart_rule_ids))->toContain((string) $reglaId);
});

// ------------------------------------------------------------------ pago: 03F-12

test('03F-12 el metodo de pago deja de estar disponible (Bagisto o allowlist) → pago_invalido; nunca Transaction', function () {
    // El pago guardado debe ser contraentrega, que no existe con recogida: envio nacional.
    $this->borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$this->configurable, $this->variante, 1]], 'envio_nacional', 'cashondelivery');
    $this->cartId = $this->borrador->cart_id;

    config(['ventasasesor.pagos.metodos_permitidos' => ['moneytransfer']]);
    $allowlist = f03f_rechazo($this);

    config(['ventasasesor.pagos.metodos_permitidos' => ['cashondelivery', 'moneytransfer']]);
    DB::table('core_config')->updateOrInsert(
        ['code' => 'sales.payment_methods.cashondelivery.active', 'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null],
        ['value' => '0'],
    );
    Cache::flush();
    $bagisto = f03f_rechazo($this);

    expect($allowlist->codigo)->toBe(Rechazo::PAGO_INVALIDO)
        ->and($allowlist->detalles['motivo'])->toBe('metodo_pago_no_disponible')
        ->and($bagisto->codigo)->toBe(Rechazo::PAGO_INVALIDO)
        ->and(DB::table('order_transactions')->count())->toBe(DB::table('order_transactions')->count());
    f03f_esperarLiberado($this);
});

test('recogida (pickup de bagisto-eu/pickup): la extension quita contraentrega; solo se ofrece transferencia y contraentrega se rechaza', function () {
    $pagos = app(PagoBorradorService::class);
    $ofrecidos = collect($pagos->vista($this->borrador->id)->metodosDisponibles)->pluck('codigo')->all();

    expect($this->borrador->fresh()->carrito->shipping_method)->toBe(PuntosRecogida::METODO)
        ->and($ofrecidos)->toBe(['moneytransfer'])
        ->and(fn () => $pagos->seleccionar($this->borrador->id, 'cashondelivery', $this->asesor))
        ->toThrow(fn (PagoBorradorInvalidoException $e) => expect($e->codigo)->toBe(PagoBorradorInvalidoException::METODO_NO_DISPONIBLE));
});

// ------------------------------------------------------------------ carrito y variantes: 03F-13, 14, 15

test('03F-13 carrito vacio → carrito_vacio', function () {
    $linea = app(LineasBorradorService::class)->listar($this->borrador->id)->lineas[0];
    app(LineasBorradorService::class)->eliminar($this->borrador->id, $linea->cartItemId, $this->asesor);

    expect(f03f_rechazo($this, 0.0)->codigo)->toBe(Rechazo::CARRITO_VACIO);
    f03f_esperarLiberado($this);
});

test('03F-14 carrito manipulado fuera del modulo → carrito_inconsistente (custom_price, excede solicitud, carrito activo)', function (Closure $manipular, string $motivo) {
    $manipular($this);

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::CARRITO_INCONSISTENTE)->and($e->detalles['motivo'])->toBe($motivo);
    f03f_esperarLiberado($this);
})->with([
    'custom_price' => [fn ($t) => CartItem::query()->where('cart_id', $t->cartId)->whereNull('parent_id')->update(['custom_price' => 1]), 'custom_price'],
    'excede solicitud' => [fn ($t) => SolicitudBorrador::query()->where('borrador_id', $t->borrador->id)->update(['cantidad_solicitada' => 10]), 'linea_excede_solicitud'],
    'sin solicitud' => [fn ($t) => SolicitudBorrador::query()->where('borrador_id', $t->borrador->id)->delete(), 'linea_sin_solicitud'],
    'carrito activo' => [fn ($t) => CartModel::query()->whereKey($t->cartId)->update(['is_active' => 1]), 'carrito_activo'],
]);

test('03F-15 variante desactivada → variante_no_vendible; validateItems NO la quita en silencio', function () {
    DB::table('product_attribute_values')->where('product_id', $this->variante->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'status')->value('id'))->update(['boolean_value' => 0]);

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::VARIANTE_NO_VENDIBLE)
        ->and($e->detalles['variante_id'])->toBe($this->variante->id)
        ->and(CartItem::query()->where('cart_id', $this->cartId)->whereNull('parent_id')->value('quantity'))->toBe(30);
    f03f_esperarLiberado($this);
});

// ------------------------------------------------------------------ stock: 03F-06 y cerco (D-54)

test('03F-06 el stock baja antes de confirmar → stock_insuficiente (revalidacion) con la variante; nada se reduce', function () {
    f03b_stock($this->variante, 10);

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::STOCK_INSUFICIENTE)
        ->and($e->detalles['fase'])->toBe('revalidacion')
        ->and($e->detalles['variantes'][0])->toBe(['variante_id' => $this->variante->id, 'cantidad' => 30, 'stock_vendible' => 10])
        ->and(CartItem::query()->where('cart_id', $this->cartId)->whereNull('parent_id')->value('quantity'))->toBe(30);
    f03f_esperarLiberado($this);
});

test('D-54 el CERCO revalida con las tablas de Bagisto bajo bloqueo aunque el indice diga otra cosa; rollback total y reintentos nativos', function () {
    // Indice sin reindexar (dice 50) y stock real 10: la validacion temprana pasa, el cerco no.
    DB::table('product_inventories')->where('product_id', $this->variante->id)->update(['qty' => 10]);
    $intentos = 0;
    Event::listen('checkout.order.save.before', function () use (&$intentos) {
        $intentos++;
    });
    $reservadoAntes = (int) ProductOrderedInventory::query()->where('product_id', $this->variante->id)->sum('qty');

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::STOCK_INSUFICIENTE)
        ->and($e->detalles['fase'])->toBe('cerco')
        ->and($e->detalles['variantes'][0])->toBe(['variante_id' => $this->variante->id, 'cantidad' => 30, 'stock_vendible' => 10])
        ->and($intentos)->toBe(0) // el cerco lanza antes: el listener posterior nunca corre
        ->and((int) ProductOrderedInventory::query()->where('product_id', $this->variante->id)->sum('qty'))->toBe($reservadoAntes)
        ->and(Invoice::query()->count())->toBe(Invoice::query()->count());
    f03f_esperarLiberado($this);
});

// ------------------------------------------------------------------ atribucion: 03F-16, 17, 18

test('03F-16 asesor (custom + permiso marcador) sin seleccion → se autoasigna; el contexto explicito llega al listener', function () {
    $asesor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor', BorradorVentaService::PERMISO]);
    $this->borrador = f03f_borradorListo($this->cliente->id, $asesor, [[$this->configurable, $this->variante, 1]]);

    $r = f03f_confirmar($this, null, null, $asesor);
    $a = AtribucionPedido::query()->where('order_id', $r->orderId)->sole();

    expect($a->commercial_owner_type->value)->toBe('advisor')
        ->and((int) $a->advisor_id)->toBe($asesor->id)
        ->and((int) $a->created_by_admin_id)->toBe($asesor->id)
        ->and($a->creation_source->value)->toBe('ventas_asesor')
        ->and($a->sales_channel->value)->toBe('whatsapp')
        ->and((int) $a->cart_id)->toBe((int) Order::query()->find($r->orderId)->cart_id)
        ->and($a->attribution_status->value)->toBe('completa');
});

test('03F-17 atribucion segun seleccion y permisos: empresa, sin asignar; admin total sin seleccion → sin asignar', function (?SeleccionPropiedadComercial $seleccion, string $propiedad) {
    $r = f03f_confirmar($this, null, $seleccion);

    expect(AtribucionPedido::query()->where('order_id', $r->orderId)->sole()->commercial_owner_type->value)->toBe($propiedad);
})->with([
    'empresa' => [fn () => SeleccionPropiedadComercial::ventaDeEmpresa(), 'company'],
    'sin asignar' => [fn () => SeleccionPropiedadComercial::dejarSinAsignar(), 'unassigned'],
    'sin seleccion' => [null, 'unassigned'],
]);

test('03F-17b asignar a otro asesor sin permiso de asignar → se rechaza ANTES del pedido y el borrador vuelve a activo', function () {
    $otro = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor']);
    $creador = f03b_admin('custom', ['ventas_asesor', BorradorVentaService::PERMISO]);
    $this->borrador = f03f_borradorListo($this->cliente->id, $creador, [[$this->configurable, $this->variante, 1]]);
    $this->cartId = $this->borrador->cart_id;

    expect(fn () => f03f_confirmar($this, null, SeleccionPropiedadComercial::asignarAsesor($otro), $creador))
        ->toThrow(AtribucionInvalidaException::class);
    f03f_esperarLiberado($this);
});

test('03F-18 (02B1A) si la atribucion falla, el pedido SE CONSERVA con fallo_tecnico e incidencia; el borrador queda confirmado', function () {
    app()->bind(RegistrarAtribucionService::class, F03fRegistrarAtribucionQueFalla::class);

    $r = f03f_confirmar($this);

    expect(Order::query()->find($r->orderId))->not->toBeNull()
        ->and(BorradorVenta::query()->find($this->borrador->id)->order_id)->toBe($r->orderId)
        ->and(AtribucionPedido::query()->where('order_id', $r->orderId)->sole()->attribution_status->value)->toBe('fallo_tecnico')
        ->and(IncidenciaAtribucion::query()->where('order_id', $r->orderId)->sole()->codigo->value)->toBe('contexto_invalido');
});

// ------------------------------------------------------------------ transaccion y enlace: 03F-26, 29, 34, 36, 45

test('03F-26 el enlace ocurre DENTRO de la transaccion del pedido (visible en save.after, antes del commit)', function () {
    $visto = null;
    Event::listen('checkout.order.save.after', function ($pedido) use (&$visto) {
        $visto = ['nivel' => DB::transactionLevel(), 'fila' => DB::table('bylopez_va_borradores_venta')->where('id', $this->borrador->id)->first(['estado', 'order_id', 'confirmation_token']), 'order_id' => $pedido->id];
    });
    $base = DB::transactionLevel();

    f03f_confirmar($this);

    expect($visto['nivel'])->toBeGreaterThan($base)
        ->and($visto['fila']->estado)->toBe('confirmado')
        ->and((int) $visto['fila']->order_id)->toBe((int) $visto['order_id'])
        ->and($visto['fila']->confirmation_token)->toBeNull();
});

test('03F-29/31 fallo DURANTE el pedido: rollback nativo en cada reintento, sin pedido, reserva ni factura; vuelve a activo', function () {
    $intentos = 0;
    Event::listen('checkout.order.save.after', function () use (&$intentos) {
        $intentos++;

        throw new RuntimeException('Fallo simulado dentro de la transaccion (03F-29).');
    });
    $reservadoAntes = (int) ProductOrderedInventory::query()->where('product_id', $this->variante->id)->sum('qty');
    $facturasAntes = Invoice::query()->count();

    expect(fn () => f03f_confirmar($this))->toThrow(RuntimeException::class, 'Fallo simulado');

    expect($intentos)->toBe(3)
        ->and(Invoice::query()->count())->toBe($facturasAntes)
        ->and((int) ProductOrderedInventory::query()->where('product_id', $this->variante->id)->sum('qty'))->toBe($reservadoAntes)
        ->and(DB::table('order_transactions')->count())->toBe(DB::table('order_transactions')->count());
    f03f_esperarLiberado($this);
});

test('03F-34 ConfirmarVentaService NO envuelve OrderRepository en una transaccion propia (solo la del propio Bagisto)', function () {
    $nivelEnElPedido = null;
    Event::listen('checkout.order.save.before', function () use (&$nivelEnElPedido) {
        $nivelEnElPedido = DB::transactionLevel();
    });
    $base = DB::transactionLevel();

    // Sin la defensa de la prueba: el camino exitoso no provoca el commit prematuro.
    $this->confirmacion->confirmar($this->borrador->id, $this->asesor, f03f_total($this->borrador));

    expect($nivelEnElPedido)->toBe($base + 1)
        ->and(DB::transactionLevel())->toBe($base);
});

test('03F-36 enlace condicional que afecta 0 filas → el pedido completo se revierte (en cada reintento)', function () {
    // Entre el cerco y el enlace, "otro" cambia el token dentro de la transaccion del pedido.
    Event::listen('checkout.order.orderitem.save.after', function () {
        DB::table('bylopez_va_borradores_venta')->where('id', $this->borrador->id)->update(['confirmation_token' => 'robado']);
    });

    $e = f03f_rechazo($this);

    expect($e->codigo)->toBe(Rechazo::CONFIRMACION_OBSOLETA)
        ->and(Invoice::query()->whereIn('order_id', Order::query()->where('cart_id', $this->cartId)->pluck('id'))->count())->toBe(0);
    f03f_esperarLiberado($this);
});

test('03F-45 orden de listeners: cerco UNICO en save.before; enlace PRIMERO en save.after, antes de atribucion, correos y factura', function () {
    // El core registra [Clase, 'metodo']; VentasAsesor, 'Clase@metodo'.
    $nombres = fn (array $ls) => array_map(fn ($l) => is_array($l) ? implode('@', $l) : $l, $ls);
    $antes = $nombres(Event::getRawListeners()['checkout.order.save.before']);
    $despues = $nombres(Event::getRawListeners()['checkout.order.save.after']);

    expect($antes)->toBe([CercoCreacionPedido::class.'@handle'])
        ->and($despues[0])->toBe(EnlazarPedidoConBorrador::class.'@handle')
        // G-11: la clasificacion comercial va justo despues del enlace (misma transaccion, lanza si falla).
        ->and($despues[1])->toBe(ClasificarOperacionPedido::class.'@handle')
        ->and($despues[2])->toBe(AtribuirPedidoCreado::class.'@handle')
        ->and(array_search('Webkul\Admin\Listeners\Order@afterCreated', $despues, true))->toBeGreaterThan(2)
        ->and(array_search('Webkul\Payment\Listeners\GenerateInvoice@handle', $despues, true))->toBeGreaterThan(2)
        ->and(array_search('Webkul\Shop\Listeners\Order@afterCreated', $despues, true))->toBeGreaterThan(2);
});

// ------------------------------------------------------------------ cerco para pedidos nativos: 03F-42 y regresion

/** Pedido por la primitiva nativa del panel: OrderResource + OrderRepository::create. */
function f03f_pedidoNativo(int $cartId): Order
{
    Cart::setCart(CartModel::query()->findOrFail($cartId));

    try {
        return app(OrderRepository::class)->create((new OrderResource(Cart::getCart()))->jsonSerialize());
    } finally {
        Cart::resetCart();
    }
}

test('03F-42 pedido nativo (ruta del panel) sobre el carrito de un borrador activo o confirmando → rechazado por el cerco', function (string $estado) {
    f03f_estado($this, ['estado' => $estado]);

    $e = f03f_error(fn () => f03f_pedidoNativo($this->cartId));

    expect($e)->toBeInstanceOf(RechazoDelCerco::class)
        ->and($e->codigo)->toBe(RechazoDelCerco::CARRITO_DE_BORRADOR)
        ->and(Order::query()->where('cart_id', $this->cartId)->count())->toBe(0);
})->with(['activo', 'confirmando']);

test('REGRESION panel y tienda: un carrito normal (sin borrador) crea su pedido por OrderRepository sin token ni bloqueo indebido', function (bool $activo) {
    $simple = f03f_simple();
    f03b_stock($simple, 50);
    $borrador = f03f_borradorListo($this->cliente->id, $this->asesor, [[$simple, $simple, 30], [$this->configurable, $this->variante, 5]]);
    BorradorVenta::query()->whereKey($borrador->id)->delete(); // el carrito queda como uno normal de Bagisto
    CartModel::query()->whereKey($borrador->cart_id)->update(['is_active' => $activo]);

    $pedido = f03f_sinCommitPrematuro(fn () => f03f_pedidoNativo($borrador->cart_id));

    expect(Order::query()->find($pedido->id))->not->toBeNull()
        ->and((int) ProductOrderedInventory::query()->where('product_id', $simple->id)->sum('qty'))->toBe(30);
})->with(['panel (carrito inactivo)' => false, 'tienda (carrito activo)' => true]);

test('D-54 el cerco exige stock SOLO donde Bagisto lo exige: descargable sin inventario pasa; virtual con manage_stock sin stock se rechaza', function () {
    $fabrica = new ProductFaker([]);
    $descargable = $fabrica->getDownloadableProductFactory()->create();
    $virtual = $fabrica->getVirtualProductFactory()->create();
    $manageStock = DB::table('attributes')->where('code', 'manage_stock')->value('id');

    foreach ([$descargable, $virtual] as $producto) {
        DB::table('product_attribute_values')->updateOrInsert(
            ['product_id' => $producto->id, 'attribute_id' => $manageStock, 'channel' => null, 'locale' => null],
            ['boolean_value' => 1],
        );
    }

    DB::table('product_inventories')->where('product_id', $virtual->id)->update(['qty' => 0]);
    $pedido = fn ($p) => ['channel_id' => core()->getDefaultChannel()->id, 'items' => [
        ['product_id' => $p->id, 'type' => $p->type, 'qty_ordered' => 3, 'children' => []],
    ]];

    app(CercoInventario::class)->validar($pedido($descargable));

    expect(fn () => app(CercoInventario::class)->validar($pedido($virtual)))
        ->toThrow(RechazoDelCerco::class);
});

test('entorno SIN la tabla de borradores (000006+ sin aplicar, p. ej. lpz_saas hoy): el cerco no rompe la tienda ni el panel', function () {
    $tabla = new ReflectionProperty(CercoCreacionPedido::class, 'tablaDeBorradores');
    $tabla->setValue(null, false);

    try {
        $pedido = f03f_sinCommitPrematuro(fn () => f03f_pedidoNativo($this->cartId));
    } finally {
        $tabla->setValue(null, null);
    }

    expect(Order::query()->find($pedido->id))->not->toBeNull();
});

test('D-54 el cerco es GLOBAL: un pedido nativo sin borrador tambien se rechaza si el stock real no alcanza bajo bloqueo', function () {
    BorradorVenta::query()->whereKey($this->borrador->id)->delete();
    DB::table('product_inventories')->where('product_id', $this->variante->id)->update(['qty' => 5]);

    $e = f03f_error(fn () => f03f_pedidoNativo($this->cartId));

    expect($e)->toBeInstanceOf(RechazoDelCerco::class)
        ->and($e->codigo)->toBe(RechazoDelCerco::STOCK_INSUFICIENTE)
        ->and(Order::query()->where('cart_id', $this->cartId)->count())->toBe(0);
});
