<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * DESPACHO V1 — GUIA DEL ENVIO y su DOCUMENTO (ByLopez\Logistica es el propietario; nada en tablas de Bagisto).
 *
 * Dos cosas separadas (decision de Camilo, 2026-10-09):
 *  - `bylopez_lg_guias`: el DATO del envio. Numero de guia, tracking, transportadora (codigo del catalogo), flete y
 *    quien lo paga, recaudo esperado (NO es dinero recibido: no toca Cartera) y origen. Una guia por Shipment.
 *  - `bylopez_lg_guia_archivos`: el DOCUMENTO (PDF), por versiones, en disco PRIVADO. Un reemplazo crea una version
 *    nueva (motivo obligatorio, quien, cuando) y marca la anterior como reemplazada; nunca se sobrescribe.
 *
 * Preparada para APIs (99 Envios, etc.): una integracion escribe en ESTAS MISMAS filas con `generada_por = api`,
 * `proveedor_referencia` y un archivo con `origen_archivo = api`. No existe otro flujo paralelo.
 *
 * - `idempotency_key` UNIQUE + `huella`: doble clic o reintento = la misma guia.
 * - (`transportadora_codigo`, `numero_guia`) UNIQUE: la misma guia no se registra dos veces.
 * - Actor sin FK con nombre snapshot; FK RESTRICT a orders y shipments (ids int(10) unsigned de Bagisto).
 * - Inmutable salvo `tracking`, `proveedor_referencia` y `recaudo_estado` (los completara una API o la conciliacion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_guias', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('shipment_id');

            $table->string('transportadora_codigo', 40);
            $table->string('numero_guia', 100);
            $table->string('tracking', 500)->nullable();

            $table->string('origen_guia', 30);
            $table->string('generada_por', 20)->default('operaria');
            $table->string('proveedor_referencia', 191)->nullable();

            $table->decimal('valor_flete', 12, 2)->nullable();
            $table->string('flete_pagador', 30);
            $table->decimal('recaudo_esperado', 12, 2)->default(0);
            $table->string('recaudo_estado', 30)->default('no_aplica');
            $table->char('moneda', 3)->nullable();

            $table->string('observacion', 500)->nullable();

            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->string('idempotency_key', 100);
            $table->char('huella', 64);
            $table->timestamps();

            $table->unique('shipment_id', 'bylgg_shipment_unique');
            $table->unique('idempotency_key', 'bylgg_idem_unique');
            $table->unique(['transportadora_codigo', 'numero_guia'], 'bylgg_guia_unique');
            $table->index('order_id', 'bylgg_order_idx');
            $table->index('recaudo_estado', 'bylgg_recaudo_idx');

            $table->foreign('order_id', 'bylgg_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('shipment_id', 'bylgg_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_origen_chk CHECK (origen_guia IN ('manual', 'directa_transportadora', '99envios'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_generada_chk CHECK (generada_por IN ('operaria', 'api'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_pagador_chk CHECK (flete_pagador IN ('cliente_al_recibir', 'bylopez', 'incluido_en_venta', 'otro'))");
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_recaudo_estado_chk CHECK (recaudo_estado IN ('no_aplica', 'pendiente_liquidar', 'liquidado'))");
        DB::statement('ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_montos_chk CHECK ((valor_flete IS NULL OR valor_flete >= 0) AND recaudo_esperado >= 0)');
        DB::statement("ALTER TABLE bylopez_lg_guias ADD CONSTRAINT bylgg_recaudo_coherente_chk CHECK ((recaudo_estado = 'no_aplica') = (recaudo_esperado = 0))");

        Schema::create('bylopez_lg_guia_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id');
            $table->unsignedInteger('version');
            // 1 = version vigente; NULL = reemplazada. El UNIQUE (guia, vigente) garantiza UNA sola vigente.
            $table->unsignedTinyInteger('vigente')->nullable();

            $table->string('origen_archivo', 20);
            $table->string('disco', 20);
            $table->string('ruta', 300);
            $table->string('nombre_original', 191)->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('tamano_bytes');
            $table->char('sha256', 64);

            $table->unsignedInteger('subido_por_admin_id')->nullable();
            $table->string('subido_por_name_snapshot', 191)->nullable();
            $table->string('motivo_reemplazo', 500)->nullable();
            $table->timestamp('reemplazado_en')->nullable();
            $table->timestamps();

            $table->unique(['guia_id', 'version'], 'bylgga_version_unique');
            $table->unique(['guia_id', 'vigente'], 'bylgga_vigente_unique');

            $table->foreign('guia_id', 'bylgga_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_guia_archivos ADD CONSTRAINT bylgga_origen_chk CHECK (origen_archivo IN ('manual', 'api'))");
        DB::statement('ALTER TABLE bylopez_lg_guia_archivos ADD CONSTRAINT bylgga_vigente_chk CHECK (vigente IS NULL OR vigente = 1)');
    }

    /** Con guias reales, revertir borra la trazabilidad del envio y deja PDFs huerfanos: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_guia_archivos');
        Schema::dropIfExists('bylopez_lg_guias');
    }
};
