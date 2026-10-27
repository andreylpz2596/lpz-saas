<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->string('prioridad', 20)->default('normal')->after('cantidad_entregada');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->dropColumn('prioridad');
        });
    }
};
