<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class OrdenCorteDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $rollosResumen = DB::table('bylopez_cc_orden_corte_rollos')
            ->select('orden_corte_id', DB::raw('COUNT(DISTINCT rollo_id) as cantidad_rollos'))
            ->groupBy('orden_corte_id');

        $detallesResumen = DB::table('bylopez_cc_ordenes_corte_detalles')
            ->select('orden_corte_id', DB::raw('SUM(cantidad_real_cortada) as total_camisetas_cortadas'))
            ->groupBy('orden_corte_id');

        $queryBuilder = DB::table('bylopez_cc_ordenes_corte as o')
            ->leftJoinSub($rollosResumen, 'rollos_resumen', 'o.id', '=', 'rollos_resumen.orden_corte_id')
            ->leftJoinSub($detallesResumen, 'detalles_resumen', 'o.id', '=', 'detalles_resumen.orden_corte_id')
            ->select(
                'o.id',
                'o.codigo',
                'o.estado',
                DB::raw('COALESCE(o.cortador_nombre_snapshot, o.cortador_nombre) as cortador_nombre_snapshot'),
                DB::raw('COALESCE(rollos_resumen.cantidad_rollos, CASE WHEN o.rollo_id IS NULL THEN 0 ELSE 1 END) as cantidad_rollos'),
                DB::raw('o.peso_total_rollos as peso_total'),
                DB::raw('COALESCE(detalles_resumen.total_camisetas_cortadas, 0) as total_camisetas_cortadas'),
                'o.fecha_entrega',
                'o.created_at'
            );

        $this->addFilter('codigo', 'o.codigo');
        $this->addFilter('cortador_nombre_snapshot', 'o.cortador_nombre_snapshot');
        $this->addFilter('estado', 'o.estado');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'codigo' => 'Codigo',
            'estado' => 'Estado',
            'cortador_nombre_snapshot' => 'Cortador',
            'cantidad_rollos' => 'Cantidad rollos',
            'peso_total' => 'Peso total',
            'total_camisetas_cortadas' => 'Total camisetas cortadas',
            'fecha_entrega' => 'Entrega',
        ] as $index => $label) {
            $column = [
                'index'      => $index,
                'label'      => $label,
                'type'       => match ($index) {
                    'cantidad_rollos', 'total_camisetas_cortadas' => 'integer',
                    'peso_total' => 'decimal',
                    'fecha_entrega' => 'date',
                    default => 'string',
                },
                'searchable' => in_array($index, ['codigo', 'cortador_nombre_snapshot'], true),
                'filterable' => in_array($index, ['codigo', 'estado', 'cortador_nombre_snapshot', 'fecha_entrega'], true),
                'sortable'   => true,
            ];

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => $this->estadoBadge((string) $row->estado);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.ordenes_corte.show', $row->id)]);
    }

    protected function estadoBadge(string $estado): string
    {
        $classes = [
            'borrador' => 'label-info',
            EstadoOrdenCorte::PendienteEntrega->value => 'label-pending',
            EstadoOrdenCorte::EntregadoACortador->value => 'label-active',
            EstadoOrdenCorte::EnCorte->value => 'label-processing',
            EstadoOrdenCorte::ReportadoPorCortador->value => 'label-processing',
            EstadoOrdenCorte::RecibidoBodega->value => 'label-active',
            EstadoOrdenCorte::Cerrado->value => 'label-closed',
            EstadoOrdenCorte::Anulado->value => 'label-canceled',
        ];

        $labels = [
            'borrador' => 'Borrador',
            EstadoOrdenCorte::PendienteEntrega->value => 'Pendiente entrega',
            EstadoOrdenCorte::EntregadoACortador->value => 'Entregado',
            EstadoOrdenCorte::EnCorte->value => 'En corte',
            EstadoOrdenCorte::ReportadoPorCortador->value => 'Reportado',
            EstadoOrdenCorte::RecibidoBodega->value => 'Recibido bodega',
            EstadoOrdenCorte::Cerrado->value => 'Cerrado',
            EstadoOrdenCorte::Anulado->value => 'Anulado',
        ];

        return sprintf(
            '<p class="%s">%s</p>',
            $classes[$estado] ?? 'label-info',
            e($labels[$estado] ?? str_replace('_', ' ', ucfirst($estado)))
        );
    }
}
