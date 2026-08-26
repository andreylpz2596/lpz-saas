<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CompraTelaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_compras_tela')
            ->select('id', 'codigo', 'proveedor_nombre', 'fecha_compra', 'total_factura', 'cantidad_rollos', 'peso_total', 'estado', 'created_at');

        foreach (['codigo', 'proveedor_nombre', 'estado'] as $field) {
            $this->addFilter($field, $field);
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'codigo' => 'Numero compra',
            'proveedor_nombre' => 'Proveedor',
            'fecha_compra' => 'Fecha compra',
            'total_factura' => 'Total factura',
            'cantidad_rollos' => 'Rollos',
            'peso_total' => 'Peso total',
            'estado' => 'Estado',
        ] as $index => $label) {
            $this->addColumn(['index' => $index, 'label' => $label, 'type' => 'string', 'searchable' => in_array($index, ['codigo', 'proveedor_nombre'], true), 'filterable' => true, 'sortable' => true]);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.compras_tela.show', $row->id)]);
        $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.compras_tela.edit', $row->id)]);
    }
}
