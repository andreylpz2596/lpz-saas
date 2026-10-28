<?php

use ByLopez\Logistica\Support\ConflictoDeConcurrencia;
use ByLopez\VentasAsesor\Support\Cartera\RespuestaErrorNoControlado;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Tests\TestCase;

uses(TestCase::class);

/*
| Un bloqueo agotado (1205) o un deadlock (1213) es un conflicto de concurrencia REINTENTABLE: el panel responde 409 «vuelve a intentarlo», no
| 500. Laravel 12 los relanza como DeadlockException (hereda de PDOException) DENTRO de una transaccion y como QueryException fuera; las dos
| formas, directas o como causa de otra excepcion, deben clasificarse igual. Sin base de datos: solo construye excepciones.
*/

function ftc_query(int $codigo): QueryException
{
    $pdo = new PDOException('SQLSTATE[HY000]: General error: '.$codigo);
    $pdo->errorInfo = ['HY000', $codigo, 'x'];

    return new QueryException('mysql', 'select 1', [], $pdo);
}

test('FTC-1 DeadlockException, QueryException 1205/1213 y la causa encadenada son conflicto de concurrencia; el resto, no', function () {
    expect(ConflictoDeConcurrencia::es(new DeadlockException('Lock wait timeout exceeded', 1205)))->toBeTrue()
        ->and(ConflictoDeConcurrencia::es(ftc_query(1205)))->toBeTrue()
        ->and(ConflictoDeConcurrencia::es(ftc_query(1213)))->toBeTrue()
        ->and(ConflictoDeConcurrencia::es(new RuntimeException('envuelta', 0, new DeadlockException('x', 1213))))->toBeTrue()
        ->and(ConflictoDeConcurrencia::es(ftc_query(1062)))->toBeFalse()
        ->and(ConflictoDeConcurrencia::es(new RuntimeException('otra cosa')))->toBeFalse();
});

test('FTC-2 la respuesta de Cartera para un bloqueo agotado es 409 «operacion_concurrente» con referencia, sin traza; otro error es 500', function () {
    $conflicto = RespuestaErrorNoControlado::json(new DeadlockException('Lock wait timeout exceeded', 1205));
    $otro = RespuestaErrorNoControlado::json(new RuntimeException('falla cualquiera'));

    expect($conflicto->getStatusCode())->toBe(409)
        ->and($conflicto->getData(true)['codigo'])->toBe('operacion_concurrente')
        ->and($conflicto->getData(true)['referencia'])->not->toBeEmpty()
        ->and(json_encode($conflicto->getData(true)))->not->toContain('Lock wait')
        ->and($otro->getStatusCode())->toBe(500)
        ->and($otro->getData(true)['codigo'])->toBe('error_inesperado');
});
