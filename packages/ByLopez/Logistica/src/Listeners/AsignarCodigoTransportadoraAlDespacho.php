<?php

namespace ByLopez\Logistica\Listeners;

use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bloque 11: un despacho NUEVO guarda `carrier_code` estable + `carrier_title` visual, sin tocar Webkul. Bagisto no
 * escribe `carrier_code` y su formulario solo tiene texto libre; este listener de `sales.shipment.save.after` (dentro
 * de la transaccion del Shipment) completa el codigo cuando:
 *
 * - la peticion trae `shipment[carrier_code]` de una transportadora activa (flujos propios / integraciones futuras), o
 * - el texto escrito es EXACTAMENTE el codigo o el nombre de una transportadora activa (TransportadorasService).
 *
 * Si no hay coincidencia exacta queda NULL (nada se adivina) y la entrega pedira elegirla. Solo actua sobre el envio
 * recien creado; los historicos no se tocan. Nunca rompe el despacho: un fallo se registra y el Shipment sigue.
 */
class AsignarCodigoTransportadoraAlDespacho
{
    public const EVENTO = 'sales.shipment.save.after';

    public function __construct(protected TransportadorasService $transportadoras) {}

    public function handle(mixed $envio): void
    {
        try {
            if (! is_object($envio) || ! isset($envio->id) || ($envio->carrier_code ?? '') !== '') {
                return;
            }

            $solicitado = request()->input('shipment.carrier_code');
            $codigo = $this->transportadoras->activaPorCodigo(is_string($solicitado) ? trim($solicitado) : null)?->codigo
                ?? $this->transportadoras->codigoParaTitulo($envio->carrier_title ?? null);

            if ($codigo !== null) {
                $envio->forceFill(['carrier_code' => $codigo])->saveQuietly();
            }
        } catch (Throwable $e) {
            Log::warning('logistica.carrier_code_no_asignado', ['shipment_id' => $envio->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
