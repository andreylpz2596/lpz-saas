<?php

namespace ByLopez\Logistica\Contracts;

use Webkul\User\Contracts\Admin;

/**
 * Frontera de Logistica con el inventario para el REINGRESO de unidades APTAS de una devolucion recibida en bodega.
 * Logistica decide cuanto y donde (la bodega del Shipment); el modulo de inventario SOLO ejecuta una entrada auditada e
 * idempotente. Nunca se usa el Refund ni `OrderItemRepository::returnQtyToProductInventory` (no son este caso).
 *
 * Debe llamarse dentro de la transaccion de la recepcion: si falla, se revierte todo (nunca recepcion confirmada con el
 * inventario a medias).
 */
interface ReingresaInventario
{
    /**
     * Suma `$cantidad` al stock fisico de la variante en ESA bodega y devuelve el movimiento del libro. Con la misma `$clave`
     * devuelve el movimiento ya hecho (no vuelve a sumar).
     *
     * @return array{movimiento_id: int, anterior: int, nueva: int}
     */
    public function reingresar(Admin $actor, int $productId, int $inventorySourceId, int $cantidad, string $motivo, string $origenTipo, int $origenId, string $clave): array;
}
