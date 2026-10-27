<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GRP-01 — SNAPSHOT comercial DURABLE del grupo con el que se vendio un pedido, 1:1 con `orders`, dentro de estructuras
 * propias (no se toca ninguna tabla de Webkul). Se escribe UNA vez, en la transaccion del pedido (listener
 * RegistrarSnapshotGrupoPedido) y es inmutable (modelo SnapshotGrupoPedido).
 *
 * Guarda id + nombre + codigo de ambos grupos: el pedido debe seguir explicando bajo que condicion se vendio aunque el
 * grupo se renombre, el cliente cambie de grupo o el borrador se limpie.
 * SIN backfill: los pedidos anteriores quedan sin fila = «sin snapshot de grupo», nunca inventado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_pedido_grupo_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('customer_id');
            $table->unsignedBigInteger('borrador_id')->nullable();

            $table->unsignedInteger('grupo_original_id')->nullable();
            $table->string('grupo_original_nombre', 191);
            $table->string('grupo_original_codigo', 191)->nullable();
            $table->unsignedInteger('grupo_efectivo_id')->nullable();
            $table->string('grupo_efectivo_nombre', 191);
            $table->string('grupo_efectivo_codigo', 191)->nullable();
            $table->boolean('grupo_override')->default(false);
            $table->timestamps();

            $table->unique('order_id', 'bvapgs_order_unique');
            $table->index('customer_id', 'bvapgs_customer_idx');
            $table->foreign('order_id', 'bvapgs_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_pedido_grupo_snapshots');
    }
};
