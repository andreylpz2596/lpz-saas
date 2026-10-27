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
            ->select('rc.id', 'o.codigo as orden_codigo', 'rc.fecha_recepcion', 'rc.merma_total', 'rc.recibido_por', 'rc.created_at')
            // F3: sobrante usable/retazos/desperdicio solo existen en recepciones
            // historicas; en las nuevas un 0 seria enganoso, por eso se ocultan (NULL).
            ->selectRaw('CASE WHEN o.merma_unica = 1 THEN NULL ELSE rc.peso_sobrante_usable END as peso_sobrante_usable')
            ->selectRaw('CASE WHEN o.merma_unica = 1 THEN NULL ELSE rc.peso_retasos END as peso_retasos')
            ->selectRaw('CASE WHEN o.merma_unica = 1 THEN NULL ELSE rc.peso_desperdicio END as peso_desperdicio');

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
        if (bouncer()->hasPermission('corte_confeccion.recepciones_corte')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.recepciones_corte.show', $row->id)]);
        }
    }
}
