<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\AdvertenciaResolucionVarianteCorte;
use ByLopez\CorteConfeccion\Enums\ErrorResolucionVarianteCorte;

/**
 * Resultado inmutable de resolver una seleccion (linea + color + eje 2) a un
 * simple de Bagisto. O bien trae el simple resuelto (productId), o bien un
 * error bloqueante; nunca ambos. Las advertencias solo acompanan a una
 * resolucion valida.
 */
final class ResolucionVarianteCorte
{
    /**
     * @param  list<AdvertenciaResolucionVarianteCorte>  $advertencias
     * @param  array{id: int, sku: string, nombre: ?string, activo: bool}|null  $configurable
     * @param  array<string, mixed>|null  $snapshot  Etiquetas para guardar en el detalle de la orden.
     * @param  array<string, mixed>  $detalle  Contexto del error (candidatos, SKUs, IDs).
     */
    private function __construct(
        public readonly ?int $productId,
        public readonly ?string $sku,
        public readonly ?ErrorResolucionVarianteCorte $error,
        public readonly array $advertencias,
        public readonly ?array $configurable,
        public readonly ?array $snapshot,
        public readonly array $detalle,
    ) {}

    public static function conExito(int $productId, string $sku, array $configurable, array $snapshot, array $advertencias = []): self
    {
        return new self($productId, $sku, null, $advertencias, $configurable, $snapshot, []);
    }

    public static function conError(ErrorResolucionVarianteCorte $error, array $detalle = [], ?array $configurable = null): self
    {
        return new self(null, null, $error, [], $configurable, null, $detalle);
    }

    public function resuelta(): bool
    {
        return $this->error === null;
    }

    public function toArray(): array
    {
        return [
            'resuelta' => $this->resuelta(),
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'error' => $this->error?->value,
            'mensaje' => $this->error?->mensaje(),
            'advertencias' => array_map(fn (AdvertenciaResolucionVarianteCorte $a) => $a->value, $this->advertencias),
            'configurable' => $this->configurable,
            'snapshot' => $this->snapshot,
            'detalle' => $this->detalle,
        ];
    }
}
