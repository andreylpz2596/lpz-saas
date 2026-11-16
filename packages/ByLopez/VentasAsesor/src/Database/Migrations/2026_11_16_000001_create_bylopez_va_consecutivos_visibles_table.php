<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CONSECUTIVOS VISIBLES de pedidos y facturas (BYL-00000001-CO, FAC-BYL-00000001-2026). ADITIVA, sin backfill.
 * Bagisto calcula el número visible con el id de la última fila + 1, así que con tablas vacías y AUTO_INCREMENT conservado
 * saltaría de 1 a «último id interno + 1». Esta tabla guarda el último número visible usado por clave; la fila se bloquea
 * dentro de la transacción que crea el documento (si la creación se revierte, el número también). Solo se usa si en
 * Configuración › Ventas se elige la clase generadora de ByLopez (ver ByLopez\VentasAsesor\Generators).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_consecutivos_visibles', function (Blueprint $table) {
            $table->string('clave', 40)->primary();
            $table->unsignedBigInteger('ultimo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_consecutivos_visibles');
    }
};
