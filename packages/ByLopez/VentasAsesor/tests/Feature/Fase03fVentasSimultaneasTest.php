<?php

use ByLopez\VentasAsesor\DataObjects\OtraVentaSinConfirmar;
use ByLopez\VentasAsesor\DataObjects\VentasSimultaneasVariante;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\Services\Venta\VentasSimultaneasService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\ShipmentRepository;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| Senal comercial de ventas simultaneas (Doc. 05 §40). Solo lpz_saas_testing.
| Informativa: no reserva, no modifica stock, no bloquea.
*/

beforeEach(function () {
    Mail::fake();
    // Sin factura automatica: permite cancelar pedidos (un pedido facturado no se cancela).
    f03f_activarCarriers();

    $this->senal = app(VentasSimultaneasService::class);
    $this->yo = f03b_admin();
    $this->yo->update(['name' => 'Juan Asesor']);
    $this->otro = f03b_admin();
    $this->otro->update(['name' => 'Pedro Asesor']);
    $this->cliente = f03b_cliente(f03b_grupo('S'), ['first_name' => 'Clienta', 'last_name' => 'Secreta', 'phone' => '3009998877']);

    $this->configurable = f03b_configurable(10000.0);
    [$this->m, $this->l] = $this->configurable->variants()->take(2)->get()->all();
    f03b_stock($this->m, 38);
    f03b_stock($this->l, 38);
});

/** Borrador con lineas en su Cart (sin entrega ni pago). */
function f03fvs_borrador($asesor, $cliente, array $lineas): BorradorVenta
{
    $borrador = app(BorradorVentaService::class)->crear($cliente->id, $asesor, CanalVenta::WHATSAPP);

    foreach ($lineas as [$padre, $variante, $cantidad]) {
        app(SolicitudBorradorService::class)->crear($borrador->id, $padre->id, $variante->id, $cantidad, $asesor);
        app(LineasBorradorService::class)->agregar($borrador->id, $padre->id, $variante->id, $cantidad, $asesor);
    }

    return $borrador->fresh();
}

/** Pedido confirmado por el flujo real (03F) sin factura automatica. */
function f03fvs_pedido($test, $producto, $variante, int $cantidad): Order
{
    $borrador = f03f_borradorListo($test->cliente->id, $test->yo, [[$producto, $variante, $cantidad]]);
    $r = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $test->yo, f03f_total($borrador)));

    return Order::query()->findOrFail($r->orderId);
}

function f03fvs_despachar(Order $pedido, int $cantidad): void
{
    $linea = OrderItem::query()->where('order_id', $pedido->id)->whereNull('parent_id')->sole();
    $fuente = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first()->id;

    app(ShipmentRepository::class)->create([
        'order_id' => $pedido->id,
        'shipment' => ['source' => $fuente, 'carrier_title' => 'Prueba', 'track_number' => null, 'items' => [$linea->id => [$fuente => $cantidad]]],
    ]);
}

// ------------------------------------------------------------------ ventas sin confirmar

test('1-2-11 configurable: stock Bagisto 38 y otra venta de 10 → stock 38 intacto, en otras 10; variante del hijo y cantidad del padre; sin "disponible"', function () {
    $ajeno = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 10]]);

    $s = $this->senal->paraVariante($this->m->id, $this->yo);
    $hijo = DB::table('cart_items')->where('cart_id', $ajeno->cart_id)->whereNotNull('parent_id')->sole();

    expect($s->stockBagisto)->toBe(38)
        ->and($s->enOtrasVentasSinConfirmar)->toBe(10)
        ->and($s->enEstaVenta)->toBe(0)
        ->and((int) $hijo->quantity)->toBe(0) // Bagisto guarda 0 en el hijo; la senal usa el padre
        ->and((int) $hijo->product_id)->toBe($this->m->id)
        ->and($s->advertencia)->toBeFalse();

    $campos = [...array_keys($s->toArray()), ...array_map(fn ($p) => $p->getName(), (new ReflectionClass(VentasSimultaneasVariante::class))->getProperties())];

    foreach ($campos as $campo) {
        expect(strtolower($campo))->not->toContain('disponib')->and(strtolower($campo))->not->toContain('reserv');
    }

    expect(json_encode($s->toArray()))->not->toContain('28');
});

test('3-18 dos asesores con la misma variante: nombre y cantidad; nunca cliente, telefono, precio ni total', function () {
    f03fvs_borrador($this->yo, $this->cliente, [[$this->configurable, $this->m, 12]]);
    f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 8]]);
    $tercero = f03b_admin();

    $s = $this->senal->paraVariante($this->m->id, $tercero);
    $json = json_encode($s->toArray());

    expect($s->enOtrasVentasSinConfirmar)->toBe(20)
        ->and(array_map(fn (OtraVentaSinConfirmar $v) => [$v->asesorNombre, $v->cantidad, $v->estado], $s->otrasVentas))
        ->toBe([['Juan Asesor', 12, 'activo'], ['Pedro Asesor', 8, 'activo']])
        ->and(array_keys($s->otrasVentas[0]->toArray()))->toBe(['asesor_id', 'asesor_nombre', 'cantidad', 'estado', 'ultima_actividad'])
        ->and($json)->not->toContain('Clienta')
        ->and($json)->not->toContain('Secreta')
        ->and($json)->not->toContain('3009998877')
        ->and($json)->not->toContain('10000');
});

test('4-5 mi borrador actual va a "esta venta" y no a otras; otro borrador MIO si es otra venta, con mi nombre', function () {
    $actual = f03fvs_borrador($this->yo, $this->cliente, [[$this->configurable, $this->m, 5]]);
    f03fvs_borrador($this->yo, $this->cliente, [[$this->configurable, $this->m, 7]]);
    f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 3]]);

    $s = $this->senal->paraVariante($this->m->id, $this->yo, $actual->id);

    expect($s->enEstaVenta)->toBe(5)
        ->and($s->enOtrasVentasSinConfirmar)->toBe(10)
        ->and(array_map(fn ($v) => [$v->asesorNombre, $v->cantidad], $s->otrasVentas))->toBe([['Juan Asesor', 7], ['Pedro Asesor', 3]]);
});

test('6-17 lote M/L: tallas separadas y un numero FIJO de consultas (sin N+1)', function () {
    $mas = $this->configurable->variants()->skip(2)->take(2)->get()->all();
    f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 4], [$this->configurable, $this->l, 6]]);

    $contar = function (array $ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->senal->paraVariantes($ids, $this->yo);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$r, $n];
    };

    $contar([$this->m->id]); // calienta cargas unicas (rol del actor, canal) antes de medir
    [, $consultasUna] = $contar([$this->m->id]);
    [$lote, $consultasLote] = $contar([$this->m->id, $this->l->id, ...array_map(fn ($v) => $v->id, $mas)]);

    expect($lote[$this->m->id]->enOtrasVentasSinConfirmar)->toBe(4)
        ->and($lote[$this->l->id]->enOtrasVentasSinConfirmar)->toBe(6)
        ->and(array_keys($lote))->toBe([$this->m->id, $this->l->id, ...array_map(fn ($v) => $v->id, $mas)])
        ->and($consultasLote)->toBe($consultasUna)
        ->and($consultasLote)->toBeLessThanOrEqual(8);
});

test('7-8-9-10 descartado y confirmado no cuentan; confirmando si (conserva Cart); carrito vacio no aporta', function () {
    $descartado = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 2]]);
    app(BorradorVentaService::class)->descartar($descartado->id, $this->otro);

    $confirmando = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 3]]);
    DB::table('bylopez_va_borradores_venta')->where('id', $confirmando->id)->update(['estado' => 'confirmando']);

    $vacio = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 4]]);
    $linea = app(LineasBorradorService::class)->listar($vacio->id)->lineas[0];
    app(LineasBorradorService::class)->eliminar($vacio->id, $linea->cartItemId, $this->otro);

    f03fvs_pedido($this, $this->configurable, $this->m, 5); // confirmado: su Cart se borra

    $s = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($s->enOtrasVentasSinConfirmar)->toBe(3)
        ->and(array_map(fn ($v) => [$v->cantidad, $v->estado], $s->otrasVentas))->toBe([[3, 'confirmando']])
        ->and($s->confirmadosSinDespachar)->toBe(5); // el confirmado aparece como pedido, no como borrador
});

test('12 simple: la cantidad sale de la propia linea; su pedido confirmado ya esta en el stock Bagisto y no se considera otra vez', function () {
    $simple = f03f_simple();
    f03b_stock($simple, 50);
    f03fvs_borrador($this->otro, $this->cliente, [[$simple, $simple, 9]]);
    f03fvs_pedido($this, $simple, $simple, 30);

    $s = $this->senal->paraVariante($simple->id, $this->yo);

    expect($s->enOtrasVentasSinConfirmar)->toBe(9)
        ->and($s->stockBagisto)->toBe(20)                          // Bagisto ya reservo las 30
        ->and($s->confirmadosSinDespachar)->toBe(30)
        ->and($s->confirmadosSinDespacharNoDescontados)->toBe(0)   // no se vuelven a considerar
        ->and($s->advertencia)->toBeFalse();                       // 9 <= 20
});

// ------------------------------------------------------------------ confirmados sin despachar

test('13-14-15 configurable confirmado: pendiente 30 con stock Bagisto intacto; Shipment parcial deja lo no despachado; completo, 0', function () {
    $pedido = f03fvs_pedido($this, $this->configurable, $this->m, 30);
    $antes = $this->senal->paraVariante($this->m->id, $this->yo);

    f03fvs_despachar($pedido, 12);
    $parcial = $this->senal->paraVariante($this->m->id, $this->yo);

    f03fvs_despachar($pedido, 18);
    $completo = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($antes->stockBagisto)->toBe(38)                         // limitacion nativa: no baja por el pedido
        ->and($antes->confirmadosSinDespachar)->toBe(30)
        ->and($antes->confirmadosSinDespacharNoDescontados)->toBe(30)
        ->and($parcial->confirmadosSinDespachar)->toBe(18)
        ->and($parcial->stockBagisto)->toBe(26)                      // el Shipment si descuenta el fisico
        ->and($completo->confirmadosSinDespachar)->toBe(0)
        ->and($completo->confirmadosSinDespacharNoDescontados)->toBe(0)
        ->and($completo->stockBagisto)->toBe(8);
});

/** Linea padre (configurable) del pedido cuya fila hija es la variante indicada. */
function f03fvs_lineaDeVariante(Order $pedido, $variante): OrderItem
{
    $hijo = OrderItem::query()->where('order_id', $pedido->id)->where('product_id', $variante->id)->whereNotNull('parent_id')->sole();

    return OrderItem::query()->findOrFail($hijo->parent_id);
}

function f03fvs_despacharVariante(Order $pedido, $variante, int $cantidad): void
{
    $fuente = core()->getDefaultChannel()->inventory_sources()->where('status', 1)->first()->id;

    app(ShipmentRepository::class)->create([
        'order_id' => $pedido->id,
        'shipment' => ['source' => $fuente, 'carrier_title' => 'Prueba', 'track_number' => null,
            'items' => [f03fvs_lineaDeVariante($pedido, $variante)->id => [$fuente => $cantidad]]],
    ]);
}

test('MULTIVARIANTE: un pedido con M=30 y L=20 del mismo configurable → cada padre con su hijo; pendientes 30 y 20 sin contaminacion; Shipment por variante', function () {
    f03b_stock($this->m, 60);
    f03b_stock($this->l, 60);
    $borrador = f03f_borradorListo($this->cliente->id, $this->yo, [[$this->configurable, $this->m, 30], [$this->configurable, $this->l, 20]]);
    $pedido = Order::query()->findOrFail(f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)
        ->confirmar($borrador->id, $this->yo, f03f_total($borrador)))->orderId);

    $padreM = f03fvs_lineaDeVariante($pedido, $this->m);
    $padreL = f03fvs_lineaDeVariante($pedido, $this->l);
    $hijos = OrderItem::query()->where('order_id', $pedido->id)->whereNotNull('parent_id')->get()->keyBy('product_id');
    $pendientes = fn () => array_map(fn ($s) => $s->confirmadosSinDespachar, $this->senal->paraVariantes([$this->m->id, $this->l->id], $this->yo));

    $inicial = $pendientes();
    f03fvs_despacharVariante($pedido, $this->m, 10);
    $parcial = $pendientes();
    f03fvs_despacharVariante($pedido, $this->m, 20);
    $mCompleto = $pendientes();

    expect(OrderItem::query()->where('order_id', $pedido->id)->count())->toBe(4)
        ->and($padreM->id)->not->toBe($padreL->id)
        ->and($padreM->type)->toBe('configurable')->and((int) $padreM->qty_ordered)->toBe(30)
        ->and($padreL->type)->toBe('configurable')->and((int) $padreL->qty_ordered)->toBe(20)
        ->and((int) $hijos[$this->m->id]->parent_id)->toBe($padreM->id)
        ->and((int) $hijos[$this->l->id]->parent_id)->toBe($padreL->id)
        ->and((int) $hijos[$this->m->id]->qty_ordered)->toBe(0)
        ->and($inicial)->toBe([$this->m->id => 30, $this->l->id => 20])
        ->and($parcial)->toBe([$this->m->id => 20, $this->l->id => 20])
        ->and($mCompleto)->toBe([$this->m->id => 0, $this->l->id => 20])
        ->and((int) $padreL->fresh()->qty_shipped)->toBe(0);
});

test('CANCELACION (nativa por pedido): cancelar el pedido de M no afecta al pedido de L', function () {
    $pedidoM = f03fvs_pedido($this, $this->configurable, $this->m, 7);
    f03fvs_pedido($this, $this->configurable, $this->l, 5);

    expect(app(OrderRepository::class)->cancel($pedidoM->id, true))->toBeTrue();

    $s = $this->senal->paraVariantes([$this->m->id, $this->l->id], $this->yo);

    expect($s[$this->m->id]->confirmadosSinDespachar)->toBe(0)
        ->and($s[$this->l->id]->confirmadosSinDespachar)->toBe(5);
});

test('PEDIDOS MULTIPLES: dos pedidos de la misma variante (10 y 15) → 25, sin duplicar padres ni hijos', function () {
    $a = f03fvs_pedido($this, $this->configurable, $this->m, 10);
    $b = f03fvs_pedido($this, $this->configurable, $this->m, 15);

    $s = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($s->confirmadosSinDespachar)->toBe(25)
        ->and($s->confirmadosSinDespacharNoDescontados)->toBe(25)
        ->and($s->stockBagisto)->toBe(38) // informativa: no alimenta el stock
        ->and(OrderItem::query()->whereIn('order_id', [$a->id, $b->id])->count())->toBe(4);
});

test('16 pedido cancelado no infla la metrica (qty_canceled nativo)', function () {
    $pedido = f03fvs_pedido($this, $this->configurable, $this->m, 7);

    expect(app(OrderRepository::class)->cancel($pedido->id, true))->toBeTrue();

    $s = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($s->confirmadosSinDespachar)->toBe(0)
        ->and((int) OrderItem::query()->where('order_id', $pedido->id)->whereNull('parent_id')->value('qty_canceled'))->toBe(7);
});

test('advertencia: 38 de stock, 10 confirmados sin despachar y 35 en otras ventas → advertencia, sin publicar -7 ni ningun disponible', function () {
    f03b_stock($this->m, 50);
    f03fvs_pedido($this, $this->configurable, $this->m, 10);
    f03b_stock($this->m, 38);
    f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 35]]);

    $s = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($s->stockBagisto)->toBe(38)
        ->and($s->confirmadosSinDespacharNoDescontados)->toBe(10)
        ->and($s->enOtrasVentasSinConfirmar)->toBe(35)
        ->and($s->advertencia)->toBeTrue()
        ->and(json_encode($s->toArray()))->not->toContain('-7');
});

// ------------------------------------------------------------------ actividad, permisos y lectura pura

test('ultima actividad: el cambio mas reciente del Cart o de sus lineas, no borradores.updated_at', function () {
    $ajeno = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 2]]);
    DB::table('cart')->where('id', $ajeno->cart_id)->update(['updated_at' => '2026-09-01 10:00:00']);
    DB::table('cart_items')->where('cart_id', $ajeno->cart_id)->update(['updated_at' => '2026-09-20 15:30:00']);
    DB::table('bylopez_va_borradores_venta')->where('id', $ajeno->id)->update(['updated_at' => '2026-09-25 09:00:00']);

    $s = $this->senal->paraVariante($this->m->id, $this->yo);

    expect($s->otrasVentas[0]->ultimaActividad->format('Y-m-d H:i:s'))->toBe('2026-09-20 15:30:00');
});

test('sin exclusion por antiguedad: un borrador activo sin movimiento hace meses sigue apareciendo', function () {
    $viejo = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 6]]);
    DB::table('cart')->where('id', $viejo->cart_id)->update(['updated_at' => now()->subMonths(3)]);
    DB::table('cart_items')->where('cart_id', $viejo->cart_id)->update(['updated_at' => now()->subMonths(3)]);

    expect($this->senal->paraVariante($this->m->id, $this->yo)->enOtrasVentasSinConfirmar)->toBe(6);
});

test('sin permiso del modulo → sin_permiso; ver nombres no cambia la propiedad del borrador ajeno', function () {
    $ajeno = f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 2]]);
    $sinPermiso = f03b_admin('custom', ['ventas_asesor']);

    expect(fn () => $this->senal->paraVariante($this->m->id, $sinPermiso))->toThrow(BorradorInvalido::class)
        ->and(fn () => app(LineasBorradorService::class)->agregar($ajeno->id, $this->configurable->id, $this->m->id, 1, $this->yo))
        ->toThrow(BorradorInvalido::class);
});

test('19 el servicio es de solo lectura: ninguna escritura en ninguna tabla', function () {
    f03fvs_borrador($this->otro, $this->cliente, [[$this->configurable, $this->m, 2]]);
    f03fvs_pedido($this, $this->configurable, $this->m, 3);
    $escrituras = [];
    DB::listen(function ($q) use (&$escrituras) {
        if (! preg_match('/^\s*(select|\(select)/i', $q->sql)) {
            $escrituras[] = $q->sql;
        }
    });

    $this->senal->paraVariantes([$this->m->id, $this->l->id], $this->yo);

    expect($escrituras)->toBe([]);
});
