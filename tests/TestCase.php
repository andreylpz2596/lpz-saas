<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Unica base de datos en la que pueden ejecutarse las pruebas.
     */
    public const BASE_DE_PRUEBAS = 'lpz_saas_testing';

    /**
     * Hosts locales autorizados para la base de pruebas.
     */
    public const HOSTS_DE_PRUEBAS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * Verifica la conexion antes de que DatabaseTransactions abra su transaccion y
     * antes de que la prueba pueda escribir: primero la configuracion, sin consultar
     * la base, y despues el nombre real de la base conectada.
     */
    protected function setUpTraits()
    {
        $conexion = config('database.connections.'.config('database.default'));

        $host = (string) ($conexion['host'] ?? '');

        static::verificarConexionDePruebas((string) ($conexion['database'] ?? ''), $host);

        static::verificarConexionDePruebas(
            (string) $this->app['db']->connection()->selectOne('SELECT DATABASE() AS base')->base,
            $host,
        );

        return parent::setUpTraits();
    }

    /**
     * Algunos controladores y servicios llaman a set_time_limit() (lectura de comprobantes, confirmación de venta).
     * En la web el límite muere con la petición; en Pest seguiría vivo para el resto del proceso y cortaría la suite
     * (FatalException «Maximum execution time») en una prueba ajena. Se vuelve al valor de la CLI (sin límite).
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        @set_time_limit(0);
    }

    /**
     * Una rama de trabajo puede tener su PROPIA base de pruebas, para no compartir escritura con otros hilos. Solo vale si
     * se declara explicitamente (BASE_DE_PRUEBAS_EXCLUSIVA en .env.testing, que no se versiona) y su nombre empieza por
     * lpz_cf_: nunca habilita lpz_saas, preproduccion ni ninguna otra.
     */
    protected static function esBaseExclusivaDeRama(string $base): bool
    {
        $declarada = (string) ($_ENV['BASE_DE_PRUEBAS_EXCLUSIVA'] ?? getenv('BASE_DE_PRUEBAS_EXCLUSIVA') ?: '');

        return $declarada !== '' && $declarada === $base && preg_match('/^lpz_cf_[a-z0-9_]+$/', $base) === 1;
    }

    public static function verificarConexionDePruebas(string $base, string $host): void
    {
        if ($base !== self::BASE_DE_PRUEBAS && ! self::esBaseExclusivaDeRama($base)) {
            throw new RuntimeException('Ejecución bloqueada: las pruebas no están conectadas a lpz_saas_testing.');
        }

        if (! in_array($host, self::HOSTS_DE_PRUEBAS, true)) {
            throw new RuntimeException('Ejecución bloqueada: la base de pruebas no está en un host local autorizado.');
        }
    }
}
