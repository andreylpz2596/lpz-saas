<?php

namespace ByLopez\Logistica\Enums;

/**
 * Quien/como se conocio el hecho (LOG-D-02, bloque 6). Se audita siempre; no autoriza nada por si sola.
 *
 * - bodega_manual / supervisor_manual: registro manual en el panel (supervisor = quien ademas puede anular).
 * - recogida_bodega: el cliente RETIRO en bodega (la cita de `pickup_orders` NO cuenta: bloque 12).
 * - integracion_transportadora: reservada; no existe integracion todavia (99envios es radar).
 * - reporte_proveedor: el hecho lo REPORTO el proveedor intermediario (99 Envios) en el cargue «Envios completos» y lo registro
 *   el administrador que ejecuto ese cargue (Cargues/docs/16). Solo la usa la extension del cargue: el panel nunca la elige
 *   (FuentePanel decide la fuente de los registros manuales). La evidencia guarda carga, fila, estado y fecha del proveedor.
 */
enum FuenteEventoLogistico: string
{
    case BODEGA_MANUAL = 'bodega_manual';
    case SUPERVISOR_MANUAL = 'supervisor_manual';
    case RECOGIDA_BODEGA = 'recogida_bodega';
    case INTEGRACION_TRANSPORTADORA = 'integracion_transportadora';
    case REPORTE_PROVEEDOR = 'reporte_proveedor';

    /** Hoy solo hay registro manual: la integracion se habilitara con su propia fase. */
    public function disponible(): bool
    {
        return $this !== self::INTEGRACION_TRANSPORTADORA;
    }

    /** Un retorno de transportadora nunca viene de un retiro en bodega. */
    public function validaParaRetorno(): bool
    {
        return $this !== self::RECOGIDA_BODEGA;
    }
}
