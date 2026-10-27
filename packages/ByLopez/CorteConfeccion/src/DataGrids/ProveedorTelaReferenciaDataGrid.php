<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * Referencias comerciales que un proveedor ofrece: color, gramaje y precio
 * sugerido por kilo. NO es un listado de inventario: un rollo fisico (tabla
 * `bylopez_cc_rollos`) se crea unicamente desde el flujo de Compra de tela y
 * queda enlazado a una de estas referencias, nunca al reves. Esta consulta ni
 * siquiera toca `bylopez_cc_rollos`.
 *
 * La ficha del proveedor abre el modal de referencia por fuera del DataGrid.
 */
class ProveedorTelaReferenciaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_proveedor_tela_referencias as r')
            ->join('attribute_options as ao', 'r.tipo_tela_id', '=', 'ao.id')
            ->select(
                'r.id',
                'r.tipo_tela_id',
                'ao.admin_name as tipo_tela',
                'r.referencia',
                'r.color',
                // F1: no es una columna registrada en prepareColumns() (igual
                // que estado_valor) -- solo dato auxiliar para que el modal
                // Vue de show.blade.php hidrate el select de color al editar.
                'r.color_option_id',
                'r.gramaje',
                'r.valor_kilo_referencia',
                'r.estado',
                'r.estado as estado_valor',
                'r.created_at'
            );

        // Vista de detalle de Proveedor: sin proveedor_id no hay filas (no existe una
        // vista global de "todas las referencias de todos los proveedores").
        $proveedorId = (int) request()->query('proveedor_id');

        $queryBuilder->where('r.proveedor_tela_id', $proveedorId ?: 0);

        foreach (['referencia', 'color', 'estado'] as $field) {
            $this->addFilter($field, 'r.'.$field);
        }

        $this->addFilter('tipo_tela', 'ao.admin_name');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'tipo_tela' => 'Tela',
            'referencia' => 'Referencia',
            'color' => 'Color',
            'gramaje' => 'Gramaje',
            'valor_kilo_referencia' => 'Precio referencia',
            'estado' => 'Estado',
        ] as $index => $label) {
            $column = [
                'index' => $index,
                'label' => $label,
                'type' => 'string',
                'searchable' => in_array($index, ['referencia', 'color'], true),
                'filterable' => in_array($index, ['tipo_tela', 'referencia', 'color', 'estado'], true),
                'sortable' => true,
            ];

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => $row->estado === 'activo'
                    ? '<span class="label-active">Activo</span>'
                    : '<span class="label-canceled">Inactivo</span>';
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.proveedores_tela.referencia.edit', $row->id)]);
        }
    }
}
