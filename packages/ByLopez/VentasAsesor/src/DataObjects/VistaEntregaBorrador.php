<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Entrega del borrador (03D): direcciones, metodos que ofrece Bagisto y el elegido.
 * Totales (con el envio) en `borrador->totales`, calculados por Bagisto.
 *
 * - requiereEnvio: el carrito tiene productos fisicos (Cart::haveStockableItems).
 * - entregaCompleta: facturacion y, si requiere envio, direccion de envio y metodo con
 *   tarifa vigente. Es lo que 03F exigira (y revalidara) antes de crear el pedido.
 * - avisos: stock_insuficiente (una linea supera el stock actual), metodos_por_calcular
 *   (hay direccion de envio pero las tarifas se invalidaron, p. ej. tras cambiar lineas).
 * - domiciliario (cierre F1, D1): solo si la entrega es un domicilio LOCAL (lo decide Logistica): zona, opciones,
 *   sugerido (ultimo usado en la zona) y elegido por el asesor. null = no se muestra selector.
 */
final class VistaEntregaBorrador
{
    public const AVISO_STOCK_INSUFICIENTE = 'stock_insuficiente';

    public const AVISO_METODOS_POR_CALCULAR = 'metodos_por_calcular';

    /**
     * @param  list<MetodoEntregaDisponible>  $metodosDisponibles
     * @param  list<string>  $avisos
     */
    public function __construct(
        public readonly VistaBorradorVenta $borrador,
        public readonly ?DireccionBorrador $facturacion,
        public readonly ?DireccionBorrador $envio,
        public readonly bool $envioIgualFacturacion,
        public readonly array $metodosDisponibles,
        public readonly ?MetodoEntregaDisponible $metodoSeleccionado,
        public readonly bool $requiereEnvio,
        public readonly bool $entregaCompleta,
        public readonly array $avisos,
        public readonly ?array $domiciliario = null,
    ) {}

    public function toArray(): array
    {
        return [
            'borrador' => get_object_vars($this->borrador),
            'facturacion' => $this->facturacion?->toArray(),
            'envio' => $this->envio?->toArray(),
            'envio_igual_facturacion' => $this->envioIgualFacturacion,
            'metodos_disponibles' => array_map(fn (MetodoEntregaDisponible $m) => $m->toArray(), $this->metodosDisponibles),
            'metodo_seleccionado' => $this->metodoSeleccionado?->toArray(),
            'requiere_envio' => $this->requiereEnvio,
            'entrega_completa' => $this->entregaCompleta,
            'avisos' => $this->avisos,
            'domiciliario' => $this->domiciliario,
        ];
    }
}
