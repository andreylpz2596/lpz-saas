<?php

namespace ByLopez\VentasAsesor\DataObjects;

use Carbon\CarbonInterface;

/**
 * Senal INFORMATIVA de ventas simultaneas sobre una variante (Doc. 05 §40). No es una
 * reserva, no modifica stock y no participa en la confirmacion.
 *
 * `stockBagisto` es el dato oficial de Bagisto, intacto. Las demas cifras son contexto y
 * NUNCA se restan de el: no existe un campo de "disponible" propio.
 *
 * - `enOtrasVentasSinConfirmar`: unidades en el Cart de otros borradores activos/confirmando.
 * - `confirmadosSinDespachar`: unidades de pedidos confirmados aun no despachadas
 *   (qty_to_ship nativo de Bagisto).
 * - `confirmadosSinDespacharNoDescontados`: cantidad informativa de pedidos confirmados
 *   pendientes de despacho que la semantica nativa del configurable todavia no refleja en el
 *   stock vendible de Bagisto (reserva 0 hasta el Shipment). No es stock, disponibilidad,
 *   reserva ni inventario comprometido. Lo vendido como producto simple ya esta reservado en
 *   `stockBagisto` y no debe volver a considerarse.
 *
 * Ninguna de estas cifras alimenta stockBagisto, el stock vendible, CercoInventario,
 * ConfirmarVentaService ni product_ordered_inventories: solo la advertencia las lee.
 * - `advertencia`: esta venta + otras sin confirmar + confirmados no descontados superan
 *   `stockBagisto`. Solo contexto: no bloquea, no reduce y no reserva.
 */
final class VentasSimultaneasVariante
{
    /** @param  list<OtraVentaSinConfirmar>  $otrasVentas */
    public function __construct(
        public readonly int $varianteId,
        public readonly int $stockBagisto,
        public readonly int $enEstaVenta,
        public readonly int $enOtrasVentasSinConfirmar,
        public readonly int $confirmadosSinDespachar,
        public readonly int $confirmadosSinDespacharNoDescontados,
        public readonly array $otrasVentas,
        public readonly bool $advertencia,
        public readonly CarbonInterface $consultadoEn,
    ) {}

    public function toArray(): array
    {
        return [
            'variante_id' => $this->varianteId,
            'stock_bagisto' => $this->stockBagisto,
            'en_esta_venta' => $this->enEstaVenta,
            'en_otras_ventas_sin_confirmar' => $this->enOtrasVentasSinConfirmar,
            'confirmados_sin_despachar' => $this->confirmadosSinDespachar,
            'confirmados_sin_despachar_no_descontados' => $this->confirmadosSinDespacharNoDescontados,
            'otras_ventas' => array_map(fn (OtraVentaSinConfirmar $v) => $v->toArray(), $this->otrasVentas),
            'advertencia' => $this->advertencia,
            'consultado_en' => $this->consultadoEn->toIso8601String(),
        ];
    }
}
