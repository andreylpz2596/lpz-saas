<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * C6 — recaudo real → custodia → liquidacion → conciliacion.
 *  1. `origen_tipo = 'cobro'`: el movimiento `recaudo` nace de un COBRO REAL confirmado (origen_id = pago_id), no del valor esperado.
 *     Es la marca estructural que distingue un recaudo C6 de uno legado (`envio`).
 *  2. `bylopez_lg_conciliaciones_movimiento`: como una liquidacion satisfizo un movimiento (efectivo / compensado). Un mismo movimiento
 *     puede conciliarse en VARIAS liquidaciones (parcial → resto despues): la unicidad es POR liquidacion, no global; la suma historica
 *     nunca supera |importe| (lo protege el servicio bajo bloqueo). Inmutable. Aditiva, sin backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bylopez_lg_cuenta_movimientos DROP CONSTRAINT bylcm_origen_chk');
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_origen_chk CHECK (origen_tipo IN ('envio', 'revision', 'ajuste', 'liquidacion', 'reverso', 'cobro'))");

        Schema::create('bylopez_lg_conciliaciones_movimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id');
            $table->foreignId('movimiento_id');
            $table->decimal('efectivo', 14, 2)->default(0);
            $table->decimal('compensado', 14, 2)->default(0);
            $table->string('origen', 10)->default('propuesta');
            $table->string('motivo', 500)->nullable();
            $table->unsignedInteger('actor_admin_id');
            $table->timestamps();

            $table->unique(['liquidacion_id', 'movimiento_id'], 'bylcn_liq_mov_unique');
            $table->index('movimiento_id', 'bylcn_movimiento_idx');

            $table->foreign('liquidacion_id', 'bylcn_liquidacion_fk')->references('id')->on('bylopez_lg_liquidaciones_transportadora')->restrictOnDelete();
            $table->foreign('movimiento_id', 'bylcn_movimiento_fk')->references('id')->on('bylopez_lg_cuenta_movimientos')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_conciliaciones_movimiento ADD CONSTRAINT bylcn_montos_chk CHECK (efectivo >= 0 AND compensado >= 0 AND efectivo + compensado > 0)');
        DB::statement("ALTER TABLE bylopez_lg_conciliaciones_movimiento ADD CONSTRAINT bylcn_origen_chk CHECK (origen IN ('propuesta', 'manual'))");
        DB::statement("ALTER TABLE bylopez_lg_conciliaciones_movimiento ADD CONSTRAINT bylcn_motivo_chk CHECK (origen = 'propuesta' OR (motivo IS NOT NULL AND motivo <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_conciliaciones_movimiento');
        DB::statement('ALTER TABLE bylopez_lg_cuenta_movimientos DROP CONSTRAINT bylcm_origen_chk');
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_origen_chk CHECK (origen_tipo IN ('envio', 'revision', 'ajuste', 'liquidacion', 'reverso'))");
    }
};
