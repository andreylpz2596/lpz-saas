<?php

namespace ByLopez\Cargues\Contracts;

use ByLopez\Cargues\Models\Carga;

/**
 * Complemento OPCIONAL del paso «Resultado» para un tipo concreto (se registra por la tabla intermedia en
 * `cargues.resultado.complementos`). El motor sigue siendo generico: sin complemento, el resultado muestra la fila, su
 * estado, los valores leidos y las incidencias. Un complemento SOLO LEE (nunca escribe) lo que el procedimiento dejo
 * persistido, y devuelve por fila de origen una lista de pares etiqueta/valor ya redactados.
 */
interface ComplementaResultado
{
    /**
     * @param  array<int, array{estado:string, valores:array<string,mixed>}>  $filas  fila_origen => datos de la fila
     * @return array<int, list<array{etiqueta:string, valor:string}>>  fila_origen => pares
     */
    public function complementar(Carga $carga, array $filas): array;
}
