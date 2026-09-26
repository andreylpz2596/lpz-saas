<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\EstadoNormalizacionTelefono;
use ByLopez\VentasAsesor\Enums\TipoTelefono;

/**
 * Resultado inmutable de NORMALIZAR un telefono (no de validarlo). `normalizado` solo
 * existe (forma E.164: '+' y hasta 15 digitos) cuando el estado es buscable; en otro
 * caso es null y `motivo` dice por que, con un codigo estable. Que exista
 * `normalizado` no significa que el numero exista o este asignado.
 */
final class TelefonoNormalizado
{
    public function __construct(
        public readonly ?string $original,
        public readonly ?string $normalizado,
        public readonly ?string $paisIso,
        public readonly TipoTelefono $tipo,
        public readonly EstadoNormalizacionTelefono $estado,
        public readonly ?string $motivo = null,
    ) {}

    public function esBuscable(): bool
    {
        return $this->estado->esBuscable() && $this->normalizado !== null;
    }

    /** Ultimos 4 digitos, para reportes y logs sin exponer el numero completo. */
    public function enmascarado(): string
    {
        $digitos = preg_replace('/\D/', '', (string) ($this->normalizado ?? $this->original));

        return $digitos === '' ? '—' : '***'.substr($digitos, -4);
    }
}
