<?php

use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException as ContextoInvalido;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\GrupoVentaInvalidoException as GrupoInvalido;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\GrupoEventoBorrador;
use ByLopez\VentasAsesor\Models\SnapshotGrupoPedido;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Services\Venta\GrupoVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| GRP-01 — Grupo comercial TEMPORAL por venta. Solo lpz_saas_testing.
|
| Precios de fixture (no reglas de negocio): descuento VIP_1 10 %, Mayorista_3 30 %, VIP_2 20 % sobre el precio base.
| El override vive en el borrador; customers.customer_group_id no se escribe nunca.
*/

const GRP_PERMISOS_BASE = ['ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos.create'];

function grp_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (GrupoInvalido|BorradorInvalido|GrupoComercialInvalidoException|ContextoInvalido $e) {
        return $e->codigo;
    }

    return null;
}

/** Venta con N lineas (una por variante) como las crea Nueva venta: solicitud + linea. */
function grp_venta($test, ?Customer $cliente = null, $asesor = null, int $lineas = 1, int $qty = 3): BorradorVenta
{
    $asesor ??= $test->asesor;
    $borrador = app(BorradorVentaService::class)->crear(($cliente ?? $test->cliente)->id, $asesor, CanalVenta::WHATSAPP);

    foreach ($test->variantes->take($lineas) as $variante) {
        app(SolicitudBorradorService::class)->crear($borrador->id, $test->configurable->id, $variante->id, 10, $asesor);
        app(LineasBorradorService::class)->agregar($borrador->id, $test->configurable->id, $variante->id, $qty, $asesor);
    }

    return $borrador->fresh();
}

/** @return list<float> precio unitario de cada linea (item padre) del carrito de la venta. */
function grp_precios(BorradorVenta $borrador): array
{
    return CartItem::query()->where('cart_id', $borrador->fresh()->cart_id)->whereNull('parent_id')->orderBy('id')
        ->pluck('price')->map(fn ($p) => round((float) $p, 2))->all();
}

function grp_esperado($test, float $porcentaje): float
{
    return round($test->base * (1 - $porcentaje / 100), 2);
}

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin('custom', [...GRP_PERMISOS_BASE, 'ventas_asesor.pedidos.cambiar_grupo_temporal']);
    $this->asesorSinPermiso = f03b_admin('custom', GRP_PERMISOS_BASE);
    $this->administrador = f03b_admin();

    $this->vip = f03b_grupo('VIP_1');
    $this->mayorista3 = f03b_grupo('Mayorista_3');
    $this->vip2 = f03b_grupo('VIP_2');
    $this->cliente = f03b_cliente($this->vip);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get()->values();
    $this->base = (float) $this->variantes[0]->price;

    foreach ($this->variantes->take(2) as $variante) {
        f03b_stock($variante, 50);
        f03b_descuentoDeGrupo($variante, $this->vip, 10, $this->configurable);
        f03b_descuentoDeGrupo($variante, $this->mayorista3, 30, $this->configurable);
        f03b_descuentoDeGrupo($variante, $this->vip2, 20, $this->configurable);
    }

    $this->grupos = app(GrupoVentaService::class);
});

// ---------------------------------------------------------------- 1. sin override

test('GRP-1 sin override: precios del grupo habitual, grupo aplicado = habitual y sin historial', function () {
    $borrador = grp_venta($this);
    $vista = app(BorradorVentaService::class)->obtener($borrador->id);

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 10)])
        ->and($vista->grupo['override'])->toBeFalse()
        ->and($vista->grupo['habitual_id'])->toBe($this->vip->id)
        ->and($vista->grupo['aplicado_id'])->toBe($this->vip->id)
        ->and($vista->grupo['cambios'])->toBe(0)
        ->and($borrador->grupo_original_id)->toBe($this->vip->id)
        ->and($borrador->grupo_efectivo_id)->toBe($this->vip->id)
        ->and($borrador->grupo_override)->toBeFalse();
});

// ---------------------------------------------------------------- 2. override

test('GRP-2 override: el cliente sigue en su grupo y la venta calcula con el grupo temporal', function () {
    $borrador = grp_venta($this);

    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Negociación especial por 500 camisetas', $this->asesor);

    $borrador = $borrador->fresh();
    $vista = app(BorradorVentaService::class)->obtener($borrador->id);

    expect((int) Customer::query()->whereKey($this->cliente->id)->value('customer_group_id'))->toBe($this->vip->id)
        ->and(grp_precios($borrador))->toBe([grp_esperado($this, 30)])
        ->and($borrador->grupo_override)->toBeTrue()
        ->and($borrador->grupo_original_id)->toBe($this->vip->id)
        ->and($borrador->grupo_efectivo_id)->toBe($this->mayorista3->id)
        ->and($borrador->grupo_override_customer_id)->toBe($this->cliente->id)
        ->and($vista->grupo['override'])->toBeTrue()
        ->and($vista->grupo['habitual_nombre'])->toBe($this->vip->name)
        ->and($vista->grupo['aplicado_nombre'])->toBe($this->mayorista3->name)
        ->and($vista->grupo['cambios'])->toBe(1);

    $evento = GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->sole();

    expect($evento->evento)->toBe(GrupoEventoBorrador::OVERRIDE_APLICADO)
        ->and($evento->grupo_original_id)->toBe($this->vip->id)
        ->and($evento->grupo_anterior_id)->toBe($this->vip->id)
        ->and($evento->grupo_nuevo_id)->toBe($this->mayorista3->id)
        ->and($evento->grupo_nuevo_nombre)->toBe($this->mayorista3->name)
        ->and($evento->motivo)->toBe('Negociación especial por 500 camisetas')
        ->and($evento->actor_admin_id)->toBe($this->asesor->id)
        ->and($evento->customer_id)->toBe($this->cliente->id)
        ->and($evento->order_id)->toBeNull();
});

// ---------------------------------------------------------------- 3. carrito con productos

test('GRP-3 carrito con varias lineas: cambiar de grupo recalcula TODAS, sin mezclar precios', function () {
    $borrador = grp_venta($this, lineas: 2);

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 10), grp_esperado($this, 10)]);

    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Compra mayor', $this->asesor);

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 30), grp_esperado($this, 30)])
        ->and(CartItem::query()->where('cart_id', $borrador->fresh()->cart_id)->whereNotNull('custom_price')->count())->toBe(0);
});

// ---------------------------------------------------------------- 4. producto agregado despues + catalogo

test('GRP-4 producto agregado despues del cambio usa el grupo temporal, y el catalogo muestra el MISMO precio que el carrito', function () {
    $borrador = grp_venta($this);
    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Compra mayor', $this->asesor);

    // Catalogo: con la venta, grupo efectivo; sin ella, el habitual del cliente.
    $conVenta = app(CatalogoVentaService::class)->matriz($this->configurable->id, $this->cliente->id, $this->asesor, 1, $borrador->id);
    $sinVenta = app(CatalogoVentaService::class)->matriz($this->configurable->id, $this->cliente->id, $this->asesor, 1, null);
    $precioDe = fn ($matriz) => round((float) collect($matriz->celdas)->first(fn ($c) => $c->variante->productId === $this->variantes[1]->id)->variante->precioUnitario, 2);

    expect($precioDe($conVenta))->toBe(grp_esperado($this, 30))
        ->and($precioDe($sinVenta))->toBe(grp_esperado($this, 10));

    app(SolicitudBorradorService::class)->crear($borrador->id, $this->configurable->id, $this->variantes[1]->id, 10, $this->asesor);
    app(LineasBorradorService::class)->agregar($borrador->id, $this->configurable->id, $this->variantes[1]->id, 2, $this->asesor);

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 30), grp_esperado($this, 30)]);
});

// ---------------------------------------------------------------- 5. permiso (servicio y HTTP)

test('GRP-5 sin permiso: el servicio rechaza y no cambia nada', function () {
    $borrador = grp_venta($this, asesor: $this->asesorSinPermiso);

    expect(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'x', $this->asesorSinPermiso)))->toBe(GrupoInvalido::SIN_PERMISO)
        ->and($this->grupos->puedeCambiar($this->asesorSinPermiso))->toBeFalse()
        ->and($this->grupos->puedeCambiar($this->asesor))->toBeTrue()
        ->and($this->grupos->puedeCambiar($this->administrador))->toBeTrue()
        ->and(grp_precios($borrador))->toBe([grp_esperado($this, 10)])
        ->and($borrador->fresh()->grupo_override)->toBeFalse()
        ->and(GrupoEventoBorrador::query()->count())->toBe(0);
});

test('GRP-5 sin permiso por request directo: la ruta responde 401 y no cambia nada; con permiso, 200 con el estado recalculado', function () {
    $borrador = grp_venta($this);
    $ruta = route('admin.ventas_asesor.nueva_venta.grupo.cambiar', $borrador->id);
    $cuerpo = ['grupo_id' => $this->mayorista3->id, 'motivo' => 'Compra mayor'];

    $this->actingAs($this->asesorSinPermiso, 'admin')->postJson($ruta, $cuerpo)->assertStatus(401);
    expect($borrador->fresh()->grupo_override)->toBeFalse();

    $respuesta = $this->actingAs($this->asesor, 'admin')->postJson($ruta, $cuerpo)->assertOk();

    expect($respuesta->json('borrador.grupo.override'))->toBeTrue()
        ->and($respuesta->json('borrador.grupo.aplicado_nombre'))->toBe($this->mayorista3->name)
        ->and($respuesta->json('borrador.grupo.habitual_nombre'))->toBe($this->vip->name)
        ->and(grp_precios($borrador))->toBe([grp_esperado($this, 30)]);

    // Motivo vacio por HTTP: error de dominio estable, sin cambios nuevos.
    $this->actingAs($this->asesor, 'admin')
        ->postJson($ruta, ['grupo_id' => $this->vip2->id, 'motivo' => '  '])
        ->assertStatus(422)->assertJsonPath('codigo', GrupoInvalido::MOTIVO_REQUERIDO);

    // Grupo invitado: rechazado por el validador comercial.
    $this->actingAs($this->asesor, 'admin')
        ->postJson($ruta, ['grupo_id' => core()->getGuestCustomerGroup()->id, 'motivo' => 'x'])
        ->assertStatus(422)->assertJsonPath('codigo', GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO);
});

test('GRP-5 otro asesor no puede cambiar el grupo de una venta ajena', function () {
    $borrador = grp_venta($this);
    $otro = f03b_admin('custom', [...GRP_PERMISOS_BASE, 'ventas_asesor.pedidos.cambiar_grupo_temporal']);

    expect(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'x', $otro)))->toBe(BorradorInvalido::NO_ES_PROPIETARIO)
        ->and($borrador->fresh()->grupo_override)->toBeFalse();
});

// ---------------------------------------------------------------- 6. motivo

test('GRP-6 motivo vacio o demasiado largo: rechazado, sin override ni historial', function () {
    $borrador = grp_venta($this);

    expect(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, '   ', $this->asesor)))->toBe(GrupoInvalido::MOTIVO_REQUERIDO)
        ->and(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, str_repeat('a', 501), $this->asesor)))->toBe(GrupoInvalido::MOTIVO_DEMASIADO_LARGO)
        ->and($borrador->fresh()->grupo_override)->toBeFalse()
        ->and(GrupoEventoBorrador::query()->count())->toBe(0)
        ->and(grp_precios($borrador))->toBe([grp_esperado($this, 10)]);
});

test('GRP-6 el mismo grupo ya aplicado se rechaza (sin evento falso)', function () {
    $borrador = grp_venta($this);

    expect(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->vip->id, 'x', $this->asesor)))->toBe(GrupoInvalido::GRUPO_SIN_CAMBIO)
        ->and(GrupoEventoBorrador::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- 7. dos cambios = dos eventos

test('GRP-7 cambiar dos veces: dos eventos, ninguno se sobrescribe, y el original sigue siendo el habitual', function () {
    $borrador = grp_venta($this);

    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Primero', $this->asesor);
    $this->grupos->cambiar($borrador->id, $this->vip2->id, 'Segundo', $this->asesor);

    $eventos = GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->orderBy('id')->get();

    expect($eventos)->toHaveCount(2)
        ->and($eventos[0]->evento)->toBe(GrupoEventoBorrador::OVERRIDE_APLICADO)
        ->and($eventos[0]->grupo_nuevo_id)->toBe($this->mayorista3->id)
        ->and($eventos[0]->motivo)->toBe('Primero')
        ->and($eventos[1]->evento)->toBe(GrupoEventoBorrador::OVERRIDE_CAMBIADO)
        ->and($eventos[1]->grupo_anterior_id)->toBe($this->mayorista3->id)
        ->and($eventos[1]->grupo_nuevo_id)->toBe($this->vip2->id)
        ->and($eventos[1]->grupo_original_id)->toBe($this->vip->id)
        ->and($eventos[1]->motivo)->toBe('Segundo')
        ->and($borrador->fresh()->grupo_eventos)->toBe(2)
        ->and(grp_precios($borrador))->toBe([grp_esperado($this, 20)]);

    // El historial (solo lectura) devuelve ambos, el mas reciente primero.
    $historial = $this->grupos->historial($borrador->id, $this->asesor);

    expect($historial)->toHaveCount(2)->and($historial[0]['motivo'])->toBe('Segundo');
});

// ---------------------------------------------------------------- 8. restaurar

test('GRP-8 restaurar: vuelve al habitual, recalcula todo, audita y exige motivo y override vigente', function () {
    $borrador = grp_venta($this, lineas: 2);

    expect(grp_error(fn () => $this->grupos->restaurar($borrador->id, 'x', $this->asesor)))->toBe(GrupoInvalido::SIN_OVERRIDE);

    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Compra mayor', $this->asesor);

    expect(grp_error(fn () => $this->grupos->restaurar($borrador->id, '', $this->asesor)))->toBe(GrupoInvalido::MOTIVO_REQUERIDO)
        ->and($borrador->fresh()->grupo_override)->toBeTrue();

    $this->grupos->restaurar($borrador->id, 'Se cayó la negociación', $this->asesor);

    $borrador = $borrador->fresh();
    $ultimo = GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->orderByDesc('id')->first();

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 10), grp_esperado($this, 10)])
        ->and($borrador->grupo_override)->toBeFalse()
        ->and($borrador->grupo_efectivo_id)->toBe($this->vip->id)
        ->and($borrador->grupo_override_customer_id)->toBeNull()
        ->and($ultimo->evento)->toBe(GrupoEventoBorrador::RESTAURACION)
        ->and($ultimo->grupo_anterior_id)->toBe($this->mayorista3->id)
        ->and($ultimo->grupo_nuevo_id)->toBe($this->vip->id)
        ->and($ultimo->motivo)->toBe('Se cayó la negociación')
        ->and(GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->count())->toBe(2)
        ->and((int) Customer::query()->whereKey($this->cliente->id)->value('customer_group_id'))->toBe($this->vip->id);
});

// ---------------------------------------------------------------- 9. cambio de cliente

test('GRP-9 cambiar de cliente cancela el override (evento propio) y no se hereda: el cliente nuevo usa SU grupo', function () {
    $borrador = grp_venta($this);
    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Compra mayor', $this->asesor);
    $clienteB = f03b_cliente($this->vip2);

    app(BorradorVentaService::class)->cambiarCliente($borrador->id, $clienteB->id, $this->asesor);

    $borrador = $borrador->fresh();
    $eventos = GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->orderBy('id')->get();

    expect(grp_precios($borrador))->toBe([grp_esperado($this, 20)])
        ->and($borrador->grupo_override)->toBeFalse()
        ->and($borrador->grupo_original_id)->toBe($this->vip2->id)
        ->and($borrador->grupo_efectivo_id)->toBe($this->vip2->id)
        ->and($borrador->grupo_override_customer_id)->toBeNull()
        ->and($eventos)->toHaveCount(2)
        ->and($eventos[1]->evento)->toBe(GrupoEventoBorrador::OVERRIDE_CANCELADO_CAMBIO_CLIENTE)
        ->and($eventos[1]->origen)->toBe(GrupoEventoBorrador::ORIGEN_CAMBIO_CLIENTE)
        ->and($eventos[1]->customer_id)->toBe($this->cliente->id)      // el cliente ANTERIOR, no el nuevo
        ->and($eventos[1]->grupo_anterior_id)->toBe($this->mayorista3->id)
        ->and($eventos[1]->grupo_nuevo_id)->toBeNull();

    $vista = app(BorradorVentaService::class)->obtener($borrador->id);

    expect($vista->grupo['override'])->toBeFalse()->and($vista->grupo['aplicado_id'])->toBe($this->vip2->id);
});

test('GRP-9 un override aplicado a OTRO cliente jamas se hereda: el contexto de precios falla cerrado', function () {
    $borrador = grp_venta($this);
    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'x', $this->asesor);
    $cartId = (int) $borrador->fresh()->cart_id;

    // Inconsistencia forzada (no ocurre por el flujo normal): el override pertenece a otro cliente.
    BorradorVenta::query()->whereKey($borrador->id)->update(['grupo_override_customer_id' => $this->cliente->id + 999]);

    expect(grp_error(fn () => app(ContextoPrecioComercial::class)->durante($cartId, $this->cliente->id, fn () => app(ContextoPrecioComercial::class)->grupo())))
        ->toBe(ContextoInvalido::OVERRIDE_DE_OTRO_CLIENTE);
});

// ---------------------------------------------------------------- 10. pedido: snapshot

test('GRP-10 crear pedido con override: snapshot durable (original, efectivo, override) y el historial queda ligado al pedido', function () {
    $borrador = f03f_borradorListo($this->cliente->id, $this->administrador, [[$this->configurable, $this->variantes[0], 3, 10]]);

    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Negociación especial por 500 camisetas', $this->administrador);
    $total = f03f_total($borrador);

    $resultado = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->administrador, $total, null));
    $pedido = Order::query()->findOrFail($resultado->orderId);
    $snapshot = SnapshotGrupoPedido::query()->where('order_id', $pedido->id)->sole();

    expect($snapshot->customer_id)->toBe($this->cliente->id)
        ->and($snapshot->borrador_id)->toBe($borrador->id)
        ->and($snapshot->grupo_original_id)->toBe($this->vip->id)
        ->and($snapshot->grupo_original_nombre)->toBe($this->vip->name)
        ->and($snapshot->grupo_original_codigo)->toBe($this->vip->code)
        ->and($snapshot->grupo_efectivo_id)->toBe($this->mayorista3->id)
        ->and($snapshot->grupo_efectivo_nombre)->toBe($this->mayorista3->name)
        ->and($snapshot->grupo_efectivo_codigo)->toBe($this->mayorista3->code)
        ->and($snapshot->grupo_override)->toBeTrue()
        ->and(round((float) OrderItem::query()->where('order_id', $pedido->id)->whereNull('parent_id')->value('price'), 2))->toBe(grp_esperado($this, 30))
        ->and(GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->pluck('order_id')->unique()->all())->toBe([$pedido->id])
        ->and((int) Customer::query()->whereKey($this->cliente->id)->value('customer_group_id'))->toBe($this->vip->id);

    // El pedido sigue explicando su condicion comercial aunque el grupo se renombre o el cliente cambie de grupo.
    $this->mayorista3->update(['name' => 'Renombrado']);
    $this->cliente->update(['customer_group_id' => $this->vip2->id]);

    expect(SnapshotGrupoPedido::query()->where('order_id', $pedido->id)->value('grupo_efectivo_nombre'))->not->toBe('Renombrado');

    // Inmutable.
    expect(fn () => $snapshot->update(['grupo_override' => false]))->toThrow(LogicException::class);
});

test('GRP-10 pedido sin override: snapshot con original = efectivo y override falso', function () {
    $borrador = f03f_borradorListo($this->cliente->id, $this->administrador, [[$this->configurable, $this->variantes[0], 3, 10]]);

    $resultado = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->administrador, f03f_total($borrador), null));
    $snapshot = SnapshotGrupoPedido::query()->where('order_id', $resultado->orderId)->sole();

    expect($snapshot->grupo_override)->toBeFalse()
        ->and($snapshot->grupo_original_id)->toBe($this->vip->id)
        ->and($snapshot->grupo_efectivo_id)->toBe($this->vip->id)
        ->and(round((float) OrderItem::query()->where('order_id', $resultado->orderId)->whereNull('parent_id')->value('price'), 2))->toBe(grp_esperado($this, 10));
});

test('GRP-10 una venta ya confirmada no admite cambiar ni restaurar el grupo (sin edicion retroactiva)', function () {
    $borrador = f03f_borradorListo($this->cliente->id, $this->administrador, [[$this->configurable, $this->variantes[0], 3, 10]]);
    f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->administrador, f03f_total($borrador), null));

    expect(grp_error(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'tarde', $this->administrador)))->toBe(BorradorInvalido::NO_EDITABLE)
        ->and(GrupoEventoBorrador::query()->where('borrador_id', $borrador->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- 11. concurrencia (dos ventas del mismo cliente)

test('GRP-11 dos ventas del mismo cliente con grupos distintos no se interfieren', function () {
    $ventaA = grp_venta($this);
    $ventaB = grp_venta($this);

    $this->grupos->cambiar($ventaA->id, $this->mayorista3->id, 'Solo la venta A', $this->asesor);

    // Recalcular B (sin override) y agregar mas a A: cada una conserva SU grupo.
    app(BorradorVentaService::class)->recalcular($ventaB->id, $this->asesor);
    app(BorradorVentaService::class)->recalcular($ventaA->id, $this->asesor);

    expect(grp_precios($ventaA))->toBe([grp_esperado($this, 30)])
        ->and(grp_precios($ventaB))->toBe([grp_esperado($this, 10)])
        ->and($ventaB->fresh()->grupo_override)->toBeFalse()
        ->and((int) Customer::query()->whereKey($this->cliente->id)->value('customer_group_id'))->toBe($this->vip->id);

    // El catalogo de cada venta tambien usa el grupo de SU venta.
    $precioDe = fn ($borrador) => round((float) collect(app(CatalogoVentaService::class)->matriz($this->configurable->id, $this->cliente->id, $this->asesor, 1, $borrador->id)->celdas)
        ->first(fn ($c) => $c->variante->productId === $this->variantes[0]->id)->variante->precioUnitario, 2);

    expect($precioDe($ventaA))->toBe(grp_esperado($this, 30))->and($precioDe($ventaB))->toBe(grp_esperado($this, 10));
});

// ---------------------------------------------------------------- atomicidad

test('ATOMICIDAD: si el recalculo falla, rollback completo: grupo anterior, precios anteriores y ningun evento', function () {
    $borrador = grp_venta($this);
    $antes = grp_precios($borrador);

    Event::listen('checkout.cart.collect.totals.before', fn () => throw new RuntimeException('fallo simulado del recalculo'));

    expect(fn () => $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'Compra mayor', $this->asesor))
        ->toThrow(RuntimeException::class, 'fallo simulado del recalculo');

    $borrador = $borrador->fresh();

    expect($borrador->grupo_override)->toBeFalse()
        ->and($borrador->grupo_efectivo_id)->toBe($this->vip->id)
        ->and($borrador->grupo_eventos)->toBe(0)
        ->and(GrupoEventoBorrador::query()->count())->toBe(0)
        ->and(grp_precios($borrador))->toBe($antes);
});

test('un override cuyo grupo ya no existe falla cerrado: nunca cae al grupo del cliente ni al invitado', function () {
    $borrador = grp_venta($this);
    $this->grupos->cambiar($borrador->id, $this->mayorista3->id, 'x', $this->asesor);
    $cartId = (int) $borrador->fresh()->cart_id;

    BorradorVenta::query()->whereKey($borrador->id)->update(['grupo_efectivo_id' => 999999]);

    expect(grp_error(fn () => app(ContextoPrecioComercial::class)->durante($cartId, $this->cliente->id, fn () => app(ContextoPrecioComercial::class)->grupo())))
        ->toBe(ContextoInvalido::GRUPO_OVERRIDE_INEXISTENTE);
});
