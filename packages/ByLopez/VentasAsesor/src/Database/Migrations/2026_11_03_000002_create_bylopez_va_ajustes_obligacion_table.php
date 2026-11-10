<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * D10 V1 — AJUSTES DE OBLIGACION: reducciones AUTORIZADAS de lo que el cliente debe (hoy: condonacion de flete), sin tocar
 * `orders.shipping_amount` ni ningun importe historico del pedido. Cartera resta los ajustes `activo` del total operativo.
 * ADITIVA, sin backfill. Un ajuste no se edita ni se borra: solo se REVERSA (estado + quien/cuando/por que), conservando la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_ajustes_obligacion', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('order_id');
            $table->unsignedInteger('customer_id');
            $table->string('concepto', 30);
            $table->string('causa', 40);
            $table->decimal('monto', 12, 4);
            $table->string('motivo', 500);
            $table->string('estado', 20)->default('activo');

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_nombre', 191)->nullable();

            $table->timestamp('reversado_en')->nullable();
            $table->unsignedInteger('reversado_por_admin_id')->nullable();
            $table->string('motivo_reverso', 500)->nullable();
            $table->string('reverso_idempotency_key', 100)->nullable();

            $table->string('idempotency_key', 100);
            $table->char('huella_solicitud', 64);
            $table->json('datos')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'bvaao_idem_unique');
            $table->unique('reverso_idempotency_key', 'bvaao_reverso_idem_unique');
            $table->index(['order_id', 'estado'], 'bvaao_order_estado_idx');
            $table->index('customer_id', 'bvaao_cliente_idx');

            $table->foreign('order_id', 'bvaao_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('customer_id', 'bvaao_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_ajustes_obligacion ADD CONSTRAINT bvaao_monto_positivo CHECK (monto > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_ajustes_obligacion');
    }
};
