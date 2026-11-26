<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Excepcion «Autorizar exceso» del cupo de cartera (Doc. 35 §8). ADITIVA; sin backfill.
 *
 * Un administrador autorizado permite que UNA venta de un cliente supere su cupo (o se cree sin cupo) hasta `exceso_autorizado`. La
 * autorizacion se CONSUME dentro de la transaccion del pedido (cerco de creacion de pedidos, bajo el bloqueo del cliente) y queda ligada
 * al pedido; nunca se reutiliza. Cada fila es su propia auditoria (quien, cuanto, por que, cuando, con que exposicion, en que pedido).
 *
 * - `vigente_unica` (UNIQUE): a lo sumo UNA autorizacion vigente por cliente. Las vencidas se marcan `vencida` al autorizar otra.
 * - Nada se borra. Las filas solo transitan vigente → consumida | anulada | vencida.
 * - FK RESTRICT hacia `customers` y `orders`; los ids de administrador van SIN FK (la auditoria sobrevive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_clientes_cupo_cartera_excesos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->decimal('exceso_autorizado', 14, 2);
            $table->string('motivo', 500);
            $table->string('estado', 20)->default('vigente'); // vigente | consumida | anulada | vencida
            $table->timestamp('vence_en')->nullable(); // nullable: sin esto MariaDB (explicit_defaults_for_timestamp apagado) le pone ON UPDATE CURRENT_TIMESTAMP y el vencimiento se movia en cada UPDATE

            $table->unsignedInteger('autorizado_por');
            $table->string('autorizado_nombre', 191)->nullable();

            // Consumo: lo escribe el cerco de creacion de pedidos, en la misma transaccion que crea el pedido.
            $table->timestamp('consumida_en')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->decimal('exposicion_antes', 14, 2)->nullable();
            $table->decimal('cupo_al_consumir', 14, 2)->nullable();
            $table->decimal('nueva_obligacion', 14, 2)->nullable();
            $table->decimal('exceso_consumido', 14, 2)->nullable();

            $table->timestamp('anulada_en')->nullable();
            $table->unsignedInteger('anulada_por')->nullable();
            $table->string('anulada_motivo', 500)->nullable();

            $table->unsignedBigInteger('vigente_unica')->nullable()->storedAs("if(`estado` = 'vigente', `customer_id`, NULL)");
            $table->timestamps();

            $table->unique('vigente_unica', 'bvaccx_vigente_uq');
            $table->index(['customer_id', 'id'], 'bvaccx_cliente_idx');
            $table->foreign('customer_id', 'bvaccx_customer_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('order_id', 'bvaccx_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_va_clientes_cupo_cartera_excesos ADD CONSTRAINT bvaccx_exceso_positivo CHECK (exceso_autorizado > 0)');
        DB::statement("ALTER TABLE bylopez_va_clientes_cupo_cartera_excesos ADD CONSTRAINT bvaccx_estado_valido CHECK (estado IN ('vigente', 'consumida', 'anulada', 'vencida'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_clientes_cupo_cartera_excesos');
    }
};
