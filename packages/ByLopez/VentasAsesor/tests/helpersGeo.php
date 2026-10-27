<?php

use ByLopez\VentasAsesor\Services\Geo\ImportadorDivipolaService;
use Illuminate\Support\Facades\DB;

/*
 * D-58 — datos minimos CONTROLADOS para las pruebas geograficas. lpz_saas_testing no trae los
 * estados de Colombia ni el catalogo DIVIPOLA, y los tests NO deben depender de que alguien haya
 * ejecutado antes el importador completo: cada test carga estas pocas filas dentro de su
 * transaccion (se revierte sola) usando el MISMO importador de produccion.
 */

if (! function_exists('geo_filas')) {
    /** Filas con el esquema real del DANE: depto, nombre depto, municipio, nombre municipio, tipo, longitud, latitud. */
    function geo_filas(): array
    {
        return [
            ['05', 'ANTIOQUIA', '05001', 'MEDELLÍN', 'Municipio', '-75,581775', '6,246631'],
            ['11', 'BOGOTÁ, D.C.', '11001', 'BOGOTÁ, D.C.', 'Municipio', '-74,106992', '4,649251'],
            ['15', 'BOYACÁ', '15001', 'TUNJA', 'Municipio', '-73,355539', '5,53988'],
            ['15', 'BOYACÁ', '15542', 'PESCA', 'Municipio', '-73,050872', '5,558808'],
            ['15', 'BOYACÁ', '15238', 'DUITAMA', 'Municipio', '-73,0241', '5,8268'],
            ['15', 'BOYACÁ', '15759', 'SOGAMOSO', 'Municipio', '-72,9339', '5,7145'],
            ['25', 'CUNDINAMARCA', '25001', 'AGUA DE DIOS', 'Municipio', '-74,6693', '4,3776'],
            ['25', 'CUNDINAMARCA', '25175', 'CHÍA', 'Municipio', '-74,0586', '4,8587'],
            ['68', 'SANTANDER', '68001', 'BUCARAMANGA', 'Municipio', '-73,132562', '7,11647'],
        ];
    }
}

if (! function_exists('geo_csv')) {
    /** Escribe un CSV con el encabezado real del DANE y devuelve su ruta (temporal). */
    function geo_csv(array $filas, ?array $encabezado = null): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'divipola_').'.csv';
        $h = fopen($ruta, 'w');

        fputcsv($h, $encabezado ?? ['Código Departamento', 'Nombre Departamento', 'Código Municipio', 'Nombre Municipio', 'Tipo: Municipio / Isla / Área no municipalizada', 'longitud', 'Latitud'], ',', '"', '');

        foreach ($filas as $fila) {
            fputcsv($h, $fila, ',', '"', '');
        }

        fclose($h);

        return $ruta;
    }
}

if (! function_exists('geo_estados')) {
    /** Garantiza los country_states de CO que el catalogo de prueba necesita (idempotente). */
    function geo_estados(): void
    {
        $paisId = DB::table('countries')->where('code', 'CO')->value('id');

        foreach (['ANT' => 'Antioquia', 'BOG' => 'Bogotá, D.C.', 'BOY' => 'Boyacá', 'CUN' => 'Cundinamarca', 'SAN' => 'Santander'] as $codigo => $nombre) {
            if (! DB::table('country_states')->where('country_code', 'CO')->where('code', $codigo)->exists()) {
                DB::table('country_states')->insert(['country_id' => $paisId, 'country_code' => 'CO', 'code' => $codigo, 'default_name' => $nombre]);
            }
        }
    }
}

if (! function_exists('geo_cargar')) {
    /** Estados + catalogo de prueba, con el importador real. Devuelve el informe. */
    function geo_cargar(?array $filas = null): array
    {
        geo_estados();

        return app(ImportadorDivipolaService::class)->ejecutar(geo_csv($filas ?? geo_filas()), true, 'test');
    }
}
