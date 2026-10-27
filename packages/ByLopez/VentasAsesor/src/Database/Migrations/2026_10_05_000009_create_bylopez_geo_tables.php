<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * D-58 (aprobada por Camilo Gaitan) — novena migracion del modulo: catalogo geografico local de
 * Colombia (DANE / DIVIPOLA). ADITIVA: tablas propias, sin tocar tablas del core.
 *
 * Los codigos DIVIPOLA son identificadores administrativos, no numeros: CHAR para conservar el
 * cero a la izquierda (departamento 2, municipio 5).
 *
 * - `departamento.country_state_id` es LA unica equivalencia DIVIPOLA → `country_states` de
 *   Bagisto (UNIQUE: un estado de Bagisto no sirve a dos departamentos DANE). La resuelve y valida
 *   el importador; en la venta nunca se comparan nombres.
 * - `municipio.activo`: un municipio que desaparece de la fuente se inactiva, no se borra (las
 *   direcciones historicas conservan su referencia). RESTRICT en las claves foraneas.
 * - `nombre_busqueda`: nombre en minusculas, sin tildes ni signos, para busquedas rapidas por
 *   inicio de palabra; el nombre de presentacion es `nombre_oficial`.
 * - `fuente`, `version_fuente` y `checksum_fuente` (sha256 del archivo importado) bastan para saber
 *   de que dataset salio cada fila. Sin coordenadas: la fuente las trae, pero hoy nada las usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_geo_departamentos', function (Blueprint $table) {
            $table->char('codigo_divipola', 2)->primary();
            $table->string('nombre_oficial', 120);
            $table->string('nombre_busqueda', 120);
            $table->unsignedInteger('country_state_id');
            $table->timestamps();

            $table->unique('country_state_id', 'bvagd_state_unique');
            $table->foreign('country_state_id', 'bvagd_state_fk')->references('id')->on('country_states')->restrictOnDelete();
        });

        Schema::create('bylopez_geo_municipios', function (Blueprint $table) {
            $table->char('codigo_divipola', 5)->primary();
            $table->string('nombre_oficial', 150);
            $table->string('nombre_busqueda', 150);
            $table->char('departamento_codigo', 2);
            $table->string('tipo', 60);
            $table->boolean('activo')->default(true);
            $table->string('fuente', 80);
            $table->string('version_fuente', 40)->nullable();
            $table->char('checksum_fuente', 64);
            $table->timestamps();

            $table->index('nombre_busqueda', 'bvagm_busqueda_idx');
            $table->index(['activo', 'nombre_busqueda'], 'bvagm_activo_busqueda_idx');
            $table->foreign('departamento_codigo', 'bvagm_departamento_fk')->references('codigo_divipola')->on('bylopez_geo_departamentos')->restrictOnDelete();
        });
    }

    /** Solo las dos tablas de esta migracion (los datos geograficos se reimportan desde la fuente). */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_geo_municipios');
        Schema::dropIfExists('bylopez_geo_departamentos');
    }
};
