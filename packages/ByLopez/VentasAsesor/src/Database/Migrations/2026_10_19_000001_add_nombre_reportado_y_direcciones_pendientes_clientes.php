<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clientes historicos — dos garantias de evidencia (ADITIVO, sobre 2026_10_18_000001):
 *
 * 1. `nombre_reportado` en el antecedente: el NOMBRE COMPLETO tal como lo reporto el proveedor. La separacion en nombre y apellidos
 *    de `customers` es una propuesta (convencion), no una identidad comprobada: el original no se pierde.
 * 2. `bylopez_va_cliente_direcciones_pendientes`: direcciones que el archivo trae pero que NO se pudieron guardar como direccion del
 *    cliente (ciudad ambigua, no encontrada, sin ciudad...). Conservan el TEXTO ORIGINAL y el motivo, y se muestran como
 *    «Dirección pendiente de completar». No son filas rechazadas: el cliente y el antecedente si se guardaron.
 *    Se resuelven (se retiran) cuando el cliente recibe una direccion equivalente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_cliente_envios_historicos', function (Blueprint $table) {
            $table->string('nombre_reportado', 191)->nullable()->after('numero_guia');
        });

        Schema::create('bylopez_va_cliente_direcciones_pendientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            // sha1 de (direccion normalizada | ciudad normalizada): una misma direccion pendiente no se duplica al repetir el archivo.
            $table->char('clave', 40);
            $table->string('direccion_original', 255);
            $table->string('ciudad_original', 191)->nullable();
            $table->string('departamento_original', 100)->nullable();
            $table->string('motivo', 40);
            $table->unsignedBigInteger('carga_id')->nullable();
            $table->unsignedInteger('fila_origen')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'clave'], 'bylvadp_unica');
            $table->foreign('customer_id', 'bylvadp_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_cliente_direcciones_pendientes');
        Schema::table('bylopez_va_cliente_envios_historicos', function (Blueprint $table) {
            $table->dropColumn('nombre_reportado');
        });
    }
};
