<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PAG-B (D-59 / Doc. 05 §42.2) — duodecima migracion del modulo, ADITIVA: a que pedido se aplico cada peso
 * de un pago.
 *
 * - UNIQUE(pago_id, order_id): un pago aplica UNA vez a cada pedido (una fila por par).
 * - `anulada_en` NULL = aplicacion ACTIVA. Rechazar (o reversar) un pago anula SUS aplicaciones, nunca las
 *   borra: la historia se conserva (P-05, I-3).
 * - `order_transaction_id` NULL hasta confirmar; UNIQUE; FK RESTRICT. Es el VINCULO ESTRUCTURAL que evita el
 *   doble conteo (Doc. 05 §42.3): una Transaction nativa vinculada a una aplicacion es de ByLopez; una sin
 *   vinculo es «ajena». MySQL/MariaDB admiten varios NULL en una columna UNIQUE.
 * - `invoice_id` nullable: se fija al confirmar, cuando se sabe contra que factura nace la Transaction.
 * - `posicion`: orden de la distribucion (propuesta FIFO editable, P-07); solo informativo.
 * - Todas las FK son RESTRICT: no se cascadea historia financiera. Los ids de Bagisto son int(10) unsigned.
 * - Sin updated_at: la fila solo cambia al anularse (`anulada_en`, `anulada_por_admin_id`) o al vincularse a
 *   su Transaction; el historial completo vive en `bylopez_va_pagos_eventos`.
 * - CHECK monto > 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_aplicaciones_pago', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pago_id')
                ->constrained('bylopez_va_pagos', 'id', 'bvaap_pago_fk')
                ->restrictOnDelete();

            $table->unsignedInteger('order_id');
            $table->unsignedInteger('invoice_id')->nullable();
            $table->decimal('monto', 12, 4);
            $table->unsignedSmallInteger('posicion')->default(1);

            $table->timestamp('anulada_en')->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();

            $table->unsignedInteger('order_transaction_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['pago_id', 'order_id'], 'bvaap_pago_order_unique');
            $table->unique('order_transaction_id', 'bvaap_transaction_unique');
            $table->index(['order_id', 'anulada_en'], 'bvaap_order_activa_idx');

            $table->foreign('order_id', 'bvaap_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('invoice_id', 'bvaap_invoice_fk')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('order_transaction_id', 'bvaap_transaction_fk')->references('id')->on('order_transactions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_aplicaciones_pago ADD CONSTRAINT bvaap_monto_positivo CHECK (monto > 0)');
    }

    /** Solo esta tabla; no hay datos que preservar mientras ningun servicio escriba en ella. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_aplicaciones_pago');
    }
};
