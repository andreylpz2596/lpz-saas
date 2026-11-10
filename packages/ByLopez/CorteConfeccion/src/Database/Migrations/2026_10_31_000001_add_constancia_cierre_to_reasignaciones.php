<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custodia fisica: devolver al origen prendas que ya salieron del taller exige una constancia de devolucion
 * (quien las devolvio / referencia del traslado) ademas del motivo. Columna aditiva; las filas existentes quedan en NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_reasignaciones', function (Blueprint $table) {
            $table->text('constancia_cierre')->nullable()->after('motivo_cierre');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_reasignaciones', function (Blueprint $table) {
            $table->dropColumn('constancia_cierre');
        });
    }
};
