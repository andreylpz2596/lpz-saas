<?php

namespace ByLopez\Logistica\Carriers;

/**
 * Envio nacional (D-40). Operacion inicial: flete por pagar a la transportadora al
 * recibir, tarifa 0 cobrada por ByLopez (D-47): no es envio gratis. La transportadora
 * efectiva (Inter Rapidisimo, Servientrega, 99envios) se decide al despachar; una
 * cotizacion por API futura podra reemplazar calculate() sin tocar VentasAsesor.
 */
class EnvioNacional extends EntregaADestino
{
    protected $code = 'envio_nacional';

    protected $method = 'envio_nacional';
}
