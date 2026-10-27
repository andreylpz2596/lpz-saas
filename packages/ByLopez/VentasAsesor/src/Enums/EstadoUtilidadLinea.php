<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estado del calculo del MARGEN COMISIONABLE de una linea vendida (COM-02; en codigo, «utilidad»). Ningun estado
 * significa «comision ganada».
 */
enum EstadoUtilidadLinea: string
{
    /** Hay costo congelado SIN autoridad (fuente historica de referencia): margen solo informativo. */
    case DIAGNOSTICA = 'diagnostica';

    /** Hay costo comisionable congelado CON autoridad (D-COST-02): margen comisionable valido. */
    case AUTORIZADA = 'autorizada';

    /** Snapshot sin costo (`costo_unitario` NULL). Excluida de todo calculo financiero. */
    case SIN_COSTO = 'sin_costo';

    /** Linea sin snapshot (pedido historico o fallo de captura). Excluida; no se reconstruye (COM-D-13). */
    case SIN_SNAPSHOT = 'sin_snapshot';

    /** Sin unidades vigentes (todo cancelado) o `qty_ordered` = 0: no hay precio unitario calculable. */
    case SIN_CANTIDAD = 'sin_cantidad';

    public function tieneUtilidad(): bool
    {
        return $this === self::DIAGNOSTICA || $this === self::AUTORIZADA;
    }
}
