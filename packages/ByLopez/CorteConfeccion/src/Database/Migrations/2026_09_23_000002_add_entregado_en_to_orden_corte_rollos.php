<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_orden_corte_rollos', function (Blueprint $table) {
            $table->timestamp('entregado_en')->nullable()->after('peso_inicial_usado');
        });

        DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_ordenes_corte as oc', 'ocr.orden_corte_id', '=', 'oc.id')
            ->whereIn('oc.estado', [
                EstadoOrdenCorte::EntregadoACortador->value,
                EstadoOrdenCorte::EnCorte->value,
                EstadoOrdenCorte::ReportadoPorCortador->value,
                EstadoOrdenCorte::RecibidoBodega->value,
                EstadoOrdenCorte::Cerrado->value,
            ])
            ->update([
                'ocr.entregado_en' => DB::raw('COALESCE(oc.fecha_entrega, ocr.created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_orden_corte_rollos', function (Blueprint $table) {
            $table->dropColumn('entregado_en');
        });
    }
};
