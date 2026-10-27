<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * R4 (Doc. 21 §5.4, Doc. 22 §5.6, A-05, Doc. 23) — LIQUIDACIONES, AJUSTES y ABONOS al asesor. Es OTRA cuenta (ByLopez debe
 * al asesor): nunca se registra en Cartera (`bylopez_va_pagos`, cliente → ByLopez). No ejecuta transferencias: registra
 * pagos hechos fuera.
 *
 * - `bylopez_va_comision_liquidaciones`: borrador → aprobada (inmutable) · borrador/aprobada sin abonos → anulada (con
 *   motivo; libera sus lineas). Consecutivo LQC-000001 (UNIQUE). Una linea entra en UNA sola liquidacion
 *   (`comision_lineas.liquidacion_id`).
 * - `bylopez_va_comision_ajustes`: importes firmados con motivo. Nacen `pendiente_aprobacion`; solo un ajuste APROBADO puede
 *   incluirse, y solo si quien liquida lo elige expresamente: nada se compensa en automatico.
 * - `bylopez_va_comision_abonos`: pagos (completos o parciales) a una liquidacion aprobada. Saldo = total − Σ abonos +
 *   Σ reversos. Un abono no se edita ni se borra: se REVIERTE una sola vez (`revierte_id` UNIQUE) con motivo.
 *   `idempotency_key` UNIQUE: doble clic o reintento no registran dos pagos. Comprobante en el disco PRIVADO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consecutivo');
            $table->unsignedInteger('asesor_admin_id');
            $table->string('asesor_name_snapshot', 191)->nullable();
            $table->date('periodo_desde');
            $table->date('periodo_hasta');
            $table->string('estado', 20)->default('borrador');
            $table->unsignedInteger('lineas_count');
            $table->decimal('total_lineas', 14, 2);
            $table->decimal('total_ajustes', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('observacion', 500)->nullable();
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('creado_por_admin_id');
            $table->string('creado_por_name_snapshot', 191);
            $table->unsignedInteger('aprobada_por_admin_id')->nullable();
            $table->string('aprobada_por_name_snapshot', 191)->nullable();
            $table->dateTime('aprobada_en')->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();
            $table->dateTime('anulada_en')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();

            $table->unique('consecutivo', 'bvacq_consecutivo_unique');
            $table->unique('idempotency_key', 'bvacq_idem_unique');
            $table->index(['asesor_admin_id', 'estado'], 'bvacq_asesor_idx');
        });

        DB::statement("ALTER TABLE bylopez_va_comision_liquidaciones ADD CONSTRAINT bvacq_estado_chk CHECK (estado IN ('borrador', 'aprobada', 'anulada'))");
        DB::statement('ALTER TABLE bylopez_va_comision_liquidaciones ADD CONSTRAINT bvacq_total_chk CHECK (total >= 0 AND periodo_hasta >= periodo_desde)');
        DB::statement("ALTER TABLE bylopez_va_comision_liquidaciones ADD CONSTRAINT bvacq_aprobada_chk CHECK (estado <> 'aprobada' OR aprobada_en IS NOT NULL)");
        DB::statement("ALTER TABLE bylopez_va_comision_liquidaciones ADD CONSTRAINT bvacq_anulada_chk CHECK ((estado = 'anulada') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL))");

        Schema::table('bylopez_va_comision_lineas', function (Blueprint $table) {
            $table->foreign('liquidacion_id', 'bvacl_liquidacion_fk')->references('id')->on('bylopez_va_comision_liquidaciones')->restrictOnDelete();
        });

        Schema::create('bylopez_va_comision_ajustes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id');
            $table->foreignId('linea_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->decimal('monto', 14, 2);
            $table->string('origen', 30);
            $table->string('motivo', 500);
            $table->string('estado', 24)->default('pendiente_aprobacion');
            $table->foreignId('liquidacion_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->unsignedInteger('resuelto_por_admin_id')->nullable();
            $table->string('resuelto_por_name_snapshot', 191)->nullable();
            $table->dateTime('resuelto_en')->nullable();
            $table->string('motivo_resolucion', 500)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'bvacj_idem_unique');
            $table->index(['asesor_admin_id', 'estado'], 'bvacj_asesor_idx');
            $table->foreign('linea_id', 'bvacj_linea_fk')->references('id')->on('bylopez_va_comision_lineas')->restrictOnDelete();
            $table->foreign('liquidacion_id', 'bvacj_liquidacion_fk')->references('id')->on('bylopez_va_comision_liquidaciones')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_comision_ajustes ADD CONSTRAINT bvacj_estado_chk CHECK (estado IN ('pendiente_aprobacion', 'aprobado', 'rechazado'))");
        DB::statement("ALTER TABLE bylopez_va_comision_ajustes ADD CONSTRAINT bvacj_origen_chk CHECK (origen IN ('manual', 'anulacion_posterior', 'reasignacion_posterior', 'devolucion_posterior'))");
        DB::statement('ALTER TABLE bylopez_va_comision_ajustes ADD CONSTRAINT bvacj_monto_chk CHECK (monto <> 0)');
        DB::statement("ALTER TABLE bylopez_va_comision_ajustes ADD CONSTRAINT bvacj_liquidacion_chk CHECK (liquidacion_id IS NULL OR estado = 'aprobado')");

        Schema::create('bylopez_va_comision_abonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id');
            $table->unsignedInteger('asesor_admin_id');
            $table->string('tipo', 12);
            $table->decimal('monto', 14, 2);
            $table->foreignId('revierte_id')->nullable();
            $table->date('fecha_pago');
            $table->string('medio', 40)->nullable();
            $table->string('referencia', 120)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->string('comprobante_disco', 20)->nullable();
            $table->string('comprobante_ruta', 255)->nullable();
            $table->string('comprobante_nombre', 191)->nullable();
            $table->string('comprobante_mime', 100)->nullable();
            $table->char('comprobante_sha256', 64)->nullable();
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->timestamps();

            $table->unique('idempotency_key', 'bvacb_idem_unique');
            $table->unique('revierte_id', 'bvacb_revierte_unique');
            $table->index(['liquidacion_id', 'tipo'], 'bvacb_liquidacion_idx');
            $table->foreign('liquidacion_id', 'bvacb_liquidacion_fk')->references('id')->on('bylopez_va_comision_liquidaciones')->restrictOnDelete();
            $table->foreign('revierte_id', 'bvacb_revierte_fk')->references('id')->on('bylopez_va_comision_abonos')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_comision_abonos ADD CONSTRAINT bvacb_tipo_chk CHECK (tipo IN ('abono', 'reverso'))");
        DB::statement("ALTER TABLE bylopez_va_comision_abonos ADD CONSTRAINT bvacb_reverso_chk CHECK ((tipo = 'reverso') = (revierte_id IS NOT NULL) AND (tipo <> 'reverso' OR observacion IS NOT NULL))");
        DB::statement('ALTER TABLE bylopez_va_comision_abonos ADD CONSTRAINT bvacb_monto_chk CHECK (monto > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_comision_abonos');
        Schema::dropIfExists('bylopez_va_comision_ajustes');

        Schema::table('bylopez_va_comision_lineas', function (Blueprint $table) {
            $table->dropForeign('bvacl_liquidacion_fk');
        });

        Schema::dropIfExists('bylopez_va_comision_liquidaciones');
    }
};
