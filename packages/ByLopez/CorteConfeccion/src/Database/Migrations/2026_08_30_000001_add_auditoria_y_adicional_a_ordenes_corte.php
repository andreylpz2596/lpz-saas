<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->unsignedInteger('creado_por_id')->nullable()->after('observacion');
            $table->unsignedInteger('reportado_por_id')->nullable()->after('creado_por_id');
            $table->timestamp('reportado_en')->nullable()->after('reportado_por_id');

            $table->foreign('creado_por_id', 'bycc_oc_creado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('reportado_por_id', 'bycc_oc_reportado_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->boolean('es_adicional')->default(false)->after('cantidad_real_cortada');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
            $table->dropForeign('bycc_oc_creado_por_fk');
            $table->dropForeign('bycc_oc_reportado_por_fk');
            $table->dropColumn(['creado_por_id', 'reportado_por_id', 'reportado_en']);
        });

        Schema::table('bylopez_cc_ordenes_corte_detalles', function (Blueprint $table) {
            $table->dropColumn('es_adicional');
        });
    }
};
