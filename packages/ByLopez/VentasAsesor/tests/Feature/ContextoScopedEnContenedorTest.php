<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use Illuminate\Container\Container;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Registro del contexto en el contenedor (Fase 02B1A)
|--------------------------------------------------------------------------
|
| Lo que se comprueba aqui no es una preferencia de estilo: con `singleton`, bajo
| Octane, el contexto comercial de un cliente sobreviviria a su peticion y podria
| atribuir el pedido del siguiente.
|
*/

/** @return list<string> Clases registradas como `scoped` en el contenedor. */
function va32_clasesScoped(): array
{
    $propiedad = new ReflectionProperty(Container::class, 'scopedInstances');
    $propiedad->setAccessible(true);

    return array_values((array) $propiedad->getValue(app()));
}

test('1. el contexto comercial de la peticion esta registrado como scoped, no como singleton', function () {
    expect(va32_clasesScoped())->toContain(ContextoComercialDePeticion::class)
        ->and(va32_clasesScoped())->toContain(ClasificadorOrigenPedido::class);

    // Dentro del mismo scope, es la MISMA instancia: el listener ve lo que registro el creador.
    expect(app(ContextoComercialDePeticion::class))->toBe(app(ContextoComercialDePeticion::class));
});

test('7. un scope nuevo no hereda el contexto anterior', function () {
    $resolver = app(ResolverAtribucionService::class);

    $primero = app(ContextoComercialDePeticion::class);
    $primero->registrar(9001, $resolver->paraStorefront(CanalVenta::ECOMMERCE, 9001));

    expect($primero->para(9001))->not->toBeNull();

    // Equivale al fin de la peticion: Laravel y Octane descartan las instancias scoped.
    app()->forgetScopedInstances();

    $segundo = app(ContextoComercialDePeticion::class);

    expect($segundo)->not->toBe($primero)
        ->and($segundo->para(9001))->toBeNull()
        ->and($segundo->cartsRegistrados())->toBe([]);
});

test('el contexto no guarda estado estatico entre instancias', function () {
    $resolver = app(ResolverAtribucionService::class);

    $uno = new ContextoComercialDePeticion;
    $uno->registrar(9100, $resolver->paraStorefront(CanalVenta::ECOMMERCE, 9100));

    $otro = new ContextoComercialDePeticion;

    expect($otro->para(9100))->toBeNull()
        ->and($uno->para(9100))->not->toBeNull();
});
