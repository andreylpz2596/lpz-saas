<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Support\OrdenConfeccionGate;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class OrdenConfeccionDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $detallesResumen = DB::table('bylopez_cc_ordenes_confeccion_detalles')
            ->select(
                'orden_confeccion_id',
                DB::raw('SUM(cantidad_entregada) as total_entregado')
            )
            ->groupBy('orden_confeccion_id');

        $retornosResumen = DB::table('bylopez_cc_ordenes_confeccion_retornos as r')
            ->join('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.retorno_id', '=', 'r.id')
            ->select(
                'r.orden_confeccion_id',
                DB::raw('SUM(rd.cantidad_reportada) as total_reportado_taller'),
                DB::raw('SUM(COALESCE(rd.cantidad_recibida, 0)) as total_recibido_bodega'),
                DB::raw('SUM(COALESCE(rd.cantidad_defectuosa, 0)) as total_defectuoso_bodega'),
                DB::raw('SUM(COALESCE(rd.cantidad_faltante, 0)) as total_faltante_bodega')
            )
            // COUNT(DISTINCT r.id): el join con retorno_detalles multiplica filas por
            // detalle dentro del mismo retorno, asi que se cuentan retornos (no
            // detalles) unicos en estado pendiente_bodega.
            ->selectRaw('COUNT(DISTINCT CASE WHEN r.estado = ? THEN r.id END) as retornos_pendientes', [EstadoOrdenConfeccionRetorno::PendienteBodega->value])
            ->groupBy('r.orden_confeccion_id');

        $queryBuilder = DB::table('bylopez_cc_ordenes_confeccion as ofc')
            ->join('bylopez_cc_talleres as t', 'ofc.taller_id', '=', 't.id')
            ->join('bylopez_cc_ordenes_corte as oc', 'ofc.orden_corte_id', '=', 'oc.id')
            ->leftJoin('bylopez_cc_recepciones_corte as rc', 'rc.orden_corte_id', '=', 'oc.id')
            ->leftJoinSub($detallesResumen, 'resumen', 'ofc.id', '=', 'resumen.orden_confeccion_id')
            ->leftJoinSub($retornosResumen, 'retornos_resumen', 'ofc.id', '=', 'retornos_resumen.orden_confeccion_id')
            ->select(
                'ofc.id',
                'ofc.codigo',
                'oc.id as orden_corte_id',
                'oc.codigo as orden_corte_codigo',
                'rc.fecha_recepcion as corte_fecha_recepcion',
                't.nombre as taller_nombre',
                'ofc.estado',
                DB::raw('COALESCE(resumen.total_entregado, 0) as total_entregado'),
                DB::raw('COALESCE(retornos_resumen.total_reportado_taller, 0) as total_reportado_taller'),
                DB::raw('COALESCE(retornos_resumen.total_recibido_bodega, 0) as total_recibido_bodega'),
                DB::raw('COALESCE(retornos_resumen.retornos_pendientes, 0) as retornos_pendientes'),
                DB::raw('CASE WHEN COALESCE(resumen.total_entregado, 0) > 0 THEN LEAST(100, ROUND((COALESCE(retornos_resumen.total_reportado_taller, 0) / resumen.total_entregado) * 100)) ELSE 0 END as progreso_confeccion'),
                'ofc.fecha_entrega',
                'ofc.created_at'
            );

        // Vista de detalle de Taller: filtra por taller via query string, mismo
        // patron ya usado en RolloDataGrid para las cards clicables de Rollos.
        if ($tallerId = (int) request()->query('taller_id')) {
            $queryBuilder->where('ofc.taller_id', $tallerId);
        }

        if (OrdenConfeccionGate::debeFiltrarListadoPorTaller()) {
            $queryBuilder->where('t.usuario_id', auth()->guard('admin')->id());
        }

        $this->addFilter('codigo', 'ofc.codigo');
        $this->addFilter('orden_corte_codigo', 'oc.codigo');
        $this->addFilter('taller_nombre', 't.nombre');
        $this->addFilter('estado', 'ofc.estado');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'codigo' => 'Codigo',
            'orden_corte_codigo' => 'Corte de origen',
            'corte_fecha_recepcion' => 'Corte recibido el',
            'taller_nombre' => 'Taller',
            'estado' => 'Estado',
            'total_entregado' => 'Total entregado',
            'total_reportado_taller' => 'Reportado taller',
            'progreso_confeccion' => 'Progreso',
            'total_recibido_bodega' => 'Recibido bodega',
            'retornos_pendientes' => 'Entregas pendientes',
            'fecha_entrega' => 'Fecha entrega',
        ] as $index => $label) {
            $column = [
                'index' => $index,
                'label' => $label,
                'type' => match ($index) {
                    'total_entregado', 'total_reportado_taller', 'total_recibido_bodega', 'progreso_confeccion', 'retornos_pendientes' => 'integer',
                    'fecha_entrega', 'corte_fecha_recepcion' => 'date',
                    default => 'string',
                },
                'searchable' => in_array($index, ['codigo', 'orden_corte_codigo', 'taller_nombre'], true),
                'filterable' => in_array($index, ['codigo', 'orden_corte_codigo', 'taller_nombre', 'estado'], true),
                'sortable' => true,
            ];

            if ($index === 'codigo') {
                $column['closure'] = fn ($row) => $this->codigoEnlace($row);
            }

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => EstadoOrdenConfeccion::badgeHtml($row->estado);
            }

            if ($index === 'orden_corte_codigo') {
                $column['closure'] = fn ($row) => $this->ordenCorteEnlace($row);
            }

            if ($index === 'progreso_confeccion') {
                $column['closure'] = fn ($row) => $this->progresoHtml($row);
            }

            if ($index === 'retornos_pendientes') {
                $column['closure'] = fn ($row) => $this->retornosPendientesHtml($row);
            }

            $this->addColumn($column);
        }
    }

    protected function progresoHtml($row): string
    {
        $totalEntregado = max((int) $row->total_entregado, 0);
        $totalReportado = max((int) $row->total_reportado_taller, 0);
        $porcentaje = $totalEntregado > 0
            ? min(100, (int) round(($totalReportado / $totalEntregado) * 100))
            : 0;

        return sprintf(
            '<div class="min-w-[96px] max-w-[120px]"><div class="mb-1 text-sm font-medium text-gray-800 dark:text-white">%d%%</div><div class="h-1.5 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-800"><div class="h-1.5 rounded bg-blue-600" style="width: %d%%"></div></div></div>',
            $porcentaje,
            $porcentaje
        );
    }

    protected function retornosPendientesHtml($row): string
    {
        $pendientes = (int) $row->retornos_pendientes;

        if ($pendientes <= 0) {
            return '<p class="text-sm text-gray-400 dark:text-gray-500">—</p>';
        }

        return sprintf(
            '<p class="label-pending">%d %s</p>',
            $pendientes,
            $pendientes === 1 ? 'entrega pendiente' : 'entregas pendientes'
        );
    }

    protected function codigoEnlace($row): string
    {
        if (! OrdenConfeccionGate::puedeVer()) {
            return e($row->codigo);
        }

        $ruta = route('admin.corte_confeccion.ordenes_confeccion.show', $row->id);

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            $ruta,
            e($row->codigo)
        );
    }

    protected function ordenCorteEnlace($row): string
    {
        if (! bouncer()->hasPermission('corte_confeccion.ordenes_corte')) {
            return e($row->orden_corte_codigo);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.ordenes_corte.show', $row->orden_corte_id),
            e($row->orden_corte_codigo)
        );
    }

    public function prepareActions(): void
    {
        if (OrdenConfeccionGate::puedeVer()) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.ordenes_confeccion.show', $row->id)]);
        }
    }
}
