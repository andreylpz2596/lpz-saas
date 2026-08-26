<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bylopez_cc_ordenes_corte_detalles', 'fecha_solicitud')) {
            return;
        }

        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->date('fecha_solicitud')->nullable()->after('talla');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bylopez_cc_ordenes_corte_detalles', 'fecha_solicitud')) {
            return;
        }

        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->dropColumn('fecha_solicitud');
        });
    }
};
