<?php

namespace ByLopez\Logistica\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Zonas iniciales de domicilio local (F1): Bogota (DIVIPOLA 11001) y Soacha (25754). Idempotente: no duplica ni pisa
 * una zona existente. Las tarifas NO se siembran aqui: dependen de las transportadoras de cada entorno y se crean en
 * Logistica › Transportadoras.
 */
class ZonasDomicilioSeeder extends Seeder
{
    public const ZONAS = [
        ['codigo' => 'BOGOTA', 'nombre' => 'Bogotá', 'municipios' => ['11001']],
        ['codigo' => 'SOACHA', 'nombre' => 'Soacha', 'municipios' => ['25754']],
    ];

    public function run(): void
    {
        $ahora = now();

        foreach (self::ZONAS as $z) {
            DB::table('bylopez_lg_zonas')->insertOrIgnore(['codigo' => $z['codigo'], 'nombre' => $z['nombre'], 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
            $id = (int) DB::table('bylopez_lg_zonas')->where('codigo', $z['codigo'])->value('id');

            foreach ($z['municipios'] as $municipio) {
                DB::table('bylopez_lg_zona_municipios')->insertOrIgnore(['zona_id' => $id, 'municipio_codigo' => $municipio, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
    }
}
