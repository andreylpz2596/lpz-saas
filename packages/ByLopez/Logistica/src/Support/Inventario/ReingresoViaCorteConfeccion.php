<?php

namespace ByLopez\Logistica\Support\Inventario;

use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\Logistica\Contracts\ReingresaInventario;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Validation\ValidationException;
use Webkul\User\Contracts\Admin;

/**
 * Adaptador de `ReingresaInventario` al libro de inventario de CorteConfeccion (`registrarEntradaExterna`, tipo
 * `reingreso_devolucion`). Junto a `AjusteViaCorteConfeccion` son los UNICOS archivos de Logistica que conocen ese paquete.
 */
class ReingresoViaCorteConfeccion implements ReingresaInventario
{
    public function __construct(protected InventarioMovimientoService $inventario) {}

    public function reingresar(Admin $actor, int $productId, int $inventorySourceId, int $cantidad, string $motivo, string $origenTipo, int $origenId, string $clave): array
    {
        try {
            $movimiento = $this->inventario->registrarEntradaExterna([
                'product_id' => $productId,
                'inventory_source_id' => $inventorySourceId,
                'cantidad' => $cantidad,
                'tipo' => TipoInventarioMovimiento::ReingresoDevolucion,
                'motivo' => $motivo,
                'idempotency_key' => $clave,
                'origen_tipo' => $origenTipo,
                'origen_id' => $origenId,
                'admin_id' => (int) $actor->id,
                'admin_nombre' => (string) $actor->name,
            ]);
        } catch (ValidationException $e) {
            throw new Invalida(Invalida::REINGRESO_FALLIDO, ['causa' => collect($e->errors())->flatten()->implode(' ')]);
        }

        return [
            'movimiento_id' => (int) $movimiento->id,
            'anterior' => (int) $movimiento->cantidad_anterior,
            'nueva' => (int) $movimiento->cantidad_nueva,
        ];
    }
}
