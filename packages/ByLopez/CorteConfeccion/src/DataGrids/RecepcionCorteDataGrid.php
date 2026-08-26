<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class RecepcionCorteDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_recepciones_corte as rc')
            ->join('bylopez_cc_ordenes_corte as o', 'rc.orden_corte_id', '=', 'o.id')
            ->select('rc.id', 'o.codigo as orden_codigo', 'rc.fecha_recepcion', 'rc.peso_sobrante_usable', 'rc.peso_retasos', 'rc.peso_desperdicio', 'rc.merma_total', 'rc.recibido_por', 'rc.created_at');

        $this->addFilter('orden_codigo', 'o.codigo');
        $this->addFilter('recibido_por', 'rc.recibido_por');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'orden_codigo' => 'Orden',
            'fecha_recepcion' => 'Recepcion',
            'peso_sobrante_usable' => 'Sobrante',
            'peso_retasos' => 'Retazos',
            'peso_desperdicio' => 'Desperdicio',
            'merma_total' => 'Merma',
            'recibido_por' => 'Recibido por',
        ] as $index => $label) {
            $this->addColumn(['index' => $index, 'label' => $label, 'type' => 'string', 'searchable' => in_array($index, ['orden_codigo', 'recibido_por'], true), 'filterable' => true, 'sortable' => true]);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.recepciones_corte.show', $row->id)]);
    }
}
