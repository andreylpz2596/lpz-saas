<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Grupo comercial TEMPORAL por venta (GRP-01) — ADITIVA. El override vive en el borrador (por venta, nunca en el cliente):
 * ContextoPrecioComercial lo lee por cart_id al resolver el grupo de precios.
 *
 * - grupo_original_id: grupo habitual del cliente al iniciar (o al cambiar de cliente) la venta.
 * - grupo_efectivo_id: grupo con el que se calcula ESTA venta. Igual al original salvo override.
 * - grupo_override: true solo mientras un usuario autorizado mantiene un grupo distinto del habitual.
 * - grupo_override_customer_id: cliente para el que se aplico el override (defensa: si el carrito cambia de cliente y
 *   el override no se cancelo, el contexto de precios FALLA CERRADO en vez de heredarlo).
 * - grupo_eventos: contador de eventos del historial (para mostrar «Ver historial» sin consulta extra).
 *
 * Sin FK a customer_groups: un grupo borrado debe detectarse y rechazarse (fail closed), no anular el override en silencio.
 * Las ventas anteriores quedan con NULL / false = comportamiento de siempre (grupo actual del cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->unsignedInteger('grupo_original_id')->nullable()->after('transportadora_id');
            $table->unsignedInteger('grupo_efectivo_id')->nullable()->after('grupo_original_id');
            $table->boolean('grupo_override')->default(false)->after('grupo_efectivo_id');
            $table->unsignedInteger('grupo_override_customer_id')->nullable()->after('grupo_override');
            $table->unsignedSmallInteger('grupo_eventos')->default(0)->after('grupo_override_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_va_borradores_venta', function (Blueprint $table) {
            $table->dropColumn(['grupo_original_id', 'grupo_efectivo_id', 'grupo_override', 'grupo_override_customer_id', 'grupo_eventos']);
        });
    }
};
