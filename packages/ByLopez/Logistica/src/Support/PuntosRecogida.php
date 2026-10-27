<?php

namespace ByLopez\Logistica\Support;

use Illuminate\Support\Collection;
use Webkul\Checkout\Contracts\CartAddress;
use Webkul\Inventory\Contracts\InventorySource;

/**
 * Puntos de recogida = fuentes de inventario ACTIVAS del canal (D-41). Son bodegas
 * reales registradas en Bagisto (inventory_sources), con calle, ciudad, departamento,
 * pais, codigo postal y telefono obligatorios: la direccion no se inventa ni se duplica.
 *
 * En la recogida, la direccion de envio de Bagisto es el LUGAR DE ENTREGA (la bodega)
 * con los datos de quien recibe, igual que cualquier direccion de envio (lugar +
 * destinatario). El metodo de recogida es el carrier `pickup` de la extension de
 * Bagisto `bagisto-eu/pickup` (reemplazo de nuestro antiguo `recogida_bodega`).
 */
class PuntosRecogida
{
    /** Codigo del carrier de recogida de la extension bagisto-eu/pickup. */
    public const METODO = 'pickup';

    /**
     * D-41: recogida ⇔ envio a una bodega real, en los dos sentidos.
     *  - `pickup` solo vale si la direccion de envio es una bodega (la extension lo ofrece con
     *    CUALQUIER direccion y no lo valida).
     *  - Con una bodega como destino solo vale `pickup`: Bagisto tambien calcula para ella
     *    carriers como `flatrate` o `free`, y «envio por pagar a la bodega» seria un estado
     *    hibrido sin sentido (03G-D, 2026-10-05).
     */
    public function metodoPermitido(string $metodo, ?CartAddress $direccion): bool
    {
        $esBodega = $this->correspondiente($direccion) !== null;

        return $metodo === self::METODO ? $esBodega : ! $esBodega;
    }

    /** @return Collection<int, InventorySource> */
    public function disponibles(): Collection
    {
        return core()->getCurrentChannel()->inventory_sources()
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function porId(int $inventorySourceId): ?InventorySource
    {
        return $this->disponibles()->firstWhere('id', $inventorySourceId);
    }

    /** La bodega a la que corresponde una direccion de carrito, o null si es un domicilio. */
    public function correspondiente(?CartAddress $direccion): ?InventorySource
    {
        if (! $direccion) {
            return null;
        }

        return $this->disponibles()->first(fn (InventorySource $fuente) => $this->lugar($fuente) === [
            $this->normalizar($direccion->address),
            $this->normalizar($direccion->city),
            $this->normalizar($direccion->state),
            $this->normalizar($direccion->country),
        ]);
    }

    /**
     * Campos de LUGAR de la bodega para Cart::saveAddresses (el destinatario lo pone el
     * llamador). `company_name` es el nombre de la bodega.
     *
     * @return array{company_name: string, address: list<string>, city: string, state: string, country: string, postcode: string}
     */
    public function lugarParaCarrito(InventorySource $fuente): array
    {
        return [
            'company_name' => (string) $fuente->name,
            'address' => [(string) $fuente->street],
            'city' => (string) $fuente->city,
            'state' => (string) $fuente->state,
            'country' => (string) $fuente->country,
            'postcode' => (string) $fuente->postcode,
        ];
    }

    private function lugar(InventorySource $fuente): array
    {
        return array_map(fn ($v) => $this->normalizar($v), [$fuente->street, $fuente->city, $fuente->state, $fuente->country]);
    }

    private function normalizar(?string $valor): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $valor)));
    }
}
