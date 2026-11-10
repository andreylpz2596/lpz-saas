<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H07: la familia (referencia) de cada detalle se CONSERVA al crearlo. OrdenTarifaService::combinaciones() la derivaba
 * en vivo de products.attribute_family_id; si el catalogo reasignaba un producto a otra familia, la lectura historica de
 * una orden cambiaba y sus tarifas guardadas quedaban huerfanas. Aditiva: columna nullable + relleno de lo existente
 * con la familia ACTUAL del producto (el unico dato disponible; no se reescribe nada despues).
 */
return new class extends Migration
{
    private const TABLAS = ['bylopez_cc_ordenes_corte_detalles', 'bylopez_cc_ordenes_confeccion_detalles'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->unsignedInteger('attribute_family_id')->nullable()->after('product_id');
            });

            DB::statement("UPDATE {$tabla} d JOIN products p ON p.id = d.product_id SET d.attribute_family_id = p.attribute_family_id WHERE d.attribute_family_id IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('attribute_family_id');
            });
        }
    }
};
