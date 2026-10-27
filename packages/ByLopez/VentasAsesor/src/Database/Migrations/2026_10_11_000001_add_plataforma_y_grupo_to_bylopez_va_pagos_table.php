<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Plataforma de recaudo de las transferencias (Nequi, Bancolombia, Davivienda...) y agrupacion de pagos combinados.
 * ADITIVA y nullable: los pagos historicos no tienen plataforma ni grupo y no se tocan.
 *
 * - `plataforma`: codigo de `ventasasesor.pagos.plataformas_transferencia`. Solo aplica al medio `transferencia`.
 * - `grupo_pago`: identificador compartido por las lineas de un pago combinado (cada linea sigue siendo un Pago
 *   independiente, con su propia referencia, confirmacion y auditoria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_pagos', function (Blueprint $table) {
            $table->string('plataforma', 30)->nullable()->after('medio_cobro');
            $table->string('grupo_pago', 40)->nullable()->after('plataforma');
            $table->index('grupo_pago', 'bvapag_grupo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_va_pagos', function (Blueprint $table) {
            $table->dropIndex('bvapag_grupo_idx');
            $table->dropColumn(['plataforma', 'grupo_pago']);
        });
    }
};
