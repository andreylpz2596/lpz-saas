<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Guarda de la anulacion de una RECEPCION de devolucion (C5) frente al saldo a favor del cliente (D10). Anular la recepcion quita
 * una fuente de credito: si ese credito ya se aplico a otro pedido o se reembolso, el disponible quedaria negativo.
 *
 *   antes()   se llama DENTRO de la transaccion de anulacion, antes de escribir nada: bloquea el pedido para serializar con quien
 *             consume ese credito (mismo orden de bloqueo que las operaciones de credito).
 *   despues() se llama con la recepcion YA marcada como anulada (todavia sin commit): si el credito consumido quedo sin respaldo,
 *             lanza OperacionLogisticaInvalidaException y la transaccion completa se deshace.
 *
 * Logistica trae una implementacion neutra (sin Cartera no hay credito); VentasAsesor la reemplaza.
 */
interface GuardaAnulacionRecepcion
{
    public function antes(int $orderId): void;

    public function despues(int $orderId): void;
}
