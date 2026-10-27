<?php

use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| Mejoras visuales de Despacho (2026-10-24). Solo se prueba lo que tiene logica: la clasificacion informativa del destino que el
| servidor entrega al drawer y su logica de presentacion en JavaScript. Solo lpz_saas_testing, datos de fixture.
*/

test('UX-1 el contexto clasifica el destino: local, fuera de cobertura o sin identificar (solo informativo: no cambia zona ni tarifa)', function () {
    log6_catalogo();
    $bodega = log2_bodega();
    $envio = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega)['envio'];
    $pedido = fn () => log2_pedido(1, ['envio' => 'domicilio_local', 'pago' => 'moneytransfer']);

    // Bogota: municipio reconocido Y zona local del origen.
    log6_municipio('11001');
    $local = $envio($pedido());

    expect($local['destino_cobertura'])->toBe('local')
        ->and($local['zona_auto']['nombre'])->toBe('Bogotá');

    // Un municipio reconocido por el catalogo geografico pero sin zona local (p. ej. Melgar): fuera de cobertura, NO se asigna zona.
    log6_municipio('73449');
    $fuera = $envio($pedido());

    expect($fuera['destino_cobertura'])->toBe('fuera_cobertura')
        ->and($fuera['zona_auto'])->toBeNull();

    // Direccion que no coincide con el catalogo: sin identificar (no se adivina por texto), tampoco hay zona.
    log6_municipio(null);
    $sin = $envio($pedido());

    expect($sin['destino_cobertura'])->toBe('no_identificado')
        ->and($sin['zona_auto'])->toBeNull();
});

test('UX-2 la logica de presentacion del drawer de despacho (metodo de pago partido, quien paga el flete, destino y reparto del recaudo) pasa en JavaScript', function () {
    $salida = [];
    exec('node '.escapeshellarg(base_path('packages/ByLopez/Logistica/tests/js/presentacion_despacho.mjs')).' '
        .escapeshellarg(base_path('packages/ByLopez/Logistica/src/Resources/views/admin/despachos/componente.blade.php')).' 2>&1', $salida, $codigo);

    expect($codigo)->toBe(0, implode("\n", $salida))
        ->and(implode("\n", $salida))->toContain('JS-OK');
});
