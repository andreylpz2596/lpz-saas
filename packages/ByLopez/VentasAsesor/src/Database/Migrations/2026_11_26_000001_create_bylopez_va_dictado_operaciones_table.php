<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 34 · Pedido por voz — guardado seguro (jornada 2026-11-26). ADITIVA: solo su propia tabla.
 *
 * «Agregar a la venta» suma cantidades dictadas. Sumar no es idempotente por si mismo: si la respuesta se pierde o la
 * asesora toca dos veces, reaplicar volveria a sumar. Cada «Agregar» lleva un identificador de operacion generado por la
 * pantalla; esta tabla lo registra EN LA MISMA transaccion que modifica la venta, con UNIQUE(admin, operacion):
 *  - un reintento o un doble clic (incluso simultaneo) encuentra la operacion y devuelve lo que se aplico, sin sumar otra vez;
 *  - si la transaccion falla, la fila no queda y la operacion se puede reintentar.
 * No existe otra tabla que guarde esto: la referencia externa del borrador solo cubre su creacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_dictado_operaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id');
            $table->string('operacion', 64);
            $table->unsignedBigInteger('borrador_id')->nullable();
            $table->string('estado', 20);
            // Lo aplicado: SKU, cantidad antes, sumada y final por variante (lo que recibe un reintento).
            $table->json('resumen')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'operacion'], 'bvado_admin_operacion_unique');
            $table->index('borrador_id', 'bvado_borrador_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_dictado_operaciones');
    }
};
