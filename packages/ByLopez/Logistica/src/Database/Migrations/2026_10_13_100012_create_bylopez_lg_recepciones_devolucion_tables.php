<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * RECEPCION FISICA DE UNA DEVOLUCION en bodega (Entregas · Fase B/C).
 *
 *   DEVOLUCION REGISTRADA (bylopez_lg_retornos)  ≠  RECIBIDA EN BODEGA (esta tabla)  ≠  REINGRESO DE INVENTARIO.
 *
 * - `bylopez_lg_recepciones_devolucion`: un evento por devolucion. Mismo patron que entregas/retornos: inmutable salvo
 *   la anulacion auditada `confirmado → anulado`. `vigente_unica` (columna generada) hace UNIQUE por devolucion mientras la
 *   recepcion este confirmada: nunca dos recepciones vigentes de la misma devolucion. `inventory_source_id` sale del
 *   SHIPMENT real (shipments.inventory_source_id), no de la configuracion.
 * - `bylopez_lg_recepcion_devolucion_items`: una fila por linea devuelta. `product_id` es la VARIANTE FISICA que el Shipment
 *   descontó (no el configurable padre). Solo las `aptas` vuelven al inventario; `novedad` (daniadas / no aptas) y los
 *   `faltantes` (esperadas − recibidas) no. `movimiento_inventario_id` apunta al libro de inventario SIN FK (frontera con
 *   el modulo de inventario) y es UNIQUE: un movimiento no puede respaldar dos lineas.
 * - Actor sin FK + nombre snapshot, como el resto de Logistica. Sin backfill: lo historico no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_recepciones_devolucion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retorno_id');
            $table->unsignedInteger('shipment_id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('inventory_source_id');
            $table->date('fecha_recepcion');
            $table->string('observacion', 500)->nullable();

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);

            $table->string('estado', 20)->default('confirmado');
            $table->string('idempotency_key', 100);
            $table->char('huella', 64);

            $table->string('motivo_anulacion', 500)->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();
            $table->string('anulada_por_name_snapshot', 191)->nullable();
            $table->timestamp('anulada_en')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key', 'bylgrd_idem_unique');
            $table->index('shipment_id', 'bylgrd_shipment_idx');
            $table->index('order_id', 'bylgrd_order_idx');

            $table->foreign('retorno_id', 'bylgrd_retorno_fk')->references('id')->on('bylopez_lg_retornos')->restrictOnDelete();
            $table->foreign('shipment_id', 'bylgrd_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
            $table->foreign('order_id', 'bylgrd_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('inventory_source_id', 'bylgrd_source_fk')->references('id')->on('inventory_sources')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_recepciones_devolucion ADD CONSTRAINT bylgrd_estado_chk CHECK (estado IN ('confirmado', 'anulado'))");
        DB::statement("ALTER TABLE bylopez_lg_recepciones_devolucion ADD CONSTRAINT bylgrd_anulacion_chk CHECK ((estado = 'anulado') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL AND anulada_por_admin_id IS NOT NULL))");
        DB::statement("ALTER TABLE bylopez_lg_recepciones_devolucion ADD vigente_unica BIGINT UNSIGNED GENERATED ALWAYS AS (IF(estado = 'confirmado', retorno_id, NULL)) STORED");
        DB::statement('ALTER TABLE bylopez_lg_recepciones_devolucion ADD UNIQUE KEY bylgrd_vigente_unica (vigente_unica)');

        Schema::create('bylopez_lg_recepcion_devolucion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_id');
            $table->foreignId('retorno_item_id');
            $table->unsignedInteger('shipment_item_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('esperadas');
            $table->unsignedInteger('recibidas');
            $table->unsignedInteger('aptas');
            $table->unsignedInteger('novedad');
            $table->unsignedInteger('faltantes');
            $table->string('motivo_novedad', 500)->nullable();
            $table->string('reingreso_estado', 20);
            $table->unsignedBigInteger('movimiento_inventario_id')->nullable();
            $table->timestamp('reingresado_en')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['recepcion_id', 'retorno_item_id'], 'bylgrdi_recepcion_item_unique');
            $table->unique('movimiento_inventario_id', 'bylgrdi_movimiento_unique');
            $table->index('shipment_item_id', 'bylgrdi_shipment_item_idx');
            $table->index('product_id', 'bylgrdi_product_idx');

            $table->foreign('recepcion_id', 'bylgrdi_recepcion_fk')->references('id')->on('bylopez_lg_recepciones_devolucion')->restrictOnDelete();
            $table->foreign('retorno_item_id', 'bylgrdi_retorno_item_fk')->references('id')->on('bylopez_lg_retorno_items')->restrictOnDelete();
            $table->foreign('shipment_item_id', 'bylgrdi_shipment_item_fk')->references('id')->on('shipment_items')->restrictOnDelete();
            $table->foreign('order_item_id', 'bylgrdi_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('product_id', 'bylgrdi_product_fk')->references('id')->on('products')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_recepcion_devolucion_items ADD CONSTRAINT bylgrdi_cantidades_chk CHECK (esperadas > 0 AND recibidas <= esperadas AND aptas + novedad = recibidas AND faltantes = esperadas - recibidas)');
        DB::statement("ALTER TABLE bylopez_lg_recepcion_devolucion_items ADD CONSTRAINT bylgrdi_reingreso_chk CHECK (reingreso_estado IN ('reingresado', 'sin_aptas', 'no_aplica') AND ((reingreso_estado = 'reingresado') = (movimiento_inventario_id IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_recepcion_devolucion_items');
        Schema::dropIfExists('bylopez_lg_recepciones_devolucion');
    }
};
