<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reasignacion de prendas pendientes entre talleres (aditiva, sin tocar datos historicos).
 *
 * - `bylopez_cc_reasignaciones`: la operacion auditada (quien, cuando, por que, situacion fisica, idempotencia).
 * - `bylopez_cc_reasignacion_lineas`: una fila por variante exacta (product_id) movida, con el detalle origen y el destino.
 * - `bylopez_cc_ordenes_confeccion_detalles.cantidad_reasignada_salida`: unidades que el detalle ENTREGO a otro taller.
 *   Responsabilidad vigente = cantidad_entregada - cantidad_reasignada_salida (la asignacion original no se edita).
 * - `bylopez_cc_ordenes_confeccion_detalles.cantidad_en_transito`: unidades recibidas por reasignacion cuyo traslado fisico
 *   aun no se confirma; no son reportables hasta confirmarlo.
 * - `bylopez_cc_ordenes_confeccion.reasignacion_origen_id`: marca la orden destino creada por una reasignacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_reasignaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('orden_corte_id')->constrained('bylopez_cc_ordenes_corte', 'id', 'bccra_orden_corte_fk')->restrictOnDelete();
            $table->foreignId('taller_origen_id')->constrained('bylopez_cc_talleres', 'id', 'bccra_taller_origen_fk')->restrictOnDelete();
            $table->foreignId('taller_destino_id')->constrained('bylopez_cc_talleres', 'id', 'bccra_taller_destino_fk')->restrictOnDelete();
            $table->foreignId('orden_confeccion_origen_id')->constrained('bylopez_cc_ordenes_confeccion', 'id', 'bccra_orden_origen_fk')->restrictOnDelete();
            $table->foreignId('orden_confeccion_destino_id')->constrained('bylopez_cc_ordenes_confeccion', 'id', 'bccra_orden_destino_fk')->restrictOnDelete();
            // en_transito | aplicada | revertida | cancelada
            $table->string('estado')->default('aplicada')->index();
            // sin_entrega_fisica | entregada_verificada
            $table->string('situacion_fisica');
            $table->text('motivo');
            $table->text('observacion')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('reasignada_por')->nullable();
            $table->dateTime('recibida_en')->nullable();
            $table->unsignedInteger('recibida_por')->nullable();
            $table->dateTime('cerrada_en')->nullable();
            $table->unsignedInteger('cerrada_por')->nullable();
            $table->text('motivo_cierre')->nullable();
            $table->timestamps();

            $table->foreign('reasignada_por', 'bccra_reasignada_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('recibida_por', 'bccra_recibida_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('cerrada_por', 'bccra_cerrada_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_reasignacion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reasignacion_id')->constrained('bylopez_cc_reasignaciones', 'id', 'bccral_reasignacion_fk')->cascadeOnDelete();
            $table->foreignId('detalle_origen_id')->constrained('bylopez_cc_ordenes_confeccion_detalles', 'id', 'bccral_detalle_origen_fk')->restrictOnDelete();
            $table->foreignId('detalle_destino_id')->constrained('bylopez_cc_ordenes_confeccion_detalles', 'id', 'bccral_detalle_destino_fk')->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('producto_sku')->nullable();
            $table->string('producto_nombre')->nullable();
            $table->string('talla')->nullable();
            $table->unsignedInteger('cantidad');
            // Unidades que el operador declaro en proceso en el taller origen al registrar (evidencia; no se mueven).
            $table->unsignedInteger('cantidad_en_proceso_declarada')->default(0);
            $table->timestamps();

            $table->unique(['reasignacion_id', 'detalle_origen_id'], 'bccral_reasignacion_detalle_unique');
        });

        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_reasignada_salida')->default(0)->after('cantidad_entregada');
            $table->unsignedInteger('cantidad_en_transito')->default(0)->after('cantidad_reasignada_salida');
        });

        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->unsignedBigInteger('reasignacion_origen_id')->nullable()->index()->after('observacion');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->dropColumn('reasignacion_origen_id');
        });

        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->dropColumn(['cantidad_reasignada_salida', 'cantidad_en_transito']);
        });

        Schema::dropIfExists('bylopez_cc_reasignacion_lineas');
        Schema::dropIfExists('bylopez_cc_reasignaciones');
    }
};
