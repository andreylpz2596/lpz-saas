<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Lectura del estado de pago de un pedido para el despacho: cuanto hay en pagos registrados SIN CONFIRMAR y cual es el
 * saldo que queda descontandolos (null = fuera de Cartera). El saldo pendiente no bloquea nada; un pago sin confirmar
 * deja incierto el valor a cobrar, y despachar / entregar en bodega espera a que Cartera lo confirme o lo rechace.
 * Logistica trae una implementacion neutra (sin Cartera no hay pagos sin revisar); VentasAsesor la reemplaza.
 */
interface PagosPendientesDeConfirmar
{
    /** @return array{sin_revisar: float, saldo: ?float} */
    public function estado(int $orderId): array;
}
