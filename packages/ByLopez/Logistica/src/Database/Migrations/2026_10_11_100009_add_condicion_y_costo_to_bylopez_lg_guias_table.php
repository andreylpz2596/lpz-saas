<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Domicilios locales F1 — SNAPSHOT de condicion de envio y costo del domicilio en la guia (ADITIVA, todo nullable: las
 * guias anteriores quedan con NULL y no se reinterpretan).
 *
 * - `condicion_envio`: gratis | por_pagar | cobrado_en_pedido | sin_definir, deducida del metodo de envio del pedido.
 * - `pagador_deducido` + `motivo_pagador`: lo que el sistema dedujo y, si la operaria eligio otro pagador, por que.
 * - Zona: `zona_id`, `zona_nombre_snapshot` y `zona_origen` (auto = coincidencia exacta con el catalogo; manual = la
 *   eligio la operaria; sin_zona = no se uso zona).
 * - Costo: `valor_flete` (columna existente) sigue siendo el COSTO FINAL REAL del servicio. Se agregan
 *   `tarifa_sugerida`, `costo_adicional` (= final - sugerida) y `motivo_costo` (obligatorio si difiere).
 * - `transportadora_tipo` y `liquida_cuenta_corriente`: capacidad de la transportadora AL DESPACHAR (un cambio posterior
 *   del catalogo no reinterpreta el despacho).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->string('condicion_envio', 20)->nullable()->after('flete_pagador');
            $table->string('pagador_deducido', 30)->nullable()->after('condicion_envio');
            $table->string('motivo_pagador', 500)->nullable()->after('pagador_deducido');
            $table->foreignId('zona_id')->nullable()->after('motivo_pagador');
            $table->string('zona_nombre_snapshot', 120)->nullable()->after('zona_id');
            $table->string('zona_origen', 10)->nullable()->after('zona_nombre_snapshot');
            $table->decimal('tarifa_sugerida', 12, 2)->nullable()->after('zona_origen');
            $table->decimal('costo_adicional', 12, 2)->nullable()->after('tarifa_sugerida');
            $table->string('motivo_costo', 500)->nullable()->after('costo_adicional');
            $table->string('transportadora_tipo', 20)->nullable()->after('transportadora_codigo');
            $table->boolean('liquida_cuenta_corriente')->nullable()->after('transportadora_tipo');

            $table->foreign('zona_id', 'bylgg_zona_fk')->references('id')->on('bylopez_lg_zonas')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_condicion_chk CHECK (condicion_envio IS NULL OR condicion_envio IN ('gratis', 'por_pagar', 'cobrado_en_pedido', 'sin_definir'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_zona_origen_chk CHECK (zona_origen IS NULL OR zona_origen IN ('auto', 'manual', 'sin_zona'))");
        DB::statement('ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_tarifa_chk CHECK (tarifa_sugerida IS NULL OR tarifa_sugerida >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_condicion_chk');
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_zona_origen_chk');
        DB::statement('ALTER TABLE bylopez_lg_guias DROP CONSTRAINT bylgg_tarifa_chk');

        Schema::table('bylopez_lg_guias', function (Blueprint $table) {
            $table->dropForeign('bylgg_zona_fk');
            $table->dropColumn([
                'condicion_envio', 'pagador_deducido', 'motivo_pagador', 'zona_id', 'zona_nombre_snapshot', 'zona_origen',
                'tarifa_sugerida', 'costo_adicional', 'motivo_costo', 'transportadora_tipo', 'liquida_cuenta_corriente',
            ]);
        });
    }
};
