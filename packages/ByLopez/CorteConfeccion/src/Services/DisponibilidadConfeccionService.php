<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use Illuminate\Support\Collection;

/**
 * Calcula, para una orden de corte ya recibida en bodega, cuantas piezas por
 * variante (product_id) estan aptas y disponibles para distribuir a talleres
 * de confeccion.
 *
 * cantidad_apta_confeccion    = cantidad_recibida - cantidad_defectuosa
 * cantidad_disponible_confeccion = cantidad_apta_confeccion - SUM(cantidad_entregada a talleres)
 *
 * La clave de agrupacion es unicamente product_id: en Bagisto cada
 * product_id de estos detalles ya es el producto "simple" (variante exacta
 * color+talla), asi que sumarle `talla` a la clave no aporta seguridad y si
 * puede fragmentar el disponible si `talla` llega inconsistente entre filas
 * (ver docs/confeccion/02_MODELO_DISTRIBUCION.md §3).
 *
 * F0.1 (docs/confeccion/STATUS.md): un mismo product_id puede aparecer en
 * MAS DE UNA fila de RecepcionCorteDetalle de la misma recepcion -- ej. una
 * fila de la solicitud original y una fila "adicional" reportada por el
 * cortador para el mismo producto (ver AgrupadorDetallesCorte, que agrupa
 * exactamente por esa razon en las vistas de solicitud/resultado). Por eso
 * porOrdenCorte() AGREGA (suma) las filas de recepcion por product_id antes
 * de calcular disponible -- nunca debe devolver mas de una fila por
 * product_id, ni tomar "la primera" o "la ultima" descartando el resto.
 *
 * Las OrdenConfeccion en estado "anulada" NO cuentan como entregadas: anular
 * libera la cantidad de vuelta al pool disponible (regla temporal aprobada
 * en docs/confeccion/02_MODELO_DISTRIBUCION.md §4, mientras no exista un
 * flujo de retorno fisico del taller).
 *
 * No persiste ningun valor: se calcula siempre a partir de RecepcionCorteDetalle
 * y de las OrdenConfeccionDetalle ya creadas, igual que el resto del modulo
 * evita duplicar datos derivables.
 */
class DisponibilidadConfeccionService
{
    public function porOrdenCorte(OrdenCorte $ordenCorte): Collection
    {
        $ordenCorte->loadMissing(['recepcion.detalles', 'ordenesConfeccion.detalles']);

        $entregadoPorProductId = $ordenCorte->ordenesConfeccion
            ->where('estado', '!=', EstadoOrdenConfeccion::Anulada->value)
            ->flatMap(fn ($ordenConfeccion) => $ordenConfeccion->detalles)
            ->groupBy('product_id')
            // Responsabilidad VIGENTE (asignado - reasignado a otro taller): una reasignacion mueve la
            // responsabilidad entre ordenes sin alterar lo que ya esta fuera del pool de distribucion.
            ->map(fn ($items) => (int) $items->sum(fn ($detalle) => $detalle->cantidad_vigente));

        return collect($ordenCorte->recepcion?->detalles ?? [])
            // Nunca fusionar filas sin product_id entre si (edge case teorico:
            // OrdenCorteDetalle.product_id es nullable en el esquema, aunque
            // el flujo normal siempre lo exige). Cada fila sin product_id
            // conserva su propio grupo via su propio id, en vez de agruparse
            // con cualquier otra fila igualmente nula.
            ->groupBy(fn ($detalle) => $detalle->product_id ?? 'sin-product-id-'.$detalle->id)
            ->map(function ($detallesDelProducto) use ($entregadoPorProductId) {
                $primero = $detallesDelProducto->first();
                $cantidadRecibida = (int) $detallesDelProducto->sum('cantidad_recibida');
                $cantidadDefectuosa = (int) $detallesDelProducto->sum('cantidad_defectuosa');
                $apta = max($cantidadRecibida - $cantidadDefectuosa, 0);
                $entregado = (int) ($entregadoPorProductId[$primero->product_id] ?? 0);

                return [
                    'product_id' => $primero->product_id,
                    'producto_sku' => $primero->producto_sku,
                    'producto_nombre' => $primero->producto_nombre,
                    'talla' => $primero->talla,
                    'cantidad_recibida' => $cantidadRecibida,
                    'cantidad_defectuosa' => $cantidadDefectuosa,
                    'cantidad_apta_confeccion' => $apta,
                    'cantidad_entregada_talleres' => $entregado,
                    'cantidad_disponible_confeccion' => max($apta - $entregado, 0),
                ];
            })->values();
    }

    public function disponiblePara(OrdenCorte $ordenCorte, ?int $productId): int
    {
        $fila = $this->porOrdenCorte($ordenCorte)->first(
            fn ($fila) => (int) $fila['product_id'] === (int) $productId
        );

        return (int) ($fila['cantidad_disponible_confeccion'] ?? 0);
    }

    public function resumenPorOrdenCorte(OrdenCorte $ordenCorte): array
    {
        $filas = $this->porOrdenCorte($ordenCorte);
        $totalPendiente = (int) $filas->sum('cantidad_disponible_confeccion');

        return [
            'total_apto_confeccion' => (int) $filas->sum('cantidad_apta_confeccion'),
            'total_distribuido' => (int) $filas->sum('cantidad_entregada_talleres'),
            'total_pendiente' => $totalPendiente,
            'puede_distribuir' => $ordenCorte->estado !== EstadoOrdenCorte::Anulado->value && $totalPendiente > 0,
        ];
    }

    public function puedeDistribuir(OrdenCorte $ordenCorte): bool
    {
        return (bool) $this->resumenPorOrdenCorte($ordenCorte)['puede_distribuir'];
    }
}
