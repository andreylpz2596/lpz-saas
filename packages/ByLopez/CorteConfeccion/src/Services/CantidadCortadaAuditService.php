<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\OrdenCorteDetalle;
use Illuminate\Support\Facades\DB;

class CantidadCortadaAuditService
{
    public function record(OrdenCorteDetalle $detalle, int $valorAnterior, int $valorNuevo): void
    {
        if ($valorAnterior === $valorNuevo) {
            return;
        }

        $user = auth()->guard('admin')->user();

        DB::table('bylopez_cc_orden_corte_cantidad_audits')->insert([
            'orden_corte_id'          => $detalle->orden_corte_id,
            'orden_corte_detalle_id'  => $detalle->id,
            'usuario_modificacion_id' => $user?->id,
            'usuario_modificacion'    => $user?->name,
            'fecha_modificacion'      => now(),
            'valor_anterior'          => $valorAnterior,
            'valor_nuevo'             => $valorNuevo,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }
}
