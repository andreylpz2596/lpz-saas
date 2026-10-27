<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Valor que la transportadora deberia RECAUDAR de un pedido contra entrega (sugerencia para el formulario de despacho).
 * Logistica trae una implementacion que solo usa datos de Bagisto; VentasAsesor la reemplaza por una que usa Cartera
 * (saldo real, descontando abonos). Es una SUGERENCIA: la operaria puede corregirla y nunca se registra como cobro.
 */
interface SugiereRecaudo
{
    /** Pedido completo: lo que se cobra contra entrega (0 si el pedido no es contra entrega). Indicador del pedido, no de una guia. */
    public function recaudoSugerido(int $orderId): float;

    /**
     * C1 — SALDO EXIGIBLE a recaudar entre TODAS las guias del pedido: lo que el cliente aun debe, contando como pendiente
     * lo que ya cobro una transportadora (ese cobro baja el saldo despues; la guia que lo cobro sigue comprometida). Es el
     * techo de la suma de `recaudo_esperado` de las guias; no depende del metodo de pago.
     */
    public function saldoExigible(int $orderId): float;
}
