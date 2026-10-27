<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * GRP-01 — HISTORIAL de cambios de grupo comercial de una venta. Una fila por evento; nunca se sobrescribe ni se borra.
 * La unica escritura posterior permitida es completar `order_id` cuando la venta se confirma.
 *
 * - evento: override_aplicado | override_cambiado | restauracion | override_cancelado_cambio_cliente (CHECK).
 * - origen: nueva_venta | cambio_cliente.
 * - Los grupos se guardan por id Y con nombre/codigo snapshot (un grupo puede renombrarse o borrarse despues).
 * - borrador_id y cart_id sin FK: el historial debe sobrevivir al descarte del borrador/carrito. order_id FK RESTRICT.
 * - actor sin FK, con nombre snapshot (mismo patron que la clasificacion del pedido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_borrador_grupo_eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrador_id');
            $table->unsignedInteger('cart_id')->nullable();
            $table->unsignedInteger('customer_id');
            $table->string('evento', 40);
            $table->string('origen', 30);

            $table->unsignedInteger('grupo_original_id')->nullable();
            $table->string('grupo_original_nombre', 191)->nullable();
            $table->unsignedInteger('grupo_anterior_id')->nullable();
            $table->string('grupo_anterior_nombre', 191)->nullable();
            $table->unsignedInteger('grupo_nuevo_id')->nullable();
            $table->string('grupo_nuevo_nombre', 191)->nullable();

            $table->text('motivo');
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_nombre_snapshot', 191)->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->timestamps();

            $table->index(['borrador_id', 'id'], 'bvabge_borrador_idx');
            $table->index('order_id', 'bvabge_order_idx');
            $table->foreign('order_id', 'bvabge_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_borrador_grupo_eventos ADD CONSTRAINT bvabge_evento_chk CHECK (evento IN ('override_aplicado', 'override_cambiado', 'restauracion', 'override_cancelado_cambio_cliente'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_borrador_grupo_eventos');
    }
};
