<?php

namespace ByLopez\VentasAsesor\Support\Precios;

/**
 * Precio de UNA variante para UN grupo y UNA cantidad, tal como lo calcula Bagisto, con la explicacion de quien gana.
 *
 * - `final`: el precio unitario del carrito (indexador de Bagisto, el mismo de Configurable::validateCartItem).
 * - `precioGrupo`: lo que aportan las reglas del grupo para esa cantidad (el precio base si ninguna aplica).
 * - `regla`: la fila de `product_customer_group_prices` que gana para esa cantidad (null si ninguna).
 * - `origen`: grupo | especial | regla_catalogo | base | indeterminado (nunca se adivina: si la explicacion no coincide
 *   con el motor, se dice «indeterminado»).
 */
final class EvaluacionPrecioGrupo
{
    public const ORIGEN_GRUPO = 'grupo';

    public const ORIGEN_ESPECIAL = 'especial';

    public const ORIGEN_REGLA_CATALOGO = 'regla_catalogo';

    public const ORIGEN_BASE = 'base';

    public const ORIGEN_INDETERMINADO = 'indeterminado';

    /** @param  array{id: ?int, qty: int, tipo: string, valor: float, todos: bool}|null  $regla */
    public function __construct(
        public readonly int $qty,
        public readonly float $base,
        public readonly float $final,
        public readonly float $precioGrupo,
        public readonly ?array $regla,
        public readonly string $origen,
        public readonly ?float $especialVigente,
        public readonly ?float $precioReglaCatalogo,
    ) {}
}
