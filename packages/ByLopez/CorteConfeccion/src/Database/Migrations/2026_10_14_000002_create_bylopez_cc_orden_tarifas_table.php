<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 05 (ajuste de Fase 2) — tarifa unitaria PACTADA por combinacion tipo de tela + referencia dentro de UNA orden.
 *
 * Una orden puede ser mixta (varias referencias): cada combinacion presente en la orden conserva su propia tarifa,
 * copiada de la ficha del prestador cuando la combinacion aparecio en la orden. Cambiar o anular una tarifa de la
 * ficha NO modifica estas filas.
 *
 * - Una fila es de una orden de confeccion O de una orden de corte (CHECK).
 * - `tarifa_unitaria` NULL = «Sin tarifa» (no existia tarifa vigente para esa combinacion al aparecer en la orden).
 *   Nunca se asume 0 ni se aplica una tarifa general.
 * - `tarifa_prestador_id`: fila de la ficha de la que se copio (trazabilidad). NULL si fue pactada a mano.
 * - `motivo`: solo si la tarifa se pacto o cambio para esa orden.
 * - Las columnas `tarifa_*` de `ordenes_confeccion` / `ordenes_corte` (primer intento de Fase 2) quedan sin uso y sin
 *   borrarse; ninguna orden las tenia pobladas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_orden_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_confeccion_id')->nullable()
                ->constrained('bylopez_cc_ordenes_confeccion', 'id', 'bccot_confeccion_fk')->cascadeOnDelete();
            $table->foreignId('orden_corte_id')->nullable()
                ->constrained('bylopez_cc_ordenes_corte', 'id', 'bccot_corte_fk')->cascadeOnDelete();
            $table->unsignedInteger('tipo_tela_id');
            $table->unsignedInteger('attribute_family_id');
            $table->decimal('tarifa_unitaria', 12, 4)->nullable();
            $table->unsignedBigInteger('tarifa_prestador_id')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->unsignedInteger('fijada_por_admin_id')->nullable();
            $table->timestamp('fijada_en')->nullable();
            $table->timestamps();

            $table->foreign('tipo_tela_id', 'bccot_tipo_tela_fk')->references('id')->on('attribute_options')->restrictOnDelete();
            $table->foreign('attribute_family_id', 'bccot_familia_fk')->references('id')->on('attribute_families')->restrictOnDelete();
            $table->foreign('tarifa_prestador_id', 'bccot_tarifa_fk')->references('id')->on('bylopez_cc_tarifas_prestador')->restrictOnDelete();

            $table->unique(['orden_confeccion_id', 'tipo_tela_id', 'attribute_family_id'], 'bccot_confeccion_combo_unique');
            $table->unique(['orden_corte_id', 'tipo_tela_id', 'attribute_family_id'], 'bccot_corte_combo_unique');
        });

        DB::statement('ALTER TABLE bylopez_cc_orden_tarifas ADD CONSTRAINT bccot_una_orden CHECK ((orden_confeccion_id IS NULL) <> (orden_corte_id IS NULL))');
        DB::statement('ALTER TABLE bylopez_cc_orden_tarifas ADD CONSTRAINT bccot_tarifa_positiva CHECK (tarifa_unitaria IS NULL OR tarifa_unitaria > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_orden_tarifas');
    }
};
