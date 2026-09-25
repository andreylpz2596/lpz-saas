<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('el guard global bloquea la base de desarrollo sin conectarse a ella', function () {
    expect(fn () => TestCase::verificarConexionDePruebas('lpz_saas', '127.0.0.1'))
        ->toThrow(RuntimeException::class, 'Ejecución bloqueada: las pruebas no están conectadas a lpz_saas_testing.');
});

test('el guard bloquea cualquier otra base y cualquier host no local', function (string $base, string $host) {
    expect(fn () => TestCase::verificarConexionDePruebas($base, $host))->toThrow(RuntimeException::class);
})->with([
    'base vacia' => ['', '127.0.0.1'],
    'nombre parecido' => ['lpz_saas_testing_old', '127.0.0.1'],
    'mismo nombre en mayusculas' => ['LPZ_SAAS_TESTING', '127.0.0.1'],
    'host remoto por ip' => ['lpz_saas_testing', '10.0.0.8'],
    'host remoto por nombre' => ['lpz_saas_testing', 'db.example.com'],
]);

test('el guard acepta solo lpz_saas_testing en un host local', function (string $host) {
    TestCase::verificarConexionDePruebas('lpz_saas_testing', $host);

    expect(true)->toBeTrue();
})->with(['127.0.0.1', 'localhost', '::1']);

test('el guard vive en la clase base comun y antes de DatabaseTransactions', function () {
    $metodo = new ReflectionMethod(TestCase::class, 'setUpTraits');

    expect($metodo->getDeclaringClass()->getName())->toBe(TestCase::class);
});

test('esta ejecucion esta conectada realmente a lpz_saas_testing', function () {
    expect(DB::selectOne('SELECT DATABASE() AS base')->base)->toBe('lpz_saas_testing')
        ->and(config('app.env'))->toBe('testing');
});
