<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CortadorDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $detallesPorOrden = DB::table('bylopez_cc_ordenes_corte_detalles')
            ->select('orden_corte_id', DB::raw('SUM(cantidad_real_cortada) as prendas_cortadas'))
            ->groupBy('orden_corte_id');

        $ordenesConResumen = DB::table('bylopez_cc_ordenes_corte as oc')
            ->leftJoinSub($detallesPorOrden, 'd', 'oc.id', '=', 'd.orden_corte_id')
            ->select(
                'oc.id',
                'oc.cortador_id',
                'oc.estado',
                DB::raw('COALESCE(d.prendas_cortadas, 0) as prendas_cortadas')
            );

        $ordenesPorCortador = DB::query()->fromSub($ordenesConResumen, 'oc')
            ->select(
                'oc.cortador_id',
                DB::raw('COUNT(CASE WHEN oc.estado IN ("'.implode('","', [
                    EstadoOrdenCorte::PendienteEntrega->value,
                    EstadoOrdenCorte::EntregadoACortador->value,
                    EstadoOrdenCorte::EnCorte->value,
                ]).'") THEN 1 END) as ordenes_activas'),
                DB::raw('SUM(CASE WHEN oc.estado <> "'.EstadoOrdenCorte::Anulado->value.'" THEN oc.prendas_cortadas ELSE 0 END) as prendas_cortadas'),
                DB::raw('COUNT(CASE WHEN oc.estado IN ("'.implode('","', [
                    EstadoOrdenCorte::PendienteEntrega->value,
                    EstadoOrdenCorte::EntregadoACortador->value,
                    EstadoOrdenCorte::EnCorte->value,
                ]).'") THEN 1 END) as pendientes_cortador'),
                DB::raw('COUNT(CASE WHEN oc.estado = "'.EstadoOrdenCorte::ReportadoPorCortador->value.'" THEN 1 END) as pendientes_recepcion'),
                DB::raw('COUNT(CASE WHEN oc.estado IN ("'.implode('","', [
                    EstadoOrdenCorte::RecibidoBodega->value,
                    EstadoOrdenCorte::Cerrado->value,
                ]).'") THEN 1 END) as ordenes_historicas')
            )
            ->groupBy('oc.cortador_id');

        $queryBuilder = DB::table('bylopez_cc_cortadores as c')
            ->leftJoin('admins as a', 'a.id', '=', 'c.usuario_id')
            ->leftJoinSub($ordenesPorCortador, 'resumen', 'c.usuario_id', '=', 'resumen.cortador_id')
            ->select(
                'c.id',
                'c.nombre',
                'c.contacto',
                'c.telefono',
                'c.estado',
                'a.name as usuario_nombre',
                'a.email as usuario_email',
                DB::raw('COALESCE(resumen.ordenes_activas, 0) as ordenes_activas'),
                DB::raw('COALESCE(resumen.prendas_cortadas, 0) as prendas_cortadas'),
                DB::raw('COALESCE(resumen.pendientes_cortador, 0) as pendientes_cortador'),
                DB::raw('COALESCE(resumen.pendientes_recepcion, 0) as pendientes_recepcion'),
                DB::raw('COALESCE(resumen.ordenes_historicas, 0) as ordenes_historicas')
            );

        CortadorAccess::filtrarCortadoresSiAplica($queryBuilder, 'c.usuario_id');

        foreach (['nombre', 'contacto', 'estado'] as $field) {
            $this->addFilter($field, 'c.'.$field);
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'nombre' => 'Cortador',
            'contacto' => 'Responsable',
            'telefono' => 'Telefono',
            'usuario_nombre' => 'Usuario asociado',
            'estado' => 'Estado',
            'ordenes_activas' => 'Ordenes activas',
            'prendas_cortadas' => 'Prendas cortadas',
            'pendientes_cortador' => 'Pendiente cortador',
            'pendientes_recepcion' => 'Pendiente recepcion',
            'ordenes_historicas' => 'Ordenes historicas',
        ] as $index => $label) {
            $esResumen = in_array($index, [
                'ordenes_activas', 'prendas_cortadas', 'pendientes_cortador',
                'pendientes_recepcion', 'ordenes_historicas',
            ], true);

            $column = [
                'index' => $index,
                'label' => $label,
                'type' => $esResumen ? 'integer' : 'string',
                'searchable' => in_array($index, ['nombre', 'contacto', 'usuario_nombre'], true),
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
        if (bouncer()->hasPermission('corte_confeccion.cortadores')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.cortadores.show', $row->id)]);
        }

        if (bouncer()->hasPermission('corte_confeccion.cortadores.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.cortadores.show', $row->id)]);
        }
    }

    protected function nombreEnlace($row): string
    {
        if (! bouncer()->hasPermission('corte_confeccion.cortadores')) {
            return e($row->nombre);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.cortadores.show', $row->id),
            e($row->nombre)
        );
    }
}
