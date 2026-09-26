<?php

namespace ByLopez\VentasAsesor\DataObjects;

use ByLopez\VentasAsesor\Enums\ResultadoIndexacionTelefono;

/** Veredicto del indexador para un Customer. Sin el numero completo: solo enmascarado. */
final class AnalisisTelefonoCliente
{
    public function __construct(
        public readonly int $customerId,
        public readonly ResultadoIndexacionTelefono $resultado,
        public readonly string $telefonoEnmascarado,
        public readonly ?string $motivo = null,
        public readonly ?int $customerIdRelacionado = null,
    ) {}
}
