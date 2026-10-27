<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * R2 (Doc. 22 §4.2.6, Doc. 23) — SNAPSHOT de la evaluacion de rentabilidad de cada pedido, escrito en la MISMA
 * transaccion del pedido (listener `checkout.order.save.after`). Inmutable (lo impone el modelo): conserva precio neto,
 * costo y su estado, version de la politica, % del asesor, costos directos estimados y el resultado por linea. Cubre
 * TODO pedido creado por el repositorio de Bagisto (Nueva venta, panel nativo, tienda): en los canales sin bloqueo
 * queda al menos el registro del incumplimiento.
 *
 * Sin backfill: los pedidos anteriores no se evaluan retroactivamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_rentabilidad_evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->string('resultado', 20);
            $table->boolean('bloqueo_activo');
            $table->string('canal', 30);
            $table->string('propiedad', 20)->nullable();
            $table->unsignedInteger('asesor_admin_id')->nullable();
            $table->foreignId('acuerdo_id')->nullable();
            $table->decimal('porcentaje', 7, 4)->nullable();
            $table->decimal('costo_pedido_estimado', 14, 2)->default(0);
            $table->decimal('holgura_pedido', 14, 2)->nullable();
            $table->json('costos_directos');
            $table->json('motivos');
            $table->dateTime('evaluado_en');
            $table->timestamps();

            $table->unique('order_id', 'bvare_order_unique');
            $table->index(['resultado', 'evaluado_en'], 'bvare_resultado_idx');
            $table->foreign('order_id', 'bvare_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('acuerdo_id', 'bvare_acuerdo_fk')->references('id')->on('bylopez_va_comision_acuerdos')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_rentabilidad_evaluaciones ADD CONSTRAINT bvare_resultado_chk CHECK (resultado IN ('cumple', 'incumple', 'incompleta', 'sin_politica', 'no_aplica'))");

        Schema::create('bylopez_va_rentabilidad_evaluacion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('familia_id')->nullable();
            $table->string('referencia', 191)->nullable();
            $table->string('sku', 191)->nullable();
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_neto_unitario', 14, 4);
            $table->decimal('costo_unitario', 14, 4)->nullable();
            $table->string('estado_costo', 20);
            $table->foreignId('politica_id')->nullable();
            $table->decimal('ganancia_requerida_unitaria', 14, 4)->nullable();
            $table->decimal('variable_unitario', 14, 4);
            $table->decimal('cargo_pct', 7, 4);
            $table->decimal('comision_unitaria', 14, 4)->nullable();
            $table->decimal('contribucion_unitaria', 14, 4)->nullable();
            $table->decimal('precio_minimo', 14, 2)->nullable();
            $table->string('resultado', 20);
            $table->timestamps();

            $table->unique('order_item_id', 'bvarel_item_unique');
            $table->index('evaluacion_id', 'bvarel_evaluacion_idx');
            $table->foreign('evaluacion_id', 'bvarel_evaluacion_fk')->references('id')->on('bylopez_va_rentabilidad_evaluaciones')->restrictOnDelete();
            $table->foreign('order_item_id', 'bvarel_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('politica_id', 'bvarel_politica_fk')->references('id')->on('bylopez_va_rentabilidad_politicas')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_rentabilidad_evaluacion_lineas ADD CONSTRAINT bvarel_resultado_chk CHECK (resultado IN ('cumple', 'incumple', 'incompleta', 'sin_politica', 'no_aplica'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_rentabilidad_evaluacion_lineas');
        Schema::dropIfExists('bylopez_va_rentabilidad_evaluaciones');
    }
};
