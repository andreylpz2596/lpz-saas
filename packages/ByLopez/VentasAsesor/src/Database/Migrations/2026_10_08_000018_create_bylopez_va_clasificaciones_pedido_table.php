<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * G-11 / COM-D-15 — decimoctava migracion del modulo, ADITIVA: clasificacion comercial POR PEDIDO, 1:1 con `orders`.
 *
 * - `tipo_operacion`: venta_normal | muestra | reposicion | garantia (CHECK). Nunca texto libre.
 * - Se escribe UNA vez, dentro de la transaccion del pedido (listener ClasificarOperacionPedido, Nueva venta). Inmutable
 *   (modelo ClasificacionPedido): reclasificar exigira su propio flujo auditado (no existe hoy).
 * - SIN backfill: los pedidos anteriores (y los de otros canales) quedan SIN fila = «sin clasificar», nunca
 *   `venta_normal` inventada (bloque 26). COM-D-13 ya impide comisionarlos.
 * - FK RESTRICT a orders (int(10) unsigned); actor sin FK con nombre snapshot; borrador sin FK (referencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_clasificaciones_pedido', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->string('tipo_operacion', 20);
            $table->string('origen', 30);
            $table->unsignedBigInteger('borrador_id')->nullable();
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_nombre_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique('order_id', 'bvacp_order_unique');
            $table->index('tipo_operacion', 'bvacp_tipo_idx');
            $table->foreign('order_id', 'bvacp_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_clasificaciones_pedido ADD CONSTRAINT bvacp_tipo_chk CHECK (tipo_operacion IN ('venta_normal', 'muestra', 'reposicion', 'garantia'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_clasificaciones_pedido');
    }
};
