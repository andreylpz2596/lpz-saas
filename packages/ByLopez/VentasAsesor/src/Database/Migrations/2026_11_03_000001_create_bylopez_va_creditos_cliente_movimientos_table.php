<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * D10 V1 — LEDGER INMUTABLE de consumos del saldo a favor del cliente. ADITIVA; solo tablas propias; sin backfill.
 *
 * La GENERACION del credito NO se guarda aqui: se deriva de las fuentes reales (devoluciones C5, cancelaciones y ajustes de
 * obligacion) en CreditoClienteService. Esta tabla solo guarda lo que SE CONSUMIO del credito de un pedido origen: aplicacion a
 * otro pedido, reembolso, y el reverso de una aplicacion.
 *
 *   credito_usado = Σ(aplicacion + reembolso) sin reverso      credito_disponible = generado − usado   (nunca < 0)
 *
 * - `revierte_id` UNIQUE: una operacion se reversa una sola vez (idempotencia estructural; sin DELETE ni UPDATE).
 * - `idempotency_key` UNIQUE: reintentos HTTP o doble clic no consumen dos veces.
 * - `recepcion_origen_id`: solo metadata informativa (el credito es agregado por pedido, no por recepcion); sin FK.
 * - FK RESTRICT: la historia financiera no se cascadea. Los ids de administrador van SIN FK (la auditoria sobrevive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_creditos_cliente_movimientos', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('customer_id');
            $table->string('tipo', 30);
            $table->unsignedInteger('order_origen_id');
            $table->unsignedInteger('order_destino_id')->nullable();
            $table->decimal('monto', 12, 4);

            $table->unsignedBigInteger('pago_id')->nullable();
            $table->unsignedBigInteger('aplicacion_pago_id')->nullable();
            $table->unsignedBigInteger('recepcion_origen_id')->nullable();
            $table->unsignedBigInteger('revierte_id')->nullable();

            $table->string('medio_devolucion', 40)->nullable();
            $table->string('referencia', 100)->nullable();
            $table->string('comprobante_ruta', 255)->nullable();
            $table->string('motivo', 500)->nullable();

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_nombre', 191)->nullable();
            $table->string('idempotency_key', 100);
            $table->char('huella_solicitud', 64);
            $table->json('datos')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('idempotency_key', 'bvacm_idem_unique');
            $table->unique('revierte_id', 'bvacm_revierte_unique');
            $table->index(['order_origen_id', 'tipo'], 'bvacm_origen_tipo_idx');
            $table->index('order_destino_id', 'bvacm_destino_idx');
            $table->index('customer_id', 'bvacm_cliente_idx');

            $table->foreign('customer_id', 'bvacm_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('order_origen_id', 'bvacm_origen_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_destino_id', 'bvacm_destino_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('pago_id', 'bvacm_pago_fk')->references('id')->on('bylopez_va_pagos')->restrictOnDelete();
            $table->foreign('revierte_id', 'bvacm_revierte_fk')->references('id')->on('bylopez_va_creditos_cliente_movimientos')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_creditos_cliente_movimientos ADD CONSTRAINT bvacm_monto_positivo CHECK (monto > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_creditos_cliente_movimientos');
    }
};
