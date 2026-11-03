<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lectura asistida de comprobantes de PAGOS A ASESORAS (jornada 2026-11-02). ADITIVA: una tabla nueva y columnas NULL en abonos; no
 * toca ni reescribe ningun abono existente (los historicos quedan con todo NULL, que es su valor correcto).
 *
 * `bylopez_va_comision_soportes`: el archivo cargado por administracion (disco PRIVADO) con su huella SHA-256 y el resultado de la
 * lectura (borrador). Cargarlo y leerlo NO registra ni aprueba nada: solo el abono confirmado explicitamente lo hace. La misma huella
 * puede estar en varios soportes (no es UNIQUE): detectar un archivo idéntico es una ADVERTENCIA, no una prohibicion automatica.
 *
 * Columnas de `bylopez_va_comision_abonos`: el soporte, la huella de la OPERACION (referencia + banco + fecha + valor + moneda +
 * direccion «egreso»), el valor total de la operacion (una transferencia puede cubrir varios abonos) y lo detectado/corregido para
 * trazabilidad. Los abonos siguen siendo inmutables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_soportes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id')->nullable();
            $table->unsignedBigInteger('liquidacion_id')->nullable();
            $table->string('disco', 20);
            $table->string('ruta', 255);
            $table->string('nombre_original', 191);
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');
            $table->char('sha256', 64);
            $table->string('lectura_estado', 20)->default('sin_leer');
            $table->string('lectura_proveedor', 60)->nullable();
            $table->json('lectura_resultado')->nullable();
            $table->string('lectura_error', 500)->nullable();
            $table->unsignedInteger('subido_por_admin_id');
            $table->timestamps();

            $table->index('sha256', 'bvacs_sha_idx');
            $table->index(['liquidacion_id', 'asesor_admin_id'], 'bvacs_liq_idx');
        });

        Schema::table('bylopez_va_comision_abonos', function (Blueprint $table) {
            $table->unsignedBigInteger('soporte_id')->nullable()->after('comprobante_sha256');
            $table->char('operacion_huella', 64)->nullable()->after('soporte_id');
            $table->string('operacion_referencia', 120)->nullable()->after('operacion_huella');
            $table->decimal('operacion_valor', 14, 2)->nullable()->after('operacion_referencia');
            $table->json('lectura_detectada')->nullable()->after('operacion_valor');
            $table->json('lectura_corregida')->nullable()->after('lectura_detectada');

            $table->index('operacion_huella', 'bvacb_huella_idx');
            $table->index('soporte_id', 'bvacb_soporte_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_va_comision_abonos', function (Blueprint $table) {
            $table->dropIndex('bvacb_huella_idx');
            $table->dropIndex('bvacb_soporte_idx');
            $table->dropColumn(['soporte_id', 'operacion_huella', 'operacion_referencia', 'operacion_valor', 'lectura_detectada', 'lectura_corregida']);
        });

        Schema::dropIfExists('bylopez_va_comision_soportes');
    }
};
