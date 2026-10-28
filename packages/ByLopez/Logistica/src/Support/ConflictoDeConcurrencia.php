<?php

namespace ByLopez\Logistica\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * ¿La excepcion es un CONFLICTO DE CONCURRENCIA reintentable (bloqueo agotado 1205 o deadlock 1213)?
 *
 * Laravel 12 relanza esos errores como `DeadlockException` (hereda de PDOException, NO de QueryException) cuando ocurren DENTRO de una
 * transaccion, y como `QueryException` cuando ocurren fuera. Revisar solo QueryException (como se hacia) clasificaba el caso comun
 * —el de toda operacion de dinero o de despacho, que corre en transaccion— como «error inesperado» (500) en lugar de «vuelve a
 * intentarlo» (409). Se recorre la cadena de excepciones para cubrir ambas formas.
 */
final class ConflictoDeConcurrencia
{
    /** @var list<int> */
    private const CODIGOS = [1205, 1213];

    public static function es(Throwable $e): bool
    {
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            if ($actual instanceof DeadlockException) {
                return true;
            }

            if ($actual instanceof QueryException && in_array((int) ($actual->errorInfo[1] ?? 0), self::CODIGOS, true)) {
                return true;
            }
        }

        return false;
    }
}
