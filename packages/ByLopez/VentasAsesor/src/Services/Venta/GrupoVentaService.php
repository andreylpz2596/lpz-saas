<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\GrupoVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\GrupoEventoBorrador as Evento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\Venta\CarritoComercial;
use ByLopez\VentasAsesor\Support\Venta\RegistroGrupoVenta;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Facades\Cart;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\User\Contracts\Admin;

/**
 * Grupo comercial TEMPORAL de UNA venta (GRP-01): grupo original (el habitual del cliente) vs grupo efectivo (el que calcula
 * esta venta).
 *
 * - El override vive en el BORRADOR, nunca en el cliente: `customers.customer_group_id` no se escribe jamas, y dos ventas
 *   simultaneas del mismo cliente pueden tener grupos efectivos distintos sin interferirse.
 * - No calcula precios: persiste el override y vuelve a preciar TODO el carrito con Cart::collectTotals() dentro de
 *   ContextoPrecioComercial::durante(), que lee el grupo efectivo del borrador (misma ruta que catalogo, lineas, pago,
 *   entrega y confirmacion). Nada de formulas en frontend.
 * - Atomico: override + collectTotals + evento de historial en UNA transaccion; si el recalculo falla, rollback completo
 *   (grupo anterior, precios anteriores, sin evento falso).
 * - Autorizacion en backend: permiso especifico, y ademas las reglas del borrador (permiso de vender, propietario,
 *   estado editable) que ya aplica BorradorVentaService::bloquearParaEditar.
 * - El motivo es obligatorio tambien al restaurar. Cada cambio es una fila nueva del historial.
 */
class GrupoVentaService
{
    public const MOTIVO_MAXIMO = 500;

    public function __construct(
        protected BorradorVentaService $borradores,
        protected CarritoComercial $carritos,
        protected ValidadorGrupoComercial $validadorGrupo,
        protected AutorizacionVentasAsesor $autorizacion,
        protected RegistroGrupoVenta $registro,
    ) {}

    public function puedeCambiar(?Admin $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_CAMBIAR_GRUPO_TEMPORAL);
    }

    /** Aplica (o cambia) el grupo temporal de la venta y recalcula todo el carrito. */
    public function cambiar(int $borradorId, int $grupoId, string $motivo, Admin $actor): void
    {
        $this->exigirPermiso($actor);
        $motivo = $this->motivoValido($motivo);

        DB::transaction(function () use ($borradorId, $grupoId, $motivo, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;
            $habitualId = $this->grupoHabitual($customerId);

            // Existente y no invitado (mismo validador que el alta de cliente); nunca una lista paralela.
            $this->validadorGrupo->validar($grupoId);

            $actualId = $this->grupoEfectivoVigente($borrador, $customerId, $habitualId);

            if ($grupoId === $actualId) {
                throw new Invalido(Invalido::GRUPO_SIN_CAMBIO);
            }

            $evento = $this->hayOverride($borrador, $customerId) ? Evento::OVERRIDE_CAMBIADO : Evento::OVERRIDE_APLICADO;

            if ($grupoId === $habitualId) {
                // Elegir el grupo habitual equivale a restaurarlo: sin override, sin badge «Temporal».
                $this->registro->quitarOverride($borrador, $habitualId);
                $evento = Evento::RESTAURACION;
            } else {
                $this->registro->aplicarOverride($borrador, $customerId, $habitualId, $grupoId);
            }

            $this->repreciar($carrito, $customerId);

            $this->registro->evento($borrador, $customerId, $evento, Evento::ORIGEN_NUEVA_VENTA, $habitualId, $actualId, $grupoId, $motivo, $actor);
        });
    }

    /** Vuelve al grupo habitual del cliente y recalcula todo el carrito. Exige override vigente y motivo. */
    public function restaurar(int $borradorId, string $motivo, Admin $actor): void
    {
        $this->exigirPermiso($actor);
        $motivo = $this->motivoValido($motivo);

        DB::transaction(function () use ($borradorId, $motivo, $actor) {
            [$borrador, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;
            $habitualId = $this->grupoHabitual($customerId);

            if (! $this->hayOverride($borrador, $customerId)) {
                throw new Invalido(Invalido::SIN_OVERRIDE);
            }

            $anteriorId = (int) $borrador->grupo_efectivo_id;

            $this->registro->quitarOverride($borrador, $habitualId);
            $this->repreciar($carrito, $customerId);

            $this->registro->evento($borrador, $customerId, Evento::RESTAURACION, Evento::ORIGEN_NUEVA_VENTA, $habitualId, $anteriorId, $habitualId, $motivo, $actor);
        });
    }

    /**
     * Historial de grupo de la venta (mas reciente primero). Solo lectura: el propietario de la venta o quien ve todas
     * las ventas.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(int $borradorId, Admin $actor): array
    {
        $borrador = BorradorVenta::query()->find($borradorId) ?? throw new BorradorInvalido(BorradorInvalido::INEXISTENTE);

        if ($borrador->propietario_admin_id !== (int) $actor->id
            && ! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS)
        ) {
            throw new BorradorInvalido(BorradorInvalido::NO_ES_PROPIETARIO);
        }

        return Evento::query()
            ->where('borrador_id', $borradorId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Evento $e) => [
                'id' => (int) $e->id,
                'evento' => $e->evento,
                'origen' => $e->origen,
                'fecha' => $e->created_at?->toIso8601String(),
                'usuario' => $e->actor_nombre_snapshot,
                'grupo_original' => $e->grupo_original_nombre,
                'grupo_anterior' => $e->grupo_anterior_nombre,
                'grupo_nuevo' => $e->grupo_nuevo_nombre,
                'motivo' => $e->motivo,
                'order_id' => $e->order_id,
            ])
            ->all();
    }

    protected function exigirPermiso(Admin $actor): void
    {
        if (! $this->puedeCambiar($actor)) {
            throw new Invalido(Invalido::SIN_PERMISO);
        }
    }

    protected function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new Invalido(Invalido::MOTIVO_REQUERIDO);
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new Invalido(Invalido::MOTIVO_DEMASIADO_LARGO);
        }

        return $motivo;
    }

    /** Grupo HABITUAL (real, actual) del cliente; debe existir y ser comercial. Solo lectura. */
    protected function grupoHabitual(int $customerId): int
    {
        $cliente = CustomerProxy::modelClass()::query()->find($customerId);

        if (! $cliente || $cliente->customer_group_id === null) {
            throw new Invalido(Invalido::CLIENTE_SIN_GRUPO_HABITUAL);
        }

        $this->validadorGrupo->validar((int) $cliente->customer_group_id);

        return (int) $cliente->customer_group_id;
    }

    /** Un override solo vale para el cliente para el que se aplico; cualquier otro caso es «sin override». */
    protected function hayOverride(BorradorVenta $borrador, int $customerId): bool
    {
        return $borrador->grupo_override
            && $borrador->grupo_efectivo_id !== null
            && $borrador->grupo_override_customer_id === $customerId;
    }

    protected function grupoEfectivoVigente(BorradorVenta $borrador, int $customerId, int $habitualId): int
    {
        return $this->hayOverride($borrador, $customerId) ? (int) $borrador->grupo_efectivo_id : $habitualId;
    }

    /**
     * collectTotals() BORRA un carrito sin lineas, asi que solo se llama si hay lineas (igual que
     * BorradorVentaService::repreciar). Corre en el contexto de precio del carrito, que ya lee el grupo efectivo ya guardado
     * en esta misma transaccion.
     */
    protected function repreciar($carrito, int $customerId): void
    {
        $this->carritos->operar($carrito, $customerId, function () use ($carrito) {
            if ($carrito->items()->exists()) {
                Cart::collectTotals();
            }
        });
    }
}
