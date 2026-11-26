<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Respuesta a «¿se puede aprobar una obligacion nueva de $X para este cliente?» (Doc. 35). La misma decision la usan el cerco de
 * creacion de pedidos (autoridad), la vista previa de Nueva venta (informativa) y las pruebas: nadie la reimplementa.
 *
 * `codigo` solo existe cuando `permitido` es false (cupo_bloqueado | cupo_sin_asignar | cupo_excedido | cupo_no_verificable).
 */
final class EvaluacionCupo
{
    public function __construct(
        public readonly bool $permitido,
        public readonly ?string $codigo,
        public readonly float $nuevaObligacion,
        public readonly float $autorizado,
        public readonly float $exposicionActual,
        public readonly float $disponibleAntes,
        /** Disponible tras la obligacion nueva; nunca negativo. */
        public readonly float $disponibleDespues,
        /** Cuanto se pasaria del cupo con la obligacion nueva (0 si cabe). */
        public readonly float $exceso,
        /**
         * INFORMATIVO: hay una autorizacion de exceso vigente que alcanzaria para esta venta. No cambia `permitido`: el cerco la
         * consume de forma atomica bajo el bloqueo del cliente y es quien decide.
         */
        public readonly bool $cubiertaPorExceso = false,
    ) {}

    /** @return array<string, float|string|null> cifras para el rechazo (la capa HTTP decide si el actor las ve) */
    public function detalles(): array
    {
        return [
            'autorizado' => $this->autorizado,
            'exposicion' => $this->exposicionActual,
            'disponible' => $this->disponibleAntes,
            'nueva_obligacion' => $this->nuevaObligacion,
            'exceso' => $this->exceso,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'permitido' => $this->permitido, 'codigo' => $this->codigo, 'disponible_despues' => $this->disponibleDespues,
            'cubierta_por_exceso' => $this->cubiertaPorExceso,
        ] + $this->detalles();
    }
}
