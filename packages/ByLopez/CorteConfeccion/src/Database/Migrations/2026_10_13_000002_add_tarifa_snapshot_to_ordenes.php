<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 05 (Fase 2) — tarifa unitaria PACTADA de cada orden (copia, no referencia viva).
 *
 * - Todo nullable y sin backfill: las ordenes existentes quedan «Sin tarifa» y no se convierten en deuda
 *   (podrian estar pagadas fuera del sistema).
 * - `tarifa_unitaria` es el valor con el que se calcula el importe. `tarifa_prestador_id` solo indica de que
 *   tarifa de la ficha se copio (trazabilidad). `tarifa_motivo` solo se llena si la tarifa se pacto o cambio
 *   para esa orden.
 */
return new class extends Migration
{
    private const COLUMNAS = ['tarifa_unitaria', 'tarifa_prestador_id', 'tarifa_motivo', 'tarifa_fijada_en', 'tarifa_fijada_por_admin_id'];

    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $this->columnas($table, 'bccoc_tarifa_fk');
        });

        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $this->columnas($table, 'bccocr_tarifa_fk');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->dropForeign('bccoc_tarifa_fk');
            $table->dropColumn(self::COLUMNAS);
        });

        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->dropForeign('bccocr_tarifa_fk');
            $table->dropColumn(self::COLUMNAS);
        });
    }

    private function columnas(Blueprint $table, string $fkName): void
    {
        $table->decimal('tarifa_unitaria', 12, 4)->nullable();
        $table->unsignedBigInteger('tarifa_prestador_id')->nullable();
        $table->string('tarifa_motivo', 500)->nullable();
        $table->timestamp('tarifa_fijada_en')->nullable();
        $table->unsignedInteger('tarifa_fijada_por_admin_id')->nullable();

        $table->foreign('tarifa_prestador_id', $fkName)->references('id')->on('bylopez_cc_tarifas_prestador')->restrictOnDelete();
    }
};
