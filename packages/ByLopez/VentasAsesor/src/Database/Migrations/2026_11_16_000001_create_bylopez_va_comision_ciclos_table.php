<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PERÍODO DE COMISIÓN POR ASESORA. ADITIVA, sin backfill: sin filas, toda asesora sigue en el mes calendario (día 1), que es
 * exactamente lo que ya está guardado en `mes_comercial`. No reescribe ninguna línea, mes ni liquidación.
 *
 *  - `bylopez_va_asesores.fecha_ingreso`: fecha de inicio de labores (editable en Asesores). Su DÍA define el período.
 *  - `bylopez_va_comision_ciclos`: historial de días de inicio por asesora (admins.id, igual que las líneas). Una fila nueva
 *    por cambio; `vigente_desde` NULL = desde siempre (solo cuando la asesora no tenía comisiones calculadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bylopez_va_asesores', 'fecha_ingreso')) {
            Schema::table('bylopez_va_asesores', function (Blueprint $table) {
                $table->date('fecha_ingreso')->nullable()->after('telefono');
            });
        }

        Schema::create('bylopez_va_comision_ciclos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id');
            $table->unsignedTinyInteger('dia_inicio');
            $table->date('vigente_desde')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('origen', 20);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->index(['asesor_admin_id', 'vigente_desde'], 'bvacc_asesor_desde_idx');
            $table->foreign('asesor_admin_id', 'bvacc_admin_fk')->references('id')->on('admins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_comision_ciclos');

        if (Schema::hasColumn('bylopez_va_asesores', 'fecha_ingreso')) {
            Schema::table('bylopez_va_asesores', fn (Blueprint $table) => $table->dropColumn('fecha_ingreso'));
        }
    }
};
