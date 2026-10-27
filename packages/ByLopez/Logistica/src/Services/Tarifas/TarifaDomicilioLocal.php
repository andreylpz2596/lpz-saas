<?php

namespace ByLopez\Logistica\Services\Tarifas;

use ByLopez\Logistica\Models\Zona;

/**
 * Cuanto cuesta un domicilio local: la tarifa vigente de la transportadora en la ZONA de la direccion (F1,
 * `bylopez_lg_tarifas`). Nunca un valor generico. Una direccion que no cae en una zona local configurada no es un
 * domicilio local (zona null) y no se le aplica nada de esto.
 */
class TarifaDomicilioLocal
{
    public function __construct(
        protected ZonaLocalDeOrigen $locales,
        protected ZonasYTarifasService $zonas,
    ) {}

    /** Zona LOCAL de una direccion (carrito o pedido) para el origen; null si no es local. La regla es ZonaLocalDeOrigen. */
    public function zonaDeDireccion(?object $direccion, ?int $origenId = null): ?Zona
    {
        return $this->locales->zonaDe($origenId, $direccion);
    }

    /** Tarifa vigente de la transportadora en la zona; null si no tiene (no se inventa). */
    public function tarifa(?int $transportadoraId, Zona $zona): ?float
    {
        if ($transportadoraId === null) {
            return null;
        }

        $tarifa = $this->zonas->tarifaVigente($transportadoraId, (int) $zona->id);

        return $tarifa ? round((float) $tarifa->valor, 2) : null;
    }
}
