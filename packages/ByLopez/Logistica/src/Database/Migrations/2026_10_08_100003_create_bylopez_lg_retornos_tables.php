<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LOG-D-03 / bloques 7-8 — RETORNO DE TRANSPORTADORA: la transportadora NO logro entregar y devolvio la mercancia.
 * Evento SEPARADO de la entrega (tablas propias, misma forma). No es refund, ni RMA, ni devolucion de un cliente
 * que ya recibio: eso es otro dominio (Fase 07). No borra el Shipment, no crea Refund, no toca comisiones: COM-04
 * solo vera que esas unidades no se entregaron.
 *
 * Mismas reglas que las entregas: inmutable salvo anulacion auditada, `idempotency_key` UNIQUE, actor sin FK con
 * nombre snapshot, FK RESTRICT a Bagisto, `motivo` obligatorio. Una unidad retornada deja de estar pendiente de
 * entrega (Σ entregado + Σ retornado ≤ qty despachada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_retornos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('shipment_id');
            $table->date('fecha_retorno');
            $table->string('fuente', 40);
            $table->string('motivo', 500);

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);

            $table->string('carrier_code_snapshot', 40)->nullable();
            $table->string('carrier_title_snapshot', 191)->nullable();
            $table->string('track_number_snapshot', 191)->nullable();
            $table->string('evidencia_referencia', 500)->nullable();

            $table->string('estado', 20)->default('confirmado');
            $table->string('idempotency_key', 100);
            $table->char('huella', 64);

            $table->string('motivo_anulacion', 500)->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();
            $table->string('anulada_por_name_snapshot', 191)->nullable();
            $table->timestamp('anulada_en')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key', 'bylgr_idem_unique');
            $table->index('order_id', 'bylgr_order_idx');
            $table->index(['shipment_id', 'estado'], 'bylgr_shipment_estado_idx');

            $table->foreign('order_id', 'bylgr_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('shipment_id', 'bylgr_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_retornos ADD CONSTRAINT bylgr_estado_chk CHECK (estado IN ('confirmado', 'anulado'))");
        DB::statement("ALTER TABLE bylopez_lg_retornos ADD CONSTRAINT bylgr_anulacion_chk CHECK ((estado = 'anulado') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL AND anulada_por_admin_id IS NOT NULL))");

        Schema::create('bylopez_lg_retorno_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retorno_id');
            $table->unsignedInteger('shipment_item_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('cantidad');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['retorno_id', 'shipment_item_id'], 'bylgri_retorno_item_unique');
            $table->index('shipment_item_id', 'bylgri_shipment_item_idx');
            $table->index('order_item_id', 'bylgri_order_item_idx');

            $table->foreign('retorno_id', 'bylgri_retorno_fk')->references('id')->on('bylopez_lg_retornos')->restrictOnDelete();
            $table->foreign('shipment_item_id', 'bylgri_shipment_item_fk')->references('id')->on('shipment_items')->restrictOnDelete();
            $table->foreign('order_item_id', 'bylgri_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_retorno_items ADD CONSTRAINT bylgri_cantidad_chk CHECK (cantidad > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_retorno_items');
        Schema::dropIfExists('bylopez_lg_retornos');
    }
};
