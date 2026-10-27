<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

/*
 * PAG-B — ayudas de prueba del dominio de Pagos / Cartera. Todo se crea dentro de la transaccion de cada
 * prueba (se revierte sola). Nada de esto toca datos reales ni usa el pedido 41.
 */

require_once __DIR__.'/helpersFase03b.php';

if (! function_exists('pagb_cliente')) {
    function pagb_cliente(): Customer
    {
        return f03b_cliente(f03b_grupo('PB'));
    }
}

if (! function_exists('pagb_pedido')) {
    /** Pedido de Bagisto con total en moneda base; `$extra` pisa cualquier atributo (status, created_at…). */
    function pagb_pedido(Customer $cliente, float $total, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'customer_id' => $cliente->id,
            'status' => 'pending_payment',
            'grand_total' => $total, 'base_grand_total' => $total,
            'sub_total' => $total, 'base_sub_total' => $total,
            'base_grand_total_refunded' => 0,
            'order_currency_code' => 'COP', 'base_currency_code' => 'COP',
        ], $extra));
    }
}

if (! function_exists('pagb_factura')) {
    function pagb_factura(Order $pedido, string $estado = 'pending', ?float $total = null): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'order_id' => $pedido->id, 'state' => $estado, 'total_qty' => 1,
            'base_grand_total' => $total ?? $pedido->base_grand_total, 'grand_total' => $total ?? $pedido->base_grand_total,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

if (! function_exists('pagb_transaccion')) {
    /** Transaction nativa (`order_transactions`) sobre una factura. */
    function pagb_transaccion(Order $pedido, int $facturaId, float $monto): int
    {
        return (int) DB::table('order_transactions')->insertGetId([
            'transaction_id' => 'pagb-'.Str::uuid(), 'payment_method' => 'transferencia', 'amount' => $monto,
            'invoice_id' => $facturaId, 'order_id' => $pedido->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

if (! function_exists('pagb_pago')) {
    function pagb_pago(Customer $cliente, array $extra = []): Pago
    {
        $clave = (string) Str::uuid();

        return Pago::query()->create(array_merge([
            'customer_id' => $cliente->id,
            'monto' => 100000,
            'medio_cobro' => 'transferencia',
            'fecha_pago' => now()->toDateString(),
            'referencia' => 'REF-1',
            'referencia_normalizada' => 'REF1',
            'mecanismo' => 'ventas_asesor',
            'idempotency_key' => $clave,
            'huella_solicitud' => hash('sha256', $clave),
            'registrado_por_admin_id' => 1,
        ], $extra));
    }
}

if (! function_exists('pagb_aplicar')) {
    function pagb_aplicar(Pago $pago, Order $pedido, float $monto, array $extra = []): AplicacionPago
    {
        return AplicacionPago::query()->create(array_merge([
            'pago_id' => $pago->id, 'order_id' => $pedido->id, 'monto' => $monto,
        ], $extra));
    }
}

if (! function_exists('pagb_atribuir')) {
    /** Atribucion comercial del pedido a un asesor (o ninguna fila si `$asesor` es null: pedido historico). */
    function pagb_atribuir(Order $pedido, ?Admin $asesor): void
    {
        if ($asesor === null) {
            return;
        }

        DB::table('bylopez_va_order_attributions')->insert([
            'order_id' => $pedido->id, 'advisor_id' => $asesor->id, 'advisor_name_snapshot' => $asesor->name,
            'commercial_owner_type' => TipoPropiedadComercial::ASESOR->value,
            'commercial_owner_source' => OrigenPropiedadComercial::AUTO_ASESOR->value,
            'sales_channel' => CanalVenta::WHATSAPP->value, 'creation_source' => MecanismoCreacion::VENTAS_ASESOR->value,
            'attribution_status' => EstadoAtribucion::COMPLETA->value, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

if (! function_exists('pagb_corte')) {
    /** Fija la fecha de corte de Cartera para la prueba (null = Cartera inactiva). */
    function pagb_corte(?string $fecha = '2000-01-01'): void
    {
        config(['ventasasesor.cartera.fecha_corte' => $fecha]);
    }
}

if (! function_exists('pagb_asesor')) {
    function pagb_asesor(array $permisos): Admin
    {
        return f03b_admin('custom', $permisos);
    }
}

if (! function_exists('pagb_estados')) {
    /** @return list<EstadoPago> */
    function pagb_estados(): array
    {
        return EstadoPago::cases();
    }
}
