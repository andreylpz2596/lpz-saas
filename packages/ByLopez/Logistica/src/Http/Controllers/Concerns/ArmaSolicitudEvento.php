<?php

namespace ByLopez\Logistica\Http\Controllers\Concerns;

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use Illuminate\Http\Request;

/** Arma la solicitud de entrega/devolucion desde el formulario del panel: la misma para la pagina clasica y para el drawer. */
trait ArmaSolicitudEvento
{
    /** El panel envia todas las filas; las que quedan en 0 no son parte del evento (el servicio rechaza ≤ 0). */
    protected function solicitudEvento(int $envio, Request $request, Fuente $fuente): SolicitudEventoEnvio
    {
        $items = array_filter((array) $request->input('items', []), fn ($c) => ! in_array(trim((string) $c), ['', '0'], true));

        return new SolicitudEventoEnvio(
            shipmentId: $envio,
            orderId: ctype_digit((string) $request->input('order_id')) ? (int) $request->input('order_id') : null,
            fecha: (string) $request->input('fecha'),
            items: $items,
            fuente: $fuente,
            idempotencyKey: (string) $request->input('idempotency_key'),
            carrierCode: $request->input('carrier_code'),
            evidenciaReferencia: $request->input('evidencia_referencia'),
            motivo: $request->input('motivo'),
            motivoCodigo: $request->input('motivo_codigo'),
        );
    }
}
