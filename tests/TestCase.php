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

    public static function verificarConexionDePruebas(string $base, string $host): void
    {
        if ($base !== self::BASE_DE_PRUEBAS) {
            throw new RuntimeException('Ejecución bloqueada: las pruebas no están conectadas a lpz_saas_testing.');
        }

        if (! in_array($host, self::HOSTS_DE_PRUEBAS, true)) {
            throw new RuntimeException('Ejecución bloqueada: la base de pruebas no está en un host local autorizado.');
        }
    }
}
