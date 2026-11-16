<?php

namespace ByLopez\VentasAsesor\Generators;

/**
 * Número visible de pedidos. Se activa en Configuración › Ventas › Configuración de pedidos › Número de pedido ›
 * Generador: ByLopez\VentasAsesor\Generators\ConsecutivoPedido
 */
class ConsecutivoPedido extends ConsecutivoVisible
{
    protected function clave(): string
    {
        return 'pedido';
    }

    protected function configuracion(): string
    {
        return 'sales.order_settings.order_number.order_number';
    }

    protected function tablaDocumentos(): string
    {
        return 'orders';
    }
}
