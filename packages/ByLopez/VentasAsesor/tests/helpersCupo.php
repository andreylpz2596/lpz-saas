<?php

use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Services\Cupo\CupoCarteraClienteService;
use ByLopez\VentasAsesor\Services\Cupo\GestionCupoCarteraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Cupo de cartera (Doc. 35) — ayudas de prueba. Solo lpz_saas_testing o la base exclusiva de la rama. Datos de fixture, no reglas.
| Reutiliza las de D10 / Pagos (administrador autorizado, pedido con factura pendiente, pago confirmado).
*/

require_once __DIR__.'/helpersD10.php';
require_once __DIR__.'/helpersFase03f.php';

/**
 * Cartera activa (corte en el pasado), umbral por defecto y bloqueo de cupo activo o no.
 *
 * @return array{admin: object, cliente: object}
 */
function cupo_base(bool $bloqueo = true): array
{
    pagb_corte('2000-01-01');
    config(['ventasasesor.cupo.activo' => $bloqueo, 'ventasasesor.cupo.umbral_alerta_pct' => 80]);

    return ['admin' => d10_admin(), 'cliente' => pagb_cliente()];
}

/** Fija la politica por el servicio real (permiso, bloqueo del cliente e historial incluidos). */
function cupo_fijar(object $admin, object $cliente, string|int|float $monto, string $estado = 'activo', string $motivo = 'Prueba de cupo'): array
{
    return app(GestionCupoCarteraService::class)->establecer($admin, (int) $cliente->id, $monto, $estado, $motivo);
}

function cupo_de(object $cliente, bool $detalle = false): CupoCartera
{
    return app(CupoCarteraClienteService::class)->delCliente((int) $cliente->id, $detalle);
}

/** Pedido pendiente del cliente, dentro de Cartera (factura pendiente), sin pagos. `$extra` pisa atributos del pedido. */
function cupo_pedido(object $cliente, float $total, array $extra = []): object
{
    $pedido = pagb_pedido($cliente, $total, $extra);
    pagb_factura($pedido, 'pending', $total);
    // Metodo de pago del pedido: ConfirmarPagoService lo exige para poder confirmar un pago (como en el flujo real de PAG-E).
    DB::table('order_payment')->insert(['order_id' => $pedido->id, 'method' => 'cashondelivery', 'method_title' => 'Pago contra entrega', 'created_at' => now(), 'updated_at' => now()]);

    return $pedido;
}

/** Registra un pago (siempre nace sin revisar) y, si se pide, lo CONFIRMA. */
function cupo_pagar(object $admin, int $orderId, string $monto, bool $confirmar = true, string $medio = 'transferencia'): int
{
    $datos = ['monto' => $monto, 'medio_cobro' => $medio, 'fecha_pago' => now()->toDateString(), 'referencia' => 'CUPO-'.Str::random(6)];
    $medio === 'transferencia' && $datos['plataforma'] = 'nequi';
    $pago = app(RegistrarPagoService::class)->registrar($admin, $orderId, $datos, 'cupo-pago-'.Str::uuid())->pagoId;
    $confirmar && app(ConfirmarPagoService::class)->confirmar($admin, $pago);

    return $pago;
}

/** Codigo estable del rechazo de una operacion de cupo (null si no lanzo). */
function cupo_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (OperacionCupoInvalidaException $e) {
        return $e->codigo;
    }

    return null;
}

/**
 * Venta lista para confirmar de un producto SIMPLE con stock (flujo REAL: borrador con solicitud, linea, entrega y pago).
 * `$test->asesor` es quien vende. @return array{0: \ByLopez\VentasAsesor\Models\BorradorVenta, 1: float, 2: object} [borrador, total que ve el asesor, producto]
 */
function cupo_venta(object $test, object $cliente, float $precio, int $cantidad = 1, ?object $asesor = null): array
{
    $simple = f03f_simple($precio);
    f03b_stock($simple, 50);
    $borrador = f03f_borradorListo($cliente->id, $asesor ?? $test->asesor, [[$simple, $simple, $cantidad]]);

    return [$borrador, f03f_total($borrador), $simple];
}

/** Confirma la venta (dentro de la defensa de prueba contra el commit prematuro) y devuelve el rechazo de dominio o null. */
function cupo_confirmar(object $test, \ByLopez\VentasAsesor\Models\BorradorVenta $borrador, float $total, ?object $asesor = null): mixed
{
    return f03f_error(fn () => app(\ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService::class)->confirmar($borrador->id, $asesor ?? $test->asesor, $total));
}

/** La venta rechazada antes del pedido queda activa, sin token, sin pedido y sin reservar stock. */
function cupo_sin_pedido_ni_reserva(\ByLopez\VentasAsesor\Models\BorradorVenta $borrador, object $producto): void
{
    $b = \ByLopez\VentasAsesor\Models\BorradorVenta::query()->find($borrador->id);

    expect($b->estado)->toBe(\ByLopez\VentasAsesor\Enums\EstadoBorradorVenta::ACTIVO)->and($b->order_id)->toBeNull()
        ->and($b->getRawOriginal('confirmation_token'))->toBeNull()
        ->and(\Webkul\Sales\Models\Order::query()->where('cart_id', $borrador->cart_id)->count())->toBe(0)
        ->and((int) \Webkul\Product\Models\ProductOrderedInventory::query()->where('product_id', $producto->id)->sum('qty'))->toBe(0);
}

/** Cancelacion de Logistica (historial) por `$valor`: la fila que Cartera resta como `cancelado`. */
function cupo_cancelar_parte(object $pedido, float $valor, int $unidades = 1): void
{
    DB::table('bylopez_lg_cancelaciones')->insert([
        'order_id' => $pedido->id, 'motivo' => 'Cancelacion parcial de prueba', 'unidades' => $unidades, 'valor_cancelado' => $valor,
        'actor_admin_id' => 1, 'actor_name_snapshot' => 'QA', 'created_at' => now(),
    ]);
}
