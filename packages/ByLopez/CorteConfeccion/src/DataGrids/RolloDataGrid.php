<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class RolloDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_rollos')
            ->select('id', 'codigo', 'proveedor', 'color', 'referencia', 'tipo_tela', 'peso_inicial', 'peso_disponible', 'estado', 'fecha_compra', 'created_at');

        foreach (['id', 'codigo', 'proveedor', 'color', 'referencia', 'tipo_tela', 'estado'] as $field) {
            $this->addFilter($field, $field);
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'codigo' => 'Codigo',
            'proveedor' => 'Proveedor',
            'tipo_tela' => 'Tela',
            'referencia' => 'Referencia',
            'color' => 'Color',
            'peso_inicial' => 'Peso inicial',
            'peso_disponible' => 'Peso disponible',
            'estado' => 'Estado',
            'fecha_compra' => 'Compra',
        ] as $index => $label) {
            $this->addColumn(['index' => $index, 'label' => $label, 'type' => 'string', 'searchable' => in_array($index, ['codigo', 'proveedor', 'color'], true), 'filterable' => true, 'sortable' => true]);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.rollos.show', $row->id)]);
        $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.rollos.edit', $row->id)]);
    }
}
