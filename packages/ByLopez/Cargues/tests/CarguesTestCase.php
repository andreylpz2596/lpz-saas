<?php

namespace ByLopez\Cargues\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase as ProyectoTestCase;

/**
 * Base de las pruebas de Cargues. A diferencia de `Tests\TestCase` NO envuelve la prueba en una transaccion: el DDL
 * (intermedias), los procedimientos y los workers de cola son otras conexiones o procesos y necesitan datos CONFIRMADOS.
 * Por eso la proteccion es mas estricta que la del proyecto y se verifica ANTES de cualquier escritura:
 *   - la base es `lpz_saas_testing` en un host local (misma regla del proyecto);
 *   - el servidor es una instancia AISLADA: puerto distinto de 3306 y datadir dentro de un `scratchpad`;
 *   - la base del tipo es `cg_tipo_test` en ese mismo servidor y no existe ninguna base `lpz_saas` ni `bagisto`.
 * Si algo no se cumple, la prueba aborta sin escribir. Nunca corre contra la base de desarrollo ni de produccion.
 */
abstract class CarguesTestCase extends BaseTestCase
{
    public const BASE_TIPO = 'cg_tipo_test';

    protected function setUpTraits()
    {
        $conexion = config('database.connections.'.config('database.default'));
        $host = (string) ($conexion['host'] ?? '');

        ProyectoTestCase::verificarConexionDePruebas((string) ($conexion['database'] ?? ''), $host);
        static::verificarAislamiento();

        return parent::setUpTraits();
    }

    public static function verificarAislamiento(): void
    {
        if (getenv('CARGUES_PRUEBAS_AISLADAS') !== '1') {
            throw new RuntimeException('Ejecución bloqueada: falta CARGUES_PRUEBAS_AISLADAS=1 (use el script de entorno aislado).');
        }

        $r = DB::selectOne('SELECT @@port AS puerto, @@datadir AS dir, DATABASE() AS base');

        if ((int) $r->puerto === 3306 || ! str_contains(strtolower(str_replace('\\', '/', (string) $r->dir)), 'scratchpad')) {
            throw new RuntimeException("Ejecución bloqueada: el servidor no es la instancia aislada (puerto {$r->puerto}, datadir {$r->dir}).");
        }

        $negocio = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name IN ('lpz_saas', 'bagisto')")->n;

        if ((int) $negocio > 0) {
            throw new RuntimeException('Ejecución bloqueada: el servidor contiene una base de negocio.');
        }

        if ($r->base !== 'lpz_saas_testing') {
            throw new RuntimeException('Ejecución bloqueada: la base conectada no es lpz_saas_testing.');
        }
    }
}
