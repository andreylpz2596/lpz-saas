<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\GrupoEventoBorrador;
use Webkul\Customer\Models\CustomerGroupProxy;
use Webkul\User\Contracts\Admin;

/**
 * Persistencia del grupo comercial de UNA venta (GRP-01): columnas del borrador + historial de eventos. No calcula
 * precios ni recalcula el carrito (eso es de GrupoVentaService con ContextoPrecioComercial); aqui no hay dependencia con
 * BorradorVentaService para que este pueda usarlo al crear o al cambiar de cliente sin ciclos.
 *
 * Todo se llama DENTRO de la transaccion del llamador. Nunca toca `customers.customer_group_id`.
 */
class RegistroGrupoVenta
{
    public const MOTIVO_CAMBIO_CLIENTE = 'Override cancelado por cambio de cliente';

    /** Venta sin override: grupo original = efectivo = grupo habitual del cliente. */
    public function inicializar(BorradorVenta $borrador, ?int $grupoHabitualId): void
    {
        $borrador->update([
            'grupo_original_id' => $grupoHabitualId,
            'grupo_efectivo_id' => $grupoHabitualId,
            'grupo_override' => false,
            'grupo_override_customer_id' => null,
        ]);
    }

    /** Aplica (o cambia) el override de ESTA venta. El cliente no se toca. */
    public function aplicarOverride(BorradorVenta $borrador, int $customerId, int $grupoHabitualId, int $grupoEfectivoId): void
    {
        $borrador->update([
            'grupo_original_id' => $grupoHabitualId,
            'grupo_efectivo_id' => $grupoEfectivoId,
            'grupo_override' => true,
            'grupo_override_customer_id' => $customerId,
        ]);
    }

    /** Vuelve al grupo habitual del cliente. */
    public function quitarOverride(BorradorVenta $borrador, int $grupoHabitualId): void
    {
        $this->inicializar($borrador, $grupoHabitualId);
    }

    /**
     * Cambio de cliente de la venta: el override del cliente anterior se cancela (con su evento propio, referido al
     * cliente anterior) y la venta arranca para el cliente nuevo con su grupo habitual. Nunca se hereda.
     */
    public function alCambiarCliente(BorradorVenta $borrador, int $clienteAnteriorId, int $clienteNuevoId, ?int $grupoNuevoClienteId, Admin $actor): void
    {
        if ($clienteAnteriorId === $clienteNuevoId) {
            return;
        }

        if ($borrador->grupo_override) {
            $this->evento(
                $borrador,
                $clienteAnteriorId,
                GrupoEventoBorrador::OVERRIDE_CANCELADO_CAMBIO_CLIENTE,
                GrupoEventoBorrador::ORIGEN_CAMBIO_CLIENTE,
                $borrador->grupo_original_id,
                $borrador->grupo_efectivo_id,
                null,
                self::MOTIVO_CAMBIO_CLIENTE,
                $actor,
            );
        }

        $this->inicializar($borrador, $grupoNuevoClienteId);
    }

    public function evento(
        BorradorVenta $borrador,
        int $customerId,
        string $evento,
        string $origen,
        ?int $originalId,
        ?int $anteriorId,
        ?int $nuevoId,
        string $motivo,
        ?Admin $actor,
    ): GrupoEventoBorrador {
        $nombres = $this->nombres(array_filter([$originalId, $anteriorId, $nuevoId]));

        $creado = GrupoEventoBorrador::query()->create([
            'borrador_id' => (int) $borrador->id,
            'cart_id' => $borrador->cart_id,
            'customer_id' => $customerId,
            'evento' => $evento,
            'origen' => $origen,
            'grupo_original_id' => $originalId,
            'grupo_original_nombre' => $originalId === null ? null : ($nombres[$originalId] ?? null),
            'grupo_anterior_id' => $anteriorId,
            'grupo_anterior_nombre' => $anteriorId === null ? null : ($nombres[$anteriorId] ?? null),
            'grupo_nuevo_id' => $nuevoId,
            'grupo_nuevo_nombre' => $nuevoId === null ? null : ($nombres[$nuevoId] ?? null),
            'motivo' => $motivo,
            'actor_admin_id' => $actor?->id,
            'actor_nombre_snapshot' => $actor ? mb_substr((string) $actor->name, 0, 191) : null,
        ]);

        $borrador->increment('grupo_eventos');

        return $creado;
    }

    /** @return array<int, string> id => nombre */
    protected function nombres(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return CustomerGroupProxy::modelClass()::query()->whereIn('id', array_unique($ids))->pluck('name', 'id')
            ->map(fn ($nombre) => mb_substr((string) $nombre, 0, 191))
            ->all();
    }
}
