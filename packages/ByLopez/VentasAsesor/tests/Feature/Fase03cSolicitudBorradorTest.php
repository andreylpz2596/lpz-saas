<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\LineaBorradorInvalidaException as LineaInvalida;
use ByLopez\VentasAsesor\Exceptions\SolicitudBorradorInvalidaException as SolicitudInvalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException as VarianteInvalida;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartItem;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Models\ProductOrderedInventory;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| Fase 03C / D-39 — Solicitud comercial (lo que el cliente QUIERE) frente al Cart de
| Bagisto (lo que se puede procesar hoy). Solo lpz_saas_testing.
*/

function f03cs_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido|VarianteInvalida|LineaInvalida|SolicitudInvalida|GrupoComercialInvalidoException $e) {
        return $e->codigo;
    }

    return null;
}

beforeEach(function () {
    $this->borradores = app(BorradorVentaService::class);
    $this->solicitudes = app(SolicitudBorradorService::class);
    $this->lineas = app(LineasBorradorService::class);
    $this->asesor = f03b_admin();
    $this->otroAsesor = f03b_admin();
    $this->grupoA = f03b_grupo('A');
    $this->grupoB = f03b_grupo('B');
    $this->clienteA = f03b_cliente($this->grupoA);

    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 30);

    $this->borrador = $this->borradores->crear($this->clienteA->id, $this->asesor, CanalVenta::WHATSAPP);
});

/** Crea la solicitud canonica: 100 unidades de la variante con 30 vendibles. */
function f03cs_cien($test)
{
    return $test->solicitudes->crear($test->borrador->id, $test->configurable->id, $test->variante->id, 100, $test->asesor)
        ->solicitud($test->variante->id);
}

// ------------------------------------------------------------ caso canonico

test('CANONICO: pedir 100 con 30 vendibles GUARDA 100, no toca el carrito y desglosa 30 / 70 / null / null', function () {
    $solicitud = f03cs_cien($this);

    expect($solicitud->cantidadSolicitada)->toBe(100)
        ->and(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->value('cantidad_solicitada'))->toBe(100)
        ->and($solicitud->cantidadEnCarrito)->toBe(0)
        ->and(CartItem::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0)
        ->and($solicitud->stockVendible)->toBe(30)
        ->and($solicitud->abastecimiento->toArray())->toBe([
            'solicitado' => 100, 'disponibleInmediato' => 30, 'faltanteInmediato' => 70, 'enProduccion' => null, 'porFabricar' => null,
        ])
        ->and($solicitud->excesoCarritoSobreStock)->toBe(0)
        ->and($solicitud->vendible)->toBeTrue();
});

test('crear una solicitud en un borrador con carrito VACIO no llama collectTotals ni borra el carrito', function () {
    f03cs_cien($this);

    expect(CartModel::query()->find($this->borrador->cart_id))->not->toBeNull()
        ->and($this->borrador->fresh()->cart_id)->not->toBeNull();
});

test('MATERIALIZACION explicita: el asesor lleva 30 al carrito; la solicitud sigue en 100', function () {
    f03cs_cien($this);

    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 30, $this->asesor);
    $solicitud = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id);

    expect($solicitud->cantidadSolicitada)->toBe(100)
        ->and($solicitud->cantidadEnCarrito)->toBe(30)
        ->and($solicitud->abastecimiento->disponibleInmediato)->toBe(30)
        ->and($solicitud->abastecimiento->faltanteInmediato)->toBe(70)
        ->and($this->lineas->listar($this->borrador->id)->lineas[0]->cantidad)->toBe(30);
});

test('materializar mas que el stock vendible lo rechaza Bagisto aunque quepa en la solicitud', function () {
    f03cs_cien($this);

    expect(f03cs_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 31, $this->asesor)))
        ->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and(CartItem::query()->where('cart_id', $this->borrador->cart_id)->count())->toBe(0);
});

test('CIERRE 03C: carrito <= stock vendible al operar. 30 se admite; llegar a 31 (agregando o modificando) se rechaza informando 30, sin recortar', function () {
    $rechazo = function (callable $accion) {
        try {
            $accion();
        } catch (LineaInvalida $e) {
            return $e;
        }

        return null;
    };

    f03cs_cien($this);
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 30, $this->asesor)->lineas[0]->cartItemId;

    $alAgregar = $rechazo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor));
    $alModificar = $rechazo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 31, $this->asesor));
    $solicitud = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id);

    expect($alAgregar?->codigo)->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and($alAgregar->abastecimiento->solicitado)->toBe(31)
        ->and($alAgregar->abastecimiento->disponibleInmediato)->toBe(30)
        ->and($alModificar?->codigo)->toBe(LineaInvalida::STOCK_INSUFICIENTE)
        ->and($alModificar->abastecimiento->disponibleInmediato)->toBe(30)
        // Nada se recorto ni se agrego solo: el carrito sigue en 30 y la solicitud en 100.
        ->and($solicitud->cantidadEnCarrito)->toBe(30)
        ->and($solicitud->cantidadSolicitada)->toBe(100)
        ->and($solicitud->abastecimiento->disponibleInmediato)->toBe(30)
        ->and($solicitud->abastecimiento->enProduccion)->toBeNull()
        ->and($solicitud->abastecimiento->porFabricar)->toBeNull()
        ->and(CartItem::query()->where('cart_id', $this->borrador->cart_id)->get()->every(fn ($i) => $i->custom_price === null))->toBeTrue();
});

test('I-13: sin solicitud no se materializa nada, y el carrito nunca supera lo solicitado', function () {
    expect(f03cs_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(LineaInvalida::SIN_SOLICITUD);

    $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->variante->id, 5, $this->asesor);
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 4, $this->asesor)->lineas[0]->cartItemId;

    expect(f03cs_codigo(fn () => $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor)))
        ->toBe(LineaInvalida::EXCEDE_SOLICITUD)
        ->and(f03cs_codigo(fn () => $this->lineas->cambiarCantidad($this->borrador->id, $itemId, 6, $this->asesor)))
        ->toBe(LineaInvalida::EXCEDE_SOLICITUD)
        ->and($this->lineas->cambiarCantidad($this->borrador->id, $itemId, 5, $this->asesor)->lineas[0]->cantidad)->toBe(5);
});

test('STOCK BAJA despues: solicitud 100, carrito 30, vendible 20 → la vista refleja 20 y 10 sin respaldo; no se toca nada', function () {
    f03cs_cien($this);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 30, $this->asesor);

    ProductOrderedInventory::query()->updateOrCreate(['product_id' => $this->variante->id, 'channel_id' => core()->getDefaultChannel()->id], ['qty' => 10]);
    app(Inventory::class)->reindexBatch([$this->variante->fresh()]);

    $solicitud = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id);

    expect($solicitud->cantidadSolicitada)->toBe(100)
        ->and($solicitud->cantidadEnCarrito)->toBe(30)
        ->and($solicitud->stockVendible)->toBe(20)
        ->and($solicitud->abastecimiento->disponibleInmediato)->toBe(20)
        ->and($solicitud->abastecimiento->faltanteInmediato)->toBe(80)
        ->and($solicitud->excesoCarritoSobreStock)->toBe(10)
        ->and((int) CartItem::query()->where('cart_id', $this->borrador->cart_id)->whereNull('parent_id')->value('quantity'))->toBe(30);
});

// ------------------------------------------------------------ validaciones

test('variante real verificada; variante de otro padre e hijo como simple se rechazan', function () {
    $ajena = f03b_configurable()->variants()->first();

    expect(f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $ajena->id, 1, $this->asesor)))
        ->toBe(VarianteInvalida::VARIANTE_NO_PERTENECE_AL_PADRE)
        ->and(f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->variante->id, $this->variante->id, 1, $this->asesor)))
        ->toBe(VarianteInvalida::PRODUCTO_NO_VENDIBLE)
        ->and(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->count())->toBe(0);
});

test('cantidad invalida se rechaza al crear y al cambiar', function (mixed $cantidad) {
    $solicitud = f03cs_cien($this);

    expect(f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->configurable->variants()->get()->last()->id, $cantidad, $this->asesor)))
        ->toBe(VarianteInvalida::CANTIDAD_INVALIDA)
        ->and(f03cs_codigo(fn () => $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, $cantidad, $this->asesor)))
        ->toBe(VarianteInvalida::CANTIDAD_INVALIDA);
})->with([0, -1, 2.5, '2.0', 'x', null]);

test('cambiar cantidad solicitada es ABSOLUTO (repetirlo no suma) y no baja de lo que hay en el carrito', function () {
    $solicitud = f03cs_cien($this);
    $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 60, $this->asesor);
    $vista = $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 60, $this->asesor);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 20, $this->asesor);

    expect($vista->solicitud($this->variante->id)->cantidadSolicitada)->toBe(60)
        ->and(f03cs_codigo(fn () => $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 19, $this->asesor)))
        ->toBe(SolicitudInvalida::MENOR_QUE_CARRITO)
        ->and($this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 20, $this->asesor)->solicitud($this->variante->id)->cantidadSolicitada)
        ->toBe(20);
});

test('eliminar solicitud: con unidades en carrito se rechaza; retiradas del carrito, se elimina', function () {
    $solicitud = f03cs_cien($this);
    $itemId = $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 3, $this->asesor)->lineas[0]->cartItemId;

    $bloqueada = f03cs_codigo(fn () => $this->solicitudes->eliminar($this->borrador->id, $solicitud->solicitudId, $this->asesor));
    $this->lineas->eliminar($this->borrador->id, $itemId, $this->asesor);
    $vista = $this->solicitudes->eliminar($this->borrador->id, $solicitud->solicitudId, $this->asesor);

    expect($bloqueada)->toBe(SolicitudInvalida::EN_CARRITO)
        ->and($vista->solicitudes)->toBe([])
        ->and(CartModel::query()->find($this->borrador->cart_id))->not->toBeNull();
});

test('una solicitud de OTRO borrador no se puede cambiar ni eliminar desde este', function () {
    $otro = $this->borradores->crear(f03b_cliente($this->grupoA)->id, $this->asesor, CanalVenta::WHATSAPP);
    $ajena = $this->solicitudes->crear($otro->id, $this->configurable->id, $this->variante->id, 5, $this->asesor)->solicitud($this->variante->id);

    expect(f03cs_codigo(fn () => $this->solicitudes->cambiarCantidad($this->borrador->id, $ajena->solicitudId, 9, $this->asesor)))
        ->toBe(SolicitudInvalida::INEXISTENTE)
        ->and(f03cs_codigo(fn () => $this->solicitudes->eliminar($this->borrador->id, $ajena->solicitudId, $this->asesor)))
        ->toBe(SolicitudInvalida::INEXISTENTE)
        ->and(SolicitudBorrador::query()->find($ajena->solicitudId)->cantidad_solicitada)->toBe(5);
});

test('misma variante: UNA solicitud por borrador; el segundo alta se rechaza (idempotente ante reenvios) y la BD lo garantiza', function () {
    f03cs_cien($this);

    $codigo = f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->variante->id, 100, $this->asesor));

    expect($codigo)->toBe(SolicitudInvalida::YA_EXISTE)
        ->and(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->count())->toBe(1)
        ->and(SolicitudBorrador::query()->value('cantidad_solicitada'))->toBe(100)
        ->and(fn () => DB::table('bylopez_va_borrador_solicitudes')->insert([
            'borrador_id' => $this->borrador->id, 'variante_id' => $this->variante->id, 'cantidad_solicitada' => 1,
        ]))->toThrow(UniqueConstraintViolationException::class);
});

// ------------------------------------------------------------ propiedad, estado, cliente

test('borrador ajeno: otro asesor no crea, cambia ni elimina; tras reclamarlo si', function () {
    $solicitud = f03cs_cien($this);

    $crear = f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->configurable->variants()->get()->last()->id, 1, $this->otroAsesor));
    $cambiar = f03cs_codigo(fn () => $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 50, $this->otroAsesor));
    $eliminar = f03cs_codigo(fn () => $this->solicitudes->eliminar($this->borrador->id, $solicitud->solicitudId, $this->otroAsesor));

    BorradorVenta::query()->whereKey($this->borrador->id)->update(['asignado_hasta' => now()->subMinute()]);
    $this->borradores->reclamar($this->borrador->id, $this->otroAsesor);

    expect([$crear, $cambiar, $eliminar])->toBe([Invalido::NO_ES_PROPIETARIO, Invalido::NO_ES_PROPIETARIO, Invalido::NO_ES_PROPIETARIO])
        ->and($this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 50, $this->otroAsesor)->solicitud($this->variante->id)->cantidadSolicitada)
        ->toBe(50);
});

test('borrador no activo: confirmando, confirmado y descartado no admiten solicitudes', function (EstadoBorradorVenta $estado) {
    $solicitud = f03cs_cien($this);
    BorradorVenta::query()->whereKey($this->borrador->id)->update(['estado' => $estado->value]);

    expect(f03cs_codigo(fn () => $this->solicitudes->crear($this->borrador->id, $this->configurable->id, $this->configurable->variants()->get()->last()->id, 1, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE)
        ->and(f03cs_codigo(fn () => $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 5, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE)
        ->and(f03cs_codigo(fn () => $this->solicitudes->eliminar($this->borrador->id, $solicitud->solicitudId, $this->asesor)))
        ->toBe(Invalido::NO_EDITABLE);
})->with([EstadoBorradorVenta::CONFIRMANDO, EstadoBorradorVenta::CONFIRMADO, EstadoBorradorVenta::DESCARTADO]);

test('al descartar el borrador el carrito desaparece y las solicitudes quedan como rastro de lo pedido', function () {
    f03cs_cien($this);

    $this->borradores->descartar($this->borrador->id, $this->asesor);

    expect(CartModel::query()->find($this->borrador->cart_id))->toBeNull()
        ->and(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->value('cantidad_solicitada'))->toBe(100);
});

test('cliente invitado: no se registran solicitudes (sin grupo comercial no hay venta comercial)', function () {
    $this->clienteA->update(['customer_group_id' => core()->getGuestCustomerGroup()->id]);

    expect(f03cs_codigo(fn () => f03cs_cien($this)))->toBe(GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO);
});

// ------------------------------------------------------------ precio y catalogo en tiempo real

test('precio de referencia: sale de Bagisto con el grupo ACTUAL (RT-03) y sigue al cambio de grupo; nunca se guarda', function () {
    f03b_descuentoDeGrupo($this->variante, $this->grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($this->variante, $this->grupoB, 30, $this->configurable);
    $antes = f03cs_cien($this)->precioUnitarioReferencia;

    $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);
    $despues = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id)->precioUnitarioReferencia;

    expect($antes)->toBe(9000.0)
        ->and($despues)->toBe(7000.0)
        ->and(array_keys(SolicitudBorrador::query()->first()->getAttributes()))
        ->toBe(['id', 'borrador_id', 'variante_id', 'cantidad_solicitada', 'created_at', 'updated_at']);
});

test('RT-03 y D-18 con materializacion: el carrito toma el precio del grupo actual y custom_price sigue NULL', function () {
    f03b_descuentoDeGrupo($this->variante, $this->grupoA, 10, $this->configurable);
    f03b_descuentoDeGrupo($this->variante, $this->grupoB, 30, $this->configurable);
    f03cs_cien($this);
    $this->lineas->agregar($this->borrador->id, $this->configurable->id, $this->variante->id, 2, $this->asesor);

    $this->clienteA->update(['customer_group_id' => $this->grupoB->id]);
    $this->borradores->recalcular($this->borrador->id, $this->asesor);
    $linea = $this->lineas->listar($this->borrador->id)->lineas[0];

    expect($linea->precioUnitario)->toBe(7000.0)
        ->and(CartItem::query()->where('cart_id', $this->borrador->cart_id)->get()->every(fn ($i) => $i->custom_price === null))->toBeTrue();
});

test('catalogo en tiempo real: variante desactivada → no vendible, sin disponible ni precio; SKU y nombre actuales', function () {
    f03cs_cien($this);
    DB::table('products')->where('id', $this->variante->id)->update(['sku' => 'SKU-NUEVO-'.$this->variante->id]);
    DB::table('product_attribute_values')->where('product_id', $this->variante->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'status')->value('id'))->update(['boolean_value' => 0]);

    $solicitud = $this->solicitudes->listar($this->borrador->id)->solicitud($this->variante->id);

    expect($solicitud->sku)->toBe('SKU-NUEVO-'.$this->variante->id)
        ->and($solicitud->vendible)->toBeFalse()
        ->and($solicitud->stockVendible)->toBe(0)
        ->and($solicitud->abastecimiento->disponibleInmediato)->toBe(0)
        ->and($solicitud->abastecimiento->faltanteInmediato)->toBe(100)
        ->and($solicitud->precioUnitarioReferencia)->toBeNull()
        ->and($solicitud->cantidadSolicitada)->toBe(100);
});

test('producto eliminado del catalogo: su solicitud cae en cascada (como sus lineas de carrito)', function () {
    f03cs_cien($this);

    DB::table('products')->where('id', $this->variante->id)->delete();

    expect(SolicitudBorrador::query()->where('borrador_id', $this->borrador->id)->count())->toBe(0);
});

// ------------------------------------------------------------ concurrencia

test('concurrencia: crear y cambiar bloquean la fila del borrador (FOR UPDATE) antes de escribir la solicitud', function () {
    $consultas = [];
    DB::listen(function ($q) use (&$consultas) {
        $consultas[] = strtolower($q->sql);
    });

    $solicitud = f03cs_cien($this);
    $this->solicitudes->cambiarCantidad($this->borrador->id, $solicitud->solicitudId, 80, $this->asesor);

    $posiciones = fn (string $patron) => collect($consultas)->keys()->filter(fn ($k) => str_contains($consultas[$k], $patron))->values();
    $bloqueos = collect($consultas)->keys()->filter(fn ($k) => str_contains($consultas[$k], 'bylopez_va_borradores_venta') && str_contains($consultas[$k], 'for update'))->values();
    $insert = $posiciones('insert into `bylopez_va_borrador_solicitudes`')->first();
    $update = $posiciones('update `bylopez_va_borrador_solicitudes`')->first();

    expect($bloqueos)->toHaveCount(2)
        ->and($bloqueos[0] < $insert)->toBeTrue()
        ->and($bloqueos[1] > $insert && $bloqueos[1] < $update)->toBeTrue();
});
