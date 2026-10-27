<?php

namespace ByLopez\Cargues\Support;

use Closure;
use Illuminate\Database\QueryException;

/**
 * Error 1615 «Prepared statement needs to be re-prepared»: aparece de forma transitoria cuando un RENAME intercambia la
 * intermedia mientras un lector tiene una sentencia preparada [V, 08 §3.7]. Reintento ACOTADO (≤ 3), solo para lecturas
 * y escrituras idempotentes sobre la intermedia.
 */
final class Reintento
{
    public static function tabla(Closure $accion, int $max = 3): mixed
    {
        $intento = 0;

        while (true) {
            try {
                return $accion();
            } catch (QueryException $e) {
                $codigo = (int) ($e->errorInfo[1] ?? 0);

                if ($codigo !== 1615 || ++$intento >= $max) {
                    throw $e;
                }

                usleep(50_000 * $intento);
            }
        }
    }
}
