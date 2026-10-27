<?php

namespace ByLopez\CorteConfeccion\DataGrids;

use ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class InventarioMovimientoDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    // Todas las tablas unidas (rd/r/oc/t/p) tienen su propia columna
    // created_at; sin calificar con la tabla, el ORDER BY por defecto
    // (cuando el usuario no pide un sort explicito) seria ambiguo para MySQL.
    protected $sortColumn = 'im.created_at';

    protected $sortOrder = 'desc';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('bylopez_cc_inventario_movimientos as im')
            ->leftJoin('bylopez_cc_ordenes_confeccion_retorno_detalles as rd', 'rd.id', '=', 'im.retorno_detalle_id')
            ->leftJoin('bylopez_cc_ordenes_confeccion_retornos as r', 'r.id', '=', 'im.retorno_id')
            ->leftJoin('bylopez_cc_ordenes_confeccion as oc', 'oc.id', '=', 'im.orden_confeccion_id')
            ->leftJoin('bylopez_cc_talleres as t', 't.id', '=', 'oc.taller_id')
            ->leftJoin('products as p', 'p.id', '=', 'im.product_id')
            ->select(
                'im.id',
                'im.created_at',
                'im.tipo_movimiento',
                'im.cantidad_anterior',
                'im.cantidad_movimiento',
                'im.cantidad_nueva',
                'im.nombre_usuario_snapshot',
                'im.orden_confeccion_id',
                'oc.codigo as orden_confeccion_codigo',
                'r.codigo as retorno_codigo',
                't.nombre as taller_nombre',
                // El SKU/nombre/talla se leen del snapshot ya guardado en el
                // detalle del retorno (mismo dato que ya se ve en el
                // historial de entregas); solo se cae al producto en vivo
                // para movimientos sin retorno_detalle_id (ajuste manual).
                DB::raw('COALESCE(rd.producto_sku, p.sku) as producto_sku'),
                DB::raw('COALESCE(rd.producto_nombre, p.sku) as producto_nombre'),
                'rd.talla'
            );

        $this->addFilter('created_at', 'im.created_at');
        $this->addFilter('tipo_movimiento', 'im.tipo_movimiento');
        $this->addFilter('orden_confeccion_codigo', 'oc.codigo');
        $this->addFilter('retorno_codigo', 'r.codigo');
        // Filtra sobre el SKU en vivo del producto (im.product_id siempre
        // esta poblado, a diferencia de rd.producto_sku que es null en un
        // ajuste manual) para que el filtro/busqueda funcione para
        // cualquier tipo de movimiento, no solo entrada_confeccion.
        $this->addFilter('producto_sku', 'p.sku');
        $this->addFilter('taller_nombre', 't.nombre');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        foreach ([
            'created_at' => 'Fecha y hora',
            'tipo_movimiento' => 'Movimiento',
            'producto_sku' => 'Producto / Variante',
            'stock_cambio' => 'Cambio de stock',
            'orden_confeccion_codigo' => 'Orden confeccion',
            'retorno_codigo' => 'Entrega',
            'taller_nombre' => 'Taller',
            'nombre_usuario_snapshot' => 'Usuario',
        ] as $index => $label) {
            $column = [
                'index' => $index,
                'label' => $label,
                'type' => match ($index) {
                    'created_at' => 'date',
                    default => 'string',
                },
                'searchable' => in_array($index, ['producto_sku', 'orden_confeccion_codigo', 'retorno_codigo'], true),
                // Solo se exponen como filtro los campos pedidos (fecha,
                // tipo, orden, entrega, sku/producto, taller, usuario); las
                // columnas de cantidad son de solo lectura para mantener el
                // panel de filtros simple, sin ruido visual.
                'filterable' => ! in_array($index, ['stock_cambio', 'nombre_usuario_snapshot'], true),
                'sortable' => $index !== 'stock_cambio',
            ];

            if ($index === 'created_at') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::fechaHoraHtml($row);
            }

            if ($index === 'tipo_movimiento') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::tipoMovimientoHtml($row);
            }

            if ($index === 'producto_sku') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::productoVarianteHtml($row);
            }

            if ($index === 'stock_cambio') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::stockCambioHtml($row);
            }

            if ($index === 'orden_confeccion_codigo') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::ordenConfeccionEnlace($row);
            }

            if ($index === 'retorno_codigo') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::entregaHtml($row);
            }

            if ($index === 'taller_nombre') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::tallerHtml($row);
            }

            if ($index === 'nombre_usuario_snapshot') {
                $column['closure'] = fn ($row) => InventarioMovimientoHtml::usuarioHtml($row);
            }

            $this->addColumn($column);
        }
    }

    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-view',
            'title' => 'Ver',
            'method' => 'GET',
            'url' => fn ($row) => route('admin.corte_confeccion.inventario_movimientos.show', $row->id),
        ]);
    }
}
