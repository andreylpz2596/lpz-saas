<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * LOG-01 / LOG-D-01..03 — ENTREGA CANONICA (ByLopez\Logistica es el propietario; VentasAsesor solo lee).
 *
 * `despachado` sigue siendo el Shipment de Bagisto (no se duplica). El dominio propio empieza donde Bagisto no
 * llega: la ENTREGA real al cliente, por envio y por unidad.
 *
 * - `bylopez_lg_entregas`: un evento por registro. Inmutable salvo UNA transicion auditada `confirmado → anulado`
 *   (motivo + quien + cuando); corregir = anular y registrar una nueva. Lo impone el modelo (Entrega); SQL directo
 *   lo salta (regla del paquete: solo escriben los servicios de Services/Entregas).
 * - `idempotency_key` UNIQUE + `huella` (sha256 de la solicitud): doble clic / reintento = la misma entrega.
 * - `fecha_entrega` = fecha REAL informada; `created_at` = fecha de registro.
 * - Actor sin FK + nombre snapshot (un admin puede borrarse; la auditoria sobrevive), como el resto de ByLopez.
 * - FK RESTRICT a orders, shipments, shipment_items y order_items (ids int(10) unsigned de Bagisto): una entrega
 *   registrada impide borrar su pedido/envio.
 * - `bylopez_lg_entrega_items`: unidades por `shipment_item` (admite parciales). Invariante (servicio, bajo
 *   FOR UPDATE del shipment): Σ entregado vigente + Σ retornado vigente ≤ shipment_items.qty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_entregas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('shipment_id');
            $table->date('fecha_entrega');
            $table->string('fuente', 40);

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

            $table->unique('idempotency_key', 'bylge_idem_unique');
            $table->index('order_id', 'bylge_order_idx');
            $table->index(['shipment_id', 'estado'], 'bylge_shipment_estado_idx');
            $table->index('fecha_entrega', 'bylge_fecha_idx');

            $table->foreign('order_id', 'bylge_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('shipment_id', 'bylge_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_entregas ADD CONSTRAINT bylge_estado_chk CHECK (estado IN ('confirmado', 'anulado'))");
        DB::statement("ALTER TABLE bylopez_lg_entregas ADD CONSTRAINT bylge_anulacion_chk CHECK ((estado = 'anulado') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL AND anulada_por_admin_id IS NOT NULL))");

        Schema::create('bylopez_lg_entrega_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id');
            $table->unsignedInteger('shipment_item_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('cantidad');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['entrega_id', 'shipment_item_id'], 'bylgei_entrega_item_unique');
            $table->index('shipment_item_id', 'bylgei_shipment_item_idx');
            $table->index('order_item_id', 'bylgei_order_item_idx');

            $table->foreign('entrega_id', 'bylgei_entrega_fk')->references('id')->on('bylopez_lg_entregas')->restrictOnDelete();
            $table->foreign('shipment_item_id', 'bylgei_shipment_item_fk')->references('id')->on('shipment_items')->restrictOnDelete();
            $table->foreign('order_item_id', 'bylgei_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_entrega_items ADD CONSTRAINT bylgei_cantidad_chk CHECK (cantidad > 0)');
    }

    /** Con entregas reales, revertir borra el hecho generador de comisiones: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_entrega_items');
        Schema::dropIfExists('bylopez_lg_entregas');
    }
};
