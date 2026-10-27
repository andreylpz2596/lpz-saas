<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PAG-B (D-59 / Doc. 05 §42.2) — decimotercera migracion del modulo, ADITIVA: auditoria APPEND-ONLY del ciclo
 * de vida de un pago (registrado, confirmado, rechazado, reversado y, mas adelante, evidencia_agregada y
 * posible_duplicado: ver `Enums\TipoEventoPago`).
 *
 * - Sin updated_at: una fila no se modifica. La inmutabilidad la impone el MODELO (`PagoEvento`: rechaza
 *   update, delete y save sobre filas existentes). Alcance honesto: son eventos de Eloquent; una consulta
 *   masiva o SQL directo los salta, igual que el historial de atribucion. Regla del modulo: solo se escribe
 *   por el servicio, nunca por consultas masivas.
 * - `actor_admin_id` nullable y SIN FK: una automatizacion futura puede no tener administrador, y si el
 *   administrador se elimina la auditoria debe sobrevivir.
 * - `pago_id` con FK RESTRICT: no se puede borrar un pago con historia.
 * - `datos` (JSON): instantanea de la distribucion y los montos al momento del evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_pagos_eventos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pago_id')
                ->constrained('bylopez_va_pagos', 'id', 'bvape_pago_fk')
                ->restrictOnDelete();

            $table->string('tipo', 30);
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('mecanismo', 30);
            $table->string('motivo', 500)->nullable();
            $table->json('datos')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['pago_id', 'created_at'], 'bvape_pago_fecha_idx');
            $table->index('tipo', 'bvape_tipo_idx');
            $table->index('actor_admin_id', 'bvape_actor_idx');
        });
    }

    /** Solo esta tabla. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_pagos_eventos');
    }
};
