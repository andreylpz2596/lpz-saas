<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Estado de una Nueva venta rapida (03G-B), compuesto UNA vez al final de cada operacion
 * a partir de las vistas de los servicios existentes. No guarda nada ni tiene reglas
 * propias: el total es el de Bagisto y `confirmable` es informativo (la confirmacion
 * revalida todo en ConfirmarVentaService). Sin matriz de catalogo.
 *
 * `metodosPagoResueltos` es false cuando la operacion no toco el pago: entonces `pago`
 * solo trae el metodo ya elegido en el Cart (resolver los ofrecidos cuesta ~650 ms).
 */
final class EstadoNuevaVenta
{
    public const MOTIVO_BORRADOR_NO_EDITABLE = 'borrador_no_editable';

    public const MOTIVO_CLIENTE_NO_CONFIRMABLE = 'cliente_no_confirmable';

    public const MOTIVO_SIN_LINEAS = 'sin_lineas';

    public const MOTIVO_STOCK_INSUFICIENTE = 'stock_insuficiente';

    public const MOTIVO_DIRECCION_FACTURACION = 'direccion_facturacion_requerida';

    public const MOTIVO_ENTREGA_INCOMPLETA = 'entrega_incompleta';

    public const MOTIVO_REVISAR_ENTREGA = 'entrega_requiere_revision';

    public const MOTIVO_SIN_PAGO = 'sin_metodo_pago';

    /**
     * @param  list<LineaBorradorVenta>  $lineas
     * @param  list<SolicitudBorradorVenta>  $solicitudes
     * @param  list<string>  $motivosNoConfirmable
     */
    public function __construct(
        public readonly VistaBorradorVenta $borrador,
        public readonly array $lineas,
        public readonly array $solicitudes,
        public readonly VistaEntregaBorrador $entrega,
        public readonly VistaPagoBorrador $pago,
        public readonly bool $metodosPagoResueltos,
        public readonly ?float $totalEsperado,
        public readonly bool $requiereDireccionFacturacion,
        public readonly bool $requiereRevisionEntrega,
        public readonly array $motivosNoConfirmable,
    ) {}

    public function confirmable(): bool
    {
        return $this->motivosNoConfirmable === [];
    }

    public function toArray(): array
    {
        return [
            'borrador' => get_object_vars($this->borrador),
            'lineas' => array_map(fn (LineaBorradorVenta $l) => $l->toArray(), $this->lineas),
            'solicitudes' => array_map(fn (SolicitudBorradorVenta $s) => $s->toArray(), $this->solicitudes),
            'entrega' => $this->entrega->toArray(),
            'pago' => $this->pago->toArray(),
            'metodos_pago_resueltos' => $this->metodosPagoResueltos,
            'total_esperado' => $this->totalEsperado,
            'requiere_direccion_facturacion' => $this->requiereDireccionFacturacion,
            'requiere_revision_entrega' => $this->requiereRevisionEntrega,
            'confirmable' => $this->confirmable(),
            'motivos_no_confirmable' => $this->motivosNoConfirmable,
        ];
    }
}
