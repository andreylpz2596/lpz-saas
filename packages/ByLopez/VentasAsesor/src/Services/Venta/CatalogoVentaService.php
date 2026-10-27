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
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\Venta\LoteCatalogo;
use Closure;
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
    public function variantes(int $productoId, int $customerId, int $cantidad = 1, ?int $cartId = null): array
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $this->exigirClienteApto($customerId);

        $hijos = $this->hijosPrecargados($producto);
        $atributos = $this->superAtributos($producto);

        return $this->conPrecioDeVenta($customerId, $cartId, fn () => $hijos
            ->map(fn (Product $hijo) => $this->aVariante($producto, $hijo, $atributos, $cantidad))
            ->values()
            ->all());
    }

    /** Una variante concreta, VERIFICADA contra su padre. Lanza si no le pertenece. */
    public function variante(int $productoId, int $varianteId, int $customerId, int $cantidad = 1, ?int $cartId = null): VarianteVenta
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $hijo = $this->hijoVerificado($producto, $varianteId);
        $this->exigirClienteApto($customerId);

        return $this->conPrecioDeVenta($customerId, $cartId,
            fn () => $this->aVariante($producto, $hijo, $this->superAtributos($producto), $cantidad));
    }

    /**
     * variante() dentro de una operacion por lote (03G-B2): mismas comprobaciones, en el mismo
     * orden y con las mismas excepciones; producto, hijos precargados, super atributos y
     * cliente apto se leen una vez por lote. Precio y stock salen de Bagisto en cada llamada.
     */
    public function varianteEnLote(LoteCatalogo $lote, int $productoId, int $varianteId, int $customerId, int $cantidad = 1, ?int $cartId = null): VarianteVenta
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendibleEnLote($lote, $productoId);
        $hijo = $this->hijoVerificadoEnLote($lote, $producto, $varianteId);
        $lote->recordar("cliente_apto:{$customerId}", function () use ($customerId) {
            $this->exigirClienteApto($customerId);

            return true;
        });
        $atributos = $lote->recordar("super_atributos:{$producto->id}", fn () => $this->superAtributos($producto));

        return $this->conPrecioDeVenta($customerId, $cartId,
            fn () => $this->aVariante($producto, $hijo, $atributos, $cantidad, $lote));
    }

    public function productoVendibleEnLote(LoteCatalogo $lote, int $productoId): Product
    {
        return $lote->recordar("producto:{$productoId}", fn () => $this->productoVendible($productoId));
    }

    /** hijoVerificado() sobre los hijos precargados; si no esta entre ellos, la verificacion normal lanza. */
    public function hijoVerificadoEnLote(LoteCatalogo $lote, Product $producto, int $varianteId): Product
    {
        $hijos = $lote->recordar("hijos:{$producto->id}", fn () => $this->hijosPrecargados($producto)->keyBy('id'));

        return $hijos->get($varianteId) ?? $this->hijoVerificado($producto, $varianteId);
    }

    /**
     * Resuelve la variante por sus opciones (codigo de atributo => option_id), p. ej.
     * ['size' => 12]. Exige todas las opciones del configurable y exactamente una coincidencia.
     *
     * @param  array<string, int>  $opciones
     */
    public function resolverVariante(int $productoId, array $opciones, int $customerId, int $cantidad = 1, ?int $cartId = null): VarianteVenta
    {
        $producto = $this->productoVendible($productoId);

        if ($producto->type !== 'configurable') {
            return $this->variante($productoId, $productoId, $customerId, $cantidad, $cartId);
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

        return $this->variante($productoId, (int) $coincidencias->first()->id, $customerId, $cantidad, $cartId);
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
                $colores[(int) $f->product_id]['swatch'] ?? null,
            ))
            // Catalogo con deuda conocida (Doc catalogo_variantes: colisiones familia+color+talla):
            // ademas del configurable real (matriz completa de tallas), existen productos "simple"
            // sueltos por cada talla del MISMO color. Para el selector de color de Nueva Venta solo
            // tiene sentido UNO por color: si ese color ya tiene un configurable, se descartan aqui
            // los simples sueltos (siguen existiendo en Bagisto, esto no borra ni modifica catalogo).
            ->groupBy(fn (ColorDeLinea $c) => $c->colorOptionId ?? $c->colorEtiqueta ?? $c->nombre)
            ->flatMap(function (Collection $grupo) {
                $conMatriz = $grupo->where('tipo', 'configurable');

                return $conMatriz->isNotEmpty() ? $conMatriz : $grupo;
            })
            ->filter(function (ColorDeLinea $c) use ($palabras) {
                $pajar = mb_strtolower($c->nombre.' '.$c->sku.' '.$c->colorEtiqueta);

                return collect($palabras)->every(fn ($p) => str_contains($pajar, $p));
            })
            ->take(max(1, min($limite, self::LIMITE_MAXIMO)))
            ->values()
            ->pipe(function (Collection $colores) {
                $disponibilidad = $this->disponibilidadDe($colores->pluck('productId')->all());

                return $colores->map(fn (ColorDeLinea $c) => $c->conDisponibilidad($disponibilidad[$c->productId]))->all();
            });
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
     * Las etiquetas traducidas de atributos y opciones se memorizan UNA vez por esta llamada
     * (LoteCatalogo, 03G-B2): mismo mecanismo que aplicarTallas y estado(), sin persistirlas.
     */
    public function matriz(int $productoId, int $customerId, Admin $actor, int $cantidad = 1, ?int $borradorId = null): MatrizVariantes
    {
        $this->exigirCantidad($cantidad);
        $producto = $this->productoVendible($productoId);
        $this->exigirClienteApto($customerId);

        // GRP-01: con borrador, el precio mostrado sale del grupo EFECTIVO de ESA venta (el mismo del carrito).
        $cartId = $borradorId === null ? null : BorradorVenta::query()->whereKey($borradorId)->value('cart_id');

        $lote = new LoteCatalogo;
        $atributos = $this->superAtributos($producto);
        $codigoTalla =(string) config('ventasasesor.nueva_venta.atributos.talla', 'size');
        $codigoColor = (string) config('ventasasesor.nueva_venta.atributos.color', 'color');
        $ordenTalla = $atributos->firstWhere('code', $codigoTalla)?->options->pluck('sort_order', 'id') ?? collect();

        $variantes = $this->conPrecioDeVenta($customerId, $cartId, fn () => $this->hijosPrecargados($producto)
            ->map(fn (Product $hijo) => $this->aVariante($producto, $hijo, $atributos, $cantidad, $lote))
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

        if (! $this->productoEsVendible($producto)) {
            throw new Invalida(Invalida::PRODUCTO_NO_VENDIBLE);
        }

        return $producto;
    }

    /** Regla de «producto de primer nivel vendible»: sin padre, configurable o simple, y activo (atributo `status`). */
    public function productoEsVendible(Product $producto): bool
    {
        return $producto->parent_id === null && in_array($producto->type, ['configurable', 'simple'], true) && (bool) $producto->status;
    }

    /** Regla de «talla vendible»: variante activa y con stock. Es la que habilita una fila en la modal de tallas. */
    public function varianteEsVendible(Product $variante): bool
    {
        return (bool) $variante->status && (int) $variante->getTypeInstance()->totalQuantity() > 0;
    }

    /**
     * Disponibilidad por producto (linea x color), con las MISMAS reglas que la matriz (productoEsVendible y
     * varianteEsVendible), en pocas consultas y sin precios ni senales. Un id que no existe o no es vendible cuenta como
     * no vendible; nunca lanza: es una consulta para anticipar y para decidir que combinaciones se muestran.
     *
     * @param  list<int>  $productoIds
     * @return array<int, array{vendible: bool, tallas_total: int, tallas_con_stock: int}>
     */
    public function disponibilidadDe(array $productoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productoIds)));

        if ($ids === []) {
            return [];
        }

        $productos = ProductProxy::modelClass()::query()->whereIn('id', $ids)->with('attribute_values')->get()->keyBy('id');
        $hijos = ProductProxy::modelClass()::query()->whereIn('parent_id', $ids)->with(['attribute_values', 'inventory_indices'])->get()->groupBy('parent_id');
        $resultado = [];

        foreach ($ids as $id) {
            $producto = $productos->get($id);

            if (! $producto || ! $this->productoEsVendible($producto)) {
                $resultado[$id] = ['vendible' => false, 'tallas_total' => 0, 'tallas_con_stock' => 0];

                continue;
            }

            $variantes = $producto->type === 'configurable' ? ($hijos->get($id) ?? collect()) : collect([$producto->loadMissing('inventory_indices')]);

            $resultado[$id] = [
                'vendible' => true,
                'tallas_total' => $variantes->count(),
                'tallas_con_stock' => $variantes->filter(fn (Product $h) => $this->varianteEsVendible($h))->count(),
            ];
        }

        return $resultado;
    }

    /**
     * Matrices de VARIOS productos (linea x color) en una consulta, cada una INDEPENDIENTE: la que no se puede vender se
     * excluye con su motivo (`no_disponible`: inactivo o inexistente; `sin_stock`: ninguna talla activa con stock) y NUNCA
     * impide que las demas se devuelvan. `$soloVendibles = false` no excluye por falta de stock (para editar lo que ya esta
     * en la venta). Errores de fondo (cliente no apto, cantidad) siguen lanzando: no son de una combinacion.
     *
     * @param  list<int>  $productoIds
     * @return array{matrices: array<int, array<string, mixed>>, excluidas: list<array{producto_id: int, motivo: string}>}
     */
    public function matrices(array $productoIds, int $customerId, Admin $actor, ?int $borradorId = null, bool $soloVendibles = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $productoIds)));
        $disponibilidad = $this->disponibilidadDe($ids);
        $matrices = [];
        $excluidas = [];

        foreach ($ids as $id) {
            $d = $disponibilidad[$id];

            if (! $d['vendible']) {
                $excluidas[] = ['producto_id' => $id, 'motivo' => 'no_disponible'];

                continue;
            }

            if ($soloVendibles && $d['tallas_con_stock'] === 0) {
                $excluidas[] = ['producto_id' => $id, 'motivo' => 'sin_stock'];

                continue;
            }

            try {
                $matrices[$id] = $this->matriz($id, $customerId, $actor, 1, $borradorId)->toArray();
            } catch (Invalida $e) {
                if (! in_array($e->codigo, [Invalida::PRODUCTO_NO_VENDIBLE, Invalida::PRODUCTO_INEXISTENTE], true)) {
                    throw $e;
                }

                $excluidas[] = ['producto_id' => $id, 'motivo' => 'no_disponible'];
            }
        }

        return ['matrices' => $matrices, 'excluidas' => $excluidas];
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
            $resultado[$id] = [
                'option_id' => (int) $optionId,
                'etiqueta' => $opcion ? (string) ($opcion->label ?: $opcion->admin_name) : null,
                // Swatch REAL del atributo color de Bagisto (swatch_type=color); null si la
                // opcion no tiene uno configurado. Nunca se inventa un hex a partir del nombre.
                'swatch' => $opcion ? $opcion->swatch_value : null,
            ];
        }

        return $resultado;
    }

    private function aVariante(Product $producto, Product $hijo, Collection $atributos, int $cantidad, ?LoteCatalogo $lote = null): VarianteVenta
    {
        $tipo = $hijo->getTypeInstance();

        return new VarianteVenta(
            padreId: (int) $producto->id,
            productId: (int) $hijo->id,
            sku: (string) $hijo->sku,
            nombre: (string) $hijo->name,
            opciones: $this->opcionesDe($hijo, $atributos, $lote),
            activa: (bool) $hijo->status,
            gestionaStock: (bool) $hijo->manage_stock,
            stockVendible: (int) $tipo->totalQuantity(),
            precioUnitario: ($precio = $tipo->getFinalPrice($cantidad)) === null ? null : round((float) $precio, 4),
            cantidadConsultada: $cantidad,
        );
    }

    /**
     * Con $lote, las etiquetas traducidas se leen una vez por atributo y opcion en la
     * operacion: cada acceso a una traduccion de Bagisto cuesta ~35 ms de PHP (03G-B2) y solo
     * depende del locale, que no cambia durante la operacion.
     */
    private function opcionesDe(Product $hijo, Collection $atributos, ?LoteCatalogo $lote = null): array
    {
        $opciones = [];
        $texto = fn (string $clave, \Closure $leer) => $lote === null ? $leer() : $lote->recordar($clave, $leer);

        foreach ($atributos as $atributo) {
            $optionId = $hijo->{$atributo->code};
            $opcion = $atributo->options->firstWhere('id', (int) $optionId);

            $opciones[$atributo->code] = [
                'atributo' => $texto("atributo:{$atributo->id}", fn () => (string) ($atributo->name ?: $atributo->admin_name)),
                'option_id' => $optionId === null ? null : (int) $optionId,
                'etiqueta' => $opcion ? $texto("opcion:{$opcion->id}", fn () => (string) ($opcion->label ?: $opcion->admin_name)) : null,
            ];
        }

        return $opciones;
    }

    private function superAtributos(Product $producto): Collection
    {
        return $producto->type === 'configurable' ? $producto->super_attributes()->with('options')->get() : collect();
    }

    /**
     * Contexto de precio del catalogo. Con carrito (venta en curso) usa el MISMO contexto que el carrito
     * (ContextoPrecioComercial::durante), asi catalogo y carrito comparten el grupo efectivo de la venta; sin carrito,
     * el contexto de creacion con el grupo actual del cliente, como siempre.
     */
    private function conPrecioDeVenta(int $customerId, ?int $cartId, Closure $operacion): mixed
    {
        return $cartId === null
            ? $this->contexto->duranteCreacionDeCarrito($customerId, $operacion)
            : $this->contexto->durante((int) $cartId, $customerId, $operacion);
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
