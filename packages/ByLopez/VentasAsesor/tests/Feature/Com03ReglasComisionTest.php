<?php

use ByLopez\VentasAsesor\DataObjects\ComisionPotencialDiagnostica as Potencial;
use ByLopez\VentasAsesor\Exceptions\ReglaComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Services\Comisiones\ComisionPotencialService;
use ByLopez\VentasAsesor\Services\Comisiones\ReglasComisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom.php';

uses(TestCase::class);

/*
| COM-03 — reglas con vigencia y comision POTENCIAL DIAGNOSTICA (nunca causada). Solo lpz_saas_testing.
*/

function com03_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

test('COM03-1 el porcentaje sale de la regla VIGENTE (no del codigo); la potencial es diagnostica: no causada, no pagable, no liquidable', function () {
    Mail::fake();
    f03f_configurarBase();
    $asesor = f03b_admin();
    $configurable = f03b_configurable(10000.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, 10);
    com_costo($variante, 5500.0);

    $pedido = com_vender(f03b_cliente(f03b_grupo('R'))->id, $asesor, $configurable, $variante, 2);
    // Fixture: venta de asesor (la atribucion real del `all` queda sin asignar por D-27).
    DB::table('bylopez_va_order_attributions')->where('order_id', $pedido->id)
        ->update(['commercial_owner_type' => 'advisor', 'advisor_id' => $asesor->id]);

    $sinRegla = app(ComisionPotencialService::class)->dePedidos([$pedido->id])[$pedido->id];

    $regla = app(ReglasComisionService::class)->programar(
        ['codigo' => 'T-GLOBAL-20', 'nombre' => 'Global 20 %', 'porcentaje' => 20, 'motivo' => 'COM-D-03'], null, 'cli');

    $p = app(ComisionPotencialService::class)->dePedidos([$pedido->id])[$pedido->id];

    expect($sinRegla->montoDiagnostico)->toBeNull()
        ->and($sinRegla->motivosNoPagable)->toContain(Potencial::MOTIVO_SIN_REGLA)
        ->and(app(ReglasComisionService::class)->vigenteEn(now()->subDay()))->toBeNull()
        ->and(app(ReglasComisionService::class)->vigenteEn(now()->addSecond())->id)->toBe($regla->id)
        ->and($p->reglaId)->toBe($regla->id)
        ->and($p->porcentaje)->toBe(20.0)
        ->and($p->utilidadDiagnostica)->toBe(9000.0)       // (10.000 − 5.500) × 2
        ->and($p->montoDiagnostico)->toBe(1800.0)          // 9.000 × 20 %
        ->and($p->esDiagnostica)->toBeTrue()
        ->and($p->causada)->toBeFalse()
        ->and($p->pagable)->toBeFalse()
        ->and($p->liquidable)->toBeFalse()
        // D-COST-02: con costo comisionable oficial ya no falta autoridad; sigue sin entrega ni pago verificado.
        ->and($p->motivosNoPagable)->not->toContain(Potencial::MOTIVO_COSTO_NO_AUTORIZADO)
        ->and($p->motivosNoPagable)->toContain(Potencial::MOTIVO_ENTREGA_NO_IMPLEMENTADA)
        ->and($p->motivosNoPagable)->toContain(Potencial::MOTIVO_PAGO_NO_EVALUADO)
        // COM-04 no existe: ninguna tabla de lineas ni liquidaciones de comision.
        ->and(Schema::hasTable('bylopez_va_comision_lineas'))->toBeFalse()
        ->and(Schema::hasTable('bylopez_va_comision_liquidaciones'))->toBeFalse();
});

test('COM03-2 un cambio futuro crea otra regla y solo CIERRA la anterior; nada retroactivo, sin solapes, sin editar ni borrar', function () {
    $reglas = app(ReglasComisionService::class);
    $a = $reglas->programar(['codigo' => 'T-A', 'nombre' => 'A', 'porcentaje' => 20], null, 'cli');
    $b = $reglas->programar(['codigo' => 'T-B', 'nombre' => 'B', 'porcentaje' => 25, 'vigente_desde' => now()->addDays(10)], null, 'cli');
    $a->refresh();

    expect((float) $a->porcentaje)->toBe(20.0)
        ->and($a->vigente_hasta->equalTo($b->vigente_desde))->toBeTrue()
        ->and($reglas->vigenteEn(now()->addMinute())->id)->toBe($a->id)
        ->and($reglas->vigenteEn(now()->addDays(11))->id)->toBe($b->id)
        ->and(com03_error(fn () => $a->update(['porcentaje' => 30])))->toBe(Invalida::CAMPO_INMUTABLE)
        ->and(com03_error(fn () => $a->update(['vigente_hasta' => now()->addDays(20)])))->toBe(Invalida::CAMPO_INMUTABLE)
        ->and(com03_error(fn () => $a->delete()))->toBe(Invalida::BORRADO_PROHIBIDO)
        ->and(com03_error(fn () => $reglas->programar(['codigo' => 'T-C', 'nombre' => 'C', 'porcentaje' => 15, 'vigente_desde' => now()->subDay()], null, 'cli')))->toBe(Invalida::RETROACTIVA)
        ->and(com03_error(fn () => $reglas->programar(['codigo' => 'T-D', 'nombre' => 'D', 'porcentaje' => 15, 'vigente_desde' => now()->addDays(5)], null, 'cli')))->toBe(Invalida::SOLAPE)
        ->and((float) $a->fresh()->porcentaje)->toBe(20.0);
});
