<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Referencia GENERICA del origen de un movimiento que no nace en Corte y Confeccion (p. ej. el reingreso por una
 * devolucion de Logistica): `origen_tipo` + `origen_id` apuntan al registro que lo motivo, sin FK (el libro no conoce a
 * los demas modulos). Aditiva y nullable: los movimientos existentes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_inventario_movimientos', function (Blueprint $table) {
            $table->string('origen_tipo', 50)->nullable()->after('idempotency_key');
            $table->unsignedBigInteger('origen_id')->nullable()->after('origen_tipo');
            $table->index(['origen_tipo', 'origen_id'], 'bylopez_cc_inv_mov_origen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_inventario_movimientos', function (Blueprint $table) {
            $table->dropIndex('bylopez_cc_inv_mov_origen_idx');
            $table->dropColumn(['origen_tipo', 'origen_id']);
        });
    }
};
