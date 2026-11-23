<?php

use ByLopez\VentasAsesor\Models\Pago;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| 2026-11-22 (Camilo): el pago que registra el ADMINISTRADOR queda aprobado en el acto; el de cualquier otro rol sigue en la
| bandeja de Cartera. Si no se puede aprobar (sin factura), el pago no se pierde: queda sin revisar y se avisa.
*/

beforeEach(function () {
    pag_linea_base();
    pagb_corte('2000-01-01');
    config()->set('ventasasesor.credito.rol_administrador_id', 1);
});

function pac_pedido(float $total, bool $conFactura = true)
{
    $pedido = pagb_pedido(pagb_cliente(), $total);
    if ($conFactura) {
        pagb_factura($pedido, 'pending', $total);
    }
    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Pago contra entrega', 'created_at' => now(), 'updated_at' => now()]);

    return $pedido;
}

function pac_cuerpo(string $clave, array $extra = []): array
{
    return array_merge(['monto' => '300000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-'.$clave, 'idempotency_key' => $clave], $extra);
}

test('el administrador registra un pago y queda aprobado con su Transaction; reenviarlo no duplica', function () {
    $pedido = pac_pedido(500000);
    $admin = Admin::factory()->create(['role_id' => 1]);
    $url = route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]);

    $this->actingAs($admin, 'admin')->postJson($url, pac_cuerpo('clave-auto-admin-0001'))
        ->assertCreated()->assertJsonPath('estado', 'confirmado')->assertJsonPath('confirmado', true)->assertJsonPath('aviso_confirmacion', null);

    $pago = Pago::query()->latest('id')->firstOrFail();
    expect($pago->estado->value)->toBe('confirmado')
        ->and((int) $pago->revisado_por_admin_id)->toBe((int) $admin->id)
        ->and(DB::table('order_transactions')->where('order_id', $pedido->id)->sum('amount'))->toEqual(300000);

    $this->actingAs($admin, 'admin')->postJson($url, pac_cuerpo('clave-auto-admin-0001'))
        ->assertOk()->assertJsonPath('duplicado', true)->assertJsonPath('estado', 'confirmado');
    expect(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(1);
});

test('otro rol (aunque sea `all`) registra y el pago queda sin revisar en Cartera', function () {
    $pedido = pac_pedido(500000);
    $url = route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]);
    $asesora = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.revisar', 'ventas_asesor.cartera.view_all']);

    $this->actingAs($asesora, 'admin')->postJson($url, pac_cuerpo('clave-auto-asesora-01'))
        ->assertCreated()->assertJsonPath('estado', 'sin_revisar')->assertJsonPath('confirmado', false);

    $otroAll = Admin::factory()->create(['role_id' => \Webkul\User\Models\Role::factory()->create(['permission_type' => 'all', 'permissions' => null])->id]);
    $this->actingAs($otroAll, 'admin')->postJson($url, pac_cuerpo('clave-auto-otroall-1', ['monto' => '100000']))
        ->assertCreated()->assertJsonPath('estado', 'sin_revisar');

    expect(DB::table('order_transactions')->where('order_id', $pedido->id)->count())->toBe(0);
});

test('sin factura el pago del administrador queda registrado sin revisar y se devuelve el motivo', function () {
    $pedido = pac_pedido(500000, conFactura: false);
    $admin = Admin::factory()->create(['role_id' => 1]);

    $r = $this->actingAs($admin, 'admin')->postJson(route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]), pac_cuerpo('clave-auto-sinfact-01'))
        ->assertCreated()->assertJsonPath('estado', 'sin_revisar')->assertJsonPath('confirmado', false);

    expect($r->json('aviso_confirmacion'))->not->toBeEmpty()
        ->and(Pago::query()->findOrFail($r->json('pago_id'))->estado->value)->toBe('sin_revisar');
});

test('con la opcion apagada el administrador tambien pasa por Cartera', function () {
    config()->set('ventasasesor.cartera.auto_confirmar_administrador', false);
    $pedido = pac_pedido(500000);

    $this->actingAs(Admin::factory()->create(['role_id' => 1]), 'admin')
        ->postJson(route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]), pac_cuerpo('clave-auto-apagada-1'))
        ->assertCreated()->assertJsonPath('estado', 'sin_revisar');
});
