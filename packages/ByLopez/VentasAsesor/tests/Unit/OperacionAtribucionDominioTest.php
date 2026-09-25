<?php

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Services\HistorialAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Dominio de operaciones e idempotencia (Fase 02A1)
|--------------------------------------------------------------------------
|
| Sin base de datos: solo enums, objetos de valor y decisiones puras.
|
*/

function va21_historial(): HistorialAtribucionService
{
    return new HistorialAtribucionService;
}

function va21_resolverPuro(): ResolverAtribucionService
{
    return new ResolverAtribucionService(new AutorizacionVentasAsesor);
}

test('el enum de operaciones expone los valores estables acordados', function () {
    expect(array_column(TipoOperacionAtribucion::cases(), 'value'))->toBe([
        'initial_assignment',
        'assign_advisor',
        'reassign_advisor',
        'mark_company',
        'mark_unassigned',
        'reconciliation',
    ]);
});

test('el tipo de operacion se deduce del estado anterior y del destino', function (
    TipoPropiedadComercial $anterior,
    TipoPropiedadComercial $nueva,
    TipoOperacionAtribucion $esperada,
) {
    expect(va21_historial()->operacionDelCambio($anterior, $nueva))->toBe($esperada);
})->with([
    'sin asignar a asesor' => [TipoPropiedadComercial::SIN_ASIGNAR, TipoPropiedadComercial::ASESOR, TipoOperacionAtribucion::ASIGNAR_ASESOR],
    'empresa a asesor' => [TipoPropiedadComercial::EMPRESA, TipoPropiedadComercial::ASESOR, TipoOperacionAtribucion::ASIGNAR_ASESOR],
    'asesor a asesor' => [TipoPropiedadComercial::ASESOR, TipoPropiedadComercial::ASESOR, TipoOperacionAtribucion::REASIGNAR_ASESOR],
    'asesor a empresa' => [TipoPropiedadComercial::ASESOR, TipoPropiedadComercial::EMPRESA, TipoOperacionAtribucion::MARCAR_EMPRESA],
    'asesor a sin asignar' => [TipoPropiedadComercial::ASESOR, TipoPropiedadComercial::SIN_ASIGNAR, TipoOperacionAtribucion::MARCAR_SIN_ASIGNAR],
]);

test('el contexto de fallo tecnico queda sin asignar, sin asesor y con codigo normalizado', function () {
    $contexto = va21_resolverPuro()->paraFalloTecnico('  Listener.Contexto_Ausente  ');

    expect($contexto->estado())->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and($contexto->esFalloTecnico())->toBeTrue()
        ->and($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBeNull()
        ->and($contexto->codigoTecnico)->toBe('listener.contexto_ausente')
        ->and($contexto->canal)->toBe(CanalVenta::DESCONOCIDO)
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::DESCONOCIDO)
        ->and($contexto->notas)->toBeNull()
        ->and($contexto->aAtributos()['attribution_status'])->toBe(EstadoAtribucion::FALLO_TECNICO);
});

test('el codigo tecnico rechaza texto libre que podria filtrar datos', function (string $codigo) {
    expect(fn () => va21_resolverPuro()->paraFalloTecnico($codigo))->toThrow(AtribucionInvalidaException::class);
})->with([
    'vacio' => [''],
    'solo espacios' => ['   '],
    'con espacios y datos' => ['fallo al crear pedido de cliente 3001112233'],
    'con credenciales' => ['db_password=secreto'],
    'demasiado largo' => [str_repeat('a', 61)],
]);

test('solo fallo_tecnico puede forzarse como estado, y siempre sin asesor', function () {
    $valido = fn (array $cambios = []) => new ContextoComercial(
        propiedad: $cambios['propiedad'] ?? TipoPropiedadComercial::SIN_ASIGNAR,
        advisorId: $cambios['advisorId'] ?? null,
        createdByAdminId: null,
        canal: CanalVenta::DESCONOCIDO,
        mecanismo: MecanismoCreacion::DESCONOCIDO,
        origenPropiedad: OrigenPropiedadComercial::SIN_CONTEXTO,
        estadoExplicito: $cambios['estado'] ?? EstadoAtribucion::FALLO_TECNICO,
        codigoTecnico: array_key_exists('codigo', $cambios) ? $cambios['codigo'] : 'x.y',
    );

    expect($valido()->estado())->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and(fn () => $valido(['estado' => EstadoAtribucion::COMPLETA]))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $valido(['estado' => EstadoAtribucion::SIN_ASESOR]))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $valido(['codigo' => null]))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $valido(['propiedad' => TipoPropiedadComercial::ASESOR, 'advisorId' => 7]))
        ->toThrow(AtribucionInvalidaException::class);
});

test('un codigo tecnico sin fallo tecnico se rechaza', function () {
    expect(fn () => new ContextoComercial(
        propiedad: TipoPropiedadComercial::EMPRESA,
        advisorId: null,
        createdByAdminId: null,
        canal: CanalVenta::ECOMMERCE,
        mecanismo: MecanismoCreacion::STOREFRONT,
        origenPropiedad: OrigenPropiedadComercial::REGLA_STOREFRONT,
        codigoTecnico: 'algo',
    ))->toThrow(AtribucionInvalidaException::class);
});

test('los valores comparables cubren los campos comerciales y normalizan enums', function () {
    $contexto = va21_resolverPuro()->paraStorefront(cartId: 41, referenciaExterna: 'ref-9');
    $valores = $contexto->valoresComparables();

    expect(array_keys($valores))->toBe(ContextoComercial::CAMPOS_COMPARABLES)
        ->and($valores['commercial_owner_type'])->toBe('company')
        ->and($valores['sales_channel'])->toBe('ecommerce')
        ->and($valores['creation_source'])->toBe('storefront')
        ->and($valores['attribution_status'])->toBe('completa')
        ->and($valores['cart_id'])->toBe(41)
        ->and($valores['external_reference'])->toBe('ref-9')
        ->and($valores['advisor_id'])->toBeNull()
        ->and(ContextoComercial::normalizar('7'))->toBe(7)
        ->and(ContextoComercial::normalizar(TipoPropiedadComercial::ASESOR))->toBe('advisor')
        ->and(ContextoComercial::normalizar(null))->toBeNull();
});
