<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Código único (SKU) por patrón «número de color + público + línea + talla» (Doc. 33). ADITIVA: el número de cada color.
 *
 * - Un color (opción del atributo `color`) tiene UN número, el mismo en Dama, Hombre y Niño (115 = Verde Cali).
 * - Lo siembra `ventas-asesor:sku-normalizar` desde el catálogo; un color nuevo recibe el siguiente número libre la primera
 *   vez que se guarda un producto con él (ficha de Bagisto o Cargues).
 * - Sin FK a attribute_options: si se borra la opción, el número queda reservado y no se reutiliza para otro color.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_sku_colores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('attribute_option_id')->unique('bvaskuc_opcion_uq');
            $table->unsignedSmallInteger('codigo')->unique('bvaskuc_codigo_uq');
            $table->string('color_snapshot', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_sku_colores');
    }
};
