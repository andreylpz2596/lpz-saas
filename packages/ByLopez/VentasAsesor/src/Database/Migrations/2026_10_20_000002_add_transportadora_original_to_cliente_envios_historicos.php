<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antecedentes de envios: conserva la TRANSPORTADORA ORIGINAL tal como la informo el archivo (evidencia). Cuando no tiene equivalencia, la clave
 * del envio pasa a ser «PEND:huella» en vez de '' (sin determinar): dos transportadoras distintas con la misma guia ya no se mezclan.
 * Aditiva y reversible; no modifica ni borra filas existentes (las que tengan '' siguen siendo «sin determinar»: el archivo no la traia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bylopez_va_cliente_envios_historicos', 'transportadora_original')) {
            Schema::table('bylopez_va_cliente_envios_historicos', function (Blueprint $table) {
                $table->string('transportadora_original', 120)->nullable()->after('transportadora_clave');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bylopez_va_cliente_envios_historicos', 'transportadora_original')) {
            Schema::table('bylopez_va_cliente_envios_historicos', function (Blueprint $table) {
                $table->dropColumn('transportadora_original');
            });
        }
    }
};
