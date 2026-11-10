<?php

/*
|--------------------------------------------------------------------------
| Uso exclusivo de lpz_saas_testing para las pruebas que CONFIRMAN datos (RET-16 y tests/Concurrencia).
| Esas pruebas limpian borrando lo que nacio despues de su foto de ids ("id > maximo previo"); si otra sesion
| escribe a la vez en la misma base, esa limpieza borraria filas ajenas y las esperas de bloqueo la harian
| fallar. Por eso solo corren cuando NINGUNA otra conexion usa la base; si no, se omiten diciendo por que.
| Las pruebas transaccionales (DatabaseTransactions) no lo necesitan: nunca confirman ni dejan residuo.
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\DB;

if (! function_exists('qa_motivo_base_compartida')) {
    /**
     * Null si esta conexion es la unica sobre la base actual; si no, el motivo para omitir la prueba. Espera hasta
     * $esperaSegundos a que se cierren conexiones que terminan (procesos hijos de la prueba anterior).
     */
    function qa_motivo_base_compartida(float $esperaSegundos = 5): ?string
    {
        $limite = microtime(true) + $esperaSegundos;

        do {
            $otras = DB::select(
                'SELECT ID AS id, USER AS usuario, COMMAND AS comando, TIME AS segundos FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID()'
            );

            if ($otras === []) {
                return null;
            }

            usleep(250_000);
        } while (microtime(true) < $limite);

        $detalle = implode(', ', array_map(fn ($c) => "#{$c->id} {$c->comando} {$c->segundos}s", $otras));

        return 'Base de pruebas compartida en este momento ('.count($otras).' conexiones ajenas: '.$detalle.'). '
            .'Esta prueba confirma datos y su limpieza podria borrar filas de otra sesion: ejecutela cuando nadie mas use la base.';
    }
}
