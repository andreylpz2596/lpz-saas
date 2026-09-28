<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 03F — octava migracion del modulo, ADITIVA. Solo toca su propia tabla.
 *
 * D-55 (aprobada por Camilo Gaitan): identidad del INTENTO de confirmacion del borrador.
 *
 * - `confirmation_token`: UUID de un intento concreto. Es el fencing token: el cerco de
 *   `checkout.order.save.before` y el enlace de `checkout.order.save.after` solo aceptan el
 *   token vigente, asi que un intento viejo que despierta no crea ni enlaza un pedido.
 *   NO es `external_reference` (identidad de la creacion del borrador) ni sustituye a
 *   `order_id` UNIQUE (un borrador, un pedido).
 * - `confirmacion_iniciada_en`: inicio del lease del intento. Separado de `updated_at`,
 *   que cambia con cualquier guardado. Solo decide CUANDO otro intento puede recuperar;
 *   la seguridad la da el token.
 *
 * Sin indices: todas las consultas que los usan filtran por `id` (clave primaria).
 * Nullable y sin default: los borradores existentes (y todo borrador fuera de
 * `confirmando`) quedan con ambos a NULL, que es su valor correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->char('confirmation_token', 36)->nullable()->after('order_id');
            $table->timestamp('confirmacion_iniciada_en')->nullable()->after('confirmation_token');
        });
    }

    /** Solo las dos columnas de esta migracion. */
    public function down(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->dropColumn(['confirmation_token', 'confirmacion_iniciada_en']);
        });
    }
};
