<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 03C (D-39) — septima migracion del modulo, ADITIVA. No toca tablas del core.
 *
 * SOLICITUD COMERCIAL: lo que el cliente quiere comprar, fuera del Cart de Bagisto. El
 * Cart solo puede contener lo que Bagisto procesa hoy (sin backorders, nunca mas que el
 * stock vendible); "pidio 100, hay 30" no cabe en el. Ninguna de las dos reserva stock.
 *
 * Minima a proposito (Doc. 05 §26):
 * - Sin producto padre: se deriva de products.parent_id (el simple es su propio padre).
 * - Sin cart_item_id: Bagisto consolida una linea por variante, borra y recrea items y
 *   borra el carrito al confirmar; lo "en carrito" se calcula por (cart_id, variante).
 * - Sin snapshots de SKU, nombre ni precio: el borrador no es historico; se leen en
 *   tiempo real y el precio sale siempre de Bagisto con RT-03.
 * - Sin external_reference: la operacion crear rechaza duplicados (UNIQUE) y cambiar
 *   cantidad es absoluta, ambas idempotentes ante reenvios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_borrador_solicitudes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('borrador_id');

            // La variante concreta que se vende: hijo del configurable o simple sin padre.
            // `products.id` es increments().
            $table->unsignedInteger('variante_id');

            $table->unsignedInteger('cantidad_solicitada');

            $table->timestamps();

            // UNA intencion agregada por variante y borrador, la misma granularidad con que
            // Bagisto consolida lineas. El reparto futuro por origen (stock, produccion,
            // fabricar; Doc. 05 §23.4) cuelga de esta fila, no la multiplica.
            $table->unique(['borrador_id', 'variante_id'], 'bvabs_borrador_variante_unique');

            // Partes del borrador: el borrador nunca se borra (se descarta), asi que las
            // solicitudes sobreviven al carrito como rastro de lo pedido.
            $table->foreign('borrador_id', 'bvabs_borrador_fk')
                ->references('id')->on('bylopez_va_borradores_venta')->cascadeOnDelete();

            // CASCADE, como cart_items: sin la variante no hay nada que vender ni que
            // mostrar, y RESTRICT impediria borrar productos desde el catalogo de Bagisto.
            $table->foreign('variante_id', 'bvabs_variante_fk')
                ->references('id')->on('products')->cascadeOnDelete();
        });
    }

    /** Solo su propia tabla. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_borrador_solicitudes');
    }
};
