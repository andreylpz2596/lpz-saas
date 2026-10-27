<?php

use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Services\Configuracion\IntermediaService;
use ByLopez\Cargues\Tests\CarguesTestCase;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
});

afterEach(fn () => cg_limpiar());

test('humo: un tipo se activa sin migración y una carga CSV llega a validada', function () {
    [$tipo, , $admin] = cg_tipo_activo();

    expect(app(IntermediaService::class)->existe(cg_conn(), CG_TABLA))->toBeTrue();

    $carga = cg_carga($tipo, $admin, cg_filas(3));
    expect($carga->estado)->toBe('recibida')
        ->and($carga->encabezados['sugerencias'])->toHaveKeys(['guia', 'telefono', 'estado', 'valor', 'fecha_estado']);

    $carga = cg_validar($carga, $admin);

    expect($carga->estado)->toBe('validada')
        ->and(CargaDato::where('carga_id', $carga->id)->count())->toBe(3)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->count())->toBe(3)
        ->and($carga->contadores['filas_validas'])->toBe(3)
        ->and($carga->contadores['filas_rechazadas'])->toBe(0);
});
