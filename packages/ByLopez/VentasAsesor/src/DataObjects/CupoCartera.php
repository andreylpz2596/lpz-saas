<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Cupo de cartera de UN cliente, derivado en el momento de consultar (Doc. 35). Solo lectura: nada de esto se guarda salvo la
 * politica (`autorizado`, `estadoPolitica`). Moneda base, 2 decimales.
 *
 *   disponible = max(0, autorizado − exposicion)      exceso = max(0, exposicion − autorizado)      jamas hay disponible negativo
 *
 * `pedidos` (detalle) es opcional y es una descomposicion EXACTA de `exposicion`: Σ pedidos[].exposicion == exposicion.
 */
final class CupoCartera
{
    public const SIN_CUPO = 'sin_cupo';

    public const ACTIVO = 'activo';

    public const CERCA = 'cerca';

    public const AGOTADO = 'agotado';

    public const EXCEDIDO = 'excedido';

    public const BLOQUEADO = 'bloqueado';

    /**
     * @param  ?string  $estadoPolitica  `activo` | `bloqueado`; null si el cliente nunca tuvo politica
     * @param  ?list<array<string, mixed>>  $pedidos  detalle de los pedidos que consumen (null = no se pidio)
     */
    public function __construct(
        public readonly int $customerId,
        public readonly bool $tienePolitica,
        public readonly float $autorizado,
        public readonly ?string $estadoPolitica,
        public readonly bool $carteraActiva,
        public readonly float $exposicion,
        public readonly float $disponible,
        public readonly float $exceso,
        public readonly ?float $utilizacionPct,
        public readonly string $estado,
        public readonly int $pedidosQueConsumen,
        public readonly int $pedidosEnRevision,
        public readonly ?int $diasPedidoMasAntiguo,
        public readonly ?string $vigenciaDesde,
        public readonly ?string $motivo,
        public readonly ?string $actualizadoEn,
        public readonly ?string $actualizadoPorNombre,
        public readonly ?array $pedidos = null,
        /** Autorizacion de exceso VIGENTE (no consumida ni vencida): {id, exceso_autorizado, vence_en, autorizado_por, motivo}. */
        public readonly ?array $excesoVigente = null,
    ) {}

    public function bloqueado(): bool
    {
        return $this->estadoPolitica === 'bloqueado';
    }

    /**
     * Copia para quien puede VER el cupo pero no su auditoria: sin quien/por que del ultimo cambio y, de la autorizacion de exceso
     * vigente, solo monto y vencimiento (el motivo y quien la dio son auditoria). Las cifras no cambian.
     */
    public function sinAuditoria(): self
    {
        return new self(
            $this->customerId, $this->tienePolitica, $this->autorizado, $this->estadoPolitica, $this->carteraActiva, $this->exposicion,
            $this->disponible, $this->exceso, $this->utilizacionPct, $this->estado, $this->pedidosQueConsumen, $this->pedidosEnRevision,
            $this->diasPedidoMasAntiguo, $this->vigenciaDesde, null, $this->actualizadoEn, null, $this->pedidos,
            $this->excesoVigente === null ? null : array_intersect_key($this->excesoVigente, array_flip(['exceso_autorizado', 'vence_en'])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'tiene_politica' => $this->tienePolitica,
            'autorizado' => $this->autorizado,
            'estado_politica' => $this->estadoPolitica,
            'bloqueado' => $this->bloqueado(),
            'cartera_activa' => $this->carteraActiva,
            'exposicion' => $this->exposicion,
            'disponible' => $this->disponible,
            'exceso' => $this->exceso,
            'utilizacion_pct' => $this->utilizacionPct,
            'estado' => $this->estado,
            'pedidos_que_consumen' => $this->pedidosQueConsumen,
            'pedidos_en_revision' => $this->pedidosEnRevision,
            'dias_pedido_mas_antiguo' => $this->diasPedidoMasAntiguo,
            'vigencia_desde' => $this->vigenciaDesde,
            'motivo' => $this->motivo,
            'actualizado_en' => $this->actualizadoEn,
            'actualizado_por' => $this->actualizadoPorNombre,
            'pedidos' => $this->pedidos,
            'exceso_vigente' => $this->excesoVigente,
        ];
    }
}
