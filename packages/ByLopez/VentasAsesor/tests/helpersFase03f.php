<?php

use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\EntregaBorradorService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Models\Product;

require_once __DIR__.'/helpersFase03b.php';

/*
| Helpers Fase 03F (solo lpz_saas_testing). Datos de fixture, no reglas de negocio.
*/

if (! function_exists('f03f_configurarBase')) {
    /**
     * Configuracion AUDITADA de produccion (Doc. 05 §32.5), la misma que 03E: contra entrega
     * con factura pendiente; transferencia aun con factura `paid` (D-48 sin desplegar). Y los
     * carriers de ByLopez activos (nacen inactivos).
     */
    function f03f_configurarBase(?string $tarifaDomicilio = '8000'): void
    {
        foreach ([
            'sales.payment_methods.cashondelivery.generate_invoice' => '1',
            'sales.payment_methods.cashondelivery.invoice_status' => 'pending',
            'sales.payment_methods.moneytransfer.generate_invoice' => '1',
            'sales.payment_methods.moneytransfer.invoice_status' => 'paid',
        ] as $codigo => $valor) {
            DB::table('core_config')->updateOrInsert(
                ['code' => $codigo, 'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null],
                ['value' => $valor],
            );
        }

        // CoreConfigRepository cachea getConfigData() y un INSERT por SQL no lo invalida (03E).
        Cache::flush();

        f03f_activarCarriers($tarifaDomicilio);
    }
}

if (! function_exists('f03f_activarCarriers')) {
    function f03f_activarCarriers(?string $tarifaDomicilio = '8000'): void
    {
        config([
            'carriers.'.PuntosRecogida::METODO.'.active' => true,
            'carriers.domicilio_local.active' => true,
            'carriers.domicilio_local.default_rate' => $tarifaDomicilio,
            'carriers.envio_nacional.active' => true,
        ]);
    }
}

if (! function_exists('f03f_simple')) {
    /**
     * Producto SIMPLE con gestion de stock. Su reserva nativa (manageInventory) SI funciona,
     * a diferencia de la variante de un configurable en Bagisto 2.4.9 (Doc. 05 §39).
     */
    function f03f_simple(float $precio = 10000.0): Product
    {
        $producto = (new ProductFaker([
            'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
            'attribute_value' => [
                'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
                'price' => ['float_value' => $precio], 'guest_checkout' => ['boolean_value' => true],
            ],
        ]))->getSimpleProductFactory()->create();

        DB::table('product_attribute_values')->where('product_id', $producto->id)
            ->where('attribute_id', DB::table('attributes')->where('code', 'manage_stock')->value('id'))
            ->update(['boolean_value' => 1]);

        return $producto->fresh();
    }
}

if (! function_exists('f03f_direccion')) {
    function f03f_direccion(array $extra = []): DireccionComercial
    {
        return new DireccionComercial(...array_merge([
            'nombre' => 'Ana', 'apellido' => 'Prueba', 'lineas' => ['Calle 1 # 2-3'], 'ciudad' => 'Bogotá',
            'departamento' => 'DC', 'pais' => 'co', 'codigoPostal' => null, 'telefono' => '3001234567', 'email' => null,
        ], $extra));
    }
}

if (! function_exists('f03f_borradorListo')) {
    /**
     * Borrador completo y confirmable: solicitud, linea, facturacion, entrega y pago.
     *
     * @param  list<array{0: Product, 1: Product, 2: int, 3?: int}>  $lineas
     *                                                                        [configurable, variante, cantidad en carrito, cantidad solicitada]
     * @param  string  $entrega  pickup (recogida, bagisto-eu/pickup) | domicilio_local | envio_nacional
     * @param  ?string  $pago  null = el permitido por defecto: con recogida la extension quita
     *                         cashondelivery, asi que queda moneytransfer (decision de Camilo)
     */
    function f03f_borradorListo(int $customerId, $asesor, array $lineas, string $entrega = PuntosRecogida::METODO, ?string $pago = null): BorradorVenta
    {
        $pago ??= $entrega === PuntosRecogida::METODO ? 'moneytransfer' : 'cashondelivery';

        $borrador = app(BorradorVentaService::class)->crear($customerId, $asesor, CanalVenta::WHATSAPP);

        foreach ($lineas as $l) {
            app(SolicitudBorradorService::class)->crear($borrador->id, $l[0]->id, $l[1]->id, $l[3] ?? $l[2], $asesor);
            app(LineasBorradorService::class)->agregar($borrador->id, $l[0]->id, $l[1]->id, $l[2], $asesor);
        }

        $entregas = app(EntregaBorradorService::class);
        $entregas->establecerFacturacion($borrador->id, OrigenDireccion::puntual(f03f_direccion()), $asesor);
        $entregas->establecerEnvio($borrador->id, $entrega === PuntosRecogida::METODO
            ? OrigenDireccion::puntoRecogida()
            : OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7']])), $asesor);
        $entregas->seleccionarMetodo($borrador->id, $entrega, $asesor);

        app(PagoBorradorService::class)->seleccionar($borrador->id, $pago, $asesor);

        return $borrador->fresh();
    }
}

if (! function_exists('f03f_total')) {
    /** El total que el asesor ve en el borrador (grand_total del Cart de Bagisto). */
    function f03f_total(BorradorVenta $borrador): float
    {
        return round((float) CartModel::query()->whereKey($borrador->fresh()->cart_id)->value('grand_total'), 2);
    }
}

if (! function_exists('f03f_sinCommitPrematuro')) {
    /**
     * OrderRepository::createOrderIfNotThenRetry() hace DB::commit() en el finally de CADA
     * intento, tambien tras un rollback (Doc. 05 §37.1). Dentro de la transaccion de
     * DatabaseTransactions (nivel 1), un pedido que falla haria COMMIT real de la prueba y
     * dejaria sus datos en lpz_saas_testing. Se abren tantos niveles (savepoints) como
     * intentos tiene el repositorio: esos commits extra solo bajan el contador, nunca llegan
     * al nivel 1. Al salir se vuelve al nivel de partida sin tocar la transaccion de la prueba.
     *
     * Es una defensa de la PRUEBA, no del producto: en produccion ConfirmarVentaService llama
     * al repositorio sin transaccion exterior (nivel 0), justamente por este comportamiento.
     */
    function f03f_sinCommitPrematuro(callable $operacion): mixed
    {
        $base = DB::transactionLevel();
        $intentos = (int) (core()->getConfigData('sales.order_settings.order_creation.max_retry_attempts') ?? 3);

        for ($i = 0; $i < max(1, $intentos); $i++) {
            DB::beginTransaction();
        }

        try {
            return $operacion();
        } finally {
            while (DB::transactionLevel() > $base) {
                DB::commit();
            }
        }
    }
}

if (! function_exists('f03f_error')) {
    /** Ejecuta y devuelve la excepcion de dominio (o null si no lanzo). */
    function f03f_error(callable $accion): ConfirmacionVentaInvalidaException|BorradorVentaInvalidoException|PedidoRechazadoPorCercoException|null
    {
        try {
            f03f_sinCommitPrematuro($accion);
        } catch (ConfirmacionVentaInvalidaException|BorradorVentaInvalidoException|PedidoRechazadoPorCercoException $e) {
            return $e;
        }

        return null;
    }
}
