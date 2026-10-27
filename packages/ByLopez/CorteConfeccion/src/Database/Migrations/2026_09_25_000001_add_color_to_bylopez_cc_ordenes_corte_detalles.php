<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 - Creacion V2 de Orden de Corte.
 *
 * Cada detalle V2 corresponde a un simple exacto (linea + color + talla) y el
 * cortador/Bodega deben ver el COLOR de forma explicita. `color` es un snapshot
 * legible del color estructurado (etiqueta de la opcion Bagisto al crear la
 * orden, estable aunque la opcion se renombre despues). Nullable: los detalles
 * historicos y los creados por el flujo legacy quedan en NULL; no se recalcula
 * ni se convierte nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->string('color')->nullable()->after('genero_linea');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
