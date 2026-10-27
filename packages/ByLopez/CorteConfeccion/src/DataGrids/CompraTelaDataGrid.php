<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoCompraTela;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CompraTelaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_compras_tela')
            ->select('id', 'codigo', 'proveedor_nombre', 'fecha_compra', 'total_factura', 'cantidad_rollos', 'peso_total', 'estado', 'created_at');

        // Vista de detalle de Proveedor: filtra por proveedor via query string, mismo
        // patron que OrdenConfeccionDataGrid usa para la vista de detalle de Taller.
        if ($proveedorId = (int) request()->query('proveedor_id')) {
            $queryBuilder->where('proveedor_id', $proveedorId);
        }

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
            $column = ['index' => $index, 'label' => $label, 'type' => 'string', 'searchable' => in_array($index, ['codigo', 'proveedor_nombre'], true), 'filterable' => true, 'sortable' => true];

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => EstadoCompraTela::badgeHtml($row->estado);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('corte_confeccion.compras_tela.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.compras_tela.edit', $row->id)]);
        }
    }
}
