<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * COTIZACIONES de una venta en curso. ADITIVA, sin backfill. No es un pedido ni reserva stock: es una FOTO congelada (cliente, lineas,
 * totales y datos de pago del momento) para poder reabrir exactamente lo que se envio aunque el catalogo o los precios cambien.
 * Sin FK al borrador a proposito: descartar la venta en curso no debe borrar lo que ya se le envio al cliente.
 * Cada vez que la venta cambia y se vuelve a cotizar nace OTRA fila (version + 1); una fila nunca se edita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_cotizaciones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('borrador_id');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('customer_id')->nullable();

            $table->date('valida_hasta');
            $table->char('huella', 64);

            $table->json('cliente');
            $table->json('lineas');
            $table->json('totales');
            $table->json('entrega')->nullable();
            $table->json('datos_pago')->nullable();
            $table->string('notas', 500)->nullable();

            $table->unsignedInteger('creada_por_admin_id');
            $table->string('creada_por_nombre', 191)->nullable();
            $table->unsignedInteger('propietario_admin_id');
            $table->timestamps();

            $table->unique(['borrador_id', 'version'], 'bvacot_borrador_version_unique');
            $table->index('propietario_admin_id', 'bvacot_propietario_idx');
            $table->index('customer_id', 'bvacot_cliente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_cotizaciones');
    }
};
