<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Support\TallerConfeccionAccess;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class TallerDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        /*
         * Produccion activa e historico, PRE-AGREGADOS por taller. Se construyen en
         * dos pasos, igual que en OrdenConfeccionDataGrid: primero un resumen por
         * ORDEN (detalles + retornos), y solo despues se agrupa por taller. Saltarse
         * el primer paso y unir detalles/retornos directo a las ordenes multiplicaria
         * filas y las sumas saldrian infladas.
         */
        $detallesPorOrden = DB::table('bylopez_cc_ordenes_confeccion_detalles')
            ->select('orden_confeccion_id', DB::raw('SUM(cantidad_entregada - cantidad_reasignada_salida) as entregado'))
            ->groupBy('orden_confeccion_id');

        $retornosPorOrden = DB::table('bylopez_cc_ordenes_confeccion_retornos as r')
            ->join('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.retorno_id', '=', 'r.id')
            ->select(
                'r.orden_confeccion_id',
                DB::raw('SUM(rd.cantidad_reportada) as reportado'),
                DB::raw('SUM(COALESCE(rd.cantidad_recibida, 0)) as recibido')
            )
            ->groupBy('r.orden_confeccion_id');

        $ordenesConResumen = DB::table('bylopez_cc_ordenes_confeccion as oc')
            ->leftJoinSub($detallesPorOrden, 'd', 'oc.id', '=', 'd.orden_confeccion_id')
            ->leftJoinSub($retornosPorOrden, 'rt', 'oc.id', '=', 'rt.orden_confeccion_id')
            ->select(
                'oc.id',
                'oc.taller_id',
                'oc.estado',
                DB::raw('COALESCE(d.entregado, 0) as entregado'),
                DB::raw('COALESCE(rt.reportado, 0) as reportado'),
                DB::raw('COALESCE(rt.recibido, 0) as recibido')
            );

        // Activa: el taller todavia tiene la orden en mano (no la ha entregado del todo).
        $activaPorTaller = DB::query()->fromSub($ordenesConResumen, 'oc')
            ->whereIn('oc.estado', [
                EstadoOrdenConfeccion::Activa->value,
                EstadoOrdenConfeccion::EnConfeccion->value,
                EstadoOrdenConfeccion::TerminadaTaller->value,
            ])
            ->select(
                'oc.taller_id',
                DB::raw('COUNT(*) as ordenes_activas'),
                DB::raw('SUM(oc.entregado) as prendas_asignadas'),
                // GREATEST evita un pendiente negativo si el reporte supera lo entregado.
                DB::raw('SUM(GREATEST(oc.entregado - oc.reportado, 0)) as prendas_pendientes')
            )
            ->groupBy('oc.taller_id');

        // Historico: la orden ya salio del taller (bodega la recibio o quedo cerrada).
        $historicoPorTaller = DB::query()->fromSub($ordenesConResumen, 'oc')
            ->whereIn('oc.estado', [
                EstadoOrdenConfeccion::RecibidaBodega->value,
                EstadoOrdenConfeccion::Cerrada->value,
            ])
            ->select(
                'oc.taller_id',
                DB::raw('COUNT(*) as ordenes_terminadas'),
                DB::raw('SUM(oc.recibido) as prendas_recibidas')
            )
            ->groupBy('oc.taller_id');

        $queryBuilder = DB::table('bylopez_cc_talleres as t')
            ->leftJoinSub($activaPorTaller, 'act', 't.id', '=', 'act.taller_id')
            ->leftJoinSub($historicoPorTaller, 'hist', 't.id', '=', 'hist.taller_id')
            ->select(
                't.id',
                't.nombre',
                't.contacto',
                't.telefono',
                't.estado',
                DB::raw('COALESCE(act.ordenes_activas, 0) as ordenes_activas'),
                DB::raw('COALESCE(act.prendas_asignadas, 0) as prendas_asignadas'),
                DB::raw('COALESCE(act.prendas_pendientes, 0) as prendas_pendientes'),
                DB::raw('COALESCE(hist.ordenes_terminadas, 0) as ordenes_terminadas'),
                DB::raw('COALESCE(hist.prendas_recibidas, 0) as prendas_recibidas')
            );

        TallerConfeccionAccess::filtrarTalleresSiAplica($queryBuilder, 't.usuario_id');

        foreach (['nombre', 'contacto', 'estado'] as $field) {
            $this->addFilter($field, 't.'.$field);
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'nombre' => 'Taller',
            'contacto' => 'Responsable',
            'telefono' => 'Telefono',
            'estado' => 'Estado',
            'ordenes_activas' => 'Ordenes activas',
            'prendas_asignadas' => 'Prendas asignadas',
            'prendas_pendientes' => 'Pendientes',
            'ordenes_terminadas' => 'Ordenes terminadas',
            'prendas_recibidas' => 'Prendas recibidas',
        ] as $index => $label) {
            $esResumen = in_array($index, [
                'ordenes_activas', 'prendas_asignadas', 'prendas_pendientes',
                'ordenes_terminadas', 'prendas_recibidas',
            ], true);

            $column = [
                'index' => $index,
                'label' => $label,
                'type' => $esResumen ? 'integer' : 'string',
                'searchable' => in_array($index, ['nombre', 'contacto'], true),
                // El resumen se ordena, pero no se filtra: son agregados, no atributos del taller.
                'filterable' => in_array($index, ['nombre', 'contacto', 'estado'], true),
                'sortable' => true,
            ];

            if ($index === 'nombre') {
                $column['closure'] = fn ($row) => $this->nombreEnlace($row);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('corte_confeccion.talleres')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.talleres.show', $row->id)]);
        }

        if (bouncer()->hasPermission('corte_confeccion.talleres.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.talleres.show', $row->id)]);
        }
    }

    protected function nombreEnlace($row): string
    {
        if (! bouncer()->hasPermission('corte_confeccion.talleres')) {
            return e($row->nombre);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.talleres.show', $row->id),
            e($row->nombre)
        );
    }
}
