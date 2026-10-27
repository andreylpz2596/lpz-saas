<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clientes historicos desde «Envios completos»: DATOS DEL CLIENTE PENDIENTES DE COMPLETAR (ADITIVO).
 *
 * Bagisto exige `customers.last_name` y el servicio de alta de clientes comerciales no admite un apellido vacio. Un destinatario de UNA
 * sola palabra NO se rechaza: el cliente se crea con el apellido marcado «(pendiente)» y aqui queda registrado QUE falta y el TEXTO ORIGINAL
 * reportado por el proveedor. No se inventa ningun dato: el marcador es explicito y editable desde la ficha del cliente. La pendiente
 * deja de mostrarse sola cuando el apellido deja de ser el marcador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_cliente_datos_pendientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customer_id');
            $table->string('campo', 30);
            $table->string('texto_original', 191);
            $table->string('motivo', 60);
            $table->unsignedBigInteger('carga_id')->nullable();
            $table->unsignedInteger('fila_origen')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'campo'], 'bylvadpd_unica');
            $table->foreign('customer_id', 'bylvadpd_customer_fk')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_cliente_datos_pendientes');
    }
};
