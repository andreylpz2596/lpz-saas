<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Domicilios locales F1 — CAPACIDADES de la transportadora (ADITIVA).
 *
 * El `tipo` describe QUE CLASE de transportador es (domiciliario, plataforma, nacional...). Las CAPACIDADES dicen QUE
 * PUEDE HACER, y son independientes del tipo (decision de Camilo, 2026-10-11): un domiciliario puede no llevar cuenta
 * corriente y una plataforma nunca la lleva. Ninguna regla de negocio se deduce del nombre ni solo del tipo.
 *
 * - `liquida_cuenta_corriente`: sus despachos alimentaran la cuenta corriente y las liquidaciones (F3/F4).
 * - `genera_guia_interna`: ByLopez le genera la guia interna BL-xxxxx (F2). En F1 solo se guarda la capacidad.
 *
 * Ambas nacen en 0: las filas existentes no cambian de comportamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_lg_transportadoras', function (Blueprint $table) {
            $table->boolean('liquida_cuenta_corriente')->default(false)->after('tipo');
            $table->boolean('genera_guia_interna')->default(false)->after('liquida_cuenta_corriente');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_lg_transportadoras', function (Blueprint $table) {
            $table->dropColumn(['liquida_cuenta_corriente', 'genera_guia_interna']);
        });
    }
};
