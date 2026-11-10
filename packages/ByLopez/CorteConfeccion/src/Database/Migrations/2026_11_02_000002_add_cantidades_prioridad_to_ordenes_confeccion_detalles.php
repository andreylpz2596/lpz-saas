<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad POR CANTIDAD (docs/confeccion/09_CONSOLIDADO_PRIORIDADES.md).
 *
 * `prioridad` (texto) se conserva intacta como la prioridad con la que se asigno el detalle. Las cantidades nuevas dicen
 * cuantas unidades de lo que el taller aun debe confeccionar son Urgentes o Altas; Normal es el resto (no se guarda).
 * Invariante: cantidad_urgente + cantidad_alta <= vigente - reportada.
 *
 * Relleno compatible: un detalle Urgente/Alta queda con TODO su saldo actual en ese nivel (nada pasa a Normal y no se
 * pierde ninguna Urgente). Las ordenes anuladas quedan en 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_urgente')->default(0)->after('prioridad');
            $table->unsignedInteger('cantidad_alta')->default(0)->after('cantidad_urgente');
        });

        $reportado = DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')
            ->selectRaw('orden_confeccion_detalle_id, SUM(cantidad_reportada) as reportada')
            ->groupBy('orden_confeccion_detalle_id');

        foreach (['urgente' => 'cantidad_urgente', 'alta' => 'cantidad_alta'] as $prioridad => $columna) {
            $filas = DB::table('bylopez_cc_ordenes_confeccion_detalles as d')
                ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
                ->leftJoinSub($reportado, 'r', 'r.orden_confeccion_detalle_id', '=', 'd.id')
                ->where('d.prioridad', $prioridad)
                ->where('o.estado', '!=', 'anulada')
                ->selectRaw('d.id, GREATEST(d.cantidad_entregada - d.cantidad_reasignada_salida - COALESCE(r.reportada, 0), 0) as saldo')
                ->get();

            foreach ($filas as $fila) {
                DB::table('bylopez_cc_ordenes_confeccion_detalles')->where('id', $fila->id)->update([$columna => (int) $fila->saldo]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_ordenes_confeccion_detalles', function (Blueprint $table) {
            $table->dropColumn(['cantidad_urgente', 'cantidad_alta']);
        });
    }
};
