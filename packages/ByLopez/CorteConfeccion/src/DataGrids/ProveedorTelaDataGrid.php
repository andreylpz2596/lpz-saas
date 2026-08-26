<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ProveedorTelaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_proveedores_tela as p')
            ->leftJoin('bylopez_cc_proveedor_tela_tipos as ptt', 'p.id', '=', 'ptt.proveedor_tela_id')
            ->leftJoin('attribute_options as ao', 'ptt.tipo_tela_id', '=', 'ao.id')
            ->select(
                'p.id',
                'p.nombre',
                'p.nit',
                'p.telefono',
                'p.contacto',
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT ao.admin_name ORDER BY ao.sort_order, ao.admin_name SEPARATOR ', '), p.tipo) as tipo"),
                'p.estado',
                'p.created_at'
            )
            ->groupBy('p.id', 'p.nombre', 'p.nit', 'p.telefono', 'p.contacto', 'p.tipo', 'p.estado', 'p.created_at');

        $this->addFilter('nombre', 'p.nombre');
        $this->addFilter('nit', 'p.nit');
        $this->addFilter('tipo', 'ao.admin_name');
        $this->addFilter('estado', 'p.estado');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'nombre' => 'Proveedor',
            'nit' => 'NIT',
            'telefono' => 'Telefono',
            'contacto' => 'Contacto',
            'tipo' => 'Tipo',
            'estado' => 'Estado',
        ] as $index => $label) {
            $this->addColumn(['index' => $index, 'label' => $label, 'type' => 'string', 'searchable' => in_array($index, ['nombre', 'nit', 'contacto'], true), 'filterable' => true, 'sortable' => true]);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.proveedores_tela.edit', $row->id)]);
    }
}
