<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\CorteConfeccion\Comprobantes\FuenteOperacionesExistentes;
use Illuminate\Support\Facades\DB;

/**
 * Implementacion de la cartera de prestadores: que operaciones y soportes ya conoce. Mira a TODOS los prestadores (una
 * transferencia pudo cargarse en la persona equivocada); quien consume el resultado filtra el detalle por permisos.
 */
class CarteraFuenteOperaciones implements FuenteOperacionesExistentes
{
    public function soportesPorHuellaArchivo(string $sha256): array
    {
        return DB::table('bylopez_cc_cartera_soportes as s')
            ->leftJoin('bylopez_cc_cartera_movimientos as m', 'm.id', '=', 's.movimiento_id')
            ->where('s.sha256', $sha256)
            ->orderBy('s.id')
            ->get(['s.id as soporte_id', 'm.id as movimiento_id', 'm.codigo as movimiento_codigo', 'm.fecha_efectiva as fecha', 'm.valor', 'm.estado as estado_movimiento', 's.taller_id', 's.cortador_id'])
            ->map(fn ($f) => $this->fila((array) $f))
            ->all();
    }

    public function operacionesPorHuella(string $huella): array
    {
        return $this->consulta()->where('o.huella', $huella)->get()->map(fn ($f) => $this->fila((array) $f))->all();
    }

    public function operacionesCandidatas(BorradorOperacion $borrador): array
    {
        $referencia = $borrador->referenciaNormalizada();

        if ($referencia === null && ($borrador->valor === null || $borrador->fecha === null)) {
            return [];
        }

        return $this->consulta()
            ->where(function ($q) use ($borrador, $referencia) {
                if ($referencia !== null) {
                    $q->whereRaw("UPPER(REGEXP_REPLACE(COALESCE(NULLIF(o.referencia, ''), o.autorizacion), '[^A-Za-z0-9]', '')) = ?", [$referencia]);
                }

                // Mismo importe + fecha solo es sospechoso si alguna de las dos operaciones no tiene referencia legible.
                if ($borrador->valor !== null && $borrador->fecha !== null) {
                    $q->orWhere(fn ($w) => $w->where('o.valor', $borrador->valor)->where('o.fecha_operacion', $borrador->fecha)
                        ->where('o.moneda', $borrador->moneda)->where('o.direccion', $borrador->direccion)
                        ->where(function ($r) use ($referencia) {
                            if ($referencia === null) {
                                $r->whereRaw('1 = 1');

                                return;
                            }

                            $r->whereRaw("COALESCE(NULLIF(o.referencia, ''), o.autorizacion) IS NULL");
                        }));
                }
            })
            ->get()->map(fn ($f) => $this->fila((array) $f))->all();
    }

    protected function consulta()
    {
        return DB::table('bylopez_cc_cartera_operaciones as o')
            ->join('bylopez_cc_cartera_movimientos as m', 'm.id', '=', 'o.movimiento_id')
            ->where('o.estado', 'aceptada')
            ->select(['o.id as operacion_id', 'o.soporte_id', 'o.valor', DB::raw("COALESCE(NULLIF(o.referencia, ''), o.autorizacion) as referencia"), 'm.id as movimiento_id', 'm.codigo as movimiento_codigo', 'm.fecha_efectiva as fecha', 'm.estado as estado_movimiento', 'm.taller_id', 'm.cortador_id']);
    }

    /** @param  array<string, mixed>  $f */
    protected function fila(array $f): array
    {
        $f['prestador'] = ! empty($f['taller_id']) ? 'taller:'.$f['taller_id'] : (! empty($f['cortador_id']) ? 'cortador:'.$f['cortador_id'] : null);
        unset($f['taller_id'], $f['cortador_id']);

        return $f;
    }
}
