<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sugerencia por stock y regla P1 (docs/confeccion/09_CONSOLIDADO_PRIORIDADES.md §9-§10). Solo aditiva.
 *
 * - `bylopez_cc_stock_minimos`: minimo POR VARIANTE (product_id simple). Sin fila = «sin minimo configurado»; una fila con
 *   0 es un minimo cero explicito. Nada se rellena: ningun minimo se inventa ni se asigna masivamente.
 * - `bylopez_cc_stock_minimo_cambios`: historial inmutable de cada cambio (anterior/nuevo; null = ausente).
 * - Reparto REAL por prioridad en reportes y reasignaciones (`cantidad_urgente`, `cantidad_alta`; Normal = el resto).
 *   Nullable a proposito: las filas anteriores quedan en null («reparto no registrado») y no se reinterpretan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_stock_minimos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->unique();
            $table->unsignedInteger('minimo');
            $table->unsignedInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'bccsm_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('actualizado_por', 'bccsm_admin_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_stock_minimo_cambios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('anterior')->nullable();
            $table->unsignedInteger('nuevo')->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('admin_id', 'bccsmc_admin_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::table('bylopez_cc_ordenes_confeccion_retorno_detalles', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_urgente')->nullable()->after('cantidad_reportada');
            $table->unsignedInteger('cantidad_alta')->nullable()->after('cantidad_urgente');
        });

        Schema::table('bylopez_cc_reasignacion_lineas', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_urgente')->nullable()->after('cantidad');
            $table->unsignedInteger('cantidad_alta')->nullable()->after('cantidad_urgente');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_reasignacion_lineas', function (Blueprint $table) {
            $table->dropColumn(['cantidad_urgente', 'cantidad_alta']);
        });

        Schema::table('bylopez_cc_ordenes_confeccion_retorno_detalles', function (Blueprint $table) {
            $table->dropColumn(['cantidad_urgente', 'cantidad_alta']);
        });

        Schema::dropIfExists('bylopez_cc_stock_minimo_cambios');
        Schema::dropIfExists('bylopez_cc_stock_minimos');
    }
};
