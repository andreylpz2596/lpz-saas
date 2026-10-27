<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Retorno;

/**
 * RETORNO DE TRANSPORTADORA (LOG-D-03): la transportadora no entrego y devolvio las unidades. Evento separado de la
 * entrega; `motivo` obligatorio. No borra el Shipment, no crea Refund, no toca comisiones ni inventario (el reingreso
 * fisico a stock es otra decision, fuera de LOG-01).
 */
class RegistrarRetornoTransportadoraService extends RegistrarEventoEnvio
{
    protected function tipo(): string
    {
        return 'retorno';
    }

    protected function modelo(): string
    {
        return Retorno::class;
    }

    protected function validarEspecifico(SolicitudEventoEnvio $s, Fuente $fuente): void
    {
        if (! $fuente->validaParaRetorno()) {
            throw new Invalida(Invalida::FUENTE_INVALIDA);
        }

        if ($this->texto($s->motivo, 500) === null) {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        }
    }

    protected function atributos(SolicitudEventoEnvio $s, string $fecha): array
    {
        return ['fecha_retorno' => $fecha, 'motivo' => $this->texto($s->motivo, 500)];
    }
}
