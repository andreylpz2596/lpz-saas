<?php

use ByLopez\Cargues\Services\Configuracion\MapValidator;
use ByLopez\Cargues\Tests\CarguesTestCase;

require_once __DIR__.'/../helpers.php';

uses(CarguesTestCase::class);

function cg_codigos(array $map, ?string $modo = 'procedimiento'): array
{
    return array_values(array_unique(array_column(app(MapValidator::class)->validar($map, $modo), 'codigo')));
}

test('el map estándar es válido', function () {
    expect(app(MapValidator::class)->validar(cg_mapa()))->toBe([]);
});

test('F1-C2 se rechazan nombres de campo inválidos, reservados o con el prefijo del motor', function () {
    foreach (['Guia X', '1guia', 'guía', str_repeat('a', 52)] as $malo) {
        $m = cg_mapa();
        $m['campos'][0]['nombre'] = $malo;
        $m['llaves'] = ['primaria' => null, 'unicas' => [], 'indices' => []];
        expect(cg_codigos($m))->toContain('CAMPO_NOMBRE_INVALIDO');
    }

    foreach (['select', 'order', 'cg_estado', 'cg_x'] as $reservado) {
        $m = cg_mapa();
        $m['campos'][2]['nombre'] = $reservado;
        $m['llaves'] = ['primaria' => null, 'unicas' => [], 'indices' => []];
        expect(cg_codigos($m))->toContain('CAMPO_RESERVADO');
    }
});

test('F1-C2 la tabla debe tener el prefijo obligatorio', function () {
    foreach (['envios', 'bylopez_cg_tipos', 'bylopez_cgi_', 'bylopez_cgi_MAYUS', 'bylopez_cgi_'.str_repeat('a', 41)] as $malo) {
        expect(cg_codigos(cg_mapa(['tabla' => $malo])))->toContain('MAP_TABLA_INVALIDA');
    }
});

test('F1-C2 una llave primaria sobre un campo nulable se rechaza', function () {
    $m = cg_mapa();
    $m['llaves']['primaria'] = ['telefono']; // nulo: true

    expect(cg_codigos($m))->toContain('LLAVE_PRIMARIA_NULABLE');
});

test('F1-C2 una llave única no admite texto (la BD compararía sin distinguir mayúsculas ni acentos)', function () {
    $m = cg_mapa();
    $m['llaves']['unicas'] = [['estado']]; // texto

    expect(cg_codigos($m))->toContain('LLAVE_TIPO_NO_PERMITIDO');
});

test('F1-C2 un índice que supera la longitud de clave se rechaza, aunque MariaDB 10.4 lo aceptaría como HASH', function () {
    $campos = array_map(fn ($i) => ['nombre' => "c{$i}", 'tipo' => 'identificador', 'longitud' => 191, 'nulo' => false], range(1, 5));
    $m = cg_mapa(['campos' => $campos, 'llaves' => ['primaria' => null, 'unicas' => [], 'indices' => []]]);
    $m['campos'] = $campos;
    $m['llaves'] = ['primaria' => null, 'unicas' => [['c1', 'c2', 'c3', 'c4', 'c5']], 'indices' => []];

    // 8 + 5 × 764 = 3 828 bytes > 3 072
    expect(cg_codigos($m))->toContain('INDICE_SUPERA_LIMITE');

    $m['llaves']['unicas'] = [['c1', 'c2', 'c3', 'c4']]; // 8 + 4 × 764 = 3 064 bytes: cabe
    expect(cg_codigos($m))->not->toContain('INDICE_SUPERA_LIMITE');
});

test('la fila de la intermedia no puede superar el límite de InnoDB', function () {
    $campos = array_map(fn ($i) => ['nombre' => "t{$i}", 'tipo' => 'texto', 'longitud' => 1000, 'nulo' => true], range(1, 17));
    $m = cg_mapa();
    $m['campos'] = $campos;
    $m['llaves'] = ['primaria' => null, 'unicas' => [], 'indices' => []];

    // 17 × (4 × 1000 + 2) = 68 034 bytes + columnas de sistema > 65 535
    expect(cg_codigos($m))->toContain('FILA_SUPERA_LIMITE');
});

test('no se admiten claves desconocidas: ni defaults, ni columnas generadas, ni CHECK, ni SQL', function () {
    $m = cg_mapa();
    $m['campos'][0]['default'] = 'X';
    $m['campos'][1]['generated'] = 'CONCAT(a,b)';
    $m['sql'] = 'DROP TABLE x';

    $c = cg_codigos($m);

    expect($c)->toContain('CAMPO_CLAVE_DESCONOCIDA')->toContain('MAP_CLAVE_DESCONOCIDA');
});

test('tipo de campo fuera de la lista cerrada, longitudes y decimales fuera de rango', function () {
    $m = cg_mapa();
    $m['campos'][0]['tipo'] = 'varchar(20); DROP TABLE x';
    expect(cg_codigos($m))->toContain('CAMPO_TIPO_INVALIDO');

    $m = cg_mapa();
    $m['campos'][2]['longitud'] = 1001;
    expect(cg_codigos($m))->toContain('CAMPO_LONGITUD');

    $m = cg_mapa();
    $m['campos'][3]['escala'] = 11;
    expect(cg_codigos($m))->toContain('DECIMAL_RANGO');
});

test('un campo obligatorio solo admite la regla de vacío «rechazar», y «default» no existe', function () {
    $m = cg_mapa();
    $m['campos'][2]['vacio'] = 'nulo'; // estado: nulo:false
    expect(cg_codigos($m))->toContain('VACIO_INVALIDO');

    $m = cg_mapa();
    $m['campos'][3]['obligatorio'] = true;
    $m['campos'][3]['vacio'] = 'ignorar';
    expect(cg_codigos($m))->toContain('OBLIGATORIO_VACIO_REGLA');

    $m = cg_mapa();
    $m['campos'][3]['vacio'] = 'default';
    expect(cg_codigos($m))->toContain('VACIO_INVALIDO');
});

test('nulo:false con obligatorio por acciones es contradictorio', function () {
    $m = cg_mapa();
    $m['campos'][2]['obligatorio'] = ['actualizar_estado'];

    expect(cg_codigos($m))->toContain('OBLIGATORIO_CONTRADICTORIO');
});

test('el modelo transaccional, el modo de duplicados y las tablas de negocio son obligatorios y cerrados', function () {
    $m = cg_mapa();
    unset($m['transaccion'], $m['tablas_negocio']);
    $m['duplicados'] = 'borrar';

    expect(cg_codigos($m))->toContain('TRANSACCION_INVALIDA')->toContain('TABLAS_NEGOCIO_NO_DECLARADAS')->toContain('DUPLICADOS_INVALIDO');
});

test('el modo tabla no está disponible en la Fase 1', function () {
    expect(cg_codigos(cg_mapa(), 'tabla'))->toBe(['MODO_NO_DISPONIBLE']);
});

test('normalizar aplica los valores por defecto sin alterar la estructura', function () {
    $m = app(MapValidator::class)->normalizar(cg_mapa(['campos' => [3 => ['vacio' => null]]]));

    expect($m['duplicados'])->toBe('rechazar_todas')
        ->and($m['campos'][0]['vacio'])->toBe('rechazar')   // obligatorio
        ->and($m['llaves']['primaria'])->toBe(['guia']);
});
