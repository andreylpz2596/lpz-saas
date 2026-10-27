<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Domicilios locales F1 (cierre, D1): domiciliario que el asesor elige mientras construye la venta (opcional). Al
 * confirmar, VentasAsesor lo comunica a Logistica (contrato DomiciliarioDeVenta), que lo guarda con el pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->foreignId('transportadora_id')->nullable()->after('order_id');

            $table->foreign('transportadora_id', 'bvabv_transportadora_fk')->references('id')->on('bylopez_lg_transportadoras')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->dropForeign('bvabv_transportadora_fk');
            $table->dropColumn('transportadora_id');
        });
    }
};
