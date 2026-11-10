<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as InvalidaLog;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException;
use ByLopez\VentasAsesor\Models\AjusteObligacion;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Services\Credito\AjusteObligacionService;
use ByLopez\VentasAsesor\Services\Credito\AplicarCreditoService;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
| D10 V1 — ayudas de prueba compartidas (Feature en Logistica y Concurrencia en VentasAsesor).
| Escenario base: el pedido A vale 70.000 (50.000 de mercancia + 20.000 de flete), el cliente lo PAGA completo (transferencia
| confirmada, dinero real recibido) y se condona el flete (error de ByLopez) → saldo a favor de 20.000 en A. El pedido B vale 50.000.
*/

require_once __DIR__.'/helpersFase03b.php';
require_once __DIR__.'/helpersPagB.php';

/** Administrador autorizado de la prueba: un rol `all` propio, designado como el unico que opera dinero (como el rol #1 en produccion). */
function d10_admin(): object
{
    // Varias llamadas en una prueba comparten el mismo rol administrador (si no, la ultima le quitaria la autorizacion a la anterior).
    $designado = (int) config('ventasasesor.credito.rol_administrador_id');

    if ($designado !== 1 && Role::query()->whereKey($designado)->exists()) {
        return Admin::factory()->create(['role_id' => $designado]);
    }

    $admin = f03b_admin('all');
    config()->set('ventasasesor.credito.rol_administrador_id', (int) $admin->role_id);

    return $admin;
}

/** Pedido con factura pendiente, metodo de pago y una linea sin enviar (como el flujo real de PAG-E). */
function d10_pedido($cliente, float $total, float $flete = 0.0): object
{
    $pedido = pagb_pedido($cliente, $total, ['base_shipping_amount' => $flete, 'shipping_amount' => $flete]);
    pagb_factura($pedido, 'pending', $total);
    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Pago contra entrega', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('order_items')->insert([
        'order_id' => $pedido->id, 'sku' => 'D10-'.$pedido->id, 'type' => 'simple', 'name' => 'Prenda', 'qty_ordered' => 2, 'qty_shipped' => 0, 'qty_invoiced' => 0,
        'qty_canceled' => 0, 'qty_refunded' => 0, 'price' => $total / 2, 'base_price' => $total / 2, 'total' => $total, 'base_total' => $total,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $pedido;
}

function d10_pagar(object $admin, int $orderId, string $monto, string $medio = 'transferencia'): int
{
    $datos = ['monto' => $monto, 'medio_cobro' => $medio, 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-'.Str::random(6)];
    $medio === 'transferencia' && $datos['plataforma'] = 'nequi';
    $pago = app(RegistrarPagoService::class)->registrar($admin, $orderId, $datos, 'd10-pago-'.Str::uuid())->pagoId;
    app(ConfirmarPagoService::class)->confirmar($admin, $pago);

    return $pago;
}

/** @return array{cliente: mixed, a: object, b: object, admin: object} A pagado 70.000 con 20.000 de flete condonado; B por cobrar 50.000. */
function d10_escenario(string $medioA = 'transferencia'): array
{
    pagb_corte('2000-01-01');
    $cliente = pagb_cliente();
    $admin = d10_admin();
    $a = d10_pedido($cliente, 70000, 20000);
    d10_pagar($admin, $a->id, '70000', $medioA);
    app(AjusteObligacionService::class)->condonarFlete($admin, $a->id, '20000', AjusteObligacion::CAUSA_ERROR_BYLOPEZ, 'Error de despacho de ByLopez', 'd10-aju-'.Str::uuid());
    $b = d10_pedido($cliente, 50000);

    return compact('cliente', 'a', 'b', 'admin');
}

function d10_saldo(int $orderId): object
{
    return app(CarteraConsultaService::class)->saldosDePedidos([$orderId])[$orderId];
}

function d10_credito(int $orderId): array
{
    return app(CreditoClienteService::class)->dePedido($orderId);
}

function d10_aplicar(array $e, string $monto = '20000', ?string $clave = null): array
{
    return app(AplicarCreditoService::class)->aplicar($e['admin'], $e['a']->id, $e['b']->id, $monto, $clave ?? 'd10-apl-'.Str::uuid());
}

function d10_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Rechazo|InvalidaLog|RevisionPagoInvalidaException $e) {
        return $e->codigo;
    }

    return null;
}

function d10_transacciones(int $orderId): float
{
    return (float) DB::table('order_transactions')->where('order_id', $orderId)->sum('amount');
}
