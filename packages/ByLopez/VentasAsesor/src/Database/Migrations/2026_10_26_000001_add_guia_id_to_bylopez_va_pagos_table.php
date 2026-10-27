<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * C6 — vinculo inequivoco PAGO ↔ GUIA. Un cobro hecho por el transportador (contra entrega o reportado por la transportadora)
 * pertenece a UNA guia; el envio se deriva (guia ↔ shipment es 1:1). Los pagos normales (transferencia, efectivo directo) lo dejan
 * NULL. Aditiva: ningun pago historico se vincula ni se modifica (sin backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_pagos', function (Blueprint $table) {
            $table->unsignedBigInteger('guia_id')->nullable()->after('grupo_pago');
            $table->index('guia_id', 'bvapag_guia_idx');
            $table->foreign('guia_id', 'bvapag_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_va_pagos', function (Blueprint $table) {
            $table->dropForeign('bvapag_guia_fk');
            $table->dropIndex('bvapag_guia_idx');
            $table->dropColumn('guia_id');
        });
    }
};
