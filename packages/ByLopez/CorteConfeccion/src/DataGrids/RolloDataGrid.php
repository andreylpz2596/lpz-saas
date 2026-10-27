<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class RolloDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    /**
     * Clave sintetica usada por las cards de resumen para agrupar
     * "agotado" + "anulado" bajo una sola card "Agotados/cerrados".
     */
    public const FILTRO_AGOTADO_CERRADO = 'agotado_cerrado';

    public function prepareQueryBuilder()
    {
        // Orden de corte ACTIVA (no cerrada/anulada) que tiene reservado este rollo, si existe.
        // Por regla de negocio (OrdenCorteService::validarRollosDisponibles) un rollo no puede
        // estar reservado en mas de una orden abierta a la vez, asi que este join no duplica filas.
        $reservaActiva = DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_ordenes_corte as oc', 'ocr.orden_corte_id', '=', 'oc.id')
            ->whereNotIn('oc.estado', [EstadoOrdenCorte::Cerrado->value, EstadoOrdenCorte::Anulado->value])
            ->select('ocr.rollo_id', 'oc.id as orden_corte_id', 'oc.codigo as orden_corte_codigo');

        $queryBuilder = DB::table('bylopez_cc_rollos as r')
            ->leftJoinSub($reservaActiva, 'reserva', 'r.id', '=', 'reserva.rollo_id')
            ->select(
                'r.id',
                'r.codigo',
                'r.proveedor',
                'r.color',
                // F1: dato auxiliar (no columna registrada) para que
                // v-rollo-edit-modal hidrate el select de color al editar.
                'r.color_option_id',
                'r.proveedor_tela_referencia_id',
                'r.referencia',
                'r.tipo_tela',
                'r.peso_inicial',
                'r.peso_disponible',
                'r.costo_total',
                'r.observacion',
                'r.estado',
                'r.estado as estado_raw',
                'r.fecha_compra',
                'r.created_at',
                DB::raw('EXISTS (SELECT 1 FROM bylopez_cc_orden_corte_rollos ocr_all WHERE ocr_all.rollo_id = r.id) as tiene_orden_corte_rollos'),
                DB::raw('EXISTS (SELECT 1 FROM bylopez_cc_ordenes_corte oc_legacy WHERE oc_legacy.rollo_id = r.id) as tiene_orden_corte_legacy'),
                'reserva.orden_corte_id',
                'reserva.orden_corte_codigo'
            );

        $estadoSolicitado = request()->query('estado');

        if ($estadoSolicitado === self::FILTRO_AGOTADO_CERRADO) {
            $queryBuilder->whereIn('r.estado', [EstadoRollo::Agotado->value, EstadoRollo::Anulado->value]);
        } elseif (in_array($estadoSolicitado, array_column(EstadoRollo::cases(), 'value'), true)) {
            $queryBuilder->where('r.estado', $estadoSolicitado);
        }

        foreach (['id', 'codigo', 'proveedor', 'color', 'referencia', 'tipo_tela', 'estado'] as $field) {
            $this->addFilter($field, 'r.'.$field);
        }

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'codigo' => 'Codigo',
            'tipo_tela' => 'Tela',
            'referencia' => 'Referencia',
            'color' => 'Color',
            'peso_inicial' => 'Peso inicial',
            'peso_disponible' => 'Peso disponible',
            'porcentaje_disponible' => '% Disponible',
            'estado' => 'Estado',
            'orden_corte_codigo' => 'Orden de corte',
            'fecha_compra' => 'Compra',
            'proveedor' => 'Proveedor',
        ] as $index => $label) {
            $column = [
                'index' => $index,
                'label' => $label,
                'type' => 'string',
                'searchable' => in_array($index, ['codigo', 'proveedor', 'color'], true),
                'filterable' => in_array($index, ['codigo', 'proveedor', 'color', 'referencia', 'tipo_tela', 'estado'], true),
                'sortable' => ! in_array($index, ['porcentaje_disponible', 'orden_corte_codigo'], true),
            ];

            if ($index === 'estado') {
                $column['closure'] = fn ($row) => EstadoRollo::badgeHtml($row->estado);
            }

            if ($index === 'porcentaje_disponible') {
                $column['closure'] = fn ($row) => $this->porcentajeDisponible($row);
            }

            if ($index === 'orden_corte_codigo') {
                $column['closure'] = fn ($row) => $this->ordenCorteEnlace($row);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('corte_confeccion.rollos')) {
            $this->addAction(['icon' => 'icon-eye', 'title' => 'Ver', 'method' => 'GET', 'url' => fn ($row) => route('admin.corte_confeccion.rollos.show', $row->id)]);
        }

        if (bouncer()->hasPermission('corte_confeccion.rollos.edit')) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Editar', 'method' => 'PUT', 'url' => fn ($row) => route('admin.corte_confeccion.rollos.update', $row->id)]);
        }
    }

    protected function porcentajeDisponible($row): string
    {
        $pesoInicial = (float) $row->peso_inicial;

        if ($pesoInicial <= 0) {
            return '—';
        }

        $porcentaje = min(max(((float) $row->peso_disponible / $pesoInicial) * 100, 0), 100);

        return number_format($porcentaje, 1, ',', '.').' %';
    }

    protected function ordenCorteEnlace($row): string
    {
        if (! $row->orden_corte_id || ! $row->orden_corte_codigo) {
            return '—';
        }

        if (! bouncer()->hasPermission('corte_confeccion.ordenes_corte')) {
            return e($row->orden_corte_codigo);
        }

        return sprintf(
            '<a class="text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.ordenes_corte.show', $row->orden_corte_id),
            e($row->orden_corte_codigo)
        );
    }
}
