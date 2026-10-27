<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LOG-D-04 — catalogo estructurado de transportadoras (primera migracion de ByLopez\Logistica, ADITIVA).
 *
 * - `codigo` es el valor ESTABLE que se guarda en `shipments.carrier_code` (columna nativa de Bagisto que hasta hoy
 *   nadie llenaba) y en los snapshots de entrega/retorno. Nunca se edita; `nombre` es solo visual.
 * - Sin datos personales: un domiciliario usa el codigo generico DOMICILIARIO (no hay modulo de personas).
 * - Valores iniciales = los conceptos aprobados por Camilo (2026-10-08), tras auditar los `carrier_title` reales:
 *   BYLOPEZ, BRAYAN, INGRI, PICAP, INTERRAPIDISMO, RECOGE EN BODEGA, OPPS, RECOLECCION INGRID y vacio. Los
 *   historicos NO se convierten (LOG-D-04 / bloque 10): siguen con `carrier_code` NULL y su texto libre.
 * - No se borran filas: se desactivan (`activo`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_transportadoras', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40);
            $table->string('nombre', 120);
            $table->string('tipo', 20);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique('codigo', 'bylgt_codigo_unique');
        });

        $ahora = now();

        DB::table('bylopez_lg_transportadoras')->insertOrIgnore(array_map(fn (array $t) => $t + [
            'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
        ], [
            ['codigo' => 'BYLOPEZ', 'nombre' => 'ByLopez', 'tipo' => 'propia'],
            ['codigo' => '99ENVIOS', 'nombre' => '99envíos', 'tipo' => 'transportadora'],
            ['codigo' => 'INTER_RAPIDISIMO', 'nombre' => 'Inter Rapidísimo', 'tipo' => 'transportadora'],
            ['codigo' => 'DOMICILIARIO', 'nombre' => 'Domiciliario', 'tipo' => 'domiciliario'],
            ['codigo' => 'RECOGIDA_BODEGA', 'nombre' => 'Recogida en bodega', 'tipo' => 'recogida'],
            ['codigo' => 'OTRA', 'nombre' => 'Otra', 'tipo' => 'otra'],
        ]));
    }

    /** Solo si ninguna entrega/retorno la usa (los snapshots guardan el codigo como texto, sin FK). */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_transportadoras');
    }
};
