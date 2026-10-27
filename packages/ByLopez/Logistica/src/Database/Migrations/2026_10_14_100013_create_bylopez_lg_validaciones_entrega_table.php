<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * VALIDACION DE ENTREGA (Entregas · Fase D): el hito en que el ADMINISTRADOR ratifica que un envio quedo entregado.
 *
 *   ENTREGA REGISTRADA (quien la reporta)  ≠  ENTREGA VALIDADA (esta tabla)  ≠  PAGO (Cartera)
 *
 * Es el hecho que habilita la comision del asesor (la comision NO se calcula aqui: COM-04 consume este hito) y el cierre
 * del pedido. Mismo patron que entregas/retornos/recepciones: evento inmutable salvo la anulacion auditada
 * `validada → anulada`. `vigente_unica` (columna generada = shipment_id mientras este validada) es UNIQUE: nunca dos
 * validaciones vigentes del mismo envio. No hay FK al actor (snapshot de nombre, como el resto de Logistica).
 * Sin backfill: lo historico no se valida solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_validaciones_entrega', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shipment_id');
            $table->unsignedInteger('order_id');
            $table->string('observacion', 500)->nullable();

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);

            // Foto de lo que se ratifico (auditoria): unidades entregadas / devueltas al validar.
            $table->unsignedInteger('entregadas');
            $table->unsignedInteger('devueltas');

            $table->string('estado', 20)->default('confirmado');
            $table->string('idempotency_key', 100);
            $table->char('huella', 64);

            $table->string('motivo_anulacion', 500)->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();
            $table->string('anulada_por_name_snapshot', 191)->nullable();
            $table->timestamp('anulada_en')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key', 'bylgve_idem_unique');
            $table->index('shipment_id', 'bylgve_shipment_idx');
            $table->index('order_id', 'bylgve_order_idx');

            $table->foreign('shipment_id', 'bylgve_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
            $table->foreign('order_id', 'bylgve_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_validaciones_entrega ADD CONSTRAINT bylgve_estado_chk CHECK (estado IN ('confirmado', 'anulado'))");
        DB::statement("ALTER TABLE bylopez_lg_validaciones_entrega ADD CONSTRAINT bylgve_anulacion_chk CHECK ((estado = 'anulado') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL AND anulada_por_admin_id IS NOT NULL))");
        DB::statement("ALTER TABLE bylopez_lg_validaciones_entrega ADD vigente_unica INT UNSIGNED GENERATED ALWAYS AS (IF(estado = 'confirmado', shipment_id, NULL)) STORED");
        DB::statement('ALTER TABLE bylopez_lg_validaciones_entrega ADD UNIQUE KEY bylgve_vigente_unica (vigente_unica)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_validaciones_entrega');
    }
};
