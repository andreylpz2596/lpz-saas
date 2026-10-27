<?php

use ByLopez\VentasAsesor\Enums\EstadoUtilidadLinea;
use ByLopez\VentasAsesor\Services\Comisiones\UtilidadVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom.php';

uses(TestCase::class);

/*
| COM-02 — utilidad de SOLO LECTURA sobre pedidos reales con snapshot de COM-01. Solo lpz_saas_testing.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin();
    $this->cliente = f03b_cliente(f03b_grupo('U'));
    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 30);
});

test('COM02-1 margen comisionable = precio neto − descuento unitario − costo comisionable congelado; configurable = UNA linea; AUTORIZADO', function () {
    com_costo($this->variante, 5500.0);
    $pedido = com_vender($this->cliente->id, $this->asesor, $this->configurable, $this->variante, 4);

    // Fixture: descuento de 2.000 en la linea (500 por unidad), como lo dejaria una regla de carrito.
    DB::table('order_items')->where('order_id', $pedido->id)->whereNull('parent_id')->update(['base_discount_amount' => 2000]);
    // El catalogo cambia despues: la utilidad usa el snapshot, no el costo de hoy.
    com_costo($this->variante, 9000.0);

    $u = app(UtilidadVentaService::class)->dePedido($pedido->id);
    $l = $u->lineas[0];

    expect($u->lineas)->toHaveCount(1)
        ->and($l->sku)->toBe($this->variante->sku)
        ->and($l->cantidad)->toBe(4)
        ->and($l->precioNetoUnitario)->toBe(9500.0)             // 10.000 − 2.000 / 4
        ->and($l->costoUnitario)->toBe(5500.0)
        ->and($l->utilidadUnitariaDiagnostica)->toBe(4000.0)
        ->and($l->utilidadTotalDiagnostica)->toBe(16000.0)
        ->and($l->estado)->toBe(EstadoUtilidadLinea::AUTORIZADA)
        ->and($l->autoridadComision)->toBeTrue()
        ->and($l->utilidadTotalAutorizada)->toBe(16000.0)
        ->and($u->utilidadDiagnostica())->toBe(16000.0)
        ->and($u->utilidadAutorizada())->toBe(16000.0);
});

test('COM02-2 sin costo → utilidad null (nunca = precio) y fuera de totales; costo > precio → 0 por unidad, sin netear', function () {
    com_costo($this->variante, null);
    $sinCosto = com_vender($this->cliente->id, $this->asesor, $this->configurable, $this->variante, 2);

    com_costo($this->variante, 12000.0);
    $conPerdida = com_vender($this->cliente->id, $this->asesor, $this->configurable, $this->variante, 2);

    $r = app(UtilidadVentaService::class)->dePedidos([$sinCosto->id, $conPerdida->id]);
    $a = $r[$sinCosto->id]->lineas[0];
    $b = $r[$conPerdida->id]->lineas[0];

    expect($a->estado)->toBe(EstadoUtilidadLinea::SIN_COSTO)
        ->and($a->precioNetoUnitario)->toBe(10000.0)
        ->and($a->utilidadUnitariaDiagnostica)->toBeNull()
        ->and($a->utilidadTotalDiagnostica)->toBeNull()
        ->and($r[$sinCosto->id]->utilidadDiagnostica())->toBe(0.0)
        ->and($r[$sinCosto->id]->lineasSinCosto())->toBe(1)
        ->and($r[$sinCosto->id]->utilidadAutorizada())->toBeNull()
        ->and($b->estado)->toBe(EstadoUtilidadLinea::AUTORIZADA)
        ->and($b->margenNegativo)->toBeTrue()
        ->and($b->utilidadUnitariaDiagnostica)->toBe(0.0)
        ->and($b->utilidadTotalDiagnostica)->toBe(0.0);
});
