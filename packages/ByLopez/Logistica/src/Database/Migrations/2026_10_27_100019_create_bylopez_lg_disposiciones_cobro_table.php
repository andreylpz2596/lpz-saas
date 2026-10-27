<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * F6 (auditoria 2026-10-21; regla aprobada 2026-10-26): un pedido que NO es contra entrega y aun debe dinero no se despacha
 * en silencio con «no cobrar nada». Se resuelve con una de tres situaciones:
 *   A. esperar el pago confirmado (sin fila: el despacho queda bloqueado),
 *   B. `cobrar_al_entregar`: la guia lleva el saldo como recaudo (regla C1 por guia),
 *   C. `credito_autorizado`: sale sin recaudo; solo con autoridad administrativa, motivo e historial.
 *
 * ADITIVA, solo inserciones (historial): la decision VIGENTE es la ultima del pedido y solo vale mientras la situacion del pago
 * sea la misma que cuando se tomo (`saldo_referencia`, `sin_revisar_referencia`); si el pago cambio (nuevo pago, rechazo,
 * devolucion recibida) hay que volver a decidir. El metodo de pago del pedido NO se modifica: esto es la instruccion de cobro.
 * `bylopez_lg_guias.disposicion_cobro_id` deja en la guia la decision con la que salio. Sin backfill: las guias anteriores quedan NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_disposiciones_cobro', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->string('tipo', 30);
            $table->string('motivo', 500)->nullable();
            $table->decimal('saldo_referencia', 12, 4);
            $table->decimal('sin_revisar_referencia', 12, 4)->default(0);
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id'], 'bylgdc_pedido_idx');
            $table->foreign('order_id', 'bylgdc_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_disposiciones_cobro ADD CONSTRAINT bylgdc_tipo_chk CHECK (tipo IN ('cobrar_al_entregar', 'credito_autorizado'))");

        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->unsignedBigInteger('disposicion_cobro_id')->nullable()->after('motivo_recaudo');
            $table->foreign('disposicion_cobro_id', 'bylgg_disposicion_fk')->references('id')->on('bylopez_lg_disposiciones_cobro')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->dropForeign('bylgg_disposicion_fk');
            $table->dropColumn('disposicion_cobro_id');
        });

        Schema::dropIfExists('bylopez_lg_disposiciones_cobro');
    }
};
