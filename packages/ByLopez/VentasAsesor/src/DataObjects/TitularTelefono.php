<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Customer al que pertenece un telefono normalizado y por donde se encontro.
 *
 * fuente: indice_principal | indice_secundario | ficha_sin_indexar (customers.phone de
 * un cliente historico que aun no tiene fila en bylopez_va_customer_phones).
 */
final class TitularTelefono
{
    public const INDICE_PRINCIPAL = 'indice_principal';

    public const INDICE_SECUNDARIO = 'indice_secundario';

    public const FICHA_SIN_INDEXAR = 'ficha_sin_indexar';

    public function __construct(
        public readonly int $customerId,
        public readonly string $fuente,
    ) {}
}
