<?php

use ByLopez\VentasAsesor\DataObjects\VistaBorradorVenta as Vista;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\ContextoPrecioComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\LineaBorradorInvalidaException as LineaInvalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as VarianteInvalida;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartItem;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductOrderedInventory;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| Fase 03C — LineasBorradorService sobre el Cart real de Bagisto. Solo lpz_saas_testing.
*/

/** Codigo estable de la excepcion de dominio que lance la accion, o null si no lanza. */
function f03c_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido|VarianteInvalida|LineaInvalida|GrupoComercialInvalidoException|ContextoPrecioComercialInvalidoException $e) {
        return $e->codigo;
    }

    return null;
}

function f03c_simple(float $precio = 5000.0): Product
{
    return (new ProductFaker([
        'attributes' => [5 => 'new', 6 => 'featured', 11 => 'price', 26 => 'guest_checkout'],
        'attribute_value' => [
            'new' => ['boolean_value' => true], 'featured' => ['boolean_value' => true],
            'price' => ['float_value' => $precio], 'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();
}

/**
 * I-13 (D-39): el carrito solo materializa lo solicitado. Estas pruebas de lineas parten de
 * una solicitud amplia para que decida Bagisto (stock) y no el tope de la solicitud.
 */
function f03c_solicitar(BorradorVenta $borrador, Product $producto, Product $variante, int $cantidad = 1000): void
{
    app(SolicitudBorradorService::class)->crear($borrador->id, $producto->id, $variante->id, $cantidad,
        Admin::query()->findOrFail($borrador->fresh()->propietario_admin_id));
}

/** Todas las filas del carrito del borrador (lineas y filas internas de variante). */
function f03c_filas(BorradorVenta $borrador)
{
    return CartItem::query()->where('cart_id', $borrador->fresh()->cart_id)->get();
}

beforeEach(function () {
    $this->borradores = app(BorradorVentaService::class);
    $this->lineas = app(LineasBorradorService::class);
    $this->asesor = f03b_admin();
    $this->otroAsesor = f03b_admin();
    $this->grupoA = f03b_grupo('A');
    $this->grupoB = f03b_grupo('B');
    $this->clienteA = f03b_cliente($this->grupoA);

    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    $this->otraVariante = $this->configurable->variants()->get()->firstWhere('id', '!=', $this->variante->id);
    f03b_stock($this->variante, 50);
    f03b_stock($this->otraVariante, 50);

    $this->borrador = $this->borradores->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
    f03c_solicitar($this->borrador, $this->configurable, $this->variante);
    f03c_solicitar($this->borrador, $this->configurable, $this->otraVariante);
});

// ------------------------------------------------------------------ A. agregar

test('A1 agregar configurable: UNA linea con la variante verificada, sus opciones y el precio de Bagisto', function () {
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 3, $this->asesor);
    $linea = $vista->lineas[0];
    $codigos = $this->configurable->super_attributes()->pluck('code')->all();

    expect($vista->lineas)->toHaveCount(1)
        ->and($linea->productoId)->toBe($this->configurable->id)
        ->and($linea->varianteId)->toBe($this->variante->id)
        ->and($linea->sku)->toBe($this->variante->sku)
        ->and($linea->cantidad)->toBe(3)
        ->and(array_keys($linea->opciones))->toBe($codigos)
        ->and($linea->opciones[$codigos[0]]['option_id'])->toBe((int) $this->variante->{$codigos[0]})
        ->and($linea->precioUnitario)->toBe((float) $this->variante->fresh()->getTypeInstance()->getFinalPrice(3))
        ->and($linea->subtotal)->toBe(round($linea->precioUnitario * 3, 2))
        // Bagisto: fila del configurable + fila interna de la variante.
        ->and(f03c_filas($this->borrador)->pluck('product_id')->sort()->values()->all())
        ->toBe(collect([$this->configurable->id, $this->variante->id])->sort()->values()->all());
});

test('A2 variante de OTRO configurable: rechazada antes de tocar el carrito', function () {
    $ajena = f03b_configurable()->variants()->first();

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $ajena->id, 1, $this->asesor)))
        ->toBe(VarianteInvalida::VARIANTE_NO_PERTENECE_AL_PADRE)
        ->and(f03c_filas($this->borrador))->toHaveCount(0);
});

test('A3 una variante (hijo) no se agrega fingiendo ser un simple independiente', function () {
    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->variante->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(VarianteInvalida::PRODUCTO_NO_VENDIBLE)
        ->and(f03c_filas($this->borrador))->toHaveCount(0);
});

test('A4 simple vendible sin padre: se agrega (variante = el propio producto); otro id como variante se rechaza', function () {
    $simple = f03c_simple();
    f03b_stock($simple, 10);
    f03c_solicitar($this->borrador, $simple, $simple);

    $vista = $this->lineas->agregar($this->borrador->id, $simple->id, $simple->id, 2, $this->asesor);

    expect($vista->lineas)->toHaveCount(1)
        ->and($vista->lineas[0]->productoId)->toBe($simple->id)
        ->and($vista->lineas[0]->varianteId)->toBe($simple->id)
        ->and($vista->lineas[0]->opciones)->toBe([])
        ->and(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $simple->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(VarianteInvalida::VARIANTE_NO_PERTENECE_AL_PADRE);
});

test('A5 cantidad: solo enteros >= 1 (o su texto exacto); 0, negativos, decimales y textos ambiguos se rechazan', function (mixed $cantidad) {
    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, $cantidad, $this->asesor)))
        ->toBe(VarianteInvalida::CANTIDAD_INVALIDA)
        ->and(f03c_filas($this->borrador))->toHaveCount(0);
})->with([0, -2, 1.5, 2.0, '0', '-1', '2.0', ' 3', '3 ', 'abc', '', '007', true, null, 2_000_000]);

test('A5b cantidad como texto decimal exacto ("4") se acepta', function () {
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, '4', $this->asesor);

    expect($vista->lineas[0]->cantidad)->toBe(4);
});

test('A6 identidad: la misma variante dos veces SUMA en una linea; otra variante crea otra linea', function () {
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 3, $this->asesor);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor);
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->otraVariante->id, 1, $this->asesor);

    $porVariante = collect($vista->lineas)->mapWithKeys(fn ($l) => [$l->varianteId => $l->cantidad]);

    expect($vista->lineas)->toHaveCount(2)
        ->and($porVariante[$this->variante->id])->toBe(5)
        ->and($porVariante[$this->otraVariante->id])->toBe(1)
        ->and(f03c_filas($this->borrador))->toHaveCount(4)
        ->and($vista->borrador->totales['items_qty'])->toBe(6.0)
        ->and($vista->borrador->totales['items_count'])->toBe(2);
});

// ------------------------------------------------------------------ B. precio

test('B1 precio por grupo: dos clientes de grupos distintos reciben el precio de Bagisto de SU grupo', function () {
    f03b_descuentoDeGrupo($this->variante, $this->grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($this->variante, $this->grupoB, 30, $this->configurable);
    $borradorB = $this->borradores->crear(f03b_cliente($this->grupoB)->id, $this->asesor, CanalVenta::WHATSAPP);
    f03c_solicitar($borradorB, $this->configurable, $this->variante);

    $precioA = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->precioUnitario;
    $precioB = $this->lineas->agregar($borradorB->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->precioUnitario;

    expect($precioA)->toBe(round(10000 * 0.9, 2))
        ->and($precioB)->toBe(round(10000 * 0.7, 2));
});

test('B2 cambio de grupo del cliente: recalcular y cambiar cantidad usan el grupo ACTUAL (B), no el congelado (A)', function () {
    f03b_descuentoDeGrupo($this->variante, $this->grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($this->variante, $this->grupoB, 30, $this->configurable);
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);
    $itemId = $vista->lineas[0]->cartItemId;

    $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);   // cambio administrativo

    $this->borradores->recalcular($this->borrador->id, $this->asesor);
    $trasRecalcular = $this->lineas->listar($this->borrador->id)->lineas[0]->precioUnitario;
    $trasCantidad = $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 2, $this->asesor);

    expect($vista->lineas[0]->precioUnitario)->toBe(9000.0)
        ->and($trasRecalcular)->toBe(7000.0)
        ->and($trasCantidad->lineas[0]->precioUnitario)->toBe(7000.0)
        ->and($trasCantidad->borrador->totales['sub_total'])->toBe(14000.0);
});

test('B3 la sesion de la tienda de OTRO cliente no contamina el precio del borrador', function () {
    f03b_descuentoDeGrupo($this->variante, $this->grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($this->variante, $this->grupoB, 30, $this->configurable);
    auth()->guard('customer')->login(f03b_cliente($this->grupoB));

    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);

    expect($vista->lineas[0]->precioUnitario)->toBe(9000.0);
});

test('B4 D-18: custom_price sigue NULL al agregar, cambiar cantidad, recalcular y cambiar de cliente', function () {
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);
    $sinCustomPrice = fn () => f03c_filas($this->borrador)->every(fn ($f) => $f->custom_price === null);
    $trasAgregar = $sinCustomPrice();

    $this->lineas->cambiarCantidad($this->borrador->id, $vista->lineas[0]->cartItemId, 3, $this->asesor);
    $trasCantidad = $sinCustomPrice();

    $this->borradores->recalcular($this->borrador->id, $this->asesor);
    $trasRecalcular = $sinCustomPrice();

    $this->borradores->cambiarCliente($this->borrador->id, f03b_cliente($this->grupoB)->id, $this->asesor);
    $trasCambioCliente = $sinCustomPrice();

    expect([$trasAgregar, $trasCantidad, $trasRecalcular, $trasCambioCliente])->toBe([true, true, true, true])
        ->and(f03c_filas($this->borrador))->toHaveCount(2);
});

test('B5 cliente que pasa al grupo invitado: no se agregan lineas (fail closed, sin precio de invitado)', function () {
    $this->clienteA->update(['customer_group_id' => core()->getGuestCustomerGroup()->id]);

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO);
});

// ------------------------------------------------------------------ C. stock

test('C1 stock suficiente: entrega inmediata = cantidad; produccion DESCONOCIDA (null, nunca 0)', function () {
    f03b_stock($this->variante, 10);

    $linea = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 4, $this->asesor)->lineas[0];

    expect($linea->stockVendible)->toBe(10)
        ->and($linea->abastecimiento->toArray())->toBe([
            'solicitado' => 4, 'disponibleInmediato' => 4, 'faltanteInmediato' => 0, 'enProduccion' => null, 'porFabricar' => null,
        ]);
});

test('C2 COMPORTAMIENTO REAL: pedir mas que el stock vendible → Bagisto rechaza TODA la linea; nada se escribe y el carrito vacio sigue vivo', function () {
    f03b_stock($this->variante, 30);

    try {
        $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 100, $this->asesor);
        $error = null;
    } catch (LineaInvalida $e) {
        $error = $e;
    }

    expect($error?->codigo)->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and($error->abastecimiento->toArray())->toBe([
            'solicitado' => 100, 'disponibleInmediato' => 30, 'faltanteInmediato' => 70, 'enProduccion' => null, 'porFabricar' => null,
        ])
        ->and(f03c_filas($this->borrador))->toHaveCount(0)
        ->and(CartModel::query()->find($this->borrador->cart_id))->not->toBeNull()
        ->and($this->borrador->fresh()->estado)->toBe(EstadoBorradorVenta::ACTIVO);
});

test('C3 el stock se valida sobre el TOTAL de la linea: 3 + 3 con 5 vendibles se rechaza y la linea queda en 3', function () {
    f03b_stock($this->variante, 5);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 3, $this->asesor);

    try {
        $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 3, $this->asesor);
        $error = null;
    } catch (LineaInvalida $e) {
        $error = $e;
    }

    expect($error?->codigo)->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and($error->abastecimiento->solicitado)->toBe(6)
        ->and($error->abastecimiento->disponibleInmediato)->toBe(5)
        ->and($this->lineas->listar($this->borrador->id)->lineas[0]->cantidad)->toBe(3);
});

test('C4 si el stock vendible baja despues de agregar, la linea muestra el faltante inmediato (I-12)', function () {
    f03b_stock($this->variante, 8);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 6, $this->asesor);

    // Otra venta reserva 5: el indice de Bagisto baja a 3.
    ProductOrderedInventory::query()->updateOrCreate(['product_id' => $this->variante->id, 'channel_id' => core()->getDefaultChannel()->id], ['qty' => 5]);
    app(Inventory::class)->reindexBatch([$this->variante->fresh()]);

    $vista = $this->lineas->listar($this->borrador->id);

    expect($vista->lineas[0]->stockVendible)->toBe(3)
        ->and($vista->lineas[0]->abastecimiento->disponibleInmediato)->toBe(3)
        ->and($vista->lineas[0]->abastecimiento->faltanteInmediato)->toBe(3)
        ->and($vista->lineas[0]->abastecimiento->enProduccion)->toBeNull()
        ->and($vista->cubreEntregaInmediata())->toBeFalse();
});

test('C5 si Bagisto rechaza el PRIMER producto y borra el carrito vacio (removeCart), la transaccion lo revierte', function () {
    // Simple con opcion personalizable obligatoria: prepareForCart devuelve texto y
    // Cart::addProduct borra el carrito si esta vacio antes de lanzar.
    $simple = f03c_simple();
    f03b_stock($simple, 10);
    $opcionId = DB::table('product_customizable_options')->insertGetId([
        'product_id' => $simple->id, 'type' => 'text', 'is_required' => 1, 'sort_order' => 1,
    ]);
    DB::table('product_customizable_option_translations')->insert([
        'product_customizable_option_id' => $opcionId, 'locale' => app()->getLocale(), 'label' => 'Bordado',
    ]);
    f03c_solicitar($this->borrador, $simple, $simple);

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $simple->id, $simple->id, 1, $this->asesor)))
        ->toBe(LineaInvalida::RECHAZADA_POR_CARRITO)
        ->and(CartModel::query()->find($this->borrador->cart_id))->not->toBeNull()
        ->and($this->borrador->fresh()->cart_id)->not->toBeNull();
});

// ------------------------------------------------------------------ D. cantidad

test('D1 cambiar cantidad: Bagisto actualiza la linea y los totales', function () {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)->lineas[0]->cartItemId;

    $vista = $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 5, $this->asesor);

    expect($vista->lineas[0]->cantidad)->toBe(5)
        ->and($vista->lineas[0]->subtotal)->toBe(round($vista->lineas[0]->precioUnitario * 5, 2))
        ->and($vista->borrador->totales['sub_total'])->toBe($vista->lineas[0]->subtotal)
        ->and($vista->borrador->totales['items_qty'])->toBe(5.0);
});

test('D2 un cart_item de OTRO carrito, o la fila interna de la variante, no se puede modificar', function () {
    $otroBorrador = $this->borradores->crear(f03b_cliente($this->grupoA)->id, $this->asesor, CanalVenta::WHATSAPP);
    f03c_solicitar($otroBorrador, $this->configurable, $this->variante);
    $ajeno = $this->lineas->agregar($otroBorrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->cartItemId;
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);
    $filaInterna = f03c_filas($this->borrador)->whereNotNull('parent_id')->first()->id;

    expect(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $ajeno, 4, $this->asesor)))
        ->toBe(LineaInvalida::LINEA_NO_PERTENECE_AL_BORRADOR)
        ->and(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $filaInterna, 4, $this->asesor)))
        ->toBe(LineaInvalida::LINEA_NO_PERTENECE_AL_BORRADOR)
        ->and((int) CartItem::query()->find($ajeno)->quantity)->toBe(1);
});

test('D3 cambiar a una cantidad invalida se rechaza sin tocar la linea', function () {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)->lineas[0]->cartItemId;

    expect(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 0, $this->asesor)))
        ->toBe(VarianteInvalida::CANTIDAD_INVALIDA)
        ->and(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 1.5, $this->asesor)))
        ->toBe(VarianteInvalida::CANTIDAD_INVALIDA)
        ->and($this->lineas->listar($this->borrador->id)->lineas[0]->cantidad)->toBe(2);
});

test('D4 el stock se revalida al cambiar cantidad: por encima del vendible se rechaza con desglose y la linea no cambia', function () {
    f03b_stock($this->variante, 5);
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)->lineas[0]->cartItemId;

    try {
        $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 6, $this->asesor);
        $error = null;
    } catch (LineaInvalida $e) {
        $error = $e;
    }

    expect($error?->codigo)->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and($error->abastecimiento->toArray())->toBe([
            'solicitado' => 6, 'disponibleInmediato' => 5, 'faltanteInmediato' => 1, 'enProduccion' => null, 'porFabricar' => null,
        ])
        ->and($this->lineas->listar($this->borrador->id)->lineas[0]->cantidad)->toBe(2);
});

// ------------------------------------------------------------------ E. eliminar

test('E1 eliminar una linea: la fila interna de la variante cae en cascada y Bagisto recalcula el resto', function () {
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor);
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->otraVariante->id, 1, $this->asesor);
    $quitar = collect($vista->lineas)->firstWhere('varianteId', $this->variante->id)->cartItemId;

    $vista = $this->lineas->eliminar($this->borrador->id, $quitar, $this->asesor);

    expect($vista->lineas)->toHaveCount(1)
        ->and($vista->lineas[0]->varianteId)->toBe($this->otraVariante->id)
        ->and(f03c_filas($this->borrador))->toHaveCount(2)
        ->and($vista->borrador->totales['items_qty'])->toBe(1.0)
        ->and($vista->borrador->totales['sub_total'])->toBe($vista->lineas[0]->subtotal);
});

test('E2 no se puede eliminar un cart_item de otro carrito', function () {
    $otroBorrador = $this->borradores->crear(f03b_cliente($this->grupoA)->id, $this->asesor, CanalVenta::WHATSAPP);
    f03c_solicitar($otroBorrador, $this->configurable, $this->variante);
    $ajeno = $this->lineas->agregar($otroBorrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->cartItemId;

    expect(f03c_codigo(fn () => $this->lineas->eliminar($this->borrador->id, $ajeno, $this->asesor)))
        ->toBe(LineaInvalida::LINEA_NO_PERTENECE_AL_BORRADOR)
        ->and(CartItem::query()->find($ajeno))->not->toBeNull();
});

test('E3 OBLIGATORIO: quitar la ULTIMA linea deja el borrador activo con su carrito vivo, vacio y en cero; se puede volver a agregar', function () {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)->lineas[0]->cartItemId;

    $vista = $this->lineas->eliminar($this->borrador->id, $itemId, $this->asesor);
    $carrito = CartModel::query()->find($this->borrador->cart_id);

    expect($carrito)->not->toBeNull()
        ->and($this->borrador->fresh()->cart_id)->toBe($carrito->id)
        ->and($vista->borrador->estado)->toBe(EstadoBorradorVenta::ACTIVO->value)
        ->and($vista->lineas)->toBe([])
        ->and(f03c_filas($this->borrador))->toHaveCount(0)
        ->and($vista->borrador->totales)->toBe([
            'items_count' => 0, 'items_qty' => 0.0, 'sub_total' => 0.0, 'discount_amount' => 0.0,
            'tax_total' => 0.0, 'shipping_amount' => 0.0, 'grand_total' => 0.0,
        ]);

    $otraVez = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);

    expect($otraVez->lineas)->toHaveCount(1)
        ->and($otraVez->borrador->cartId)->toBe($carrito->id);
});

// ------------------------------------------------------------------ F. propiedad y estados

test('F1 otro asesor no agrega, cambia ni elimina lineas de un borrador que no es suyo', function () {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->cartItemId;

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->otroAsesor)))
        ->toBe(Invalido::NO_ES_PROPIETARIO)
        ->and(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 2, $this->otroAsesor)))
        ->toBe(Invalido::NO_ES_PROPIETARIO)
        ->and(f03c_codigo(fn () => $this->lineas->eliminar($this->borrador->id, $itemId, $this->otroAsesor)))
        ->toBe(Invalido::NO_ES_PROPIETARIO)
        ->and($this->lineas->listar($this->borrador->id)->lineas[0]->cantidad)->toBe(1);
});

test('F2 tras reclamar el borrador vencido, el nuevo propietario opera y el anterior ya no', function () {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->cartItemId;
    BorradorVenta::query()->whereKey($this->borrador->id)->update(['asignado_hasta' => now()->subMinute()]);

    $this->borradores->reclamar($this->borrador->id, $this->otroAsesor);

    expect($this->lineas->cambiarCantidad($this->borrador->id, $itemId, 4, $this->otroAsesor)->lineas[0]->cantidad)->toBe(4)
        ->and(f03c_codigo(fn () => $this->lineas->eliminar($this->borrador->id, $itemId, $this->asesor)))->toBe(Invalido::NO_ES_PROPIETARIO);
});

test('F3 sin permiso ventas_asesor.pedidos.create no se operan lineas', function () {
    $sinPermiso = f03b_admin('custom', ['dashboard']);

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $sinPermiso)))
        ->toBe(Invalido::SIN_PERMISO);
});

test('F4 solo el estado activo admite lineas: confirmando, confirmado y descartado se rechazan', function (EstadoBorradorVenta $estado) {
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)->lineas[0]->cartItemId;
    BorradorVenta::query()->whereKey($this->borrador->id)->update(['estado' => $estado->value]);

    expect(f03c_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE)
        ->and(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 2, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE)
        ->and(f03c_codigo(fn () => $this->lineas->eliminar($this->borrador->id, $itemId, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE);
})->with([EstadoBorradorVenta::CONFIRMANDO, EstadoBorradorVenta::CONFIRMADO, EstadoBorradorVenta::DESCARTADO]);

test('F5 P-09: cliente inactivo o suspendido SI admite lineas, pero la vista lo marca NO confirmable', function () {
    $this->clienteA->update(['status' => 0, 'is_suspended' => 1]);

    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);

    expect($vista->lineas)->toHaveCount(1)
        ->and($vista->borrador->clienteConfirmable)->toBeFalse()
        ->and($vista->borrador->avisos)->toContain(Vista::AVISO_CLIENTE_INACTIVO, Vista::AVISO_CLIENTE_SUSPENDIDO);
});

// ------------------------------------------------------------------ G. concurrencia y contrato

test('G1 cada mutacion bloquea borrador y carrito (FOR UPDATE) antes de escribir lineas', function () {
    $consultas = [];
    DB::listen(function ($q) use (&$consultas) {
        $consultas[] = strtolower($q->sql);
    });

    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor);

    $bloqueoBorrador = collect($consultas)->search(fn ($s) => str_contains($s, 'bylopez_va_borradores_venta') && str_contains($s, 'for update'));
    $bloqueoCarrito = collect($consultas)->search(fn ($s) => str_contains($s, 'from `cart`') && str_contains($s, 'for update'));
    $primeraEscritura = collect($consultas)->search(fn ($s) => str_starts_with($s, 'insert into `cart_items`'));

    expect($bloqueoBorrador)->not->toBeFalse()
        ->and($bloqueoCarrito)->not->toBeFalse()
        ->and($primeraEscritura)->not->toBeFalse()
        ->and($bloqueoBorrador < $bloqueoCarrito && $bloqueoCarrito < $primeraEscritura)->toBeTrue();
});

test('G2 agregados repetidos no pierden cantidades ni duplican la linea; tras descartar, nada mas se modifica', function () {
    foreach (range(1, 5) as $i) {
        $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor);
    }

    $vista = $this->lineas->listar($this->borrador->id);
    $itemId = $vista->lineas[0]->cartItemId;
    $this->borradores->descartar($this->borrador->id, $this->asesor);

    expect($vista->lineas)->toHaveCount(1)
        ->and($vista->lineas[0]->cantidad)->toBe(10)
        ->and(f03c_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 3, $this->asesor)))->toBe(Invalido::NO_EDITABLE);
});

test('G3 la vista es un contrato plano: sin modelos Eloquent y con los totales que calculo Bagisto', function () {
    $vista = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor);
    $carrito = CartModel::query()->find($this->borrador->cart_id);
    $plano = $vista->toArray();

    array_walk_recursive($plano, function ($valor) {
        expect(is_object($valor) && ! $valor instanceof DateTimeInterface)->toBeFalse();
    });

    expect($vista->borrador->totales['grand_total'])->toBe((float) $carrito->grand_total)
        ->and($vista->borrador->totales['sub_total'])->toBe((float) $carrito->sub_total)
        ->and($plano['lineas'][0]['abastecimiento']['enProduccion'])->toBeNull()
        ->and($plano['cubre_entrega_inmediata'])->toBeTrue();
});
