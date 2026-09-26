<?php

use ByLopez\VentasAsesor\Events\NotificacionClienteOmitida;
use ByLopez\VentasAsesor\Listeners\Correo\CustomerConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\InvoiceConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\OrderConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\RefundConDestinatarioVerificado;
use ByLopez\VentasAsesor\Listeners\Correo\ShipmentConDestinatarioVerificado;
use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Webkul\Admin\Mail\Order\InvoicedNotification as AdminInvoicedNotification;
use Webkul\Core\Models\CoreConfig;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Models\CustomerNote;
use Webkul\Payment\Listeners\GenerateInvoice;
use Webkul\Sales\Models\OrderComment;
use Webkul\Sales\Models\Refund;
use Webkul\Shop\Listeners\Customer as ShopCustomerListener;
use Webkul\Shop\Listeners\Invoice as ShopInvoiceListener;
use Webkul\Shop\Listeners\Order as ShopOrderListener;
use Webkul\Shop\Listeners\Refund as ShopRefundListener;
use Webkul\Shop\Listeners\Shipment as ShopShipmentListener;
use Webkul\Shop\Mail\Customer\NoteNotification;
use Webkul\Shop\Mail\Order\CanceledNotification;
use Webkul\Shop\Mail\Order\CommentedNotification;
use Webkul\Shop\Mail\Order\CreatedNotification;
use Webkul\Shop\Mail\Order\InvoicedNotification;
use Webkul\Shop\Mail\Order\RefundedNotification;
use Webkul\Shop\Mail\Order\ShippedNotification;

require_once __DIR__.'/../helpersRt04b1a.php';

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-04B1a — Guard de correos al cliente (D-RT04-06)
|--------------------------------------------------------------------------
| Solo lpz_saas_testing. MAIL_MAILER=array y QUEUE_CONNECTION=sync: ningun correo
| sale del proceso. Mail::fake() para contar envios; mailer `array` real cuando hay
| que demostrar que no se lanza TypeError (el fake no construye el envelope).
*/

const RT04B1A_EMAILS_SIN_DESTINATARIO = [
    'null' => [null, DestinatarioCorreo::SIN_EMAIL],
    'vacio' => ['', DestinatarioCorreo::EMAIL_VACIO],
    'espacios' => ['   ', DestinatarioCorreo::EMAIL_VACIO],
    'invalido' => ['no-es-un-email', DestinatarioCorreo::EMAIL_INVALIDO],
];

function rt04b1a_activarConfig(string $codigo): void
{
    CoreConfig::query()->where('code', $codigo)->delete();
    CoreConfig::factory()->create(['code' => $codigo, 'value' => 1, 'channel_code' => core()->getDefaultChannelCode()]);
}

beforeEach(function () {
    config(['mail.from.address' => 'remitente@example.test', 'mail.from.name' => 'RT04B1a']);
});

// ---------------------------------------------------------------- contrato del binding

test('contrato: cada listener del core se resuelve con su subclase y la subclase hereda del core', function (string $core, string $propia) {
    expect(app($core))->toBeInstanceOf($propia)->toBeInstanceOf($core);
})->with([
    [ShopOrderListener::class, OrderConDestinatarioVerificado::class],
    [ShopInvoiceListener::class, InvoiceConDestinatarioVerificado::class],
    [ShopShipmentListener::class, ShipmentConDestinatarioVerificado::class],
    [ShopRefundListener::class, RefundConDestinatarioVerificado::class],
    [ShopCustomerListener::class, CustomerConDestinatarioVerificado::class],
]);

test('contrato: el guard se engancha en los metodos del core que siguen existiendo con la firma esperada', function () {
    $prepareMail = new ReflectionMethod(ShopOrderListener::class, 'prepareMail');
    $nota = new ReflectionMethod(ShopCustomerListener::class, 'afterNoteCreated');

    expect($prepareMail->isProtected())->toBeTrue()
        ->and(array_map(fn ($p) => $p->getName(), $prepareMail->getParameters()))->toBe(['entity', 'notification'])
        ->and((new ReflectionMethod(OrderConDestinatarioVerificado::class, 'prepareMail'))->getDeclaringClass()->getName())->toBe(OrderConDestinatarioVerificado::class)
        ->and(array_map(fn ($p) => $p->getName(), $nota->getParameters()))->toBe(['note']);
});

// ---------------------------------------------------------------- pedido creado

test('pedido creado con email valido: exactamente un correo al cliente, sin omision', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => $email = 'valido-'.uniqid().'@example.test']);

    app(ShopOrderListener::class)->afterCreated($pedido);

    Mail::assertQueued(CreatedNotification::class, 1);
    Mail::assertQueued(CreatedNotification::class, fn ($m) => $m->hasTo($email));
    Event::assertNotDispatched(NotificacionClienteOmitida::class);
});

test('pedido creado sin destinatario: no se intenta el correo y se registra UNA omision con su motivo', function (?string $email, string $motivo) {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => null]);
    $pedido->customer_email = $email;

    app(ShopOrderListener::class)->afterCreated($pedido);

    Mail::assertNotQueued(CreatedNotification::class);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
    Event::assertDispatched(NotificacionClienteOmitida::class, fn ($e) => $e->motivo === $motivo
        && $e->notificacion === CreatedNotification::class && $e->orderId === $pedido->id);
})->with(RT04B1A_EMAILS_SIN_DESTINATARIO);

test('pedido creado con email NULL y el mailer real: sin TypeError (sin guard el core lo lanza, ver RT-04A)', function () {
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => null]);

    app(ShopOrderListener::class)->afterCreated($pedido);

    expect(app('mailer')->getSymfonyTransport()->messages()->count())->toBe(0);
});

test('checkout.order.save.after REAL con email NULL: todos los listeners corren y el del cliente omite sin error', function () {
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => null]);

    Event::dispatch('checkout.order.save.after', $pedido);

    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

// ---------------------------------------------------------------- cancelado y comentado (eventos reales)

test('pedido cancelado (evento real): valido envia uno; sin email omite uno', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $conEmail] = rt04b1a_pedido();
    ['pedido' => $sinEmail] = rt04b1a_pedido(['email' => null]);

    Event::dispatch('sales.order.cancel.after', $conEmail);
    Event::dispatch('sales.order.cancel.after', $sinEmail);

    Mail::assertQueued(CanceledNotification::class, 1);
    Mail::assertQueued(CanceledNotification::class, fn ($m) => $m->order->id === $conEmail->id);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

test('comentario con aviso al cliente (evento real): valido envia uno; sin email omite; sin aviso no hace nada', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $conEmail] = rt04b1a_pedido();
    ['pedido' => $sinEmail] = rt04b1a_pedido(['email' => null]);

    foreach ([[$conEmail, 1], [$sinEmail, 1], [$sinEmail, 0]] as [$pedido, $avisar]) {
        Event::dispatch('sales.order.comment.create.after', OrderComment::query()->create([
            'order_id' => $pedido->id, 'comment' => 'RT04B1a', 'customer_notified' => $avisar,
        ]));
    }

    Mail::assertQueued(CommentedNotification::class, 1);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

// ---------------------------------------------------------------- factura

test('factura (InvoiceRepository real) con email valido: un correo al cliente; el del admin sigue', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    rt04b1a_activarConfig('emails.general.notifications.emails.general.notifications.new_invoice_mail_to_admin');
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => $email = 'valido-'.uniqid().'@example.test']);

    rt04b1a_facturar($pedido, $item);

    Mail::assertQueued(InvoicedNotification::class, 1);
    Mail::assertQueued(InvoicedNotification::class, fn ($m) => $m->hasTo($email));
    Mail::assertQueued(AdminInvoicedNotification::class, 1);
    Event::assertNotDispatched(NotificacionClienteOmitida::class);
});

test('factura sin email con el mailer real: se crea, cierra su transaccion y omite al cliente (el correo al admin se sigue encolando)', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    rt04b1a_activarConfig('emails.general.notifications.emails.general.notifications.new_invoice_mail_to_admin');
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    $nivel = DB::transactionLevel();

    $factura = rt04b1a_facturar($pedido, $item);

    expect($factura->exists)->toBeTrue()
        ->and(DB::transactionLevel())->toBe($nivel);
    Mail::assertNotQueued(InvoicedNotification::class);
    Mail::assertQueued(AdminInvoicedNotification::class, 1);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

test('factura sin email con el mailer REAL (sin fake): ninguna excepcion y la transaccion se cierra', function () {
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    $nivel = DB::transactionLevel();

    rt04b1a_facturar($pedido, $item);

    expect(DB::transactionLevel())->toBe($nivel);
});

test('CARACTERIZACION sin guard: la factura sin email lanza TypeError y deja su transaccion abierta (N5)', function () {
    app()->offsetUnset(ShopInvoiceListener::class);   // vuelve el listener del core
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    $nivel = DB::transactionLevel();

    try {
        expect(fn () => rt04b1a_facturar($pedido, $item))->toThrow(TypeError::class)
            ->and(DB::transactionLevel())->toBe($nivel + 1);
    } finally {
        while (DB::transactionLevel() > $nivel) {
            DB::rollBack();
        }
    }
});

test('email_sent NO prueba entrega: tras omitir por falta de destinatario el core igual marca email_sent = 1; la evidencia propia es la omision', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);

    $factura = rt04b1a_facturar($pedido, $item);

    Mail::assertNotQueued(InvoicedNotification::class);
    Event::assertDispatched(NotificacionClienteOmitida::class, fn ($e) => $e->entidadId === $factura->id);
    expect((int) $factura->fresh()->email_sent)->toBe(1);
});

test('reenvio manual de factura: se respeta el email escrito por el admin aunque el pedido no tenga email', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    $factura = rt04b1a_facturar($pedido, $item);
    Event::fake([NotificacionClienteOmitida::class]);

    Event::dispatch('sales.invoice.send_duplicate_email', [$factura, 'reenvio@example.test']);

    Mail::assertQueued(InvoicedNotification::class, fn ($m) => $m->hasTo('reenvio@example.test'));
    Event::assertNotDispatched(NotificacionClienteOmitida::class);
});

test('factura AUTOMATICA (GenerateInvoice, contraentrega) de un pedido sin email: se genera sin error y omite al cliente', function () {
    Event::fake([NotificacionClienteOmitida::class]);
    rt04b1a_activarConfig('sales.payment_methods.cashondelivery.generate_invoice');
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => null]);

    expect(core()->getConfigData('sales.payment_methods.cashondelivery.generate_invoice'))->toBeTruthy();

    app(GenerateInvoice::class)->handle($pedido);

    expect($pedido->invoices()->count())->toBe(1);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

// ---------------------------------------------------------------- envio y reembolso

test('envio (ShipmentRepository real): valido envia uno; sin email se crea y omite uno', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $conEmail, 'item' => $i1] = rt04b1a_pedido();
    ['pedido' => $sinEmail, 'item' => $i2] = rt04b1a_pedido(['email' => null]);

    rt04b1a_enviar($conEmail, $i1);
    $envio = rt04b1a_enviar($sinEmail, $i2);

    expect($envio->exists)->toBeTrue();
    Mail::assertQueued(ShippedNotification::class, 1);
    Mail::assertQueued(ShippedNotification::class, fn ($m) => $m->shipment->order_id === $conEmail->id);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
});

test('reembolso (RefundRepository real, tras facturar) con email valido: exactamente un correo al cliente, sin omision', function () {
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => $email = 'valido-'.uniqid().'@example.test']);
    rt04b1a_facturar($pedido, $item);
    Event::fake([NotificacionClienteOmitida::class]);

    $reembolso = rt04b1a_reembolsar($pedido, $item);

    expect($reembolso->exists)->toBeTrue()->and($reembolso->items)->toHaveCount(1);
    Mail::assertQueued(RefundedNotification::class, 1);
    Mail::assertQueued(RefundedNotification::class, fn ($m) => $m->hasTo($email) && $m->refund->id === $reembolso->id);
    Event::assertNotDispatched(NotificacionClienteOmitida::class);
});

test('reembolso (RefundRepository real) sin email con el mailer real: se crea, cierra su transaccion y omite UNA vez', function () {
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    rt04b1a_facturar($pedido, $item);
    Event::fake([NotificacionClienteOmitida::class]);
    $nivel = DB::transactionLevel();

    $reembolso = rt04b1a_reembolsar($pedido, $item);

    expect($reembolso->exists)->toBeTrue()
        ->and(DB::transactionLevel())->toBe($nivel)
        ->and((float) $item->fresh()->qty_refunded)->toBe(1.0);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
    Event::assertDispatched(NotificacionClienteOmitida::class, fn ($e) => $e->entidad === Refund::class
        && $e->entidadId === $reembolso->id && $e->notificacion === RefundedNotification::class);
});

test('CARACTERIZACION sin guard: el reembolso sin email lanza TypeError y deja su transaccion abierta', function () {
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido(['email' => null]);
    rt04b1a_facturar($pedido, $item);
    app()->offsetUnset(ShopRefundListener::class);   // vuelve el listener del core
    $nivel = DB::transactionLevel();

    try {
        expect(fn () => rt04b1a_reembolsar($pedido, $item))->toThrow(TypeError::class)
            ->and(DB::transactionLevel())->toBe($nivel + 1);
    } finally {
        while (DB::transactionLevel() > $nivel) {
            DB::rollBack();
        }
    }
});

// ---------------------------------------------------------------- nota del cliente

test('nota del panel con aviso (evento real): valido envia uno; sin email omite; sin aviso no hace nada', function () {
    Mail::fake();
    Event::fake([NotificacionClienteOmitida::class]);
    $conEmail = Customer::factory()->create();
    $sinEmail = Customer::factory()->create(['email' => null]);

    foreach ([[$conEmail, 1], [$sinEmail, 1], [$sinEmail, 0]] as [$cliente, $avisar]) {
        Event::dispatch('customer.note.create.after', CustomerNote::query()->create([
            'customer_id' => $cliente->id, 'note' => 'RT04B1a', 'customer_notified' => $avisar,
        ]));
    }

    Mail::assertQueued(NoteNotification::class, 1);
    Event::assertDispatchedTimes(NotificacionClienteOmitida::class, 1);
    Event::assertDispatched(NotificacionClienteOmitida::class, fn ($e) => $e->customerId === $sinEmail->id);
});

// ---------------------------------------------------------------- fallo real del mailer

test('fallo real del mailer con email valido: el guard no lo oculta; el core lo registra como siempre y no hay omision', function () {
    Event::fake([NotificacionClienteOmitida::class]);
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => 'valido-'.uniqid().'@example.test']);

    Mail::shouldReceive('queue')->once()->andThrow(new TransportException('smtp caido'));
    Log::shouldReceive('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'smtp caido'));
    Log::shouldReceive('info')->never();

    app(ShopOrderListener::class)->afterCreated($pedido);

    Event::assertNotDispatched(NotificacionClienteOmitida::class);
});

test('error no recuperable del mailer con email valido: se propaga igual que en el core', function () {
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => 'valido-'.uniqid().'@example.test']);

    Mail::shouldReceive('queue')->once()->andThrow(new Error('fallo interno del mailer'));

    expect(fn () => app(ShopOrderListener::class)->afterCreated($pedido))->toThrow(Error::class, 'fallo interno del mailer');
});

test('la omision se registra sin datos personales', function () {
    ['pedido' => $pedido] = rt04b1a_pedido(['email' => null]);
    $pedido->customer_email = 'no-es-un-email';

    Log::shouldReceive('info')->once()->withArgs(function ($mensaje, $contexto) {
        return $mensaje === 'ventas_asesor.notificacion_cliente.omitida'
            && array_keys($contexto) === ['notificacion', 'entidad', 'entidad_id', 'order_id', 'customer_id', 'motivo']
            && ! in_array('no-es-un-email', $contexto, true);
    });

    app(ShopOrderListener::class)->afterCreated($pedido);
});
