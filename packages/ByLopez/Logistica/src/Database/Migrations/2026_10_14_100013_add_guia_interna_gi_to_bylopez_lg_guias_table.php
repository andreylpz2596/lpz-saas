<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GUIA INTERNA GI-000001 (reemplaza la propuesta «BL») — ADITIVA.
 *
 * - `bylopez_lg_consecutivos`: contador global por clave (hoy solo `guia_interna`). Se bloquea con FOR UPDATE dentro de la
 *   transaccion del despacho: nunca MAX+1 sin proteccion. Un rollback devuelve el contador (el numero no se uso).
 * - `bylopez_lg_guias.consecutivo_interno`: parte numerica del GI, UNIQUE (los NULL de las guias externas no chocan). Es la
 *   garantia de unicidad GLOBAL en base de datos (el UNIQUE existente es por transportadora).
 * - Se amplian los CHECK: `origen_guia = 'interna'` y `generada_por = 'sistema'`, y un CHECK de coherencia: una guia es
 *   interna si y solo si tiene consecutivo y la genera el sistema.
 *
 * Sin backfill: las guias manuales anteriores no se renumeran ni se convierten (consecutivo NULL, origen intacto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_consecutivos', function (Blueprint $table) {
            $table->string('clave', 40)->primary();
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->timestamps();
        });

        DB::table('bylopez_lg_consecutivos')->insert(['clave' => 'guia_interna', 'ultimo' => 0, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->unsignedBigInteger('consecutivo_interno')->nullable()->after('numero_guia');
            $table->unique('consecutivo_interno', 'bylgg_consecutivo_unique');
        });

        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_origen_chk');
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_generada_chk');
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_origen_chk CHECK (origen_guia IN ('manual', 'directa_transportadora', '99envios', 'interna'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_generada_chk CHECK (generada_por IN ('operaria', 'api', 'sistema'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_interna_chk CHECK ((origen_guia = 'interna') = (consecutivo_interno IS NOT NULL) AND (origen_guia = 'interna') = (generada_por = 'sistema'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_interna_chk');
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_generada_chk');
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_origen_chk');
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_origen_chk CHECK (origen_guia IN ('manual', 'directa_transportadora', '99envios'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_generada_chk CHECK (generada_por IN ('operaria', 'api'))");

        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->dropUnique('bylgg_consecutivo_unique');
            $table->dropColumn('consecutivo_interno');
        });

        Schema::dropIfExists('bylopez_lg_consecutivos');
    }
};
