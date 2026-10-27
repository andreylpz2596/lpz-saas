<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('inventory_source_id')->index();
            $table->string('tipo_movimiento', 50)->index();
            $table->integer('cantidad_anterior');
            $table->integer('cantidad_movimiento');
            $table->integer('cantidad_nueva');
            $table->foreignId('orden_confeccion_id')->nullable()->constrained('bylopez_cc_ordenes_confeccion')->nullOnDelete();
            $table->foreignId('retorno_id')->nullable()->constrained('bylopez_cc_ordenes_confeccion_retornos')->nullOnDelete();
            $table->foreignId('retorno_detalle_id')->nullable()->constrained('bylopez_cc_ordenes_confeccion_retorno_detalles')->nullOnDelete();
            $table->integer('admin_id')->unsigned()->nullable()->index();
            $table->string('nombre_usuario_snapshot')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();

            $table->unique(
                ['tipo_movimiento', 'retorno_detalle_id', 'inventory_source_id'],
                'bylopez_cc_inv_mov_retorno_tipo_source_unique'
            );
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('inventory_source_id')->references('id')->on('inventory_sources')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_inventario_movimientos');
    }
};
