<?php

namespace ByLopez\Logistica\Contracts;

use Webkul\User\Contracts\Admin;

/**
 * Mensajes al CLIENTE sobre un pedido (texto para copiar y pegar en WhatsApp). Logistica solo OFRECE la puerta (rutas y componente);
 * las reglas y el texto viven en un unico servicio que VentasAsesor enlaza aqui (mismo patron que SugiereRecaudo). Es solo lectura:
 * generar un mensaje nunca cambia un estado.
 *
 * Los dos metodos lanzan MensajePedidoNoDisponibleException (codigo estable) si el actor no puede o el mensaje no corresponde.
 */
interface MensajesPedidoCliente
{
    /**
     * Mensajes que corresponden AHORA al estado real del pedido.
     *
     * @return list<array{clave: string, tipo: string, etiqueta: string}>
     */
    public function opciones(int $orderId, Admin $actor): array;

    /**
     * Texto vigente de un mensaje (se arma en el momento, con los datos actuales: una guia corregida sale corregida).
     *
     * @return array{clave: string, tipo: string, etiqueta: string, texto: string, telefono: ?string, pedido: array{id: int, numero: string}}
     */
    public function generar(int $orderId, string $clave, Admin $actor): array;
}
