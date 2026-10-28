<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cancelacion de lo PENDIENTE de despacho (jornada 2026-10-27/28). La cancelacion nativa de Bagisto reintegra al stock fisico lo que
 * ya salio (OrderItemRepository::returnQtyToProductInventory) y es imposible en pedidos autofacturados; ByLopez cancela el
 * pendiente por su cuenta: libera la reserva, NO toca el stock fisico, deja historial (actor, motivo, lineas) y reduce la
 * obligacion del cliente SIN Refund ni reembolso ficticio. Solo inserciones. Sin backfill.
 *
 * `valor_cancelado` = valor historico de las unidades canceladas (misma formula de C1/C5: precio efectivo − descuento + impuesto
 * de la linea). Cartera lo descuenta del total exigible como lo hace con lo devuelto recibido (C5), pero identificado aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_cancelaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->string('motivo', 500);
            $table->unsignedInteger('unidades');
            $table->decimal('valor_cancelado', 12, 4);
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id'], 'bylgcn_pedido_idx');
            $table->foreign('order_id', 'bylgcn_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::create('bylopez_lg_cancelacion_lineas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancelacion_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('cantidad');
            $table->decimal('valor', 12, 4);
            $table->boolean('libero_reserva')->default(true);

            $table->unique(['cancelacion_id', 'order_item_id'], 'bylgcl_unica');
            $table->foreign('cancelacion_id', 'bylgcl_cancelacion_fk')->references('id')->on('bylopez_lg_cancelaciones')->restrictOnDelete();
            $table->foreign('order_item_id', 'bylgcl_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_cancelacion_lineas');
        Schema::dropIfExists('bylopez_lg_cancelaciones');
    }
};
