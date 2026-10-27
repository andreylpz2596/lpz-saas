<?php

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido as Tipo;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\ClasificacionPedido;
use ByLopez\VentasAsesor\Services\Comisiones\TipoOperacionPedidoService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCom.php';
require_once __DIR__.'/../helpersRt04b1a.php';

uses(TestCase::class);

/*
| G-11 / COM-D-15 — clasificacion comercial POR PEDIDO desde Nueva venta. Solo lpz_saas_testing.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    $this->cliente = f03b_cliente(f03b_grupo('G11'));
    $this->configurable = f03b_configurable(8500.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 30);
    $this->asesor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor', BorradorVentaService::PERMISO]);
    $this->admin = f03b_admin('all');
});

function g11_borrador($test, $actor): BorradorVenta
{
    return f03f_borradorListo($test->cliente->id, $actor, [[$test->configurable, $test->variante, 1]]);
}

function g11_confirmar(BorradorVenta $borrador, $actor, Tipo|string|null $tipo): Order
{
    $r = f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $actor, f03f_total($borrador), null, $tipo));

    return Order::query()->findOrFail($r->orderId);
}

test('G11-1/4 la venta del asesor queda venta_normal por defecto, 1:1 con el pedido, con actor y borrador', function () {
    $borrador = g11_borrador($this, $this->asesor);
    $pedido = g11_confirmar($borrador, $this->asesor, null);
    $fila = ClasificacionPedido::query()->where('order_id', $pedido->id)->sole();

    expect($fila->tipo_operacion)->toBe(Tipo::VENTA_NORMAL)
        ->and($fila->origen)->toBe(ClasificacionPedido::ORIGEN_NUEVA_VENTA)
        ->and($fila->borrador_id)->toBe((int) $borrador->id)
        ->and($fila->actor_admin_id)->toBe((int) $this->asesor->id)
        ->and($fila->actor_nombre_snapshot)->toBe($this->asesor->name)
        ->and(app(TipoOperacionPedidoService::class)->esComisionable((int) $pedido->id))->toBeTrue()
        // Reintento idempotente de la confirmacion: mismo pedido, ninguna segunda fila.
        ->and(f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->asesor, f03f_total($borrador)))->orderId)->toBe((int) $pedido->id)
        ->and(ClasificacionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(fn () => $fila->update(['tipo_operacion' => Tipo::MUESTRA]))->toThrow(LogicException::class);
});

test('G11-2 el asesor no puede forzar muestra / reposicion / garantia: rechazo antes de crear el pedido', function () {
    foreach ([Tipo::MUESTRA, 'reposicion', 'garantia'] as $tipo) {
        $borrador = g11_borrador($this, $this->asesor);
        $error = f03f_error(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->asesor, f03f_total($borrador), null, $tipo));

        expect($error?->codigo)->toBe(Rechazo::TIPO_OPERACION_SIN_PERMISO)
            ->and($borrador->fresh()->order_id)->toBeNull();
    }

    $borrador = g11_borrador($this, $this->asesor);

    // Por HTTP (API de Nueva venta): 403 y sin pedido.
    $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.confirmar', $borrador->id), ['total_esperado' => f03f_total($borrador), 'tipo_operacion' => 'garantia'])
        ->assertForbidden()
        ->assertJson(['codigo' => Rechazo::TIPO_OPERACION_SIN_PERMISO]);

    expect($borrador->fresh()->order_id)->toBeNull()
        ->and(f03f_error(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $this->admin, f03f_total($borrador), null, 'regalo'))?->codigo)
        ->toBe(Rechazo::TIPO_OPERACION_INVALIDO);
});

test('G11-3/5 admin (all) puede elegir los cuatro tipos; solo venta_normal es comisionable', function () {
    $servicio = app(TipoOperacionPedidoService::class);
    $pedidos = [];

    foreach (Tipo::cases() as $tipo) {
        $pedidos[$tipo->value] = (int) g11_confirmar(g11_borrador($this, $this->admin), $this->admin, $tipo)->id;
    }

    $tipos = $servicio->dePedidos(array_values($pedidos));

    foreach (Tipo::cases() as $tipo) {
        expect($tipos[$pedidos[$tipo->value]])->toBe($tipo)
            ->and($servicio->esComisionable($pedidos[$tipo->value]))->toBe($tipo === Tipo::VENTA_NORMAL);
    }

    expect($servicio->puedeClasificar($this->admin))->toBeTrue()
        ->and($servicio->puedeClasificar($this->asesor))->toBeFalse();
});

test('G11-6 los pedidos sin clasificar (historicos u otros canales) no se rellenan ni son comisionables', function () {
    ['pedido' => $pedido] = rt04b1a_pedido();
    $servicio = app(TipoOperacionPedidoService::class);

    expect($servicio->dePedido((int) $pedido->id))->toBeNull()
        ->and($servicio->esComisionable((int) $pedido->id))->toBeFalse()
        ->and(ClasificacionPedido::query()->where('order_id', $pedido->id)->exists())->toBeFalse();
});
