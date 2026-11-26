<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Cupo / limite de cartera por cliente (Doc. 35). ADITIVA; solo tablas propias; sin backfill.
 *
 * - `bylopez_va_clientes_cupo_cartera`: la POLITICA vigente (una fila por cliente). Solo guarda el limite autorizado y su estado
 *   (`activo` | `bloqueado`). NO guarda saldo utilizado: la exposicion se DERIVA de Cartera cada vez.
 * - `bylopez_va_clientes_cupo_cartera_historial`: auditoria de SOLO ADICION (nunca se borra ni se edita). Cada cambio guarda anterior,
 *   nuevo, motivo, usuario, fecha y una foto informativa de la exposicion al momento del cambio.
 * - FK RESTRICT hacia `customers`: la historia financiera no se cascadea. Los ids de administrador van SIN FK (la auditoria sobrevive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_clientes_cupo_cartera', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->decimal('cupo_autorizado', 14, 2)->default(0);
            $table->string('estado', 20)->default('activo'); // activo | bloqueado
            $table->timestamp('vigencia_desde')->nullable();
            $table->string('motivo', 500)->nullable(); // motivo del ULTIMO cambio (la historia completa esta en el historial)
            $table->unsignedInteger('creado_por')->nullable();
            $table->unsignedInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->unique('customer_id', 'bvacc_cliente_uq');
            $table->foreign('customer_id', 'bvacc_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_clientes_cupo_cartera ADD CONSTRAINT bvacc_cupo_no_negativo CHECK (cupo_autorizado >= 0)');
        DB::statement("ALTER TABLE bylopez_va_clientes_cupo_cartera ADD CONSTRAINT bvacc_estado_valido CHECK (estado IN ('activo', 'bloqueado'))");

        Schema::create('bylopez_va_clientes_cupo_cartera_historial', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->string('accion', 30); // asignacion | cambio_cupo | cambio_estado | cambio_cupo_estado
            $table->decimal('cupo_anterior', 14, 2)->nullable(); // null = primera asignacion
            $table->decimal('cupo_nuevo', 14, 2);
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->string('motivo', 500);
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_nombre', 191)->nullable();
            $table->decimal('exposicion_al_cambiar', 14, 2)->nullable(); // foto informativa; nunca se usa para calcular
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'id'], 'bvacch_cliente_idx');
            $table->foreign('customer_id', 'bvacch_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_clientes_cupo_cartera_historial');
        Schema::dropIfExists('bylopez_va_clientes_cupo_cartera');
    }
};
