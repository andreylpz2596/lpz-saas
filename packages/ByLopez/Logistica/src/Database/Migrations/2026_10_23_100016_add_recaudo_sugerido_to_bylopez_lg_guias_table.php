<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * C1 — TRAZABILIDAD del recaudo por guia (ADITIVA, nullable: las guias anteriores quedan con NULL y no se reinterpretan).
 *
 * - `recaudo_sugerido`: lo que el SERVIDOR calculo para ESA guia al despachar (mercancia historica de sus unidades + flete
 *   cobrado al cliente si es la primera guia, acotado al saldo exigible que aun no esta comprometido). `recaudo_esperado`
 *   (columna existente) sigue siendo lo que quedo registrado; si difieren, la guia fue MODIFICADA a mano.
 * - `motivo_recaudo`: obligatorio cuando `recaudo_esperado` es distinto de `recaudo_sugerido`. Quien lo modifico es el actor
 *   del despacho (`actor_admin_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->decimal('recaudo_sugerido', 12, 2)->nullable()->after('recaudo_estado');
            $table->string('motivo_recaudo', 500)->nullable()->after('recaudo_sugerido');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->dropColumn(['recaudo_sugerido', 'motivo_recaudo']);
        });
    }
};
