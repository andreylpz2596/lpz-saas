<?php

namespace ByLopez\Logistica\Enums;

/**
 * Quien/como se conocio el hecho (LOG-D-02, bloque 6). Se audita siempre; no autoriza nada por si sola.
 *
 * - bodega_manual / supervisor_manual: registro manual en el panel (supervisor = quien ademas puede anular).
 * - recogida_bodega: el cliente RETIRO en bodega (la cita de `pickup_orders` NO cuenta: bloque 12).
 * - integracion_transportadora: reservada; no existe integracion todavia (99envios es radar).
 */
enum FuenteEventoLogistico: string
{
    case BODEGA_MANUAL = 'bodega_manual';
    case SUPERVISOR_MANUAL = 'supervisor_manual';
    case RECOGIDA_BODEGA = 'recogida_bodega';
    case INTEGRACION_TRANSPORTADORA = 'integracion_transportadora';

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
