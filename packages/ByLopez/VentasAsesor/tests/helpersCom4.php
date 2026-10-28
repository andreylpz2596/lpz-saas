<?php

use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as InvalidaComision;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\CostoEstado;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/helpersCom.php';
require_once __DIR__.'/helpersPagB.php';
require_once __DIR__.'/../../Logistica/tests/helpersDespacho.php';

/* Helpers de COM4 (solo lpz_saas_testing): escenarios de comisiones con ventas reales. Datos de fixture, no reglas. */

/** @return array{asesor: mixed, cliente: mixed, configurable: Product, variante: Product} */
function com4_escenario(string $porcentaje = '10', ?int $umbral = 1000, bool $costoConfirmado = true, int $stock = 2000): array
{
    Mail::fake();
    f03f_configurarBase();
    pagb_corte('2000-01-01');
    config(['ventasasesor.comisiones.fecha_inicio' => '2000-01-01']);

    $asesor = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos.create', 'ventas_asesor.pedidos.view_own', 'ventas_asesor.comisiones.view_own']));
    $configurable = f03b_configurable(10000.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, $stock);
    com_costo($variante, 6000.0);

    if ($costoConfirmado) {
        $familia = (int) app(RentabilidadVentaService::class)->familiasDe([(int) $variante->id])[(int) $variante->id];
        CostoEstado::query()->firstOrCreate(['familia_id' => $familia, 'version' => 1], ['estado' => CostoEstado::CONFIRMADO, 'vigente_desde' => now()->subDays(40), 'motivo' => 'COM4', 'creado_por_admin_id' => $asesor->id, 'creado_por_name_snapshot' => 'COM4']);
    }

    [$inicioMes] = MesComercial::rango(MesComercial::de(now()));
    ComisionAcuerdo::query()->create([
        'asesor_admin_id' => $asesor->id, 'version' => 1, 'porcentaje' => $porcentaje, 'umbral_mensual_unidades' => $umbral, 'vigente_desde' => $inicioMes,
        'activo' => true, 'motivo' => 'COM4', 'creado_por_admin_id' => $asesor->id, 'creado_por_name_snapshot' => 'COM4',
    ]);

    return ['asesor' => $asesor, 'cliente' => f03b_cliente(f03b_grupo('COM4')), 'configurable' => $configurable, 'variante' => $variante];
}

/** Venta real de `$unidades` (recogida + transferencia). La atribucion del admin de prueba se fija a asesor (fixture, como Com03). */
function com4_vender(array $e, int $unidades): Order
{
    $pedido = com_vender($e['cliente']->id, $e['asesor'], $e['configurable'], $e['variante'], $unidades);
    DB::table('bylopez_va_order_attributions')->where('order_id', $pedido->id)->update(['commercial_owner_type' => 'advisor', 'advisor_id' => $e['asesor']->id]);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);

    return $pedido->fresh();
}

/** Pago por Cartera (registrar + confirmar) por el total del pedido. */
function com4_pagar(Order $pedido, ?float $monto = null): int
{
    $admin = f03b_admin('all');
    // Fixture: la factura nace PENDIENTE (como en la operacion real); la base de pruebas la deja pagada y Cartera la marcaria «sin evidencia».
    DB::table('invoices')->where('order_id', $pedido->id)->update(['state' => 'pending']);
    $pago = app(RegistrarPagoService::class)->registrar($admin, (int) $pedido->id, [
        'monto' => number_format((float) ($monto ?? $pedido->base_grand_total), 2, '.', ''), 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi',
        'fecha_pago' => now()->toDateString(), 'referencia' => 'COM4-'.Str::random(6),
    ], 'com4-pago-'.Str::uuid())->pagoId;

    if (! DB::table('invoices')->where('order_id', $pedido->id)->exists()) {
        pagb_factura($pedido);
    }

    app(ConfirmarPagoService::class)->confirmar($admin, $pago);

    return $pago;
}

/** Entrega en el mostrador (recogida) y, si `$validar`, validacion de la entrega por el administrador. */
function com4_entregar(Order $pedido, bool $validar = true): int
{
    $r = app(DespachoService::class)->entregarEnBodega(log2_bodega(), new SolicitudRecogida((int) $pedido->id, 'com4-ent-'.Str::uuid()));

    if ($validar) {
        app(ValidarEntregaService::class)->validar(f03b_admin('all'), (int) $r->shipmentId, 'com4-val-'.Str::uuid(), null);
    }

    return (int) $r->shipmentId;
}

function com4_linea(Order $pedido): ComisionLinea
{
    return ComisionLinea::query()->where('order_id', $pedido->id)->where('estado', '!=', ComisionLinea::ANULADA)->orderBy('id')->firstOrFail();
}

/** Lo que muestra el modulo para el pedido, leido como el administrador. */
function com4_pantalla(Order $pedido): array
{
    $p = app(ConsultaComisionesService::class)->detallePedido(f03b_admin('all'), (int) $pedido->id);

    return ['condiciones' => $p['condiciones'], 'linea' => $p['lineas'][0]];
}

function com4_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (InvalidaComision $e) {
        return $e->codigo;
    }

    return null;
}
