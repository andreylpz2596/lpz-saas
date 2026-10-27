<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;

/**
 * Agrupa OrdenCorteDetalle por producto + genero/linea para las vistas de
 * Solicitud y Resultado real (accordions). Centralizado aqui para que las
 * 3 cards (solicitud, resultado solicitado, adicionales) usen exactamente
 * la misma logica de agrupacion, evitando duplicarla en Blade.
 */
class AgrupadorDetallesCorte
{
    public static function agrupar(iterable $detalles): Collection
    {
        return collect($detalles)
            // preserveKeys=true: Collection::groupBy() reindexa cada grupo desde 0 por
            // defecto. Se necesita la clave original de $detalles (el indice dentro de
            // $orden->detalles) para que vistas como el formulario de recepcion generen
            // name="detalles[$i]..." consistente con el orden que espera
            // RecepcionCorteService::create().
            // F4: se agrupa por linea + nombre (una orden V2 tiene un simple por talla, con el
            // mismo nombre de linea x color): una tarjeta por linea/color con sus tallas.
            // Un nombre vacio cae al product_id para no fusionar filas sin nombre.
            ->groupBy(fn ($detalle) => trim((string) $detalle->genero_linea).'|'.(trim((string) $detalle->producto_nombre) ?: 'p'.$detalle->product_id), true)
            ->map(fn ($grupo) => [
                'producto_nombre' => $grupo->first()->producto_nombre,
                'producto_sku' => $grupo->first()->producto_sku,
                'genero_linea' => $grupo->first()->genero_linea,
                'color' => $grupo->first()->color,
                'filas' => $grupo,
                'total_proyectado' => (int) $grupo->sum('cantidad_proyectada'),
                'total_real' => (int) $grupo->sum('cantidad_real_cortada'),
                'cantidad_tallas' => $grupo->count(),
            ])
            ->values();
    }
}
