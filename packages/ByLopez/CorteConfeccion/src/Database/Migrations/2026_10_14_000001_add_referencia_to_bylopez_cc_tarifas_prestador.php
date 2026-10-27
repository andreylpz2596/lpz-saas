<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 05 (ajuste de Fase 2) — la tarifa deja de ser «general por prestador» y pasa a ser por combinacion
 * prestador + tipo de tela + referencia del producto.
 *
 * - `tipo_tela_id`: opcion del atributo Bagisto `tipo_tela` (la misma de Rollo y ProveedorTelaReferencia).
 * - `attribute_family_id`: familia Bagisto = «referencia del producto» (la «linea de corte» del Doc. Orden de Corte V2).
 * - ADITIVA y sin borrar nada: las filas anteriores (tarifa general) conservan ambas columnas en NULL, quedan como
 *   historial «legacy» y NO se aplican a ninguna orden ni se distribuyen entre referencias. Cada referencia debe
 *   configurarse de forma explicita desde la ficha.
 * - CHECK: tipo_tela_id y attribute_family_id van juntos (ambos NULL = legacy; ambos con valor = tarifa vigente del modelo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_tarifas_prestador', function (Blueprint $table) {
            $table->unsignedInteger('tipo_tela_id')->nullable()->after('cortador_id');
            $table->unsignedInteger('attribute_family_id')->nullable()->after('tipo_tela_id');

            $table->foreign('tipo_tela_id', 'bcctp_tipo_tela_fk')->references('id')->on('attribute_options')->restrictOnDelete();
            $table->foreign('attribute_family_id', 'bcctp_familia_fk')->references('id')->on('attribute_families')->restrictOnDelete();

            $table->index(['taller_id', 'tipo_tela_id', 'attribute_family_id', 'vigente_desde'], 'bcctp_taller_combo_idx');
            $table->index(['cortador_id', 'tipo_tela_id', 'attribute_family_id', 'vigente_desde'], 'bcctp_cortador_combo_idx');
        });

        DB::statement('ALTER TABLE bylopez_cc_tarifas_prestador ADD CONSTRAINT bcctp_combo_completa CHECK ((tipo_tela_id IS NULL) = (attribute_family_id IS NULL))');
    }

    public function down(): void
    {
        // Falla (FK RESTRICT) si ya hay lineas de orden que referencian tarifas por referencia.
        DB::statement('ALTER TABLE bylopez_cc_tarifas_prestador DROP CONSTRAINT bcctp_combo_completa');

        Schema::table('bylopez_cc_tarifas_prestador', function (Blueprint $table) {
            $table->dropIndex('bcctp_taller_combo_idx');
            $table->dropIndex('bcctp_cortador_combo_idx');
            $table->dropForeign('bcctp_tipo_tela_fk');
            $table->dropForeign('bcctp_familia_fk');
            $table->dropColumn(['tipo_tela_id', 'attribute_family_id']);
        });
    }
};
