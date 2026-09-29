<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Acceso rapido de "Nueva venta" (03G-B): una linea de producto del negocio (categoria)
 * resuelta por la configuracion del paquete, nunca por un id fijo. Si no se puede resolver
 * con certeza (inexistente, inactiva o ambigua) `categoriaId` es null y `motivo` lo explica.
 */
final class AccesoRapido
{
    public const MOTIVO_INEXISTENTE = 'categoria_inexistente';

    public const MOTIVO_AMBIGUA = 'categoria_ambigua';

    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly ?int $categoriaId,
        public readonly ?string $motivo = null,
    ) {}

    public function resuelto(): bool
    {
        return $this->categoriaId !== null;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
