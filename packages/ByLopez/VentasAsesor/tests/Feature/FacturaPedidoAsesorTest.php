<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03b.php';
require_once __DIR__.'/../helpersRt04b1a.php';

uses(TestCase::class);

beforeEach(fn () => Mail::fake());

/*
| «Ver factura» en Mis ventas / Pedidos: abre el PDF de la factura (inline, otra pestana) solo si el pedido esta PAGADO y
| tiene factura, y solo para quien puede ver ese pedido. Pruebas con peticiones HTTP directas.
*/

function fpa_asesor(string $nombre): Admin
{
    $admin = f03b_admin('custom', [Autz::PERMISO_MODULO, 'ventas_asesor.pedidos', Autz::PERMISO_PEDIDOS_VER_PROPIOS]);
    $admin->update(['name' => $nombre]);

    return $admin;
}

/** Pedido atribuido al asesor, con factura real; $pagado = factura `paid` + cobro por el total. */
function fpa_pedido(Admin $asesor, bool $pagado): array
{
    $e = rt04b1a_pedido();
    $pedido = $e['pedido'];
    DB::table('orders')->where('id', $pedido->id)->update(['status' => 'processing']);

    DB::table('bylopez_va_order_attributions')->insert([
        'order_id' => $pedido->id, 'advisor_id' => $asesor->id, 'advisor_name_snapshot' => $asesor->name,
        'commercial_owner_type' => TipoPropiedadComercial::ASESOR->value, 'commercial_owner_source' => OrigenPropiedadComercial::AUTO_ASESOR->value,
        'sales_channel' => CanalVenta::WHATSAPP->value, 'creation_source' => MecanismoCreacion::VENTAS_ASESOR->value,
        'attribution_status' => EstadoAtribucion::COMPLETA->value, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $factura = rt04b1a_facturar($pedido->fresh(), $e['item']);

    if ($pagado) {
        DB::table('invoices')->where('id', $factura->id)->update(['state' => 'paid']);
        DB::table('order_transactions')->insert([
            'transaction_id' => 'fpa-'.uniqid(), 'payment_method' => 'moneytransfer', 'amount' => (float) $factura->base_grand_total,
            'invoice_id' => $factura->id, 'order_id' => $pedido->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['pedido' => $pedido->fresh(), 'factura' => $factura];
}

test('pedido pagado propio: el listado ofrece «Ver factura» en otra pestana y la ruta entrega el PDF inline', function () {
    $a = fpa_asesor('Asesora Factura');
    $p = fpa_pedido($a, true);

    $lista = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk();
    $lista->assertSee('data-accion="ver_factura"', false)->assertSee('target="_blank"', false);

    $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $p['pedido']->id]))
        ->assertOk()->assertSee('data-accion="ver_factura"', false);

    $r = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.factura', $p['pedido']->id))->assertOk();

    expect($r->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($r->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

test('pedido sin pagar: no hay boton y la ruta responde 404', function () {
    $a = fpa_asesor('Asesora Sin Pago');
    $p = fpa_pedido($a, false);

    $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()
        ->assertDontSee('data-accion="ver_factura"', false);

    $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.factura', $p['pedido']->id))->assertNotFound();
});

test('un asesor no abre la factura de un pedido ajeno (403) ni de uno inexistente (404)', function () {
    $a = fpa_asesor('Asesora A');
    $b = fpa_asesor('Asesora B');
    $p = fpa_pedido($a, true);

    $this->actingAs($b, 'admin')->get(route('admin.ventas_asesor.pedidos.factura', $p['pedido']->id))->assertForbidden();
    $this->actingAs($b, 'admin')->get(route('admin.ventas_asesor.pedidos.factura', 99999999))->assertNotFound();
});

test('sin sesion no se llega a la factura', function () {
    $a = fpa_asesor('Asesora Anonima');
    $p = fpa_pedido($a, true);

    $this->get(route('admin.ventas_asesor.pedidos.factura', $p['pedido']->id))->assertRedirect();
});
