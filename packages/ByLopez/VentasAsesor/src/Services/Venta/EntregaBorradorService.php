<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\DireccionBorrador;
use ByLopez\VentasAsesor\DataObjects\MetodoEntregaDisponible;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\DataObjects\VistaEntregaBorrador;
use ByLopez\VentasAsesor\DataObjects\VistaLineasBorrador;
use ByLopez\VentasAsesor\Exceptions\EntregaBorradorInvalidaException as EntregaInvalida;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorDireccionComercial;
use ByLopez\VentasAsesor\Support\Venta\CarritoComercial;
use Closure;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartAddress;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Customer\Models\CustomerAddressProxy;
use Webkul\Shipping\Facades\Shipping;
use Webkul\User\Contracts\Admin;

/**
 * Direccion y metodo de entrega del borrador (03D) sobre Bagisto, sin sistemas
 * paralelos:
 *
 * - Direcciones: Cart::saveAddresses() (copia al CartAddress; parent_address_id si es
 *   guardada). Una direccion puntual NO se guarda en el cliente. En 03F, OrderResource
 *   copia el snapshot a OrderAddress. No se usa el FormRequest del panel (exige email,
 *   contra RT-04); las reglas son las de RT-04 (ValidadorDireccionComercial).
 * - Recogida (D-41): la direccion de envio es la BODEGA real (fuente de inventario
 *   activa, PuntosRecogida) con los datos de quien recibe tomados de la facturacion.
 *   Bagisto exige direccion de envio para productos fisicos; no se inventa ninguna.
 * - Metodos: los que devuelve Shipping::collectRates() para el carrito y su destino. No
 *   hay lista propia: un carrier nuevo aparece solo. La seleccion es Cart::saveShipping
 *   Method(), que exige que el metodo este entre las tarifas.
 * - Guardar direcciones invalida el metodo (lo hace Bagisto) y se recalculan tarifas;
 *   cambiar lineas lo invalida LineasBorradorService.
 * - Stock: si una linea supera el stock actual se rechaza (la misma comprobacion que el
 *   panel antes de guardar direcciones). Nada se recorta.
 * - Concurrencia: bloqueo del borrador y del carrito (bloquearParaEditar), el mismo
 *   orden que el resto de operaciones; las tarifas son filas hijas del carrito.
 *
 * No crea pedidos, no crea Shipment, no elige transportadora y no procesa pagos.
 */
class EntregaBorradorService
{
    public function __construct(
        protected BorradorVentaService $borradores,
        protected LineasBorradorService $lineas,
        protected CarritoComercial $carritos,
        protected ValidadorDireccionComercial $validador,
        protected PuntosRecogida $puntosRecogida,
    ) {}

    /** $lineas: las del mismo borrador ya leidas en esta operacion (03G-B2); evita releerlas. */
    public function vista(int $borradorId, ?VistaLineasBorrador $lineas = null): VistaEntregaBorrador
    {
        $lineas = $lineas !== null && $lineas->borrador->id === $borradorId ? $lineas : null;
        $vista = $lineas?->borrador ?? $this->borradores->obtener($borradorId);
        $carrito = $vista->cartId === null ? null
            : CartProxy::modelClass()::query()->with(['billing_address', 'shipping_address', 'shipping_rates', 'items.product'])->find($vista->cartId);

        $facturacion = $carrito?->billing_address;
        $envio = $carrito?->shipping_address;
        $requiereEnvio = (bool) $carrito?->haveStockableItems();

        $metodos = $carrito ? $carrito->shipping_rates->sortBy('id')->values()->map(fn ($t) => $this->aMetodo($t))->all() : [];
        $seleccionado = null;

        foreach ($metodos as $metodo) {
            if ($carrito->shipping_method !== null && $metodo->codigo === $carrito->shipping_method) {
                $seleccionado = $metodo;
            }
        }

        $avisos = [];

        if ($this->lineasSinStock($borradorId, $lineas) !== []) {
            $avisos[] = VistaEntregaBorrador::AVISO_STOCK_INSUFICIENTE;
        }

        if ($requiereEnvio && $envio && $metodos === []) {
            $avisos[] = VistaEntregaBorrador::AVISO_METODOS_POR_CALCULAR;
        }

        return new VistaEntregaBorrador(
            borrador: $vista,
            facturacion: $facturacion ? $this->aDireccion($facturacion, DireccionBorrador::ROL_FACTURACION) : null,
            envio: $envio ? $this->aDireccion($envio, DireccionBorrador::ROL_ENVIO) : null,
            envioIgualFacturacion: (bool) $facturacion?->use_for_shipping,
            metodosDisponibles: $metodos,
            metodoSeleccionado: $seleccionado,
            requiereEnvio: $requiereEnvio,
            entregaCompleta: $facturacion !== null && (! $requiereEnvio || ($envio !== null && $seleccionado !== null)),
            avisos: $avisos,
        );
    }

    /**
     * Facturacion: guardada del cliente o puntual. Si el envio seguia a la facturacion
     * (use_for_shipping), la sigue tambien ahora; si no, el envio no se toca.
     */
    public function establecerFacturacion(int $borradorId, OrigenDireccion $origen, Admin $actor): VistaEntregaBorrador
    {
        $this->establecerFacturacionSinVista($borradorId, $origen, $actor);

        return $this->vista($borradorId);
    }

    /** Misma operacion sin reconstruir la vista (uso por lotes, 03G-B). */
    public function establecerFacturacionSinVista(int $borradorId, OrigenDireccion $origen, Admin $actor): void
    {
        if (! in_array($origen->tipo, [OrigenDireccion::GUARDADA, OrigenDireccion::PUNTUAL], true)) {
            throw new EntregaInvalida(EntregaInvalida::ORIGEN_NO_VALIDO);
        }

        $this->enBorrador($borradorId, $actor, function (CartContract $carrito) use ($origen) {
            $datos = $this->datosDeCliente($carrito, $origen);
            $datos['use_for_shipping'] = (bool) $carrito->billing_address?->use_for_shipping;

            Cart::saveAddresses(['billing' => $datos]);
            $this->recalcular();
        });
    }

    /** Envio: igual a la facturacion, guardada, puntual o punto de recogida (bodega). */
    public function establecerEnvio(int $borradorId, OrigenDireccion $origen, Admin $actor): VistaEntregaBorrador
    {
        $this->establecerEnvioSinVista($borradorId, $origen, $actor);

        return $this->vista($borradorId);
    }

    public function establecerEnvioSinVista(int $borradorId, OrigenDireccion $origen, Admin $actor): void
    {
        $this->enBorrador($borradorId, $actor, function (CartContract $carrito) use ($origen) {
            $facturacion = $carrito->billing_address ?? throw new EntregaInvalida(EntregaInvalida::FACTURACION_REQUERIDA);

            if (! $carrito->haveStockableItems()) {
                throw new EntregaInvalida(EntregaInvalida::SIN_PRODUCTOS_FISICOS);
            }

            $billing = $this->reenvioDe($facturacion);

            if ($origen->tipo === OrigenDireccion::IGUAL_FACTURACION) {
                Cart::saveAddresses(['billing' => ['use_for_shipping' => true] + $billing]);
            } else {
                $envio = $origen->tipo === OrigenDireccion::PUNTO_RECOGIDA
                    ? $this->puntoRecogida($origen->inventorySourceId, $facturacion)
                    : $this->datosDeCliente($carrito, $origen);

                Cart::saveAddresses(['billing' => ['use_for_shipping' => false] + $billing, 'shipping' => $envio]);
            }

            $this->recalcular();
        });
    }

    /** Vuelve a pedir a Bagisto las tarifas (p. ej. tras cambiar lineas). */
    public function calcularMetodos(int $borradorId, Admin $actor): VistaEntregaBorrador
    {
        $this->calcularMetodosSinVista($borradorId, $actor);

        return $this->vista($borradorId);
    }

    public function calcularMetodosSinVista(int $borradorId, Admin $actor): void
    {
        $this->enBorrador($borradorId, $actor, function (CartContract $carrito) {
            $this->exigirDestino($carrito);
            Shipping::collectRates();
        });
    }

    /** Elige un metodo entre las tarifas vigentes de Bagisto para este carrito y destino. */
    public function seleccionarMetodo(int $borradorId, string $metodo, Admin $actor): VistaEntregaBorrador
    {
        $this->seleccionarMetodoSinVista($borradorId, $metodo, $actor);

        return $this->vista($borradorId);
    }

    public function seleccionarMetodoSinVista(int $borradorId, string $metodo, Admin $actor): void
    {
        $this->enBorrador($borradorId, $actor, function (CartContract $carrito) use ($metodo) {
            $this->exigirDestino($carrito);

            // saveShippingMethod recalcula las tarifas y exige que el metodo este entre ellas.
            if (trim($metodo) === '' || ! Cart::saveShippingMethod($metodo)) {
                throw new EntregaInvalida(EntregaInvalida::METODO_NO_DISPONIBLE);
            }

            Cart::collectTotals();
        });
    }

    /**
     * Transaccion + bloqueo del borrador y del carrito + grupo apto + RT-03 + stock, y la
     * operacion con el Cart de Bagisto apuntando al carrito del borrador.
     */
    protected function enBorrador(int $borradorId, Admin $actor, Closure $operacion): void
    {
        DB::transaction(function () use ($borradorId, $actor, $operacion) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;
            $this->carritos->exigirGrupoApto($customerId);

            $this->carritos->operar($carrito, $customerId, function () use ($borrador, $operacion) {
                if (! Cart::isItemsHaveSufficientQuantity() && Cart::getCart()->items->isNotEmpty()) {
                    throw new EntregaInvalida(EntregaInvalida::STOCK_INSUFICIENTE, $this->lineasSinStock((int) $borrador->id));
                }

                $operacion(Cart::getCart());
            });
        });
    }

    /** Totales (collectTotals borra un carrito vacio: solo con lineas) y tarifas del destino. */
    protected function recalcular(): void
    {
        $carrito = Cart::getCart();

        if ($carrito->items()->exists()) {
            Cart::collectTotals();
        }

        if (Cart::getCart()->haveStockableItems() && Cart::getCart()->shipping_address) {
            Shipping::collectRates();
        }
    }

    protected function exigirDestino(CartContract $carrito): void
    {
        if (! $carrito->haveStockableItems()) {
            throw new EntregaInvalida(EntregaInvalida::SIN_PRODUCTOS_FISICOS);
        }

        if (! $carrito->billing_address) {
            throw new EntregaInvalida(EntregaInvalida::FACTURACION_REQUERIDA);
        }

        if (! $carrito->shipping_address) {
            throw new EntregaInvalida(EntregaInvalida::DIRECCION_ENVIO_REQUERIDA);
        }
    }

    /** Parametros de Cart::saveAddresses para una direccion guardada del cliente o puntual. */
    protected function datosDeCliente(CartContract $carrito, OrigenDireccion $origen): array
    {
        if ($origen->tipo === OrigenDireccion::GUARDADA) {
            $guardada = CustomerAddressProxy::modelClass()::query()
                ->where('customer_id', $carrito->customer_id)
                ->where('address_type', 'customer')
                ->find($origen->addressId);

            if (! $guardada) {
                throw new EntregaInvalida(EntregaInvalida::DIRECCION_NO_PERTENECE_AL_CLIENTE);
            }

            // address_type + id: Bagisto guarda parent_address_id (vinculo informativo).
            return ['address_type' => 'customer', 'id' => (int) $guardada->id] + $this->campos($guardada);
        }

        if ($origen->tipo === OrigenDireccion::PUNTUAL && $origen->direccion !== null) {
            $columnas = $this->validador->validar($origen->direccion, exigirCodigoPostal: false);

            return ['address' => explode(PHP_EOL, $columnas['address'])] + $columnas;
        }

        throw new EntregaInvalida(EntregaInvalida::ORIGEN_NO_VALIDO);
    }

    /**
     * Recogida: LUGAR = la bodega real; PERSONA = quien recibe, de la facturacion (como en
     * cualquier direccion de envio: lugar + destinatario). Nada se inventa.
     */
    protected function puntoRecogida(?int $inventorySourceId, CartAddress $facturacion): array
    {
        if ($inventorySourceId !== null) {
            $fuente = $this->puntosRecogida->porId($inventorySourceId)
                ?? throw new EntregaInvalida(EntregaInvalida::PUNTO_RECOGIDA_INEXISTENTE);
        } else {
            $disponibles = $this->puntosRecogida->disponibles();

            if ($disponibles->isEmpty()) {
                throw new EntregaInvalida(EntregaInvalida::PUNTO_RECOGIDA_INEXISTENTE);
            }

            if ($disponibles->count() > 1) {
                throw new EntregaInvalida(EntregaInvalida::PUNTO_RECOGIDA_AMBIGUO);
            }

            $fuente = $disponibles->first();
        }

        return $this->puntosRecogida->lugarParaCarrito($fuente) + [
            'first_name' => $facturacion->first_name,
            'last_name' => $facturacion->last_name,
            'phone' => $facturacion->phone,
            'email' => $facturacion->email,
        ];
    }

    /** La facturacion actual como parametros, conservando su vinculo con la guardada. */
    protected function reenvioDe(CartAddress $facturacion): array
    {
        $datos = $this->campos($facturacion) + ['vat_id' => $facturacion->vat_id];

        if ($facturacion->parent_address_id !== null) {
            $datos = ['address_type' => 'customer', 'id' => (int) $facturacion->parent_address_id] + $datos;
        }

        return $datos;
    }

    private function campos($direccion): array
    {
        return [
            'company_name' => $direccion->company_name,
            'first_name' => $direccion->first_name,
            'last_name' => $direccion->last_name,
            'email' => $direccion->email,
            'address' => explode(PHP_EOL, (string) $direccion->address),
            'city' => $direccion->city,
            'state' => $direccion->state,
            'country' => $direccion->country,
            'postcode' => $direccion->postcode,
            'phone' => $direccion->phone,
        ];
    }

    /** @return list<array{cart_item_id: int, variante_id: int, sku: string, cantidad: int, stock_vendible: int}> */
    private function lineasSinStock(int $borradorId, ?VistaLineasBorrador $lineas = null): array
    {
        $afectadas = [];

        foreach (($lineas ?? $this->lineas->listar($borradorId))->lineas as $linea) {
            if ($linea->abastecimiento->faltanteInmediato > 0) {
                $afectadas[] = [
                    'cart_item_id' => $linea->cartItemId,
                    'variante_id' => $linea->varianteId,
                    'sku' => $linea->sku,
                    'cantidad' => $linea->cantidad,
                    'stock_vendible' => $linea->stockVendible,
                ];
            }
        }

        return $afectadas;
    }

    private function aDireccion(CartAddress $direccion, string $rol): DireccionBorrador
    {
        $bodega = $rol === DireccionBorrador::ROL_ENVIO ? $this->puntosRecogida->correspondiente($direccion) : null;

        return new DireccionBorrador(
            rol: $rol,
            origen: $bodega ? 'punto_recogida' : ($direccion->parent_address_id !== null ? 'guardada' : 'puntual'),
            customerAddressId: $direccion->parent_address_id === null ? null : (int) $direccion->parent_address_id,
            inventorySourceId: $bodega ? (int) $bodega->id : null,
            nombre: (string) $direccion->first_name,
            apellido: (string) $direccion->last_name,
            empresa: $direccion->company_name,
            lineas: explode(PHP_EOL, (string) $direccion->address),
            ciudad: (string) $direccion->city,
            departamento: $direccion->state,
            pais: $direccion->country,
            codigoPostal: $direccion->postcode === '' ? null : $direccion->postcode,
            telefono: $direccion->phone,
            email: $direccion->email,
        );
    }

    private function aMetodo($tarifa): MetodoEntregaDisponible
    {
        return new MetodoEntregaDisponible(
            codigo: (string) $tarifa->method,
            carrier: (string) $tarifa->carrier,
            titulo: (string) $tarifa->method_title,
            descripcion: $tarifa->method_description,
            precio: (float) $tarifa->price,
            descuento: (float) $tarifa->discount_amount,
        );
    }
}
