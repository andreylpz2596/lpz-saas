<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
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

        if ($cortadorId = request()->query('cortador_id')) {
            $queryBuilder->where('o.cortador_id', $cortadorId);
        }

        CortadorAccess::filtrarOrdenesCorteSiAplica($queryBuilder, 'o.cortador_id');

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
                'index' => $index,
                'label' => $label,
                'type' => match ($index) {
                    'cantidad_rollos', 'total_camisetas_cortadas' => 'integer',
                    'peso_total' => 'decimal',
                    'fecha_entrega' => 'date',
                    default => 'string',
                },
                'searchable' => in_array($index, ['codigo', 'cortador_nombre_snapshot'], true),
                'filterable' => in_array($index, ['codigo', 'estado', 'cortador_nombre_snapshot', 'fecha_entrega'], true),
                'sortable' => true,
            ];

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => $this->estadoBadge((string) $row->estado);
            }

            if ($index === 'codigo') {
                $column['closure'] = fn ($row) => $this->codigoEnlace($row);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('corte_confeccion.ordenes_corte')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.ordenes_corte.show', $row->id)]);

            // No existe una pantalla de edicion separada: la solicitud es de solo lectura una vez
            // creada, y el resto del flujo (reportar resultado, confirmar recepcion, cambiar de
            // estado) ocurre dentro de la misma vista de detalle. "Editar" apunta ahi.
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.ordenes_corte.show', $row->id)]);
        }
    }

    protected function estadoBadge(string $estado): string
    {
        return EstadoOrdenCorte::badgeHtml($estado);
    }

    protected function codigoEnlace($row): string
    {
        if (! bouncer()->hasPermission('corte_confeccion.ordenes_corte')) {
            return e($row->codigo);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.ordenes_corte.show', $row->id),
            e($row->codigo)
        );
    }
}
