<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ProveedorTelaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    /**
     * Resumen comercial PRE-AGREGADO por proveedor: cantidad de compras, valor
     * total historico y rollos comprados. Extraido a un metodo propio para que la
     * ficha de detalle (ProveedorTelaController::resumenProveedor()) calcule
     * exactamente los mismos numeros con la misma consulta, en vez de reescribirla.
     *
     * Se usa de dos formas: unida como subconsulta aqui (para el listado completo,
     * sin N+1) o filtrada y ejecutada directo con ->first() (para un solo proveedor
     * en su ficha, donde no hay fila que multiplicar).
     */
    public static function resumenComprasSubquery(): Builder
    {
        return DB::table('bylopez_cc_compras_tela')
            ->select(
                'proveedor_id',
                DB::raw('COUNT(*) as total_compras'),
                DB::raw('COALESCE(SUM(total_factura), 0) as total_comprado'),
                DB::raw('COALESCE(SUM(cantidad_rollos), 0) as total_rollos_comprados')
            )
            ->whereNotNull('proveedor_id')
            ->groupBy('proveedor_id');
    }

    public function prepareQueryBuilder()
    {
        /*
         * Resumen comercial PRE-AGREGADO por proveedor. Va como subconsulta unida una
         * sola vez, no como consulta por fila: el listado sigue siendo una consulta.
         *
         * No puede resolverse con un leftJoin directo a las compras porque la consulta
         * principal ya agrupa por proveedor para concatenar sus tipos de tela; unir el
         * detalle multiplicaria las filas y las sumas saldrian infladas.
         */
        $resumenCompras = self::resumenComprasSubquery();

        $queryBuilder = DB::table('bylopez_cc_proveedores_tela as p')
            ->leftJoin('bylopez_cc_proveedor_tela_tipos as ptt', 'p.id', '=', 'ptt.proveedor_tela_id')
            ->leftJoin('attribute_options as ao', 'ptt.tipo_tela_id', '=', 'ao.id')
            ->leftJoinSub($resumenCompras, 'compras_resumen', 'p.id', '=', 'compras_resumen.proveedor_id')
            ->select(
                'p.id',
                'p.nombre',
                'p.nit',
                'p.telefono',
                'p.contacto',
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT ao.admin_name ORDER BY ao.sort_order, ao.admin_name SEPARATOR ', '), p.tipo) as tipo"),
                'p.estado',
                'p.created_at',
                DB::raw('COALESCE(compras_resumen.total_compras, 0) as total_compras'),
                DB::raw('COALESCE(compras_resumen.total_comprado, 0) as total_comprado'),
                DB::raw('COALESCE(compras_resumen.total_rollos_comprados, 0) as total_rollos_comprados')
            )
            ->groupBy(
                'p.id', 'p.nombre', 'p.nit', 'p.telefono', 'p.contacto', 'p.tipo', 'p.estado', 'p.created_at',
                'compras_resumen.total_compras', 'compras_resumen.total_comprado', 'compras_resumen.total_rollos_comprados'
            );

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
            'total_compras' => 'Compras',
            'total_comprado' => 'Total comprado',
            'total_rollos_comprados' => 'Rollos comprados',
        ] as $index => $label) {
            $esResumen = in_array($index, ['total_compras', 'total_comprado', 'total_rollos_comprados'], true);

            $column = [
                'index' => $index,
                'label' => $label,
                'type' => $esResumen ? 'integer' : 'string',
                'searchable' => in_array($index, ['nombre', 'nit', 'contacto'], true),
                // El resumen se ordena, pero no se filtra: son agregados, no atributos del proveedor.
                'filterable' => ! $esResumen,
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
        if (bouncer()->hasPermission('corte_confeccion.proveedores_tela')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.proveedores_tela.show', $row->id)]);
        }

        // Mismo patron que TallerDataGrid: el icono "Editar" del listado apunta a la
        // MISMA ficha de detalle, no a una pagina de edicion aparte. La edicion real
        // ocurre por el modal de esa ficha; la pagina completa (tipos de tela y
        // referencias en bloque) sigue existiendo, pero ya no es la accion principal.
        if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.proveedores_tela.show', $row->id)]);
        }
    }

    protected function nombreEnlace($row): string
    {
        if (! bouncer()->hasPermission('corte_confeccion.proveedores_tela')) {
            return e($row->nombre);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.proveedores_tela.show', $row->id),
            e($row->nombre)
        );
    }
}
