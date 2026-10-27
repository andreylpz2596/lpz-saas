<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Lectura del estado de pago de un pedido para el despacho, desde la fuente unica de Cartera (null = pedido fuera de Cartera).
 * Los tres importes NO se suman entre si: son partes de la misma cuenta.
 *
 *   sin_revisar = dinero REPORTADO y aplicado al pedido que Cartera aun no confirma ni rechaza (no es dinero verificado).
 *   verificado  = dinero CONFIRMADO aplicado al pedido (pagos confirmados + Transactions nativas ajenas a ByLopez).
 *   saldo       = saldo segun Cartera: total − devuelto recibido (C5) − verificado − sin_revisar. Ya DESCUENTA lo reportado.
 *   Por eso «lo que falta confirmar» = saldo + sin_revisar (nunca saldo − sin_revisar ni verificado + saldo + sin_revisar).
 *
 * El saldo pendiente no bloquea por si solo; un pago sin confirmar deja incierto el valor a cobrar. Logistica trae una
 * implementacion neutra (sin Cartera no hay pagos); VentasAsesor la reemplaza. Mismas restricciones de acceso que el despacho:
 * solo lo leen servicios que ya exigieron el permiso de Logistica correspondiente.
 */
interface PagosPendientesDeConfirmar
{
    /** @return array{sin_revisar: float, verificado: ?float, saldo: ?float} */
    public function estado(int $orderId): array;
}
