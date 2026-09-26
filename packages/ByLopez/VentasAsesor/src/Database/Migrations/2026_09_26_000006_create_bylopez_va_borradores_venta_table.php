<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 03B — sexta migracion del modulo, ADITIVA. No toca tablas del core.
 *
 * Control comercial del borrador de venta alrededor del Cart REAL de Bagisto
 * (aprobado por Camilo Gaitan). NO duplica lo que ya guarda `cart`: cliente, snapshots
 * de nombre/email, lineas, precios y totales viven alli. Aqui solo lo que el carrito no
 * sabe: de quien es el borrador, hasta cuando, en que estado de negocio esta, por que
 * canal/mecanismo llego (para la atribucion explicita al confirmar), su referencia
 * externa (idempotencia) y el pedido que produjo.
 *
 * `propietario_admin_id` es el DUEÑO DEL BORRADOR (puede cambiar al reclamarlo), no el
 * asesor comercial de la venta: la propiedad comercial se decide al confirmar (D-27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->id();

            // `cart.id` es increments(). NULL cuando el carrito ya no existe: Bagisto lo
            // borra al crear el pedido (Cart::removeCart) y el modulo al descartar.
            $table->unsignedInteger('cart_id')->nullable();

            // Sin FK a admins, igual que la atribucion (02A1): dar de baja a un admin no
            // debe borrar ni bloquear el historial del borrador.
            $table->unsignedInteger('propietario_admin_id');
            $table->unsignedInteger('creado_por_admin_id');

            $table->string('estado', 20)->default('activo');

            // Contexto de atribucion que no puede deducirse del carrito.
            $table->string('canal_venta', 30);
            $table->string('mecanismo_creacion', 30);

            // Idempotencia de canales externos (API, n8n, WhatsApp, voz). Nunca texto libre.
            $table->string('external_reference', 191)->nullable();

            // Fin de la ventana en la que solo el propietario puede trabajarlo.
            $table->timestamp('asignado_hasta')->nullable();

            $table->unsignedInteger('order_id')->nullable();

            $table->timestamps();

            $table->unique('cart_id', 'bvabv_cart_unique');
            $table->unique('order_id', 'bvabv_order_unique');
            // Una misma solicitud externa no crea dos borradores (por mecanismo). MySQL admite
            // varios NULL: los borradores del panel sin referencia no chocan.
            $table->unique(['mecanismo_creacion', 'external_reference'], 'bvabv_mecanismo_referencia_unique');
            $table->index(['propietario_admin_id', 'estado'], 'bvabv_propietario_estado_idx');

            $table->foreign('cart_id', 'bvabv_cart_fk')->references('id')->on('cart')->nullOnDelete();

            // RESTRICT, como la atribucion: un pedido con borrador no desaparece en silencio.
            $table->foreign('order_id', 'bvabv_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    /** Solo su propia tabla. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_borradores_venta');
    }
};
