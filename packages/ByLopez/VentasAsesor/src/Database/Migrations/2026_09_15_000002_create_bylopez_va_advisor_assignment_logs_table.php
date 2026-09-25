<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Historial de cambios de propiedad comercial [M-§5.2]. Nunca se borra ni se
 * reescribe: es la unica auditoria de por que una venta cambio de dueño, porque
 * el core no tiene tabla de auditoria administrativa [A-§5.1, H5].
 *
 * Los identificadores de administrador se guardan SIN clave foranea, junto a su
 * snapshot de nombre: si un administrador se elimina, el historial debe seguir
 * diciendo quien era, no quedar en null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_advisor_assignment_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_attribution_id')
                ->constrained('bylopez_va_order_attributions', 'id', 'bvaal_attribution_fk')
                ->cascadeOnDelete();

            // Redundante a proposito, para auditar sin depender de la atribucion.
            $table->unsignedInteger('order_id')->index('bvaal_order_idx');

            $table->unsignedInteger('advisor_id_anterior')->nullable()->index('bvaal_advisor_prev_idx');
            $table->string('advisor_anterior_snapshot')->nullable();

            $table->unsignedInteger('advisor_id_nuevo')->nullable()->index('bvaal_advisor_new_idx');
            $table->string('advisor_nuevo_snapshot')->nullable();

            $table->string('tipo_propiedad_anterior');
            $table->string('tipo_propiedad_nuevo');

            $table->unsignedInteger('changed_by_admin_id')->nullable();
            $table->string('changed_by_name_snapshot')->nullable();

            $table->string('motivo');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_advisor_assignment_logs');
    }
};
