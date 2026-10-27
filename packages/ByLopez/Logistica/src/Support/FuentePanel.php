<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use Webkul\User\Contracts\Admin;

/**
 * Fuente de un registro hecho en el PANEL (LOG-D-02). La decide el sistema, no el operador:
 *
 * - entrega con transportadora RECOGIDA_BODEGA, o de un pedido con metodo `pickup`: `recogida_bodega` (el cliente
 *   retiro; la cita de `pickup_orders` no basta);
 * - quien puede anular (ADMIN / SUPERVISION): `supervisor_manual`;
 * - el resto (BODEGA): `bodega_manual`.
 */
class FuentePanel
{
    public const TRANSPORTADORA_RECOGIDA = 'RECOGIDA_BODEGA';

    public const METODO_RECOGIDA = 'pickup';

    public function __construct(protected AutorizacionLogistica $autorizacion) {}

    public function paraEntrega(?Admin $actor, ?string $codigoTransportadora, ?string $metodoEnvioPedido): Fuente
    {
        if ($codigoTransportadora === self::TRANSPORTADORA_RECOGIDA || $metodoEnvioPedido === self::METODO_RECOGIDA) {
            return Fuente::RECOGIDA_BODEGA;
        }

        return $this->manual($actor);
    }

    public function paraRetorno(?Admin $actor): Fuente
    {
        return $this->manual($actor);
    }

    private function manual(?Admin $actor): Fuente
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_ANULAR)
            ? Fuente::SUPERVISOR_MANUAL
            : Fuente::BODEGA_MANUAL;
    }
}
