<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Models\Entrega;

/**
 * ENTREGA CONFIRMADA (LOG-01): el cliente recibio estas unidades. Unico hecho de «entregado» del sistema; COM-04 lo
 * leera para causar comisiones. Reglas comunes en RegistrarEventoEnvio.
 */
class RegistrarEntregaService extends RegistrarEventoEnvio
{
    protected function tipo(): string
    {
        return 'entrega';
    }

    protected function modelo(): string
    {
        return Entrega::class;
    }

    protected function validarEspecifico(SolicitudEventoEnvio $s, Fuente $fuente): void {}

    protected function atributos(SolicitudEventoEnvio $s, string $fecha): array
    {
        return ['fecha_entrega' => $fecha];
    }
}
