<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->date('fecha_reporte_taller')->nullable()->after('fecha_entrega');
            $table->date('fecha_recepcion_bodega')->nullable()->after('fecha_reporte_taller');
            $table->unsignedInteger('reportado_por')->nullable()->after('observacion');
            $table->unsignedInteger('recibido_por')->nullable()->after('reportado_por');

            $table->foreign('reportado_por', 'bccof_reportado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('recibido_por', 'bccof_recibido_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_reportada_taller')->nullable()->after('cantidad_entregada');
            $table->unsignedInteger('cantidad_recibida_bodega')->nullable()->after('cantidad_reportada_taller');
            $table->text('observacion_taller')->nullable()->after('prioridad');
            $table->text('observacion_bodega')->nullable()->after('observacion_taller');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->dropColumn([
                'cantidad_reportada_taller',
                'cantidad_recibida_bodega',
                'observacion_taller',
                'observacion_bodega',
            ]);
        });

        Schema::table('bylopez_cc_ordenes_confeccion', function (Blueprint $table) {
            $table->dropForeign('bccof_reportado_por_fk');
            $table->dropForeign('bccof_recibido_por_fk');

            $table->dropColumn([
                'fecha_reporte_taller',
                'fecha_recepcion_bodega',
                'reportado_por',
                'recibido_por',
            ]);
        });
    }
};
