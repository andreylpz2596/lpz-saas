<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\AccesoRapido;
use ByLopez\VentasAsesor\DataObjects\CeldaMatriz;
use ByLopez\VentasAsesor\DataObjects\ColorDeLinea;
use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\DataObjects\MatrizVariantes;
use ByLopez\VentasAsesor\DataObjects\ProductoVenta;
use ByLopez\VentasAsesor\DataObjects\VarianteVenta;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Exceptions\NuevaVentaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as Invalida;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\AttributeProxy;
use Webkul\Category\Models\CategoryProxy;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Models\ProductAttributeValueProxy;
use Webkul\Product\Models\ProductFlatProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Contracts\Admin;

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
        protected VentasSimultaneasService $simultaneas,
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

        $hijos = $this->hijosPrecargados($producto);
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
     * Accesos rapidos de "Nueva venta" (03G-B): lineas de producto del negocio resueltas por
     * slug/nombre de categoria segun la configuracion del paquete (nunca por id). Una sola
     * consulta; ante inexistente o ambiguo el acceso queda sin resolver (falla cerrado).
     *
     * @return list<AccesoRapido>
     */
    public function accesosRapidos(): array
    {
        $accesos = (array) config('ventasasesor.nueva_venta.accesos_rapidos', []);
        $slugs = collect($accesos)->flatMap(fn ($a) => (array) ($a['slugs'] ?? []))->map('strval')->all();
        $nombres = collect($accesos)->flatMap(fn ($a) => (array) ($a['nombres'] ?? []))->map('strval')->all();

        $filas = $slugs === [] && $nombres === [] ? collect() : DB::table('category_translations as t')
            ->join('categories as c', 'c.id', '=', 't.category_id')
            ->where('c.status', 1)
            ->where(fn ($q) => $q->whereIn('t.slug', $slugs)->orWhereIn('t.name', $nombres))
            ->get(['c.id as id', 't.slug as slug', 't.name as name']);

        return collect($accesos)->map(function (array $a) use ($filas) {
            $slugs = array_map('mb_strtolower', array_map('strval', (array) ($a['slugs'] ?? [])));
            $nombres = array_map('mb_strtolower', array_map('strval', (array) ($a['nombres'] ?? [])));

            $porSlug = $filas->filter(fn ($f) => in_array(mb_strtolower($f->slug), $slugs, true))->pluck('id')->unique()->values();
            $candidatas = $porSlug->isNotEmpty() ? $porSlug
                : $filas->filter(fn ($f) => in_array(mb_strtolower($f->name), $nombres, true))->pluck('id')->unique()->values();

            return new AccesoRapido(
                (string) $a['clave'],
                (string) $a['etiqueta'],
                $candidatas->count() === 1 ? (int) $candidatas->first() : null,
                $candidatas->isEmpty() ? AccesoRapido::MOTIVO_INEXISTENTE : ($candidatas->count() > 1 ? AccesoRapido::MOTIVO_AMBIGUA : null),
            );
        })->values()->all();
    }

    /**
     * Productos de primer nivel de una linea (categoria y sus descendientes) = "linea x
     * color", con el color de sus variantes. `$texto` filtra por nombre, SKU o color.
     *
     * @return list<ColorDeLinea>
     */
    public function coloresDeAcceso(string $clave, ?string $texto = null, int $limite = 50): array
    {
        $acceso = collect($this->accesosRapidos())->firstWhere('clave', $clave)
            ?? throw new NuevaVentaInvalidaException(NuevaVentaInvalidaException::ACCESO_RAPIDO_INEXISTENTE);

        if (! $acceso->resuelto()) {
            throw new NuevaVentaInvalidaException(NuevaVentaInvalidaException::ACCESO_RAPIDO_SIN_RESOLVER, causa: $acceso->motivo);
        }

        $raiz = CategoryProxy::modelClass()::query()->findOrFail($acceso->categoriaId);
        $categorias = CategoryProxy::modelClass()::query()
            ->where('_lft', '>=', $raiz->_lft)->where('_rgt', '<=', $raiz->_rgt)->pluck('id');

        $flats = ProductFlatProxy::modelClass()::query()
            ->select(['product_id', 'type', 'sku', 'name'])
            ->where('channel', core()->getDefaultChannel()->code)
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())
            ->whereNull('parent_id')
            ->whereIn('type', ['configurable', 'simple'])
            ->where('status', 1)
            ->whereIn('product_id', fn ($q) => $q->select('product_id')->from('product_categories')->whereIn('category_id', $categorias))
            ->orderBy('name')
            ->limit(500)
            ->get();

        $colores = $this->coloresDe($flats->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $palabras = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim((string) $texto))), fn ($p) => $p !== ''));

        return $flats
            ->map(fn ($f) => new ColorDeLinea(
                (int) $f->product_id, (string) $f->type, (string) $f->sku, (string) $f->name,
                $colores[(int) $f->product_id]['option_id'] ?? null, $colores[(int) $f->product_id]['etiqueta'] ?? null,
            ))
            ->filter(function (ColorDeLinea $c) use ($palabras) {
                $pajar = mb_strtolower($c->nombre.' '.$c->sku.' '.$c->colorEtiqueta);

                return collect($palabras)->every(fn ($p) => str_contains($pajar, $p));
            })
            ->take(max(1, min($limite, self::LIMITE_MAXIMO)))
            ->values()
            ->all();
    }

    /** Variante por SKU EXACTO (hijo o simple), verificada contra su padre. */
    public function buscarVariantePorSku(string $sku, int $customerId, int $cantidad = 1): VarianteVenta
    {
        $sku = trim($sku);
        $producto = $sku === '' ? null : ProductProxy::modelClass()::query()->where('sku', $sku)->first();

        if (! $producto) {
            throw new Invalida(Invalida::VARIANTE_NO_ENCONTRADA);
        }

        return $this->variante((int) ($producto->parent_id ?? $producto->id), (int) $producto->id, $customerId, $cantidad);
    }

    /**
     * Matriz de un producto para vender por tallas: variantes en orden de talla (sort_order de
     * la opcion en Bagisto), stock y precio de Bagisto y la senal de ventas simultaneas en
     * LOTE. Stock y precio se precargan (las consultas no crecen con el numero de variantes).
     */
    public function matriz(int $productoId, int $customerId, Admin $actor, int $cantidad = 1, ?int $borradorId = null): MatrizVariantes
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $this->exigirClienteApto($customerId);

        $atributos = $this->superAtributos($producto);
        $codigoTalla = (string) config('ventasasesor.nueva_venta.atributos.talla', 'size');
        $codigoColor = (string) config('ventasasesor.nueva_venta.atributos.color', 'color');
        $ordenTalla = $atributos->firstWhere('code', $codigoTalla)?->options->pluck('sort_order', 'id') ?? collect();

        $variantes = $this->contexto->duranteCreacionDeCarrito($customerId, fn () => $this->hijosPrecargados($producto)
            ->map(fn (Product $hijo) => $this->aVariante($producto, $hijo, $atributos, $cantidad))
            ->values());

        $senales = $this->simultaneas->paraVariantes($variantes->map->productId->all(), $actor, $borradorId);

        $celdas = $variantes
            ->map(fn (VarianteVenta $v) => new CeldaMatriz(
                $v,
                (int) ($ordenTalla[$v->opciones[$codigoTalla]['option_id'] ?? 0] ?? 0),
                $senales[$v->productId] ?? null,
            ))
            ->sortBy([
                fn (CeldaMatriz $a, CeldaMatriz $b) => $a->ordenTalla <=> $b->ordenTalla,
                fn (CeldaMatriz $a, CeldaMatriz $b) => ($a->variante->opciones[$codigoColor]['option_id'] ?? 0) <=> ($b->variante->opciones[$codigoColor]['option_id'] ?? 0),
                fn (CeldaMatriz $a, CeldaMatriz $b) => $a->variante->productId <=> $b->variante->productId,
            ])
            ->values();

        $colores = $variantes
            ->map(fn (VarianteVenta $v) => $v->opciones[$codigoColor] ?? null)
            ->filter()
            ->unique('option_id')
            ->map(fn (array $c) => ['option_id' => $c['option_id'], 'etiqueta' => $c['etiqueta']])
            ->values()
            ->all();

        return new MatrizVariantes(
            (int) $producto->id, (string) $producto->type, (string) $producto->sku, (string) $producto->name,
            $colores, $celdas->all(), $cantidad,
        );
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

    /** Hijos con sus relaciones de stock, precio y atributos cargadas en lote (sin N+1). */
    private function hijosPrecargados(Product $producto): EloquentCollection
    {
        $hijos = $producto->type === 'configurable' ? $producto->variants()->get() : new EloquentCollection([$producto]);
        $hijos->load(['attribute_values', 'inventory_indices', 'price_indices']);

        return $hijos;
    }

    /**
     * @param  list<int>  $productoIds
     * @return array<int, array{option_id: int|null, etiqueta: string|null}> color por producto
     */
    private function coloresDe(array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        $atributo = AttributeProxy::modelClass()::query()->with('options')
            ->where('code', (string) config('ventasasesor.nueva_venta.atributos.color', 'color'))->first();

        if (! $atributo) {
            return [];
        }

        $hijos = ProductProxy::modelClass()::query()->whereIn('parent_id', $productoIds)->orderBy('id')->get(['id', 'parent_id']);
        $valores = ProductAttributeValueProxy::modelClass()::query()
            ->where('attribute_id', $atributo->id)
            ->whereIn('product_id', array_merge($productoIds, $hijos->pluck('id')->all()))
            ->whereNotNull($atributo->column_name)
            ->pluck($atributo->column_name, 'product_id');

        $resultado = [];

        foreach ($productoIds as $id) {
            $optionId = $valores[$id] ?? $hijos->where('parent_id', $id)->map(fn ($h) => $valores[$h->id] ?? null)->filter()->first();

            if ($optionId === null) {
                continue;
            }

            $opcion = $atributo->options->firstWhere('id', (int) $optionId);
            $resultado[$id] = ['option_id' => (int) $optionId, 'etiqueta' => $opcion ? (string) ($opcion->label ?: $opcion->admin_name) : null];
        }

        return $resultado;
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
