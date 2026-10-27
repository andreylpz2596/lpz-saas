<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_ordenes_confeccion_retornos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('orden_confeccion_id')->constrained('bylopez_cc_ordenes_confeccion', 'id', 'bccofr_orden_confeccion_fk')->cascadeOnDelete();
            $table->foreignId('taller_id')->constrained('bylopez_cc_talleres', 'id', 'bccofr_taller_fk')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->dateTime('fecha_reporte_taller');
            $table->unsignedInteger('reportado_por')->nullable();
            $table->dateTime('fecha_recepcion_bodega')->nullable();
            $table->unsignedInteger('recibido_por')->nullable();
            $table->string('estado')->default('pendiente_bodega')->index();
            $table->text('observacion_taller')->nullable();
            $table->text('observacion_bodega')->nullable();
            $table->timestamps();

            $table->unique(['orden_confeccion_id', 'numero'], 'bccofr_orden_numero_unique');
            $table->foreign('reportado_por', 'bccofr_reportado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('recibido_por', 'bccofr_recibido_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_ordenes_confeccion_retorno_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retorno_id')->constrained('bylopez_cc_ordenes_confeccion_retornos', 'id', 'bccofrd_retorno_fk')->cascadeOnDelete();
            $table->foreignId('orden_confeccion_detalle_id')->constrained('bylopez_cc_ordenes_confeccion_detalles', 'id', 'bccofrd_detalle_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('producto_sku')->nullable();
            $table->string('producto_nombre')->nullable();
            $table->string('talla')->nullable();
            $table->unsignedInteger('cantidad_reportada');
            $table->unsignedInteger('cantidad_recibida')->nullable();
            $table->unsignedInteger('cantidad_defectuosa')->default(0);
            $table->unsignedInteger('cantidad_faltante')->nullable();
            $table->text('observacion_bodega')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_ordenes_confeccion_retorno_detalles');
        Schema::dropIfExists('bylopez_cc_ordenes_confeccion_retornos');
    }
};
