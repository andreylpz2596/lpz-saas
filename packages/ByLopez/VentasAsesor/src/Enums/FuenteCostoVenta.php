<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Origen del costo unitario congelado en `bylopez_va_costos_venta` (COM-01). Fuente UNICA de estos valores.
 *
 * D-COST-02 (2026-10-08): el atributo `cost` de Bagisto es el COSTO COMISIONABLE OFICIAL, fijado por la empresa.
 * No es el costo productivo (CorteConfeccion, D-COST-07) ni la utilidad real de la empresa (D-COST-01). Las filas ya
 * capturadas nunca cambian de fuente.
 */
enum FuenteCostoVenta: string
{
    /** Costo comisionable oficial: `cost` de Bagisto de la variante vendida, valor > 0. CON autoridad de comision. */
    case COST_BAGISTO_COMISIONABLE = 'cost_bagisto_comisionable';

    /**
     * Historico (COM-01 inicial, antes de D-COST-02): mismo atributo capturado como mera referencia, SIN autoridad.
     * Ya no se escribe; se conserva para leer filas antiguas sin reinterpretarlas (en desarrollo no hubo ninguna).
     */
    case ATRIBUTO_COST_BAGISTO_REFERENCIA = 'atributo_cost_bagisto_referencia';

    /** No habia costo utilizable (ausente, cero o tipo de producto no soportado). `costo_unitario` = NULL. */
    case SIN_COSTO = 'sin_costo';

    public function otorgaAutoridadComision(): bool
    {
        return $this === self::COST_BAGISTO_COMISIONABLE;
    }
}
