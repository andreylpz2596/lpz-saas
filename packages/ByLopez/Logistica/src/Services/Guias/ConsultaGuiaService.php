<?php

namespace ByLopez\Logistica\Services\Guias;

use Illuminate\Support\Facades\DB;

/**
 * Lectura de guias por pedido, en lote (sin N+1). Es lo que consumen la bandeja, el modal, el listado de Pedidos y el
 * detalle del asesor. `$conFinanzas = false` oculta flete, quien lo paga y recaudo: el asesor ve transportadora,
 * numero, tracking y PDF, no costos logisticos.
 *
 * Numero/tracking (dato del envio) y PDF (documento) viajan por separado: `tiene_pdf` solo dice si hay version vigente.
 */
class ConsultaGuiaService
{
    /**
     * @param  list<int|string>  $orderIds
     * @return array<int, list<array<string, mixed>>> order_id => guias (la mas antigua primero)
     */
    public function deOrdenes(array $orderIds, bool $conFinanzas = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            return [];
        }

        $filas = DB::table('bylopez_lg_guias as g')
            ->leftJoin('bylopez_lg_transportadoras as t', 't.codigo', '=', 'g.transportadora_codigo')
            ->leftJoin('bylopez_lg_guia_archivos as a', fn ($j) => $j->on('a.guia_id', '=', 'g.id')->where('a.vigente', 1))
            ->whereIn('g.order_id', $ids)
            ->orderBy('g.id')
            ->get([
                'g.*', 't.nombre as transportadora_nombre',
                'a.id as archivo_id', 'a.version as archivo_version', 'a.tamano_bytes as archivo_tamano',
                'a.created_at as archivo_en', 'a.subido_por_name_snapshot as archivo_por', 'a.origen_archivo as archivo_origen',
                DB::raw('(SELECT COUNT(*) FROM bylopez_lg_guia_archivos x WHERE x.guia_id = g.id) as archivo_versiones'),
            ]);

        $resultado = [];

        foreach ($filas as $f) {
            $guia = [
                'id' => (int) $f->id,
                'order_id' => (int) $f->order_id,
                'shipment_id' => (int) $f->shipment_id,
                'transportadora_codigo' => $f->transportadora_codigo,
                'transportadora' => $f->transportadora_nombre ?? $f->transportadora_codigo,
                'numero_guia' => $f->numero_guia,
                'tracking' => $f->tracking,
                'tracking_es_url' => $f->tracking !== null && (bool) preg_match('#^https?://#i', $f->tracking),
                'origen_guia' => $f->origen_guia,
                'generada_por' => $f->generada_por,
                'observacion' => $f->observacion,
                'registrada_por' => $f->actor_name_snapshot,
                'registrada_en' => $f->created_at,
                'tiene_pdf' => $f->archivo_id !== null,
                'archivo_version' => $f->archivo_version === null ? null : (int) $f->archivo_version,
                'archivo_versiones' => (int) $f->archivo_versiones,
                'archivo_tamano' => $f->archivo_tamano === null ? null : (int) $f->archivo_tamano,
                'archivo_en' => $f->archivo_en,
                'archivo_por' => $f->archivo_por,
                'archivo_origen' => $f->archivo_origen,
            ];

            if ($conFinanzas) {
                $guia += [
                    'valor_flete' => $f->valor_flete === null ? null : (float) $f->valor_flete,
                    'flete_pagador' => $f->flete_pagador,
                    'recaudo_esperado' => (float) $f->recaudo_esperado,
                    'recaudo_estado' => $f->recaudo_estado,
                    'moneda' => $f->moneda,
                ];
            }

            $resultado[(int) $f->order_id][] = $guia;
        }

        return $resultado;
    }

    /** Historial de correcciones de una guia (la mas reciente primero): anterior, nuevo, motivo, quien y cuando. */
    public function correcciones(int $guiaId): array
    {
        return DB::table('bylopez_lg_guia_correcciones')->where('guia_id', $guiaId)->orderByDesc('id')
            ->get(['numero_anterior', 'numero_nuevo', 'transportadora_anterior', 'transportadora_nueva', 'motivo', 'actor_name_snapshot', 'created_at'])
            ->map(fn ($c) => (array) $c)->all();
    }

    /** Historial de versiones del PDF de una guia (mas reciente primero), para la vista «Ver envio». */
    public function versiones(int $guiaId): array
    {
        return DB::table('bylopez_lg_guia_archivos')->where('guia_id', $guiaId)->orderByDesc('version')
            ->get(['version', 'vigente', 'origen_archivo', 'nombre_original', 'tamano_bytes', 'subido_por_name_snapshot', 'motivo_reemplazo', 'created_at', 'reemplazado_en'])
            ->map(fn ($v) => (array) $v)->all();
    }
}
