<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

/**
 * «Referencias y precios» de un taller o de un cortador (Doc. 05): una fila por combinacion tipo de tela + referencia
 * (familia Bagisto) con la tarifa que rige hoy y, si la hay, la siguiente ya programada. Las vigencias anteriores ya
 * reemplazadas y las anuladas no se listan aqui: estan en «Historial» de cada fila.
 *
 * El prestador y su tipo salen de la ruta (`tipo` = default de la ruta, `id`); el controlador autoriza el acceso a la
 * ficha antes de devolver el DataGrid, igual que el listado de referencias del proveedor de tela.
 */
class TarifaReferenciaDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $columna = request()->route('tipo') === TarifaPrestadorGate::TIPO_CORTADOR ? 'cortador_id' : 'taller_id';
        $prestadorId = (int) request()->route('id');

        $queryBuilder = DB::table('bylopez_cc_tarifas_prestador as t')
            ->join('attribute_options as ao', 'ao.id', '=', 't.tipo_tela_id')
            ->join('attribute_families as af', 'af.id', '=', 't.attribute_family_id')
            ->where('t.'.$columna, $prestadorId ?: 0)
            ->whereNull('t.anulada_en')
            // Oculta las vigencias ya reemplazadas por otra activa que ya empezo.
            ->whereNotExists(function ($query) use ($columna) {
                $query->select(DB::raw(1))
                    ->from('bylopez_cc_tarifas_prestador as n')
                    ->whereColumn('n.'.$columna, 't.'.$columna)
                    ->whereColumn('n.tipo_tela_id', 't.tipo_tela_id')
                    ->whereColumn('n.attribute_family_id', 't.attribute_family_id')
                    ->whereNull('n.anulada_en')
                    ->whereColumn('n.vigente_desde', '>', 't.vigente_desde')
                    ->whereRaw('n.vigente_desde <= CURDATE()');
            })
            ->select(
                't.id',
                't.tipo_tela_id',
                't.attribute_family_id',
                'ao.admin_name as tipo_tela',
                'af.name as referencia',
                't.valor_unitario',
                't.vigente_desde',
                't.observacion',
                DB::raw('(select min(s.vigente_desde) from bylopez_cc_tarifas_prestador as s where s.'.$columna.' = t.'.$columna.' and s.tipo_tela_id = t.tipo_tela_id and s.attribute_family_id = t.attribute_family_id and s.anulada_en is null and s.vigente_desde > t.vigente_desde) as siguiente_desde')
            );

        $this->addFilter('tipo_tela', 'ao.admin_name');
        $this->addFilter('referencia', 'af.name');
        $this->addFilter('valor_unitario', 't.valor_unitario');
        $this->addFilter('vigente_desde', 't.vigente_desde');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'tipo_tela',
            'label' => 'Tipo de tela',
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'referencia',
            'label' => 'Referencia',
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'valor_unitario',
            'label' => 'Valor por unidad',
            'type' => 'decimal',
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'vigente_desde',
            'label' => 'Vigencia',
            'type' => 'date',
            'filterable' => true,
            'sortable' => true,
            'closure' => function ($row) {
                $desde = substr((string) $row->vigente_desde, 0, 10);
                $estado = $desde <= now()->toDateString()
                    ? '<span class="label-active">Vigente</span>'
                    : '<span class="label-pending">Programada</span>';
                $hasta = $row->siguiente_desde
                    ? 'hasta '.now()->parse($row->siguiente_desde)->subDay()->toDateString()
                    : 'sin fecha de fin';

                return '<span class="flex flex-col gap-1">'.$estado.'<span class="text-xs text-gray-600 dark:text-gray-300">Desde '.e($desde).' · '.e($hasta).'</span></span>';
            },
        ]);
    }

    public function prepareActions(): void
    {
        $this->addAction(['icon' => 'icon-view', 'title' => 'Historial', 'method' => 'GET', 'url' => fn ($row) => '#historial-'.$row->id]);

        $tipo = request()->route('tipo');

        if (TarifaPrestadorGate::puedeGestionar((string) $tipo)) {
            $this->addAction(['icon' => 'icon-edit', 'title' => 'Cambiar tarifa', 'method' => 'GET', 'url' => fn ($row) => '#cambiar-'.$row->id]);
            $this->addAction(['icon' => 'icon-cancel', 'title' => 'Anular vigencia', 'method' => 'GET', 'url' => fn ($row) => '#anular-'.$row->id]);
        }
    }
}
