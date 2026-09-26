<?php

use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Webkul\Shop\Listeners\Order as ShopOrderListener;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-04B1a — Caracterizacion del extension point de listeners (antes del guard)
|--------------------------------------------------------------------------
|
| Demuestra con un ESPIA de prueba (no con el guard real) que:
|   1. el core registra sus listeners de correo como [Clase, 'metodo'];
|   2. Laravel resuelve esa clase por el contenedor en cada disparo;
|   3. un binding sustituye exactamente esa clase, sin añadir un segundo listener;
|   4. parent:: ejecuta el cuerpo del core.
|
*/

class Rt04b1aEspiaListenerPedido extends ShopOrderListener
{
    public static int $llamadas = 0;

    public static int $llegoAlCore = 0;

    public function afterCommented($comment)
    {
        static::$llamadas++;

        parent::afterCommented($comment);

        // El core retorna sin enviar si customer_notified es falso: llegar aqui prueba
        // que parent:: se ejecuto sin lanzar.
        static::$llegoAlCore++;
    }
}

beforeEach(function () {
    Rt04b1aEspiaListenerPedido::$llamadas = 0;
    Rt04b1aEspiaListenerPedido::$llegoAlCore = 0;
});

test('el core registra los listeners de correo del Shop como [Clase, metodo], una sola vez por evento', function (string $evento, string $clase, string $metodo) {
    $registrados = array_values(array_filter(
        Event::getRawListeners()[$evento] ?? [],
        fn ($l) => is_array($l) && $l[0] === $clase,
    ));

    expect($registrados)->toBe([[$clase, $metodo]]);
})->with([
    ['checkout.order.save.after', 'Webkul\Shop\Listeners\Order', 'afterCreated'],
    ['sales.order.cancel.after', 'Webkul\Shop\Listeners\Order', 'afterCanceled'],
    ['sales.order.comment.create.after', 'Webkul\Shop\Listeners\Order', 'afterCommented'],
    ['sales.invoice.save.after', 'Webkul\Shop\Listeners\Invoice', 'afterCreated'],
    ['sales.invoice.send_duplicate_email', 'Webkul\Shop\Listeners\Invoice', 'afterCreated'],
    ['sales.shipment.save.after', 'Webkul\Shop\Listeners\Shipment', 'afterCreated'],
    ['sales.refund.save.after', 'Webkul\Shop\Listeners\Refund', 'afterCreated'],
    ['customer.note.create.after', 'Webkul\Shop\Listeners\Customer', 'afterNoteCreated'],
    ['customer.registration.after', 'Webkul\Shop\Listeners\Customer', 'afterCreated'],
    ['customer.password.update.after', 'Webkul\Shop\Listeners\Customer', 'afterPasswordUpdated'],
]);

test('un binding del contenedor sustituye la clase del listener en el disparo real, sin duplicarlo, y parent:: ejecuta el core', function () {
    $antes = count(Event::getListeners('sales.order.comment.create.after'));

    app()->bind(ShopOrderListener::class, Rt04b1aEspiaListenerPedido::class);

    Event::dispatch('sales.order.comment.create.after', (object) ['customer_notified' => false]);

    expect(Rt04b1aEspiaListenerPedido::$llamadas)->toBe(1)
        ->and(Rt04b1aEspiaListenerPedido::$llegoAlCore)->toBe(1)
        ->and(count(Event::getListeners('sales.order.comment.create.after')))->toBe($antes);
});
