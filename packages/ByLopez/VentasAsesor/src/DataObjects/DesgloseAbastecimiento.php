<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Como se cubriria una cantidad solicitada (03B; D-19, D-36, D-37).
 *
 * INVARIANTE I-12 (D-36): la entrega inmediata SOLO cuenta stock vendible real de
 * Bagisto.
 *
 *   disponibleInmediato = min(solicitado, stock vendible)
 *   faltanteInmediato   = solicitado - disponibleInmediato
 *
 * Nada de produccion, confeccion, pendientes de recibir ni pedidos especiales entra en
 * esas dos cifras, nunca.
 *
 * INFORMATIVO (D-37), para conversar sobre espera; no es stock ni habilita entrega:
 *
 *   enProduccion ← unidades verificables en Corte/Confeccion; null = DESCONOCIDO (sin
 *                  fuente fiable), nunca 0 sin evidencia
 *   porFabricar  ← faltante que ni el stock ni la produccion cubren; solo existe si
 *                  enProduccion es conocido, y seria pedido especial solo por decision
 *                  expresa
 *
 * No reserva, no escribe stock y no promete fechas.
 */
final class DesgloseAbastecimiento
{
    public function __construct(
        public readonly int $solicitado,
        public readonly int $disponibleInmediato,
        public readonly int $faltanteInmediato,
        public readonly ?int $enProduccion = null,
        public readonly ?int $porFabricar = null,
    ) {}

    /**
     * Desde el stock vendible de Bagisto. Una variante que no gestiona stock
     * (manage_stock = 0) es siempre vendible para Bagisto, asi que cubre lo solicitado.
     */
    public static function desdeStock(int $solicitado, int $stockVendible, bool $gestionaStock): self
    {
        $disponible = $gestionaStock ? max(0, min($solicitado, $stockVendible)) : $solicitado;

        return new self($solicitado, $disponible, $solicitado - $disponible);
    }

    /**
     * Agrega la produccion como dato INFORMATIVO. Nunca cambia disponibleInmediato ni
     * faltanteInmediato (I-12). null mantiene la produccion como desconocida.
     */
    public function conProduccionInformativa(?int $enProduccion): self
    {
        if ($enProduccion === null) {
            return new self($this->solicitado, $this->disponibleInmediato, $this->faltanteInmediato);
        }

        $enProduccion = max(0, $enProduccion);

        return new self(
            $this->solicitado,
            $this->disponibleInmediato,
            $this->faltanteInmediato,
            $enProduccion,
            max(0, $this->faltanteInmediato - $enProduccion),
        );
    }

    public function cubreEntregaInmediata(): bool
    {
        return $this->faltanteInmediato === 0;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
