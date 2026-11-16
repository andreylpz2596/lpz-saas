<?php

namespace ByLopez\VentasAsesor\Generators;

/**
 * Número visible de facturas. Se activa en Configuración › Ventas › Configuración de facturas › Número de factura ›
 * Clase generadora: ByLopez\VentasAsesor\Generators\ConsecutivoFactura
 */
class ConsecutivoFactura extends ConsecutivoVisible
{
    protected function clave(): string
    {
        return 'factura';
    }

    protected function configuracion(): string
    {
        return 'sales.invoice_settings.invoice_number.invoice_number';
    }

    protected function tablaDocumentos(): string
    {
        return 'invoices';
    }
}
