<?php

namespace ByLopez\Logistica\DataObjects;

use Illuminate\Http\UploadedFile;

/**
 * Registrar guia y despachar un pedido (o completar un despacho parcial). Sin Request ni sesion: la usan el panel y,
 * en el futuro, una integracion.
 *
 * - `unidades`: order_item_id (linea padre del pedido) => cantidad. Vacio = TODAS las pendientes (caso normal).
 * - `valorFlete`/`recaudoEsperado`: texto o numero; null = sin dato (recaudo null = el que calcula el servidor para la guia).
 * - `archivo`: PDF opcional adjunto en el mismo acto (queda como version 1 de la guia).
 * - `fletePagador`: vacio = el que se deduce de la condicion de envio. Otro pagador exige `motivoPagador`.
 * - `valorFlete`: COSTO FINAL real del servicio; vacio = la tarifa sugerida. Si difiere de ella exige `motivoCosto`.
 */
final class SolicitudDespacho
{
    /** @param  array<int|string, mixed>  $unidades */
    public function __construct(
        public readonly int $orderId,
        public readonly string $idempotencyKey,
        public readonly string $transportadora,
        public readonly string $numeroGuia,
        public readonly string $origenGuia,
        public readonly string $fletePagador,
        public readonly array $unidades = [],
        public readonly int|float|string|null $valorFlete = null,
        public readonly int|float|string|null $recaudoEsperado = null,
        public readonly ?string $tracking = null,
        public readonly ?string $observacion = null,
        public readonly ?UploadedFile $archivo = null,
        // Domicilios locales F1: zona elegida a mano (solo si no hay zona automatica) y motivos de las excepciones.
        public readonly ?int $zonaId = null,
        public readonly ?string $motivoPagador = null,
        public readonly ?string $motivoCosto = null,
        // C1: motivo cuando `recaudoEsperado` se aparta del que calcula el servidor para la guia.
        public readonly ?string $motivoRecaudo = null,
    ) {}
}
