<?php

namespace ByLopez\Logistica\Carriers;

/**
 * Domicilio local (D-40). Tarifa configurable (D-47); sin zonas, distancias ni
 * domiciliario: quien entrega se decide al despachar (fase de logistica).
 */
class DomicilioLocal extends EntregaADestino
{
    protected $code = 'domicilio_local';

    protected $method = 'domicilio_local';
}
