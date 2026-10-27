<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Domicilios locales F1 (cierre, D1): domiciliario ELEGIDO por el asesor en Nueva venta, asociado al pedido. Es una
 * preferencia para el despacho (el drawer la preselecciona y BODEGA puede cambiarla), no una asignacion: no mueve
 * dinero, inventario ni Cartera. Un pedido, a lo sumo una preferencia (order_id UNIQUE). No toca `orders` de Bagisto.
 *
 * Sin snapshots: la transportadora nunca se borra (solo se desactiva; FK restrict) y quien eligio queda por id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_preferencias_entrega', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->foreignId('transportadora_id');
            $table->string('origen', 20)->default('venta');
            $table->unsignedInteger('seleccionado_por_admin_id')->nullable();
            $table->timestamps();

            $table->unique('order_id', 'bylgpe_order_unique');
            $table->foreign('order_id', 'bylgpe_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('transportadora_id', 'bylgpe_transportadora_fk')->references('id')->on('bylopez_lg_transportadoras')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_preferencias_entrega');
    }
};
