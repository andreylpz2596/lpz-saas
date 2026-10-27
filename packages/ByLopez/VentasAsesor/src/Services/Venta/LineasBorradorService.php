<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\DataObjects\LineaBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\VistaLineasBorrador;
use ByLopez\VentasAsesor\Exceptions\LineaBorradorInvalidaException as LineaInvalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as VarianteInvalida;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Support\Venta\CantidadVenta;
use ByLopez\VentasAsesor\Support\Venta\CarritoComercial;
use ByLopez\VentasAsesor\Support\Venta\LoteCatalogo;
use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Contracts\CartItem;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartItemProxy;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Exceptions\InsufficientProductInventoryException;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Contracts\Admin;

/**
 * Lineas del borrador de venta (03C) sobre el Cart REAL de Bagisto. No hay un segundo
 * motor de carrito: se agrega, cambia y quita con Cart::addProduct / updateItems /
 * removeItem, y Bagisto valida producto y stock y calcula precio, reglas, impuestos y
 * totales (Doc. 05 §25).
 *
 * - Variante: siempre verificada contra su padre por CatalogoVentaService (I-5) ANTES de
 *   llamar a Bagisto, que no lo comprueba.
 * - Precio: toda operacion que reprecia corre en ContextoPrecioComercial::durante(cart,
 *   cliente del carrito) con el grupo ACTUAL del cliente, validado antes (RT-03). Nunca
 *   custom_price (D-18) ni precios de entrada.
 * - Concurrencia: cada mutacion bloquea borrador y carrito (BorradorVentaService::
 *   bloquearParaEditar) en una transaccion; un fallo revierte todo, incluido el borrado
 *   del carrito que Bagisto hace si falla el primer addProduct.
 * - Carrito vacio: collectTotals() BORRA un carrito sin lineas, asi que al quitar la
 *   ultima linea no se llama; los totales del carrito vacio se ponen a cero.
 * - Stock: Bagisto no admite mas unidades que el stock vendible (sin backorders). Se
 *   rechaza con STOCK_INSUFICIENTE y el desglose.
 * - I-13 (D-39): el carrito solo MATERIALIZA lo solicitado. Toda unidad en el carrito
 *   tiene una solicitud comercial de su variante (SolicitudBorradorService) y el total
 *   del carrito por variante nunca supera la cantidad solicitada. Agregar es la accion
 *   explicita de llevar unidades al carrito; crear una solicitud nunca lo hace.
 * - 03D: agregar, cambiar cantidad y eliminar INVALIDAN el metodo de envio
 *   (Cart::resetShippingMethod: borra tarifas y metodo, conserva direcciones), porque
 *   Bagisto no recalcula tarifas al cambiar lineas. El asesor vuelve a pedir metodos.
 */
class LineasBorradorService
{
    public function __construct(
        protected BorradorVentaService $borradores,
        protected CatalogoVentaService $catalogo,
        protected CarritoComercial $carritos,
    ) {}

    /**
     * Con $lote (03G-B2) se precargan en bloque las relaciones que cada linea lee (familia,
     * valores de atributo e indice de inventario); el resultado es el mismo que sin el.
     */
    public function listar(int $borradorId, ?LoteCatalogo $lote = null): VistaLineasBorrador
    {
        $vista = $this->borradores->obtener($borradorId);
        $relaciones = $lote === null ? ['product', 'child.product'] : [
            'product.attribute_family', 'product.attribute_values', 'product.inventory_indices',
            'child.product.attribute_family', 'child.product.attribute_values', 'child.product.inventory_indices',
        ];

        $lineas = $vista->cartId === null ? [] : CartItemProxy::modelClass()::query()
            ->with($relaciones)
            ->where('cart_id', $vista->cartId)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get()
            ->map(fn (CartItem $item) => $this->aLinea($item))
            ->values()
            ->all();

        return new VistaLineasBorrador($vista, $lineas);
    }

    /**
     * Agrega un producto: configurable con su variante, o simple sin padre (varianteId =
     * productoId). Si la variante ya esta en el carrito, Bagisto suma la cantidad a esa
     * linea (getQtyRequest) y valida el stock sobre el total.
     */
    public function agregar(int $borradorId, int $productoId, int $varianteId, mixed $cantidad, Admin $actor): VistaLineasBorrador
    {
        $this->agregarSinVista($borradorId, $productoId, $varianteId, $cantidad, $actor);

        return $this->listar($borradorId);
    }

    /** Misma operacion que agregar() sin reconstruir la vista (uso por lotes, 03G-B). */
    public function agregarSinVista(int $borradorId, int $productoId, int $varianteId, mixed $cantidad, Admin $actor, ?LoteCatalogo $lote = null): void
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        DB::transaction(function () use ($borradorId, $productoId, $varianteId, $cantidad, $actor, $lote) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;

            // I-5 y grupo comercial apto del cliente: los valida el catalogo.
            $variante = $lote === null
                ? $this->catalogo->variante($productoId, $varianteId, $customerId, $cantidad, (int) $carrito->id)
                : $this->catalogo->varianteEnLote($lote, $productoId, $varianteId, $customerId, $cantidad, (int) $carrito->id);

            if (! $variante->activa) {
                throw new VarianteInvalida(VarianteInvalida::PRODUCTO_NO_VENDIBLE);
            }

            // I-13: solo se materializa lo solicitado.
            $this->exigirDentroDeSolicitud((int) $borrador->id, $variante->productId,
                $this->cantidadEnCarrito((int) $carrito->id, $variante->productId) + $cantidad);

            $producto = ProductProxy::modelClass()::query()->findOrFail($productoId);

            // product_id: Bagisto lo usa para reconocer una linea existente (compareOptions).
            $datos = ['product_id' => (int) $producto->id, 'quantity' => $cantidad];

            if ($producto->type === 'configurable') {
                $datos['selected_configurable_option'] = $variante->productId;
            }

            $this->enCarrito($carrito, $customerId, function () use ($carrito, $producto, $datos, $variante, $cantidad) {
                Cart::resetShippingMethod();

                try {
                    Cart::addProduct($producto, $datos);
                } catch (InsufficientProductInventoryException) {
                    $total = $cantidad + $this->cantidadEnCarrito((int) $carrito->id, $variante->productId);

                    throw new LineaInvalida(LineaInvalida::STOCK_INSUFICIENTE,
                        DesgloseAbastecimiento::desdeStock($total, $variante->stockVendible, $variante->gestionaStock));
                } catch (Exception $e) {
                    throw $this->rechazoDelCarrito($e);
                }
            });
        });
    }

    /** Fija la cantidad de una linea. Bagisto revalida stock y reprecia (precio por cantidad). */
    public function cambiarCantidad(int $borradorId, int $cartItemId, mixed $cantidad, Admin $actor): VistaLineasBorrador
    {
        $this->cambiarCantidadSinVista($borradorId, $cartItemId, $cantidad, $actor);

        return $this->listar($borradorId);
    }

    public function cambiarCantidadSinVista(int $borradorId, int $cartItemId, mixed $cantidad, Admin $actor): void
    {
        $cantidad = CantidadVenta::normalizar($cantidad);

        DB::transaction(function () use ($borradorId, $cartItemId, $cantidad, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $item = $this->lineaDelCarrito($carrito, $cartItemId);
            $this->exigirDentroDeSolicitud((int) $borrador->id, (int) $this->productoVariante($item)->id, $cantidad);
            $customerId = (int) $carrito->customer_id;
            $this->exigirGrupoApto($customerId);

            $this->enCarrito($carrito, $customerId, function () use ($item, $cantidad) {
                // Misma regla que aplica updateItems; se consulta antes solo para dar un
                // codigo estable con el desglose (Bagisto lanza una Exception generica).
                $item->quantity = $cantidad;

                if (! Cart::isItemHaveQuantity($item)) {
                    $variante = $this->productoVariante($item);

                    throw new LineaInvalida(LineaInvalida::STOCK_INSUFICIENTE, DesgloseAbastecimiento::desdeStock(
                        $cantidad, (int) $variante->getTypeInstance()->totalQuantity(), (bool) $variante->manage_stock));
                }

                Cart::resetShippingMethod();

                try {
                    Cart::updateItems(['qty' => [$item->id => $cantidad]]);
                } catch (Exception $e) {
                    throw $this->rechazoDelCarrito($e);
                }

                // updateItems omite en silencio un item que no reconoce como del carrito.
                if ((int) CartItemProxy::modelClass()::query()->whereKey($item->id)->value('quantity') !== $cantidad) {
                    throw new LineaInvalida(LineaInvalida::RECHAZADA_POR_CARRITO);
                }
            });
        });
    }

    /**
     * Quita una linea (su fila interna de variante cae en cascada). Si era la ultima, el
     * carrito queda vacio y vivo con totales a cero; si no, Bagisto recalcula el resto.
     */
    public function eliminar(int $borradorId, int $cartItemId, Admin $actor): VistaLineasBorrador
    {
        $this->eliminarSinVista($borradorId, $cartItemId, $actor);

        return $this->listar($borradorId);
    }

    public function eliminarSinVista(int $borradorId, int $cartItemId, Admin $actor): void
    {
        DB::transaction(function () use ($borradorId, $cartItemId, $actor) {
            [, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $item = $this->lineaDelCarrito($carrito, $cartItemId);
            $customerId = (int) $carrito->customer_id;
            $quedanLineas = CartItemProxy::modelClass()::query()
                ->where('cart_id', $carrito->id)->whereNull('parent_id')->whereKeyNot($item->id)->exists();

            if (! $quedanLineas) {
                Cart::setCart($carrito);

                try {
                    Cart::resetShippingMethod();
                    Cart::removeItem($item->id) ?: throw new LineaInvalida(LineaInvalida::RECHAZADA_POR_CARRITO);
                } finally {
                    Cart::resetCart();
                }

                $this->totalesDeCarritoVacio((int) $carrito->id);

                return;
            }

            $this->exigirGrupoApto($customerId);

            $this->enCarrito($carrito, $customerId, function () use ($item) {
                Cart::resetShippingMethod();
                Cart::removeItem($item->id) ?: throw new LineaInvalida(LineaInvalida::RECHAZADA_POR_CARRITO);
                Cart::collectTotals();
            });
        });
    }

    /** Id de la linea de primer nivel que contiene esa variante en el carrito, o null. */
    public function lineaDeVariante(?int $cartId, int $varianteId): ?int
    {
        if ($cartId === null) {
            return null;
        }

        $id = CartItemProxy::modelClass()::query()
            ->where('cart_id', $cartId)
            ->whereNull('parent_id')
            ->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->where('product_id', $varianteId)->where('type', '!=', 'configurable'))
                ->orWhereHas('children', fn ($hijo) => $hijo->where('product_id', $varianteId)))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** El Cart de Bagisto apunta al carrito del borrador solo durante la operacion, en RT-03. */
    protected function enCarrito(CartContract $carrito, int $customerId, Closure $operacion): void
    {
        $this->carritos->operar($carrito, $customerId, $operacion);
    }

    /** Linea de primer nivel de ESTE carrito; nunca la fila interna de una variante. */
    protected function lineaDelCarrito(CartContract $carrito, int $cartItemId): CartItem
    {
        $item = CartItemProxy::modelClass()::query()
            ->where('cart_id', $carrito->id)
            ->whereNull('parent_id')
            ->find($cartItemId);

        if (! $item) {
            throw new LineaInvalida(LineaInvalida::LINEA_NO_PERTENECE_AL_BORRADOR);
        }

        return $item;
    }

    /**
     * Unidades de una variante hoy en el carrito (lineas de primer nivel: el simple, o el
     * configurable cuya fila interna es la variante). No es una reserva.
     */
    public function cantidadEnCarrito(?int $cartId, int $varianteId): int
    {
        if ($cartId === null) {
            return 0;
        }

        return (int) CartItemProxy::modelClass()::query()
            ->where('cart_id', $cartId)
            ->whereNull('parent_id')
            ->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->where('product_id', $varianteId)->where('type', '!=', 'configurable'))
                ->orWhereHas('children', fn ($hijo) => $hijo->where('product_id', $varianteId)))
            ->sum('quantity');
    }

    /** I-13: el total en carrito de la variante no supera su cantidad solicitada. */
    protected function exigirDentroDeSolicitud(int $borradorId, int $varianteId, int $totalEnCarrito): void
    {
        $solicitado = SolicitudBorrador::query()
            ->where('borrador_id', $borradorId)
            ->where('variante_id', $varianteId)
            ->value('cantidad_solicitada');

        if ($solicitado === null) {
            throw new LineaInvalida(LineaInvalida::SIN_SOLICITUD);
        }

        if ($totalEnCarrito > (int) $solicitado) {
            throw new LineaInvalida(LineaInvalida::EXCEDE_SOLICITUD);
        }
    }

    /** Sin lineas no hay nada que sumar: el carrito vacio queda con totales a cero. */
    protected function totalesDeCarritoVacio(int $cartId): void
    {
        CartProxy::modelClass()::query()->whereKey($cartId)->update(array_fill_keys([
            'items_count', 'items_qty',
            'sub_total', 'base_sub_total', 'sub_total_incl_tax', 'base_sub_total_incl_tax',
            'tax_total', 'base_tax_total', 'discount_amount', 'base_discount_amount',
            'shipping_amount', 'base_shipping_amount', 'shipping_amount_incl_tax', 'base_shipping_amount_incl_tax',
            'grand_total', 'base_grand_total',
        ], 0));
    }

    protected function exigirGrupoApto(int $customerId): void
    {
        $this->carritos->exigirGrupoApto($customerId);
    }

    /**
     * Bagisto lanza Exception generica con mensaje traducido cuando rechaza (opciones
     * requeridas, producto inactivo...). Las excepciones propias pasan tal cual.
     */
    protected function rechazoDelCarrito(Exception $e): Exception
    {
        return $e::class === Exception::class ? new LineaInvalida(LineaInvalida::RECHAZADA_POR_CARRITO, null, $e) : $e;
    }

    protected function productoVariante(CartItem $item): Product
    {
        return $item->type === 'configurable' ? $item->child->product : $item->product;
    }

    private function aLinea(CartItem $item): LineaBorradorVenta
    {
        $variante = $this->productoVariante($item);
        $gestionaStock = (bool) $variante->manage_stock;
        $stock = (int) $variante->getTypeInstance()->totalQuantity();
        $cantidad = (int) $item->quantity;

        $opciones = [];

        foreach ((array) ($item->additional['attributes'] ?? []) as $codigo => $atributo) {
            $opciones[$codigo] = [
                'atributo' => $atributo['attribute_name'] ?? null,
                'option_id' => isset($atributo['option_id']) ? (int) $atributo['option_id'] : null,
                'etiqueta' => isset($atributo['option_label']) ? (string) $atributo['option_label'] : null,
            ];
        }

        return new LineaBorradorVenta(
            cartItemId: (int) $item->id,
            productoId: (int) $item->product_id,
            varianteId: (int) $variante->id,
            sku: (string) $variante->sku,
            nombre: (string) ($item->type === 'configurable' ? $item->child->name : $item->name),
            opciones: $opciones,
            cantidad: $cantidad,
            precioUnitario: (float) $item->price,
            subtotal: (float) $item->total,
            descuento: (float) $item->discount_amount,
            impuesto: (float) $item->tax_amount,
            gestionaStock: $gestionaStock,
            stockVendible: $stock,
            abastecimiento: DesgloseAbastecimiento::desdeStock($cantidad, $stock, $gestionaStock),
        );
    }
}
