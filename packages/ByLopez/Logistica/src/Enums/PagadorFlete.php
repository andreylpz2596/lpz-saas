<?php

namespace ByLopez\Logistica\Enums;

/**
 * Quien asume el flete (estructurado, no texto libre): Finanzas lo usara para saber si es un gasto real de ByLopez.
 *
 * - cliente_al_recibir: lo paga el cliente a la transportadora al recibir (no es gasto de ByLopez).
 * - bylopez: lo paga ByLopez (gasto; exige valor).
 * - incluido_en_venta: ya se cobro dentro del pedido (el costo real lo asume ByLopez al pagar a la transportadora).
 * - otro: cualquier otro caso, solo si realmente se necesita.
 *
 * En un despacho nuevo el pagador NO tiene un valor fijo: se deduce de la condicion de envio (CondicionEnvio::pagador()).
 */
enum PagadorFlete: string
{
    case CLIENTE_AL_RECIBIR = 'cliente_al_recibir';
    case BYLOPEZ = 'bylopez';
    case INCLUIDO_EN_VENTA = 'incluido_en_venta';
    case OTRO = 'otro';

    /** El flete sale de la caja de ByLopez: el valor real es obligatorio para poder medirlo. */
    public function exigeValor(): bool
    {
        return $this === self::BYLOPEZ;
    }
}
