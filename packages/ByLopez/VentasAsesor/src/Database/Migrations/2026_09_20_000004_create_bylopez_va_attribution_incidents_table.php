<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 02B1A — cuarta migracion del modulo, ADITIVA. Las tres anteriores no se
 * tocan.
 *
 * Cola OPERATIVA de incidencias de atribucion. Es MUTABLE a proposito y no debe
 * confundirse con `bylopez_va_advisor_assignment_logs`, que es el historial
 * inmutable de propiedad comercial: aqui una fila se reabre, se cuenta y se
 * resuelve; alli nada se modifica jamas.
 *
 * Que NO se guarda, por diseño [D-§16.4]:
 *   - valores recibidos o esperados de los campos en conflicto;
 *   - contexto comercial serializado, request o payload;
 *   - datos del cliente, telefono, direccion o tokens;
 *   - mensajes de excepcion, trazas ni notas libres.
 *
 * `campos_conflicto` guarda UNICAMENTE nombres de campo de una lista permitida.
 * Un nombre de campo dice que revisar; su valor seria una fuga de datos en una
 * tabla que nadie vuelve a depurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_attribution_incidents', function (Blueprint $table) {
            $table->id();

            // `orders.id` es increments() (int unsigned), no bigint.
            $table->unsignedInteger('order_id');

            // Puede no existir todavia: el conflicto se detecta antes de tenerla.
            $table->unsignedBigInteger('order_attribution_id')->nullable();

            $table->string('codigo', 60);

            $table->json('campos_conflicto')->nullable();

            $table->string('estado', 20)->default('open');

            // Cuantas veces se detecto la misma incidencia. Nunca menor que 1.
            $table->unsignedInteger('attempt_count')->default(1);

            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();

            $table->timestamp('resolved_at')->nullable();

            // Sin clave foranea + snapshot, igual que el historial: dar de baja a un
            // administrador no puede borrar quien resolvio la incidencia [M-§11.2].
            $table->unsignedInteger('resolved_by_admin_id')->nullable();
            $table->string('resolved_by_name_snapshot')->nullable();

            $table->timestamps();

            // Base de la idempotencia: un pedido tiene como mucho UNA incidencia viva
            // por codigo. Sin esto, cada reintento del listener crearia una fila nueva.
            $table->unique(['order_id', 'codigo'], 'bvaai_order_codigo_unique');

            $table->index('estado', 'bvaai_estado_idx');
            $table->index('codigo', 'bvaai_codigo_idx');
            $table->index('last_detected_at', 'bvaai_last_detected_idx');
            $table->index(['estado', 'last_detected_at'], 'bvaai_estado_fecha_idx');

            /*
             * RESTRICT, no cascade: borrar un pedido con una incidencia abierta debe
             * fallar en la base de datos. Una incidencia que desaparece sola es peor
             * que no tenerla, porque nadie se entera de que se perdio.
             */
            $table->foreign('order_id', 'bvaai_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();

            $table->foreign('order_attribution_id', 'bvaai_attribution_fk')
                ->references('id')->on('bylopez_va_order_attributions')->restrictOnDelete();
        });

        /*
         * `attempt_count >= 1` tambien en la base. El dominio ya lo garantiza; esto
         * cubre a cualquier script que escriba por fuera. MariaDB 10.4 aplica CHECK
         * de verdad (no lo ignora como MySQL 5.7), asi que es seguro declararlo.
         */
        DB::statement(
            'ALTER TABLE `bylopez_va_attribution_incidents`
             ADD CONSTRAINT `bvaai_attempt_count_check` CHECK (`attempt_count` >= 1)'
        );
    }

    /** Solo su propia tabla: no revierte nada de las migraciones anteriores. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_attribution_incidents');
    }
};
