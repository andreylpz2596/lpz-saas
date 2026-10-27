<?php

namespace ByLopez\Cargues\Services\Lectura;

/**
 * Una fila del archivo, SIN normalizar. `valores` es una lista por posicion de columna (0 = A):
 *  - `null`        celda AUSENTE (no existe en el archivo; en CSV, campo que falta al final de un registro corto)
 *  - `""`          texto vacio (la celda existe y esta vacia)
 *  - `"  "`        espacios: se conservan tal cual
 *  - string / int / float / bool   valor tal como lo guarda el archivo (los numeros no se convierten en texto)
 *  - array         celda especial: fecha numerica {n,f,d}, formula {f,c} o error {e}
 * La lista termina en la ultima celda presente: lo que falta al final es ausente.
 */
final class FilaLeida
{
    public function __construct(public readonly int $numero, public readonly array $valores) {}

    /** Fila sin ninguna celda con contenido (solo ausentes, vacios o espacios). */
    public function estaVacia(): bool
    {
        foreach ($this->valores as $v) {
            if ($v !== null && (! is_string($v) || trim($v) !== '')) {
                return false;
            }
        }

        return true;
    }
}
