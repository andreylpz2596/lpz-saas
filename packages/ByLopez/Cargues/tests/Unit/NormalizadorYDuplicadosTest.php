<?php

use ByLopez\Cargues\Services\Cargas\DuplicadosService;
use ByLopez\Cargues\Services\Cargas\Normalizador;
use ByLopez\Cargues\Services\Configuracion\MapValidator;
use ByLopez\Cargues\Tests\CarguesTestCase;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

function cg_norm(array $originales, array $sobreMapa = [], array $relaciones = ['guia' => 0, 'telefono' => 1, 'estado' => 2, 'valor' => 3, 'fecha_estado' => 4]): array
{
    $campos = app(MapValidator::class)->normalizar(cg_mapa($sobreMapa))['campos'];

    return app(Normalizador::class)->normalizar($originales, $relaciones, $campos, 'actualizar_estado');
}

function cg_codigos_norm(array $r, string $severidad = 'error'): array
{
    return array_column(array_filter($r['errores'], fn ($e) => $e['severidad'] === $severidad), 'codigo');
}

test('F1-L6 columna sin relacionar: el campo queda ausente y se registra', function () {
    $r = cg_norm(['G1', null, 'X', null, null], [], ['guia' => 0, 'estado' => 2]);

    expect($r['valores']['telefono'])->toBeNull()
        ->and($r['ausentes'])->toBe(['telefono' => 'sin_relacionar', 'valor' => 'sin_relacionar', 'fecha_estado' => 'sin_relacionar'])
        ->and($r['errores'])->toBe([]);
});

test('F1-L6 celda vacía: ausente, "" y espacios se tratan igual según la regla «vacio»; «nulo» deja NULL', function () {
    foreach ([null, '', '   ', "\t "] as $vacio) {
        $r = cg_norm(['G1', $vacio, 'X', null, null]);

        expect($r['valores']['telefono'])->toBeNull()->and($r['errores'])->toBe([])->and($r['ausentes'])->toBe([]);
    }
});

test('F1-L6 «rechazar» rechaza la celda vacía y «ignorar» la marca como ausente (no es NULL)', function () {
    $r = cg_norm(['G1', '', 'X', null, null], ['campos' => [1 => ['vacio' => 'rechazar']]]);
    expect(cg_codigos_norm($r))->toBe(['VACIO_RECHAZADO']);

    $r = cg_norm(['G1', '', 'X', null, null], ['campos' => [1 => ['vacio' => 'ignorar']]]);
    expect($r['errores'])->toBe([])->and($r['ausentes'])->toBe(['telefono' => 'vacio_ignorar']);
});

test('F1-L6 un campo obligatorio vacío se rechaza siempre', function () {
    $r = cg_norm(['', '300', '  ', null, null]);

    expect(cg_codigos_norm($r))->toBe(['OBLIGATORIO_VACIO', 'OBLIGATORIO_VACIO']);
});

test('F1-L6 el texto «NULL», «null» o «\\N» es texto, no un NULL', function () {
    foreach (['NULL', 'null', '\\N'] as $t) {
        $r = cg_norm(['G1', $t, 'X', null, null]);

        expect($r['valores']['telefono'])->toBe($t);
    }
});

test('no se pierden ceros ni signos en identificadores que llegan como texto', function () {
    $r = cg_norm(['0012345678', '+573001234567', 'X', null, null]);

    expect($r['valores']['guia'])->toBe('0012345678')->and($r['valores']['telefono'])->toBe('+573001234567')->and($r['errores'])->toBe([]);
});

test('un identificador que llega como NÚMERO se acepta con una advertencia (ya perdió sus ceros en el archivo)', function () {
    $r = cg_norm([12345678.0, null, 'X', null, null]);

    expect($r['valores']['guia'])->toBe('12345678')->and(cg_codigos_norm($r, 'advertencia'))->toBe(['IDENTIFICADOR_NUMERICO'])->and(cg_codigos_norm($r))->toBe([]);
});

test('el texto que no cabe se rechaza: nunca se trunca', function () {
    $r = cg_norm([str_repeat('9', 21), null, str_repeat('x', 41), null, null]);

    expect(cg_codigos_norm($r))->toBe(['LONGITUD_EXCEDIDA', 'LONGITUD_EXCEDIDA']);
});

test('decimales con el formato configurado, sin perder ni inventar dígitos', function () {
    expect(cg_norm(['G', null, 'X', '1.234,50', null])['valores']['valor'])->toBe('1234.50')
        ->and(cg_norm(['G', null, 'X', '-0,5', null])['valores']['valor'])->toBe('-0.50')
        ->and(cg_norm(['G', null, 'X', '138000', null])['valores']['valor'])->toBe('138000.00')
        ->and(cg_norm(['G', null, 'X', '1,230', null])['valores']['valor'])->toBe('1.23')
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', '1,234', null])))->toBe(['DECIMAL_ESCALA_EXCEDIDA'])
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', '1,2345', null])))->toBe(['DECIMAL_ESCALA_EXCEDIDA'])
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', '12a', null])))->toBe(['DECIMAL_INVALIDO'])
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', '1.5', null])))->toBe(['DECIMAL_INVALIDO']);   // con «,» decimal, un punto no es decimal

    // Un número de Excel (float) se normaliza con su representación más corta (no con precision=14) y sin el formato regional.
    expect(cg_norm(['G', null, 'X', 0.30000000000000004, null])['errores'][0]['codigo'])->toBe('DECIMAL_ESCALA_EXCEDIDA')
        ->and(cg_norm(['G', null, 'X', 1234.5, null])['valores']['valor'])->toBe('1234.50')
        ->and(cg_norm(['G', null, 'X', 138000, null])['valores']['valor'])->toBe('138000.00');
});

test('fechas: formato configurado, fecha de Excel y rechazo de las imposibles', function () {
    expect(cg_norm(['G', null, 'X', null, '01/10/2026 10:30'])['valores']['fecha_estado'])->toBe('2026-10-01 10:30:00')
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', null, '31/02/2026 10:30'])))->toBe(['FECHA_INVALIDA'])
        ->and(cg_codigos_norm(cg_norm(['G', null, 'X', null, '2026-10-01 10:30'])))->toBe(['FECHA_INVALIDA']);

    $r = cg_norm(['G', null, 'X', null, ['n' => 46296.4375, 'f' => 'dd/mm/yyyy hh:mm', 'd' => '2026-10-01 10:30:00']]);
    expect($r['valores']['fecha_estado'])->toBe('2026-10-01 10:30:00');

    expect(cg_codigos_norm(cg_norm(['G', null, 'X', null, 12345.5])))->toBe(['FECHA_INVALIDA']); // número sin formato de fecha
});

test('enteros y booleanos', function () {
    expect(cg_norm(['G', null, 'X', '42', null], ['campos' => [3 => ['tipo' => 'entero', 'precision' => null, 'escala' => null, 'formato' => null]]])['valores']['valor'])->toBe(42);

    $c = [['nombre' => 'activo', 'etiqueta' => 'Activo', 'tipo' => 'booleano', 'nulo' => true, 'vacio' => 'nulo']];
    $n = app(Normalizador::class);

    foreach ([['Sí', 1], ['no', 0], ['1', 1], [true, 1], ['X', 1], ['FALSE', 0]] as [$entrada, $esperado]) {
        expect($n->normalizar([$entrada], ['activo' => 0], $c, 'a')['valores']['activo'])->toBe($esperado);
    }

    expect($n->normalizar(['quizá'], ['activo' => 0], $c, 'a')['errores'][0]['codigo'])->toBe('BOOLEANO_INVALIDO');
});

test('una fórmula sin valor en caché y un error de Excel no producen un dato', function () {
    $r = cg_norm(['G', ['f' => '=A1+1', 'c' => null], 'X', null, null]);
    expect($r['valores']['telefono'])->toBeNull();

    $r = cg_norm(['G', ['e' => '#N/A'], 'X', null, null]);
    expect($r['valores']['telefono'])->toBeNull();
});

/** Las 12 filas de `08` §3.2 (fila 1 = encabezado): llave primaria guía y única teléfono. */
function cg_doce_filas(): array
{
    return [
        2 => ['guia' => 'G100', 'telefono' => '3001000001'], 3 => ['guia' => 'G101', 'telefono' => '3001000002'], 4 => ['guia' => 'G102', 'telefono' => null],
        5 => ['guia' => 'G101', 'telefono' => '3001000003'], 6 => ['guia' => 'G103', 'telefono' => null], 7 => ['guia' => 'G104', 'telefono' => '3001000004'],
        8 => ['guia' => 'G105', 'telefono' => '3001000004'], 9 => ['guia' => 'G106', 'telefono' => '3001000006'], 10 => ['guia' => 'G101', 'telefono' => '3001000010'],
        11 => ['guia' => 'G107', 'telefono' => '3001000007'], 12 => ['guia' => '0012345', 'telefono' => '3001000008'], 13 => ['guia' => '12345', 'telefono' => '3001000009'],
    ];
}

test('F1-D1 las tres políticas de duplicados rechazan exactamente las filas de 08 §3.2', function () {
    $llaves = ['primaria' => ['guia'], 'unicas' => [['telefono']]];
    $svc = app(DuplicadosService::class);

    foreach (['rechazar_todas' => [3, 5, 7, 8, 10], 'primera' => [5, 8, 10], 'ultima' => [3, 5, 7]] as $politica => $esperadas) {
        [$aceptadas, $rechazadas] = $svc->detectar(cg_doce_filas(), $llaves, $politica);

        expect(array_keys($rechazadas))->toEqualCanonicalizing($esperadas)
            ->and(count($aceptadas) + count($rechazadas))->toBe(12);
    }
});

test('los NULL no colisionan y «0012345» es distinto de «12345»', function () {
    [, $rechazadas] = app(DuplicadosService::class)->detectar(cg_doce_filas(), ['primaria' => ['guia'], 'unicas' => [['telefono']]], 'rechazar_todas');

    expect($rechazadas)->not->toHaveKeys([4, 6, 12, 13]); // teléfonos NULL (4, 6) y los dos códigos numéricos
});
