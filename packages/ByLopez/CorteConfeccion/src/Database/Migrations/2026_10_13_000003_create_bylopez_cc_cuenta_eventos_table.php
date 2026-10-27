<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 05 — auditoria APPEND-ONLY de la cuenta de un taller o cortador. En la Fase 2 registra cambios de tarifa
 * (tarifa_creada, tarifa_anulada, tarifa_orden_modificada); la Fase 3 sumara pagos, aplicaciones y ajustes.
 *
 * - Sin updated_at: una fila no se modifica; la inmutabilidad la impone el modelo `CuentaEvento`.
 * - Los ids de orden y de administrador van SIN FK: la auditoria debe sobrevivir a su borrado.
 * - `datos` (JSON): valores anterior y nuevo al momento del evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_cuenta_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->nullable()
                ->constrained('bylopez_cc_talleres', 'id', 'bccce_taller_fk')->restrictOnDelete();
            $table->foreignId('cortador_id')->nullable()
                ->constrained('bylopez_cc_cortadores', 'id', 'bccce_cortador_fk')->restrictOnDelete();
            $table->unsignedBigInteger('orden_confeccion_id')->nullable();
            $table->unsignedBigInteger('orden_corte_id')->nullable();
            $table->unsignedBigInteger('tarifa_prestador_id')->nullable();
            $table->string('tipo', 40);
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->json('datos')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['taller_id', 'created_at'], 'bccce_taller_fecha_idx');
            $table->index(['cortador_id', 'created_at'], 'bccce_cortador_fecha_idx');
            $table->index('tipo', 'bccce_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_cuenta_eventos');
    }
};
