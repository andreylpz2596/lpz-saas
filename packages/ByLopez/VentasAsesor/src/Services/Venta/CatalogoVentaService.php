<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\DataObjects\ProductoVenta;
use ByLopez\VentasAsesor\DataObjects\VarianteVenta;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as Invalida;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Illuminate\Support\Collection;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Models\ProductFlatProxy;
use Webkul\Product\Models\ProductProxy;

/**
 * Catalogo comercial de venta (03B). SOLO LECTURA: nunca escribe carrito, stock ni precios.
 *
 * - Variantes: para un configurable, sus hijos REALES (`products.parent_id`). Toda
 *   variante que salga de aqui fue verificada contra su padre: Bagisto no lo comprueba en
 *   Configurable::prepareForCart (Doc. 05 §22.3).
 * - Stock: el mismo que usa Bagisto, `TypeInstance::totalQuantity()` (indice de
 *   inventario del canal: inventario de origenes activos menos reservas). No se leen
 *   `product_inventories` ni `product_salable_inventories` (este ultimo no lo consume
 *   ningun codigo del core).
 * - Precio: `TypeInstance::getFinalPrice($cantidad)` de la variante, lo mismo que usa el
 *   carrito al repreciar (Configurable::validateCartItem), dentro del contexto de RT-03
 *   del cliente. Aqui no hay ninguna regla de grupo.
 */
class CatalogoVentaService
{
    public const LIMITE_MAXIMO = 50;

    public function __construct(
        protected ContextoPrecioComercial $contexto,
        protected ValidadorGrupoComercial $validadorGrupo,
    ) {}

    /**
     * Productos de primer nivel vendibles (configurables y simples sin padre), activos en
     * el canal por defecto. Cada palabra debe aparecer en el nombre o el SKU.
     *
     * @return list<ProductoVenta>
     */
    public function buscarProductos(string $texto, int $limite = 20): array
    {
        $palabras = array_values(array_filter(preg_split('/\s+/u', trim($texto)), fn ($p) => mb_strlen($p) >= 2));

        if ($palabras === []) {
            return [];
        }

        $consulta = ProductFlatProxy::modelClass()::query()
            ->select(['product_id', 'type', 'sku', 'name'])
            ->where('channel', core()->getDefaultChannel()->code)
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())
            ->whereNull('parent_id')
            ->whereIn('type', ['configurable', 'simple'])
            ->where('status', 1);

        foreach ($palabras as $palabra) {
            $patron = '%'.addcslashes($palabra, '%_\\').'%';
            $consulta->where(fn ($q) => $q->where('name', 'like', $patron)->orWhere('sku', 'like', $patron));
        }

        return $consulta->orderBy('name')
            ->limit(max(1, min($limite, self::LIMITE_MAXIMO)))
            ->get()
            ->map(fn ($f) => new ProductoVenta((int) $f->product_id, (string) $f->type, (string) $f->sku, (string) $f->name))
            ->all();
    }

    /**
     * Variantes reales de un producto, con stock y precio para ESE cliente y cantidad.
     *
     * @return list<VarianteVenta>
     */
    public function variantes(int $productoId, int $customerId, int $cantidad = 1): array
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $this->exigirClienteApto($customerId);

        $hijos = $producto->type === 'configurable' ? $producto->variants()->get() : collect([$producto]);
        $atributos = $this->superAtributos($producto);

        return $this->contexto->duranteCreacionDeCarrito($customerId, fn () => $hijos
            ->map(fn (Product $hijo) => $this->aVariante($producto, $hijo, $atributos, $cantidad))
            ->values()
            ->all());
    }

    /** Una variante concreta, VERIFICADA contra su padre. Lanza si no le pertenece. */
    public function variante(int $productoId, int $varianteId, int $customerId, int $cantidad = 1): VarianteVenta
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $hijo = $this->hijoVerificado($producto, $varianteId);
        $this->exigirClienteApto($customerId);

        return $this->contexto->duranteCreacionDeCarrito($customerId,
            fn () => $this->aVariante($producto, $hijo, $this->superAtributos($producto), $cantidad));
    }

    /**
     * Resuelve la variante por sus opciones (codigo de atributo => option_id), p. ej.
     * ['size' => 12]. Exige todas las opciones del configurable y exactamente una coincidencia.
     *
     * @param  array<string, int>  $opciones
     */
    public function resolverVariante(int $productoId, array $opciones, int $customerId, int $cantidad = 1): VarianteVenta
    {
        $producto = $this->productoVendible($productoId);

        if ($producto->type !== 'configurable') {
            return $this->variante($productoId, $productoId, $customerId, $cantidad);
        }

        $atributos = $this->superAtributos($producto);

        foreach ($atributos as $atributo) {
            if (! isset($opciones[$atributo->code])) {
                throw new Invalida(Invalida::OPCIONES_INCOMPLETAS);
            }
        }

        $coincidencias = $producto->variants()->get()->filter(fn (Product $hijo) => $atributos->every(
            fn ($atributo) => (int) $hijo->{$atributo->code} === (int) $opciones[$atributo->code]
        ));

        if ($coincidencias->isEmpty()) {
            throw new Invalida(Invalida::VARIANTE_NO_ENCONTRADA);
        }

        if ($coincidencias->count() > 1) {
            throw new Invalida(Invalida::VARIANTE_AMBIGUA);
        }

        return $this->variante($productoId, (int) $coincidencias->first()->id, $customerId, $cantidad);
    }

    /**
     * Desglose para una cantidad. Entrega inmediata = solo stock vendible (I-12, D-36).
     * Sin fuente de produccion integrada, enProduccion queda null (desconocido, D-37).
     */
    public function desglose(VarianteVenta $variante, int $cantidad): DesgloseAbastecimiento
    {
        $this->exigirCantidad($cantidad);

        return DesgloseAbastecimiento::desdeStock($cantidad, $variante->stockVendible, $variante->gestionaStock);
    }

    /**
     * Garantia central (invariante I-5): el hijo existe y su parent_id es el configurable.
     * Para un simple sin padre, la unica "variante" valida es el propio producto.
     */
    public function hijoVerificado(Product $producto, int $varianteId): Product
    {
        if ($producto->type !== 'configurable') {
            if ($varianteId !== (int) $producto->id) {
                throw new Invalida(Invalida::VARIANTE_NO_PERTENECE_AL_PADRE);
            }

            return $producto;
        }

        $hijo = ProductProxy::modelClass()::query()->find($varianteId);

        if (! $hijo) {
            throw new Invalida(Invalida::PRODUCTO_INEXISTENTE);
        }

        if ((int) $hijo->parent_id !== (int) $producto->id) {
            throw new Invalida(Invalida::VARIANTE_NO_PERTENECE_AL_PADRE);
        }

        return $hijo;
    }

    /** Producto de primer nivel vendible: existe, sin padre, configurable o simple, activo. */
    public function productoVendible(int $productoId): Product
    {
        $producto = ProductProxy::modelClass()::query()->find($productoId);

        if (! $producto) {
            throw new Invalida(Invalida::PRODUCTO_INEXISTENTE);
        }

        if ($producto->parent_id !== null || ! in_array($producto->type, ['configurable', 'simple'], true) || ! $producto->status) {
            throw new Invalida(Invalida::PRODUCTO_NO_VENDIBLE);
        }

        return $producto;
    }

    private function aVariante(Product $producto, Product $hijo, Collection $atributos, int $cantidad): VarianteVenta
    {
        $tipo = $hijo->getTypeInstance();

        return new VarianteVenta(
            padreId: (int) $producto->id,
            productId: (int) $hijo->id,
            sku: (string) $hijo->sku,
            nombre: (string) $hijo->name,
            opciones: $this->opcionesDe($hijo, $atributos),
            activa: (bool) $hijo->status,
            gestionaStock: (bool) $hijo->manage_stock,
            stockVendible: (int) $tipo->totalQuantity(),
            precioUnitario: ($precio = $tipo->getFinalPrice($cantidad)) === null ? null : round((float) $precio, 4),
            cantidadConsultada: $cantidad,
        );
    }

    private function opcionesDe(Product $hijo, Collection $atributos): array
    {
        $opciones = [];

        foreach ($atributos as $atributo) {
            $optionId = $hijo->{$atributo->code};
            $opcion = $atributo->options->firstWhere('id', (int) $optionId);

            $opciones[$atributo->code] = [
                'atributo' => (string) ($atributo->name ?: $atributo->admin_name),
                'option_id' => $optionId === null ? null : (int) $optionId,
                'etiqueta' => $opcion ? (string) ($opcion->label ?: $opcion->admin_name) : null,
            ];
        }

        return $opciones;
    }

    private function superAtributos(Product $producto): Collection
    {
        return $producto->type === 'configurable' ? $producto->super_attributes()->with('options')->get() : collect();
    }

    /** El precio comercial solo existe para un cliente con grupo apto (invitado excluido). */
    private function exigirClienteApto(int $customerId): void
    {
        $cliente = CustomerProxy::modelClass()::query()->find($customerId);

        if (! $cliente) {
            throw new DatosClienteComercialInvalidosException(['customer_id' => DatosClienteComercialInvalidosException::REQUERIDO]);
        }

        $this->validadorGrupo->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);
    }

    private function exigirCantidad(int $cantidad): void
    {
        if ($cantidad < 1) {
            throw new Invalida(Invalida::CANTIDAD_INVALIDA);
        }
    }
}
