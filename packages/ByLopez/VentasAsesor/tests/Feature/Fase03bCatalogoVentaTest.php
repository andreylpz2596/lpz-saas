<?php

use ByLopez\VentasAsesor\DataObjects\DesgloseAbastecimiento;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as Invalida;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\ProductOrderedInventory;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| Fase 03B — CatalogoVentaService (solo lectura). Solo lpz_saas_testing.
*/

function f03b_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida|GrupoComercialInvalidoException $e) {
        return $e->codigo;
    }

    return null;
}

beforeEach(function () {
    $this->catalogo = app(CatalogoVentaService::class);
    $this->configurable = f03b_configurable();
    $this->cliente = f03b_cliente();
});

test('variantes: devuelve los hijos REALES del configurable con ids, SKU y opciones de sus super-atributos', function () {
    $variantes = $this->catalogo->variantes($this->configurable->id, $this->cliente->id);
    $hijos = $this->configurable->variants()->pluck('id')->sort()->values()->all();
    $codigos = $this->configurable->super_attributes()->pluck('code')->all();

    expect(collect($variantes)->pluck('productId')->sort()->values()->all())->toBe($hijos)
        ->and(collect($variantes)->pluck('padreId')->unique()->all())->toBe([$this->configurable->id])
        ->and(array_keys($variantes[0]->opciones))->toBe($codigos)
        ->and($variantes[0]->opciones[$codigos[0]]['option_id'])->toBeInt()
        ->and($variantes[0]->opciones[$codigos[0]]['etiqueta'])->not->toBeNull();
});

test('INVARIANTE: una variante de OTRO configurable se rechaza aunque ambos ids existan', function () {
    $otro = f03b_configurable();
    $varianteAjena = $otro->variants()->first();

    expect(f03b_codigo(fn () => $this->catalogo->variante($this->configurable->id, $varianteAjena->id, $this->cliente->id)))
        ->toBe(Invalida::VARIANTE_NO_PERTENECE_AL_PADRE)
        ->and(f03b_codigo(fn () => $this->catalogo->variante($this->configurable->id, 99_999_999, $this->cliente->id)))
        ->toBe(Invalida::PRODUCTO_INEXISTENTE);
});

test('una variante (hijo) no se acepta como producto de primer nivel', function () {
    $hijo = $this->configurable->variants()->first();

    expect(f03b_codigo(fn () => $this->catalogo->variantes($hijo->id, $this->cliente->id)))->toBe(Invalida::PRODUCTO_NO_VENDIBLE);
});

test('resolverVariante: por opciones reales devuelve exactamente ese hijo; incompleto o inexistente se rechaza', function () {
    $hijo = $this->configurable->variants()->first();
    $opciones = collect($this->configurable->super_attributes()->get())
        ->mapWithKeys(fn ($a) => [$a->code => (int) $hijo->{$a->code}])->all();

    $resuelta = $this->catalogo->resolverVariante($this->configurable->id, $opciones, $this->cliente->id);

    expect($resuelta->productId)->toBe($hijo->id)
        ->and(f03b_codigo(fn () => $this->catalogo->resolverVariante($this->configurable->id, array_slice($opciones, 0, 1, true), $this->cliente->id)))
        ->toBe(count($opciones) > 1 ? Invalida::OPCIONES_INCOMPLETAS : null)
        ->and(f03b_codigo(fn () => $this->catalogo->resolverVariante($this->configurable->id, array_map(fn () => 99_999_999, $opciones), $this->cliente->id)))
        ->toBe(Invalida::VARIANTE_NO_ENCONTRADA);
});

test('stock: el mismo que Bagisto (indice del canal = inventario - reservas), sin leer tablas por su cuenta', function () {
    $hijo = $this->configurable->variants()->first();
    f03b_stock($hijo, 7);

    $antes = $this->catalogo->variante($this->configurable->id, $hijo->id, $this->cliente->id);

    ProductOrderedInventory::query()->updateOrCreate(['product_id' => $hijo->id, 'channel_id' => core()->getDefaultChannel()->id], ['qty' => 2]);
    app(Inventory::class)->reindexBatch([$hijo->fresh()]);

    $despues = $this->catalogo->variante($this->configurable->id, $hijo->id, $this->cliente->id);

    expect($antes->stockVendible)->toBe(7)
        ->and($despues->stockVendible)->toBe(5)
        ->and($despues->stockVendible)->toBe((int) $hijo->fresh()->getTypeInstance()->totalQuantity());
});

test('precio: dos clientes de grupos distintos reciben el precio que Bagisto calcula para CADA grupo (RT-03)', function () {
    $hijo = $this->configurable->variants()->first();
    $base = (float) $hijo->price;
    $grupoA = f03b_grupo('A');
    $grupoB = f03b_grupo('B');
    f03b_descuentoDeGrupo($hijo, $grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($hijo, $grupoB, 30, $this->configurable);
    $clienteA = f03b_cliente($grupoA);
    $clienteB = f03b_cliente($grupoB);

    // La sesion de la tienda con A no influye en el precio consultado para B.
    auth()->guard('customer')->login($clienteA);

    $precioA = $this->catalogo->variante($this->configurable->id, $hijo->id, $clienteA->id)->precioUnitario;
    $precioB = $this->catalogo->variante($this->configurable->id, $hijo->id, $clienteB->id)->precioUnitario;

    expect(round($precioA, 2))->toBe(round($base * 0.9, 2))
        ->and(round($precioB, 2))->toBe(round($base * 0.7, 2));
});

test('cliente invitado o sin grupo: no hay precio comercial (fail closed)', function () {
    $invitado = f03b_cliente(core()->getGuestCustomerGroup());
    $sinGrupo = Customer::factory()->create(['customer_group_id' => null]);

    expect(f03b_codigo(fn () => $this->catalogo->variantes($this->configurable->id, $invitado->id)))->toBe(GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO)
        ->and(f03b_codigo(fn () => $this->catalogo->variantes($this->configurable->id, $sinGrupo->id)))->toBe(GrupoComercialInvalidoException::GRUPO_REQUERIDO);
});

test('solo lectura: consultar variantes no escribe nada (ni carrito ni custom_price)', function () {
    $escrituras = [];
    DB::listen(function ($q) use (&$escrituras) {
        if (! preg_match('/^\s*select\b/i', $q->sql)) {
            $escrituras[] = $q->sql;
        }
    });

    $this->catalogo->variantes($this->configurable->id, $this->cliente->id, 3);

    expect($escrituras)->toBe([]);
});

test('buscarProductos: encuentra el configurable por nombre y nunca devuelve variantes hijas', function () {
    $nombre = DB::table('product_flat')->where('product_id', $this->configurable->id)->value('name');

    // Nombre completo: una sola palabra de faker puede aparecer en mas de 50 productos y
    // quedar fuera del limite, lo que hacia la prueba intermitente.
    $encontrados = collect($this->catalogo->buscarProductos((string) $nombre, 50));

    expect($encontrados->pluck('productId'))->toContain($this->configurable->id)
        ->and($encontrados->pluck('productId')->intersect($this->configurable->variants()->pluck('id'))->all())->toBe([]);
});

test('buscarProductos por SKU del padre: las variantes (con product_flat.parent_id NULL, como en Bagisto 2.4) no se cuelan', function () {
    $variantes = $this->configurable->variants()->pluck('id');
    DB::table('products')->whereIn('id', $variantes)->get()->each(fn ($v) => expect($v->parent_id)->toBe($this->configurable->id));
    expect(DB::table('product_flat')->whereIn('product_id', $variantes)->whereNotNull('parent_id')->count())->toBe(0);   // el indexador no la llena

    $encontrados = collect($this->catalogo->buscarProductos($this->configurable->sku, 50));               // el SKU del padre está contenido en el de sus variantes

    expect($encontrados->pluck('productId')->all())->toBe([$this->configurable->id])
        ->and(count($this->catalogo->variantes($this->configurable->id, $this->cliente->id)))->toBe($variantes->count());
});

test('D-36/D-37: 100 pedidos con 30 vendibles → 30 para entrega inmediata; produccion DESCONOCIDA (null, nunca 0)', function () {
    $hijo = $this->configurable->variants()->first();
    f03b_stock($hijo, 30);

    $variante = $this->catalogo->variante($this->configurable->id, $hijo->id, $this->cliente->id);
    $desglose = $this->catalogo->desglose($variante, 100);

    expect($desglose->toArray())->toBe([
        'solicitado' => 100, 'disponibleInmediato' => 30, 'faltanteInmediato' => 70, 'enProduccion' => null, 'porFabricar' => null,
    ])->and($desglose->cubreEntregaInmediata())->toBeFalse();
});

test('INVARIANTE I-12: la produccion es informativa y NUNCA suma a la entrega inmediata (30 + 50 en confeccion ≠ 80)', function () {
    $desglose = DesgloseAbastecimiento::desdeStock(100, 30, true)->conProduccionInformativa(50);

    expect($desglose->disponibleInmediato)->toBe(30)
        ->and($desglose->faltanteInmediato)->toBe(70)
        ->and($desglose->enProduccion)->toBe(50)
        ->and($desglose->porFabricar)->toBe(20)
        ->and($desglose->cubreEntregaInmediata())->toBeFalse()
        ->and(DesgloseAbastecimiento::desdeStock(100, 30, true)->conProduccionInformativa(null)->enProduccion)->toBeNull();
});
