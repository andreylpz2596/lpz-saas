<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\Contracts\CalendarioLaboral;
use ByLopez\VentasAsesor\DataObjects\VistaBorradorVenta as Vista;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as Invalido;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Asesores\AsesorActualService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoPrecioComercial;
use ByLopez\VentasAsesor\Support\Venta\RegistroGrupoVenta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartAddressProxy;
use Webkul\Checkout\Models\CartPaymentProxy;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Customer\Models\CustomerGroupProxy;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\User\Contracts\Admin;

/**
 * Borrador comercial de venta alrededor del Cart REAL de Bagisto (03B, D-16).
 *
 * - El carrito se crea con Cart::createCart(cliente explicito, is_active = 0): invisible
 *   para la sesion de la tienda del cliente (initCart/mergeCart solo usan is_active = 1).
 * - Toda operacion que reprecia corre dentro de ContextoPrecioComercial::durante(cart,
 *   cliente): RT-03 falla cerrado si cart.customer_id y el cliente no coinciden, asi que
 *   no existe un estado final "carrito de A preciado como B".
 * - Concurrencia: cada mutacion bloquea la fila del borrador (lockForUpdate) dentro de
 *   una transaccion; la idempotencia externa la garantiza un UNIQUE, no un if de PHP.
 * - Propiedad: el creador es propietario durante N dias habiles (calendario laboral);
 *   despues otro asesor autorizado puede reclamarlo. Solo el propietario lo edita.
 * - Las lineas las gestiona LineasBorradorService (03C). No confirma pedidos. Nunca
 *   escribe precios.
 *
 * P-09: un cliente inactivo o suspendido PUEDE tener borrador; la vista lo avisa y la
 * futura confirmacion lo bloqueara.
 */
class BorradorVentaService
{
    public const PERMISO = 'ventas_asesor.pedidos.create';

    public function __construct(
        protected ContextoPrecioComercial $contexto,
        protected ValidadorGrupoComercial $validadorGrupo,
        protected CalendarioLaboral $calendario,
        protected AutorizacionVentasAsesor $autorizacion,
        protected AsesorActualService $asesorActual,
        protected RegistroGrupoVenta $registroGrupo,
    ) {}

    public function crear(
        int $customerId,
        Admin $actor,
        CanalVenta $canal,
        MecanismoCreacion $mecanismo = MecanismoCreacion::VENTAS_ASESOR,
        ?string $referenciaExterna = null,
    ): BorradorVenta {
        $this->exigirPermiso($actor);
        $referencia = $this->normalizarReferencia($referenciaExterna);
        $cliente = $this->clienteApto($customerId);

        if ($referencia !== null && ($existente = $this->porReferencia($mecanismo, $referencia))) {
            return $this->mismaSolicitud($existente, $customerId);
        }

        try {
            return DB::transaction(function () use ($cliente, $actor, $canal, $mecanismo, $referencia) {
                $carrito = Cart::createCart(['customer' => $cliente, 'is_active' => false]);
                Cart::resetCart();

                return BorradorVenta::query()->create([
                    'cart_id' => $carrito->id,
                    'propietario_admin_id' => $actor->id,
                    'creado_por_admin_id' => $actor->id,
                    'estado' => Estado::ACTIVO,
                    'canal_venta' => $canal,
                    'mecanismo_creacion' => $mecanismo,
                    'external_reference' => $referencia,
                    'asignado_hasta' => $this->finDeAsignacion(),
                    // GRP-01: sin override, grupo original = efectivo = grupo habitual del cliente.
                    'grupo_original_id' => $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
                    'grupo_efectivo_id' => $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera: otra peticion con la misma referencia gano; su carrito se revirtio aqui.
            $existente = $referencia === null ? null : $this->porReferencia($mecanismo, $referencia);

            if (! $existente) {
                throw $e;
            }

            return $this->mismaSolicitud($existente, $customerId);
        }
    }

    /** Borrador ya creado con esa referencia externa (idempotencia), o null. Solo lectura. */
    public function existentePorReferencia(MecanismoCreacion $mecanismo, ?string $referenciaExterna): ?BorradorVenta
    {
        $referencia = $this->normalizarReferencia($referenciaExterna);

        return $referencia === null ? null : $this->porReferencia($mecanismo, $referencia);
    }

    public function obtener(int $borradorId): Vista
    {
        $borrador = BorradorVenta::query()->with('carrito.customer.group', 'carrito.customer.addresses')->find($borradorId);

        if (! $borrador) {
            throw new Invalido(Invalido::INEXISTENTE);
        }

        return $this->vista($borrador);
    }

    /**
     * P0 (Cliente frecuente, doc 33): lectura o uso de un borrador solo por su propietario. No devuelve datos del
     * borrador ajeno; inexistente = 404 y ajeno = 403, igual que el resto de la API.
     */
    public function exigirDelPropietario(int $borradorId, Admin $actor, ?int $customerId = null): BorradorVenta
    {
        $borrador = BorradorVenta::query()->find($borradorId) ?? throw new Invalido(Invalido::INEXISTENTE);

        $this->exigirPropietario($borrador, $actor);

        // El cliente del precio debe ser el del borrador: ni se prea con otro cliente ni se consulta con un borrador ajeno.
        if ($customerId !== null && $this->clienteDelBorrador($borrador) !== $customerId) {
            throw new Invalido(Invalido::CLIENTE_NO_COINCIDE);
        }

        return $borrador;
    }

    /** Cliente del borrador: el de su carrito; si ya se confirmo y el carrito no existe, el de su pedido. Null = indeterminado (falla cerrado). */
    protected function clienteDelBorrador(BorradorVenta $borrador): ?int
    {
        $id = $borrador->cart_id ? DB::table('cart')->where('id', $borrador->cart_id)->value('customer_id') : null;

        if ($id === null && $borrador->order_id) {
            $id = DB::table('orders')->where('id', $borrador->order_id)->value('customer_id');
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * P0: el actor solo puede leer las direcciones de un cliente con el que trabaja, es decir, si es propietario de un
     * borrador cuyo carrito es de ese cliente.
     */
    public function actorTrabajaConCliente(int $customerId, Admin $actor): bool
    {
        return BorradorVenta::query()
            ->where('propietario_admin_id', (int) $actor->id)
            ->whereIn('cart_id', fn ($q) => $q->select('id')->from('cart')->where('customer_id', $customerId))
            ->exists();
    }

    /** @return list<Vista> */
    public function listarDelPropietario(int $adminId, bool $soloActivos = true, bool $soloConLineas = false): array
    {
        return BorradorVenta::query()
            ->with('carrito.customer.group', 'carrito.customer.addresses')
            ->where('propietario_admin_id', $adminId)
            // P7: una venta en curso real tiene al menos una prenda; las vacias (consulta de inventario) no se muestran.
            ->when($soloConLineas, fn ($q) => $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('cart_items')
                ->whereColumn('cart_items.cart_id', 'bylopez_va_borradores_venta.cart_id')))
            ->when($soloActivos, fn ($q) => $q->where('estado', Estado::ACTIVO->value))
            ->orderByDesc('id')
            ->get()
            ->map(fn (BorradorVenta $b) => $this->vista($b))
            ->all();
    }

    /** Otro asesor autorizado toma el borrador cuando vencio la ventana del propietario. */
    public function reclamar(int $borradorId, Admin $actor): Vista
    {
        $this->exigirPermiso($actor);

        DB::transaction(function () use ($borradorId, $actor) {
            $borrador = $this->bloquearEditable($borradorId);

            if ($borrador->propietario_admin_id === (int) $actor->id) {
                return;
            }

            if ($borrador->asignado_hasta !== null && now()->lt($borrador->asignado_hasta)) {
                throw new Invalido(Invalido::AUN_ASIGNADO);
            }

            $borrador->update([
                'propietario_admin_id' => $actor->id,
                'asignado_hasta' => $this->finDeAsignacion(),
            ]);
        });

        return $this->obtener($borradorId);
    }

    /** Final: el carrito se borra (sus lineas, direcciones y pago caen en cascada). */
    public function descartar(int $borradorId, Admin $actor): Vista
    {
        $this->exigirPermiso($actor);

        DB::transaction(function () use ($borradorId, $actor) {
            $borrador = $this->bloquearEditable($borradorId);
            $this->exigirPropietario($borrador, $actor);

            $cartId = $borrador->cart_id;
            $borrador->update(['estado' => Estado::DESCARTADO]);

            if ($cartId !== null) {
                CartProxy::modelClass()::query()->whereKey($cartId)->delete();
            }
        });

        return $this->obtener($borradorId);
    }

    /**
     * Cambia el cliente del borrador (Doc. 05 §22.6). En UNA transaccion: reescribe la
     * identidad del carrito, retira lo que dependia del cliente anterior (direcciones,
     * pago y envio) y, si hay lineas, reprecia TODO con el grupo actual del cliente nuevo.
     */
    public function cambiarCliente(int $borradorId, int $customerId, Admin $actor): Vista
    {
        $this->exigirPermiso($actor);
        $cliente = $this->clienteApto($customerId);

        DB::transaction(function () use ($borradorId, $cliente, $actor) {
            $borrador = $this->bloquearEditable($borradorId);
            $this->exigirPropietario($borrador, $actor);
            $carrito = $this->carritoBloqueado($borrador);

            // GRP-01: el override del cliente anterior se cancela (con su evento) y la venta arranca con el grupo habitual del nuevo.
            $this->registroGrupo->alCambiarCliente(
                $borrador,
                (int) $carrito->customer_id,
                (int) $cliente->id,
                $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
                $actor,
            );

            $carrito->update([
                'customer_id' => $cliente->id,
                'is_guest' => 0,
                'customer_first_name' => $cliente->first_name,
                'customer_last_name' => $cliente->last_name,
                'customer_email' => $cliente->email,
            ]);

            CartAddressProxy::modelClass()::query()->where('cart_id', $carrito->id)->delete();
            CartPaymentProxy::modelClass()::query()->where('cart_id', $carrito->id)->delete();

            $this->repreciar($carrito->fresh(), (int) $cliente->id, true);
        });

        return $this->obtener($borradorId);
    }

    /** Reprecia con el grupo ACTUAL del cliente del carrito (p. ej. si cambio de grupo). */
    public function recalcular(int $borradorId, Admin $actor): Vista
    {
        $this->exigirPermiso($actor);

        DB::transaction(function () use ($borradorId, $actor) {
            $borrador = $this->bloquearEditable($borradorId);
            $this->exigirPropietario($borrador, $actor);
            $carrito = $this->carritoBloqueado($borrador);

            $this->clienteApto((int) $carrito->customer_id);
            $this->repreciar($carrito, (int) $carrito->customer_id, false);
        });

        return $this->obtener($borradorId);
    }

    /**
     * Para otros servicios del borrador (03C, LineasBorradorService). Se llama DENTRO de
     * una transaccion del llamador: exige permiso, estado editable y propietario, y
     * bloquea borrador y carrito en el mismo orden que las mutaciones de este servicio.
     *
     * @return array{0: BorradorVenta, 1: \Webkul\Checkout\Contracts\Cart}
     */
    public function bloquearParaEditar(int $borradorId, Admin $actor): array
    {
        $this->exigirPermiso($actor);
        $borrador = $this->bloquearEditable($borradorId);
        $this->exigirPropietario($borrador, $actor);

        return [$borrador, $this->carritoBloqueado($borrador)];
    }

    /**
     * collectTotals() BORRA un carrito sin lineas (Cart::validateItems), asi que solo se
     * llama si hay lineas. Siempre dentro del contexto de RT-03 del cliente del carrito.
     */
    protected function repreciar($carrito, int $customerId, bool $retirarEnvio): void
    {
        $this->contexto->durante((int) $carrito->id, $customerId, function () use ($carrito, $retirarEnvio) {
            Cart::setCart($carrito);

            try {
                if ($retirarEnvio) {
                    Cart::resetShippingMethod();
                }

                if ($carrito->items()->exists()) {
                    Cart::collectTotals();
                }
            } finally {
                Cart::resetCart();
            }
        });
    }

    protected function bloquearEditable(int $borradorId): BorradorVenta
    {
        $borrador = BorradorVenta::query()->lockForUpdate()->find($borradorId);

        if (! $borrador) {
            throw new Invalido(Invalido::INEXISTENTE);
        }

        if (! $borrador->estado->esEditable()) {
            throw new Invalido(Invalido::NO_EDITABLE);
        }

        return $borrador;
    }

    protected function carritoBloqueado(BorradorVenta $borrador)
    {
        $carrito = $borrador->cart_id === null ? null
            : CartProxy::modelClass()::query()->lockForUpdate()->find($borrador->cart_id);

        if (! $carrito) {
            throw new Invalido(Invalido::SIN_CARRITO);
        }

        return $carrito;
    }

    protected function exigirPropietario(BorradorVenta $borrador, Admin $actor): void
    {
        if ($borrador->propietario_admin_id !== (int) $actor->id) {
            throw new Invalido(Invalido::NO_ES_PROPIETARIO);
        }
    }

    protected function exigirPermiso(Admin $actor): void
    {
        // Doc. 33 P3: quien compra en autoservicio puede operar SU borrador; el cliente lo fija el servidor (ClienteActualService).
        if (! $this->autorizacion->tienePermiso($actor, self::PERMISO)
            && ! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_COMPRAR)) {
            throw new Invalido(Invalido::SIN_PERMISO);
        }

        // ASESOR-01: quien vende como asesor necesita su perfil activo; si no, falla cerrado (no hay atribucion ambigua).
        if (! $this->asesorActual->operable($actor)) {
            throw new Invalido(Invalido::ASESOR_SIN_ASOCIACION);
        }
    }

    /** Cliente existente con grupo comercial apto (invitado y sin grupo, rechazados). */
    protected function clienteApto(int $customerId)
    {
        $cliente = CustomerProxy::modelClass()::query()->find($customerId);

        if (! $cliente) {
            throw new Invalido(Invalido::CLIENTE_INEXISTENTE);
        }

        $this->validadorGrupo->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);

        return $cliente;
    }

    protected function porReferencia(MecanismoCreacion $mecanismo, string $referencia): ?BorradorVenta
    {
        return BorradorVenta::query()
            ->where('mecanismo_creacion', $mecanismo->value)
            ->where('external_reference', $referencia)
            ->first();
    }

    /**
     * Misma referencia externa = misma solicitud: se devuelve el borrador existente (en
     * cualquier estado). Si su carrito es de otro cliente, la referencia esta en uso.
     */
    protected function mismaSolicitud(BorradorVenta $existente, int $customerId): BorradorVenta
    {
        $clienteDelCarrito = $existente->cart_id === null ? null
            : CartProxy::modelClass()::query()->whereKey($existente->cart_id)->value('customer_id');

        if ($clienteDelCarrito !== null && (int) $clienteDelCarrito !== $customerId) {
            throw new Invalido(Invalido::REFERENCIA_EN_USO);
        }

        return $existente;
    }

    protected function normalizarReferencia(?string $referencia): ?string
    {
        if ($referencia === null) {
            return null;
        }

        $referencia = trim($referencia);

        if ($referencia === '' || mb_strlen($referencia) > 191) {
            throw new Invalido(Invalido::REFERENCIA_INVALIDA);
        }

        return $referencia;
    }

    protected function finDeAsignacion()
    {
        return $this->calendario->sumarDiasHabiles(now(), (int) config('ventasasesor.borradores.dias_habiles_asignacion', 1));
    }

    protected function vista(BorradorVenta $borrador): Vista
    {
        $carrito = $borrador->carrito;
        $cliente = $carrito?->customer;
        $avisos = [];
        $grupoApto = false;

        if ($cliente) {
            try {
                $this->validadorGrupo->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);
                $grupoApto = true;
            } catch (GrupoComercialInvalidoException) {
                $avisos[] = Vista::AVISO_GRUPO_NO_APTO;
            }

            if (! $cliente->status) {
                $avisos[] = Vista::AVISO_CLIENTE_INACTIVO;
            }

            if ($cliente->is_suspended) {
                $avisos[] = Vista::AVISO_CLIENTE_SUSPENDIDO;
            }
        }

        return new Vista(
            id: (int) $borrador->id,
            estado: $borrador->estado->value,
            cartId: $borrador->cart_id,
            cliente: $cliente ? [
                'customer_id' => (int) $cliente->id,
                'nombre' => (string) $cliente->first_name,
                'apellido' => (string) $cliente->last_name,
                'email' => $cliente->email,
                // D-57 (el responsable funcional): el telefono de la direccion predeterminada es el del envio y
                // pesa mas para el asesor; si no existe, se cae al telefono de la ficha del
                // cliente. `addresses` va eager-cargada (sin N+1): no dispara consulta nueva.
                'telefono_principal' => optional($cliente->addresses->firstWhere('default_address', true))->phone ?: $cliente->phone,
                'grupo_id' => $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id,
                'grupo_nombre' => $cliente->group?->name,
                'activo' => (bool) $cliente->status,
                'suspendido' => (bool) $cliente->is_suspended,
            ] : null,
            totales: $carrito ? [
                'items_count' => (int) $carrito->items_count,
                'items_qty' => (float) $carrito->items_qty,
                'sub_total' => (float) $carrito->sub_total,
                'discount_amount' => (float) $carrito->discount_amount,
                'tax_total' => (float) $carrito->tax_total,
                'shipping_amount' => (float) $carrito->shipping_amount,
                'grand_total' => (float) $carrito->grand_total,
            ] : null,
            propietarioAdminId: $borrador->propietario_admin_id,
            creadoPorAdminId: $borrador->creado_por_admin_id,
            asignadoHasta: $borrador->asignado_hasta,
            reclamable: $borrador->estado === Estado::ACTIVO && ($borrador->asignado_hasta === null || ! now()->lt($borrador->asignado_hasta)),
            canalVenta: $borrador->canal_venta->value,
            mecanismoCreacion: $borrador->mecanismo_creacion->value,
            externalReference: $borrador->external_reference,
            orderId: $borrador->order_id,
            clienteConfirmable: $cliente !== null && $grupoApto && $cliente->status && ! $cliente->is_suspended,
            avisos: $avisos,
            grupo: $this->vistaGrupo($borrador, $cliente),
        );
    }

    /**
     * GRP-01: grupo habitual (el real del cliente) y grupo aplicado a ESTA venta. El nombre del grupo aplicado solo cuesta una
     * consulta cuando hay override; sin override es el del cliente, ya cargado.
     *
     * @return array{habitual_id: ?int, habitual_nombre: ?string, aplicado_id: ?int, aplicado_nombre: ?string, override: bool, cambios: int}|null
     */
    protected function vistaGrupo(BorradorVenta $borrador, ?object $cliente): ?array
    {
        if (! $cliente) {
            return null;
        }

        $habitualId = $cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id;
        $override = $borrador->grupo_override && $borrador->grupo_override_customer_id === (int) $cliente->id && $borrador->grupo_efectivo_id !== null;

        return [
            'habitual_id' => $habitualId,
            'habitual_nombre' => $cliente->group?->name,
            'aplicado_id' => $override ? $borrador->grupo_efectivo_id : $habitualId,
            'aplicado_nombre' => $override
                ? CustomerGroupProxy::modelClass()::query()->whereKey($borrador->grupo_efectivo_id)->value('name')
                : $cliente->group?->name,
            'override' => $override,
            'cambios' => (int) $borrador->grupo_eventos,
        ];
    }
}
