<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;

/**
 * HTML de cada celda de Movimientos de inventario. Fuente UNICA de la visual
 * aprobada: la usan el DataGrid (inventario-movimientos) y la pestana
 * Movimientos del detalle de Orden de Confeccion, para que ambas se vean igual.
 * Recibe un objeto fila con: created_at, tipo_movimiento, cantidad_anterior,
 * cantidad_movimiento, cantidad_nueva, nombre_usuario_snapshot, orden_confeccion_id,
 * orden_confeccion_codigo, retorno_codigo, taller_nombre, producto_sku, producto_nombre, talla.
 */
final class InventarioMovimientoHtml
{
    /**
     * Adapta un InventarioMovimiento ya cargado (con retorno, retornoDetalle y su
     * orden/taller) a la misma forma de fila que entrega el DataGrid. No consulta.
     */
    public static function filaDesdeModelo($movimiento, $orden, ?string $talla = null): object
    {
        $detalle = $movimiento->retornoDetalle;

        return (object) [
            'id' => $movimiento->id,
            'created_at' => $movimiento->created_at?->format('Y-m-d H:i:s'),
            'tipo_movimiento' => $movimiento->tipo_movimiento,
            'cantidad_anterior' => $movimiento->cantidad_anterior,
            'cantidad_movimiento' => $movimiento->cantidad_movimiento,
            'cantidad_nueva' => $movimiento->cantidad_nueva,
            'nombre_usuario_snapshot' => $movimiento->nombre_usuario_snapshot,
            'orden_confeccion_id' => $movimiento->orden_confeccion_id,
            'orden_confeccion_codigo' => $orden->codigo,
            'retorno_codigo' => $movimiento->retorno?->codigo,
            'taller_nombre' => $orden->taller?->nombre,
            'producto_sku' => $detalle?->producto_sku,
            'producto_nombre' => $detalle?->producto_nombre ?: $detalle?->producto_sku,
            'talla' => $detalle?->talla ?: $talla,
        ];
    }

    public static function tipoMovimientoHtml($row): string
    {
        $tipo = TipoInventarioMovimiento::tryFrom((string) $row->tipo_movimiento);

        $clases = match ($tipo) {
            TipoInventarioMovimiento::EntradaConfeccion => 'inline-flex w-max items-center gap-1 rounded-full bg-green-600 px-2.5 py-1 text-xs font-semibold text-white',
            TipoInventarioMovimiento::AjusteManual => 'inline-flex w-max items-center gap-1 rounded-full bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white',
            TipoInventarioMovimiento::ReingresoDevolucion => 'inline-flex w-max items-center gap-1 rounded-full bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white',
            default => 'inline-flex w-max items-center gap-1 rounded-full bg-gray-600 px-2.5 py-1 text-xs font-semibold text-white',
        };

        $etiqueta = $tipo?->label() ?? e($row->tipo_movimiento);

        return sprintf('<span class="%s"><span class="icon-arrow-down text-sm"></span>%s</span>', $clases, e($etiqueta));
    }

    public static function productoVarianteHtml($row): string
    {
        $nombre = $row->producto_nombre ?: $row->producto_sku;
        $talla = $row->talla ? ' / Talla '.$row->talla : '';

        return sprintf(
            '<div class="min-w-[190px]"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">%s%s</p><p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">SKU %s</p></div>',
            e($nombre),
            e($talla),
            e($row->producto_sku)
        );
    }

    public static function fechaHoraHtml($row): string
    {
        $fecha = $row->created_at ? date('Y-m-d', strtotime($row->created_at)) : '-';
        $hora = $row->created_at ? date('H:i:s', strtotime($row->created_at)) : '-';

        return sprintf(
            '<div class="flex min-w-[112px] items-start gap-2"><span class="icon-calendar text-lg text-blue-600"></span><div><p class="text-sm font-semibold text-gray-900 dark:text-white">%s</p><p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">%s</p></div></div>',
            e($fecha),
            e($hora)
        );
    }

    public static function stockCambioHtml($row): string
    {
        $movimiento = (int) $row->cantidad_movimiento;
        $signo = $movimiento > 0 ? '+' : '';
        $movimientoClases = $movimiento >= 0
            ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-gray-950 dark:text-green-400'
            : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-gray-950 dark:text-red-400';

        return sprintf(
            '<div class="flex min-w-[220px] items-center gap-1.5">
                <div class="min-w-[58px] rounded-md border border-gray-200 bg-gray-50 px-2 py-1.5 text-center dark:border-gray-800 dark:bg-gray-950">
                    <p class="text-[10px] font-semibold uppercase text-gray-500 dark:text-gray-400">Anterior</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">%d</p>
                </div>
                <span class="text-xs text-gray-400">-&gt;</span>
                <div class="min-w-[58px] rounded-md border px-2 py-1.5 text-center %s">
                    <p class="text-[10px] font-semibold uppercase">Entrada</p>
                    <p class="text-sm font-bold">%s%d</p>
                </div>
                <span class="text-xs text-gray-400">-&gt;</span>
                <div class="min-w-[58px] rounded-md border border-blue-200 bg-blue-50 px-2 py-1.5 text-center text-blue-700 dark:border-blue-900 dark:bg-gray-950 dark:text-blue-400">
                    <p class="text-[10px] font-semibold uppercase">Nueva</p>
                    <p class="text-sm font-bold">%d</p>
                </div>
            </div>',
            (int) $row->cantidad_anterior,
            $movimientoClases,
            $signo,
            $movimiento,
            (int) $row->cantidad_nueva
        );
    }

    public static function ordenConfeccionEnlace($row): string
    {
        if (! $row->orden_confeccion_id || ! bouncer()->hasPermission('corte_confeccion.ordenes_confeccion')) {
            return e($row->orden_confeccion_codigo ?? '-');
        }

        return sprintf(
            '<a class="text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400" href="%s">%s</a>',
            route('admin.corte_confeccion.ordenes_confeccion.show', $row->orden_confeccion_id),
            e($row->orden_confeccion_codigo)
        );
    }

    public static function entregaHtml($row): string
    {
        return sprintf('<span class="text-xs text-gray-600 dark:text-gray-300">%s</span>', e($row->retorno_codigo ?? '-'));
    }

    public static function tallerHtml($row): string
    {
        return sprintf(
            '<span class="inline-flex min-w-[96px] items-center gap-1.5 text-xs font-medium text-gray-700 dark:text-gray-300"><span class="icon-store text-base text-gray-500"></span>%s</span>',
            e($row->taller_nombre ?? '-')
        );
    }

    public static function usuarioHtml($row): string
    {
        return sprintf(
            '<span class="inline-flex min-w-[80px] items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><span class="icon-users text-base text-gray-500"></span>%s</span>',
            e($row->nombre_usuario_snapshot ?? '-')
        );
    }
}
