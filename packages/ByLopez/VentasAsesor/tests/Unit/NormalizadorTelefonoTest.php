<?php

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\Enums\EstadoNormalizacionTelefono as Estado;
use ByLopez\VentasAsesor\Enums\TipoTelefono as Tipo;
use ByLopez\VentasAsesor\Support\Telefono\NormalizadorTelefonoBasico;
use Tests\TestCase;

uses(TestCase::class);

/*
| RT-04B1a — NormalizadorTelefonoBasico. Numeros ficticios.
*/

beforeEach(function () {
    $this->normalizador = app(NormalizadorTelefono::class);
});

test('el contrato se resuelve con la implementacion basica', function () {
    expect($this->normalizador)->toBeInstanceOf(NormalizadorTelefonoBasico::class);
});

test('las representaciones equivalentes de un movil colombiano producen el mismo E.164', function (string $entrada) {
    $t = $this->normalizador->normalizar($entrada);

    expect($t->normalizado)->toBe('+573005550101')
        ->and($t->estado)->toBe(Estado::ESTRUCTURA_RECONOCIDA)
        ->and($t->tipo)->toBe(Tipo::MOVIL)
        ->and($t->paisIso)->toBe('CO')
        ->and($t->esBuscable())->toBeTrue();
})->with([
    '3005550101',
    '300 555 0101',
    '+57 300 555 0101',
    '573005550101',
    '+57 (300) 555-0101',
    '0057 300 555 0101',
    '300.555.0101',
    "300\u{00A0}555\u{00A0}0101",
]);

test('los cuatro ejemplos del requerimiento RT-04B1a son el mismo numero', function () {
    $resultados = array_map(
        fn (string $e) => $this->normalizador->normalizar($e)->normalizado,
        ['3001230045', '300 123 0045', '+57 300 123 0045', '573001230045'],
    );

    expect(array_unique($resultados))->toBe(['+573001230045']);
});

test('fijo colombiano con indicativo vigente (60 + region)', function () {
    $t = $this->normalizador->normalizar('601 234 5678');

    expect($t->normalizado)->toBe('+576012345678')->and($t->tipo)->toBe(Tipo::FIJO)->and($t->estado)->toBe(Estado::ESTRUCTURA_RECONOCIDA);
});

test('fijo sin indicativo vigente es AMBIGUO: no se inventa ciudad', function (string $entrada) {
    $t = $this->normalizador->normalizar($entrada);

    expect($t->estado)->toBe(Estado::AMBIGUO)
        ->and($t->normalizado)->toBeNull()
        ->and($t->motivo)->toBe('fijo_sin_indicativo_vigente')
        ->and($t->esBuscable())->toBeFalse();
})->with(['2345678', '1 234 5678', '+57 1 234 5678']);

test('vacio: null, cadena vacia o solo espacios', function (?string $entrada) {
    $t = $this->normalizador->normalizar($entrada);

    expect($t->estado)->toBe(Estado::VACIO)->and($t->normalizado)->toBeNull()->and($t->esBuscable())->toBeFalse();
})->with([null, '', '   ']);

test('caracteres no permitidos invalidan el valor en vez de desaparecer (sin extensiones inventadas)', function (string $entrada) {
    $t = $this->normalizador->normalizar($entrada);

    expect($t->estado)->toBe(Estado::INVALIDO)->and($t->motivo)->toBe('caracteres_no_permitidos')->and($t->normalizado)->toBeNull();
})->with(['300 555 0101 ext 12', 'tres cero cero', '300-555-0101#', '+57+3005550101']);

test('numeracion colombiana no reconocida es INVALIDO', function (string $entrada) {
    expect($this->normalizador->normalizar($entrada)->estado)->toBe(Estado::INVALIDO);
})->with(['12345', '4005550101', '30055501012', '+57 400 555 0101']);

test('internacional explicito se conserva en E.164 sin verificar ni inventar pais', function (string $entrada, string $e164) {
    $t = $this->normalizador->normalizar($entrada);

    expect($t->normalizado)->toBe($e164)
        ->and($t->estado)->toBe(Estado::INTERNACIONAL_NO_VERIFICADO)
        ->and($t->paisIso)->toBeNull()
        ->and($t->tipo)->toBe(Tipo::DESCONOCIDO)
        ->and($t->esBuscable())->toBeTrue();
})->with([
    ['+1 415 555 2671', '+14155552671'],
    ['+44 20 7946 0958', '+442079460958'],
    ['0052 55 1234 5678', '+525512345678'],
]);

test('internacional con longitud imposible es INVALIDO (E.164: 8 a 15 digitos aqui)', function (string $entrada) {
    expect($this->normalizador->normalizar($entrada)->motivo)->toBe('longitud_internacional');
})->with(['+1234', '+1234567890123456', '00']);

test('sin + y con un pais por defecto sin reglas propias queda AMBIGUO', function () {
    $t = $this->normalizador->normalizar('5512345678', 'MX');

    expect($t->estado)->toBe(Estado::AMBIGUO)->and($t->motivo)->toBe('pais_por_defecto_sin_reglas');
});

test('el pais por defecto sale de la configuracion del modulo', function () {
    config(['ventasasesor.telefono.pais_por_defecto' => 'MX']);

    expect($this->normalizador->normalizar('3005550101')->estado)->toBe(Estado::AMBIGUO);
});

test('normalizar no es validar: se acepta la ESTRUCTURA aunque el numero no exista o el pais no este asignado', function () {
    // Estructura de movil colombiano: no se consulta si existe ni si esta asignado.
    $colombiano = $this->normalizador->normalizar('300 000 0000');
    // +999 no es un codigo de pais asignado: solo se comprobo la forma E.164.
    $internacional = $this->normalizador->normalizar('+999 1234 5678');

    expect($colombiano->estado)->toBe(Estado::ESTRUCTURA_RECONOCIDA)
        ->and($internacional->estado)->toBe(Estado::INTERNACIONAL_NO_VERIFICADO)
        ->and($internacional->normalizado)->toBe('+99912345678')
        ->and($internacional->paisIso)->toBeNull()
        ->and(array_column(Estado::cases(), 'value'))->not->toContain('valido');
});

test('conserva el original (recortado) y enmascara con los ultimos 4 digitos', function () {
    $t = $this->normalizador->normalizar('  300 555 0101 ');

    expect($t->original)->toBe('300 555 0101')->and($t->enmascarado())->toBe('***0101');
});
