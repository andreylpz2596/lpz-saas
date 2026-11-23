<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\ConfirmacionVenta;
use ByLopez\VentasAsesor\DataObjects\ReconciliacionConfirmacion as Reconciliacion;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Enums\EstadoReconciliacionConfirmacion as Clasificacion;
use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\PagoBorradorInvalidoException;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as RechazoDelCerco;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Services\Asesores\AsesorActualService;
use ByLopez\VentasAsesor\Services\Comisiones\TipoOperacionPedidoService;
use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\Logistica\MunicipioDesdeCatalogo;
use ByLopez\VentasAsesor\Support\Venta\CarritoComercial;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartItemProxy;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\User\Contracts\Admin;

/**
 * Confirmacion de un borrador de venta en un pedido REAL de Bagisto (03F). Sin Request,
 * Route, Session, Blade ni Vue: la usan POS, API, n8n, WhatsApp y voz.
 *
 * UN BORRADOR → UN SOLO PEDIDO (D-55), aunque haya doble clic, reintentos, respuesta
 * perdida, lease vencido o un intento viejo que despierta:
 *
 * A. Paso corto (transaccion propia, FOR UPDATE del borrador): permiso y propietario,
 *    reconciliacion (§38.7) y, si corresponde, `confirmando` + confirmation_token nuevo +
 *    confirmacion_iniciada_en. `confirmado` devuelve el mismo pedido (ya_existia).
 * B. Revalidacion y pedido, SIN transaccion exterior: OrderRepository::create() hace
 *    DB::commit() en su finally tambien tras un rollback; dentro de otra transaccion la
 *    confirmaria antes de tiempo y soltaria sus bloqueos (Doc. 05 §37.1). Por eso aqui
 *    NUNCA se envuelve en DB::transaction (lo protege una prueba).
 *    Dentro de la transaccion del propio Bagisto: el cerco (`save.before`: token vigente +
 *    inventario, D-54) y el enlace condicionado por token (`save.after`).
 * C. Tras el commit: se borra el Cart como el panel (borradores.cart_id → NULL por FK).
 *
 * Cualquier fallo antes del pedido, o agotados los reintentos nativos, devuelve el borrador
 * a `activo` con un UPDATE condicionado por el token de ESTE intento: un intento viejo
 * nunca reactiva ni pisa el de otro.
 *
 * No reduce cantidades, no inventa stock ni precios, no usa custom_price, no descuenta
 * stock fisico, no crea Shipment ni registra dinero (sin Transaction; D-53 fuera de 03F).
 */
class ConfirmarVentaService
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ValidadorGrupoComercial $validadorGrupo,
        protected CarritoComercial $carritos,
        protected PagoBorradorService $pagos,
        protected ResolverAtribucionService $resolver,
        protected ContextoComercialDePeticion $contextoComercial,
        protected ConfirmacionEnCurso $confirmaciones,
        protected OrderRepository $pedidos,
        protected CartRepository $carritosBagisto,
        protected PuntosRecogida $puntosRecogida,
        protected AsesorActualService $asesorActual,
        protected TipoOperacionPedidoService $tiposOperacion,
        protected RentabilidadVentaService $rentabilidad,
    ) {}

    /**
     * @param  int|float|string  $totalEsperado  El total que vio y acepto el asesor o cliente.
     */
    public function confirmar(
        int $borradorId,
        Admin $actor,
        int|float|string $totalEsperado,
        ?SeleccionPropiedadComercial $seleccion = null,
        TipoOperacionPedido|string|null $tipoOperacion = null,
    ): ConfirmacionVenta {
        $this->exigirPermiso($actor);
        $totalEsperado = $this->normalizarTotal($totalEsperado);
        $tipoOperacion = $this->tipoOperacionPermitido($actor, $tipoOperacion);

        $inicio = $this->iniciar($borradorId, $actor);

        if (is_int($inicio)) {
            return $this->resultado($borradorId, $inicio, true);
        }

        [$token, $cartId] = $inicio;

        $this->ampliarTiempo();
        $terminado = false;
        // Un pedido grande puede agotar el tiempo de PHP (error FATAL: no pasa por el catch). Sin esto el borrador
        // quedaria `confirmando` hasta que venza el lease y «Reintentar» responderia «en proceso» sin que nadie trabaje.
        register_shutdown_function(function () use ($borradorId, $token, &$terminado) {
            if ($terminado || ! in_array(error_get_last()['type'] ?? null, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            try {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                $this->liberar($borradorId, $token);
            } catch (Throwable) {
                // Sin conexion: el lease vence solo y el intento se recupera.
            }
        });

        try {
            $pedido = $this->crearPedido($borradorId, $token, $actor, $totalEsperado, $seleccion, $tipoOperacion);
        } catch (Throwable $e) {
            $terminado = true;
            $this->liberar($borradorId, $token);

            throw $this->traducir($e);
        }

        $terminado = true;

        $this->retirarCarrito($cartId, (int) $pedido->id);

        return $this->resultado($borradorId, (int) $pedido->id, false);
    }

    /** Tope de tiempo de la confirmacion (web: PHP corta a los 30 s por defecto). Debe quedar bajo el lease. */
    protected function ampliarTiempo(): void
    {
        $lease = max(0, (int) config('ventasasesor.confirmacion.lease_segundos', 300));
        $tope = min((int) config('ventasasesor.confirmacion.tiempo_maximo_segundos', 120), max(1, $lease - 30));

        $actual = (int) ini_get('max_execution_time');

        // 0 = sin limite (consola, colas, pruebas): no se le pone uno. Solo se SUBE el de la peticion web.
        if ($actual > 0 && $actual < $tope) {
            @set_time_limit($tope);
        }
    }

    /** Clasificacion de solo lectura (Doc. 05 §38.7). No repara ni bloquea nada. */
    public function reconciliar(int $borradorId): Reconciliacion
    {
        $borrador = BorradorVenta::query()->find($borradorId) ?? throw new BorradorInvalido(BorradorInvalido::INEXISTENTE);

        return $this->clasificar($borrador);
    }

    /**
     * Paso A. Devuelve el order_id si ya estaba confirmado, o [token, cart_id] del intento
     * nuevo. Nada de validaciones de carrito aqui: van en el paso B, cuyos fallos liberan.
     *
     * @return int|array{0: string, 1: int}
     */
    protected function iniciar(int $borradorId, Admin $actor): int|array
    {
        return DB::transaction(function () use ($borradorId, $actor) {
            $borrador = BorradorVenta::query()->lockForUpdate()->find($borradorId)
                ?? throw new BorradorInvalido(BorradorInvalido::INEXISTENTE);

            if ($borrador->propietario_admin_id !== (int) $actor->id) {
                throw new BorradorInvalido(BorradorInvalido::NO_ES_PROPIETARIO);
            }

            $clasificacion = $this->clasificar($borrador);

            return match ($clasificacion->estado) {
                Clasificacion::YA_CONFIRMADO => $clasificacion->orderId,
                Clasificacion::CONFIRMACION_EN_PROCESO => throw new Rechazo(Rechazo::CONFIRMACION_EN_PROCESO),
                Clasificacion::INCONSISTENTE => throw new Rechazo(Rechazo::CONFIRMACION_INCONSISTENTE, $clasificacion->toArray()),
                Clasificacion::DESCARTADO => throw new Rechazo(Rechazo::BORRADOR_NO_CONFIRMABLE, ['motivo' => 'descartado']),
                Clasificacion::CONFIRMABLE, Clasificacion::RECUPERABLE => [$this->tomar($borrador), (int) $borrador->cart_id],
            };
        });
    }

    /** Nuevo intento: rota el token (el anterior, si lo habia, queda obsoleto). */
    protected function tomar(BorradorVenta $borrador): string
    {
        $token = (string) Str::uuid();

        BorradorVenta::query()->whereKey($borrador->id)->update([
            'estado' => Estado::CONFIRMANDO->value,
            'confirmation_token' => $token,
            'confirmacion_iniciada_en' => now(),
        ]);

        return $token;
    }

    protected function clasificar(BorradorVenta $b): Reconciliacion
    {
        $id = (int) $b->id;

        if ($b->estado === Estado::DESCARTADO) {
            return new Reconciliacion($id, Clasificacion::DESCARTADO);
        }

        if ($b->estado === Estado::CONFIRMADO) {
            return $b->order_id !== null
                ? new Reconciliacion($id, Clasificacion::YA_CONFIRMADO, orderId: $b->order_id)
                : new Reconciliacion($id, Clasificacion::INCONSISTENTE, Reconciliacion::MOTIVO_CONFIRMADO_SIN_PEDIDO);
        }

        if ($b->order_id !== null) {
            return new Reconciliacion($id, Clasificacion::INCONSISTENTE, Reconciliacion::MOTIVO_CONFIRMANDO_CON_PEDIDO, $b->order_id);
        }

        if ($b->cart_id === null) {
            return new Reconciliacion($id, Clasificacion::INCONSISTENTE, Reconciliacion::MOTIVO_SIN_CARRITO);
        }

        // orders.cart_id no tiene indice ni UNIQUE: se cuentan, nunca se elige uno.
        $pedidos = OrderProxy::modelClass()::query()->where('cart_id', $b->cart_id)->count();

        if ($pedidos > 0) {
            return new Reconciliacion($id, Clasificacion::INCONSISTENTE, $pedidos > 1
                ? Reconciliacion::MOTIVO_PEDIDOS_DUPLICADOS_DEL_CARRITO
                : Reconciliacion::MOTIVO_PEDIDO_DEL_CARRITO_SIN_ENLACE, pedidosDelCarrito: $pedidos);
        }

        if ($b->estado === Estado::ACTIVO) {
            return new Reconciliacion($id, Clasificacion::CONFIRMABLE);
        }

        return $this->leaseVigente($b)
            ? new Reconciliacion($id, Clasificacion::CONFIRMACION_EN_PROCESO)
            : new Reconciliacion($id, Clasificacion::RECUPERABLE);
    }

    /** Sin hora de inicio (dato anterior a D-55) no hay lease que respetar. */
    protected function leaseVigente(BorradorVenta $b): bool
    {
        if ($b->confirmacion_iniciada_en === null) {
            return false;
        }

        $segundos = max(0, (int) config('ventasasesor.confirmacion.lease_segundos', 300));

        return now()->lt($b->confirmacion_iniciada_en->copy()->addSeconds($segundos));
    }

    /** Paso B: revalidacion completa y pedido. Sin transaccion exterior. */
    protected function crearPedido(
        int $borradorId,
        string $token,
        Admin $actor,
        float $totalEsperado,
        ?SeleccionPropiedadComercial $seleccion,
        TipoOperacionPedido $tipoOperacion = TipoOperacionPedido::VENTA_NORMAL,
    ): Order {
        $borrador = BorradorVenta::query()->find($borradorId);

        if (! $borrador || $borrador->confirmation_token !== $token || $borrador->estado !== Estado::CONFIRMANDO) {
            throw new Rechazo(Rechazo::CONFIRMACION_OBSOLETA);
        }

        $carrito = CartProxy::modelClass()::query()->find($borrador->cart_id)
            ?? throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'sin_carrito']);

        $customerId = $this->revalidarCliente($carrito);
        $lineas = $this->revalidarLineas($borrador, $carrito);

        return $this->carritos->operar($carrito, $customerId, function () use ($borrador, $token, $actor, $totalEsperado, $seleccion, $lineas, $tipoOperacion) {
            // RT-03: reprecia con el grupo ACTUAL del cliente; reglas, descuentos e impuestos.
            Cart::collectTotals();

            $cart = $this->exigirMismasLineas($lineas);
            $this->revalidarStock($cart);
            $this->revalidarEntrega($cart, $borrador);
            $this->revalidarPago();

            $cart = Cart::getCart();
            $this->revalidarTotal($cart, $totalEsperado);
            $this->revalidarErroresDeBagisto();

            $datos = (new OrderResource($cart))->jsonSerialize();
            $cartId = (int) $cart->id;

            // 02B1A: el contexto explicito de atribucion llega al listener existente, que
            // escribe la atribucion una sola vez (y, si falla, conserva el pedido).
            $contexto = $this->resolver->paraCreacionAdministrativa(
                creador: $actor,
                mecanismo: $borrador->mecanismo_creacion,
                canal: $borrador->canal_venta,
                seleccion: $seleccion,
                cartId: $cartId,
                referenciaExterna: $borrador->external_reference,
            );

            // R2 (Doc. 23): proteccion de rentabilidad sobre el precio FINAL de cada linea, con el % vigente AHORA del
            // propietario comercial real. Solo actua con el bloqueo activo; si rechaza, el pedido no se crea.
            $this->rentabilidad->exigirEnConfirmacion($cartId, $contexto->propiedad->value, $contexto->advisorId, $tipoOperacion);

            return $this->contextoComercial->durante($cartId, $contexto,
                fn () => $this->confirmaciones->durante($cartId, (int) $borrador->id, $token,
                    fn () => $this->pedidos->create($datos), $tipoOperacion, (int) $actor->id));
        });
    }

    protected function revalidarCliente(CartContract $carrito): int
    {
        $cliente = $carrito->customer_id === null ? null : CustomerProxy::modelClass()::query()->find($carrito->customer_id);

        $motivo = match (true) {
            $cliente === null => 'cliente_inexistente',
            (bool) $carrito->is_guest => 'invitado',
            ! $cliente->status => 'cliente_inactivo',
            (bool) $cliente->is_suspended => 'cliente_suspendido',
            default => null,
        };

        if ($motivo === null) {
            try {
                $this->validadorGrupo->validar($cliente->customer_group_id === null ? null : (int) $cliente->customer_group_id);
            } catch (GrupoComercialInvalidoException) {
                $motivo = 'grupo_no_apto';
            }
        }

        if ($motivo !== null) {
            throw new Rechazo(Rechazo::CLIENTE_NO_CONFIRMABLE, ['motivo' => $motivo]);
        }

        return (int) $cliente->id;
    }

    /**
     * Invariantes del modulo ANTES de collectTotals (validateItems() quitaria en silencio una
     * linea inactiva y borraria el carrito vacio). Devuelve las lineas: cart_item_id => cantidad.
     *
     * @return array<int, int>
     */
    protected function revalidarLineas(BorradorVenta $borrador, CartContract $carrito): array
    {
        if ($carrito->is_active) {
            throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'carrito_activo']);
        }

        $items = CartItemProxy::modelClass()::query()->with('product')->where('cart_id', $carrito->id)->orderBy('id')->get();
        $lineas = $items->whereNull('parent_id');

        if ($lineas->isEmpty()) {
            throw new Rechazo(Rechazo::CARRITO_VACIO);
        }

        if ($items->contains(fn ($item) => $item->custom_price !== null)) {
            throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'custom_price']);
        }

        $porVariante = [];

        foreach ($lineas as $linea) {
            $producto = $linea->product;
            $hijos = $items->where('parent_id', $linea->id);

            $variante = match (true) {
                ! $producto || $producto->parent_id !== null => null,
                $linea->type === 'configurable' && $hijos->count() === 1 => $hijos->first()->product,
                $linea->type === 'simple' && $hijos->isEmpty() => $producto,
                default => null,
            };

            if (! $variante || ($variante !== $producto && (int) $variante->parent_id !== (int) $producto->id)) {
                throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'variante_no_pertenece_al_padre', 'cart_item_id' => (int) $linea->id]);
            }

            if (! $producto->status || ! $variante->status) {
                throw new Rechazo(Rechazo::VARIANTE_NO_VENDIBLE, ['variante_id' => (int) $variante->id, 'cart_item_id' => (int) $linea->id]);
            }

            $porVariante[(int) $variante->id] = ($porVariante[(int) $variante->id] ?? 0) + (int) $linea->quantity;
        }

        // I-13 (D-39): el carrito solo materializa lo solicitado. Solicitud 100, carrito 30 → pedido 30.
        $solicitado = SolicitudBorrador::query()->where('borrador_id', $borrador->id)->pluck('cantidad_solicitada', 'variante_id');

        foreach ($porVariante as $varianteId => $cantidad) {
            if (! isset($solicitado[$varianteId]) || $cantidad > (int) $solicitado[$varianteId]) {
                throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, [
                    'motivo' => isset($solicitado[$varianteId]) ? 'linea_excede_solicitud' : 'linea_sin_solicitud',
                    'variante_id' => $varianteId,
                ]);
            }
        }

        return $lineas->mapWithKeys(fn ($linea) => [(int) $linea->id => (int) $linea->quantity])->all();
    }

    /** collectTotals no debe haber quitado, agregado ni cambiado lineas (ni borrado el carrito). */
    protected function exigirMismasLineas(array $lineas): CartContract
    {
        $cart = Cart::getCart() ?? throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'carrito_eliminado']);

        $actuales = $cart->items()->get()->mapWithKeys(fn ($linea) => [(int) $linea->id => (int) $linea->quantity])->all();
        ksort($actuales);
        ksort($lineas);

        if ($actuales !== $lineas) {
            throw new Rechazo(Rechazo::CARRITO_INCONSISTENTE, ['motivo' => 'lineas_cambiaron']);
        }

        return $cart;
    }

    /** Validacion temprana (la de Bagisto). La garantia concurrente es el cerco (D-54). */
    protected function revalidarStock(CartContract $cart): void
    {
        if (Cart::isItemsHaveSufficientQuantity()) {
            return;
        }

        $variantes = [];

        foreach ($cart->items()->with(['product', 'child.product'])->get() as $linea) {
            if (! Cart::isItemHaveQuantity($linea)) {
                $variante = $linea->type === 'configurable' ? $linea->child->product : $linea->product;

                $variantes[] = [
                    'variante_id' => (int) $variante->id,
                    'cantidad' => (int) $linea->quantity,
                    'stock_vendible' => (int) $variante->getTypeInstance()->totalQuantity(),
                ];
            }
        }

        throw new Rechazo(Rechazo::STOCK_INSUFICIENTE, ['fase' => 'revalidacion', 'variantes' => $variantes]);
    }

    /**
     * Facturacion y, con productos fisicos, envio y metodo. saveShippingMethod() vuelve a
     * pedir las tarifas a Bagisto para el carrito y destino actuales y exige que el metodo
     * elegido siga entre ellas (la recogida solo existe si el envio es una bodega real).
     * Luego collectTotals aplica la tarifa vigente y el envio gratis de CartRule.
     */
    protected function revalidarEntrega(CartContract $cart, BorradorVenta $borrador): void
    {
        $motivo = match (true) {
            ! $cart->billing_address => 'facturacion_requerida',
            ! $cart->haveStockableItems() => null,
            ! $cart->shipping_address => 'direccion_envio_requerida',
            (string) $cart->shipping_method === '' => 'metodo_entrega_requerido',
            // D-41: la recogida (`pickup` de bagisto-eu/pickup) solo con envio a una bodega real.
            ! $this->puntosRecogida->metodoPermitido((string) $cart->shipping_method, $cart->shipping_address),
            // Domicilio local (zona local de Logistica): exige domiciliario valido con tarifa resoluble; sin el, el metodo
            // ni siquiera se ofreceria (no hay valor generico).
            $this->domicilioLocalSinDomiciliario($cart, $borrador) => 'domiciliario_requerido',
            ! Cart::saveShippingMethod((string) $cart->shipping_method) => 'metodo_entrega_no_disponible',
            default => null,
        };

        if ($motivo !== null) {
            throw new Rechazo(Rechazo::ENTREGA_INVALIDA, ['motivo' => $motivo]);
        }

        // saveShippingMethod borro y recreo las tarifas, pero el Cart del facade conserva en
        // cache la tarifa elegida ANTERIOR (cargada por el collectTotals previo): el envio
        // gratis de CartRule se aplicaria sobre esa fila ya borrada. Se recarga el Cart.
        Cart::refreshCart();
        Cart::collectTotals();
    }

    /** true si la entrega es un domicilio local y NO hay domiciliario valido con tarifa en la zona (DomiciliarioDeVenta). */
    protected function domicilioLocalSinDomiciliario(CartContract $cart, BorradorVenta $borrador): bool
    {
        $envio = $cart->shipping_address;

        $opciones = app(DomiciliarioDeVenta::class)->opcionesParaVenta(
            app(MunicipioDesdeCatalogo::class)->municipioDeDireccion($envio->city, $envio->state, $envio->country),
            (string) $cart->shipping_method,
            $borrador->transportadora_id,
        );

        return $opciones !== null && ! $opciones['listo'];
    }

    /** 03E: el metodo guardado sigue en allowlist ∩ Payment::getPaymentMethods(). */
    protected function revalidarPago(): void
    {
        try {
            $this->pagos->metodoVigenteConCartYaFijado();
        } catch (PagoBorradorInvalidoException $e) {
            throw new Rechazo(Rechazo::PAGO_INVALIDO, ['motivo' => $e->codigo]);
        }
    }

    protected function revalidarTotal(CartContract $cart, float $totalEsperado): void
    {
        $totalNuevo = round((float) $cart->grand_total, 2);

        if (abs($totalNuevo - $totalEsperado) < 0.005) {
            return;
        }

        throw new Rechazo(Rechazo::TOTALES_CAMBIARON, [
            'total_esperado' => $totalEsperado,
            'total_nuevo' => $totalNuevo,
            'totales' => [
                'sub_total' => (float) $cart->sub_total,
                'discount_amount' => (float) $cart->discount_amount,
                'tax_total' => (float) $cart->tax_total,
                'shipping_amount' => (float) $cart->shipping_amount,
                'grand_total' => $totalNuevo,
            ],
        ]);
    }

    /** La misma comprobacion final del panel (Admin\OrderController::store). */
    protected function revalidarErroresDeBagisto(): void
    {
        $error = Cart::getErrors();

        if ($error === []) {
            return;
        }

        throw match ($error['error_code'] ?? null) {
            'INSUFFICIENT_QUANTITY' => new Rechazo(Rechazo::STOCK_INSUFICIENTE, ['fase' => 'revalidacion']),
            default => new Rechazo(Rechazo::BORRADOR_NO_CONFIRMABLE, ['motivo' => strtolower((string) ($error['error_code'] ?? 'error_carrito'))]),
        };
    }

    /** Vuelve a `activo` SOLO si este intento sigue siendo el vigente. */
    protected function liberar(int $borradorId, string $token): void
    {
        BorradorVenta::query()
            ->whereKey($borradorId)
            ->where('confirmation_token', $token)
            ->where('estado', Estado::CONFIRMANDO->value)
            ->whereNull('order_id')
            ->update([
                'estado' => Estado::ACTIVO->value,
                'confirmation_token' => null,
                'confirmacion_iniciada_en' => null,
            ]);
    }

    protected function traducir(Throwable $e): Throwable
    {
        if (! $e instanceof RechazoDelCerco) {
            return $e;
        }

        $codigo = match ($e->codigo) {
            RechazoDelCerco::STOCK_INSUFICIENTE => Rechazo::STOCK_INSUFICIENTE,
            RechazoDelCerco::CONFIRMACION_OBSOLETA => Rechazo::CONFIRMACION_OBSOLETA,
            default => Rechazo::CONFIRMACION_INCONSISTENTE,
        };

        return new Rechazo($codigo, ['fase' => 'cerco'] + $e->detalles, $e);
    }

    /**
     * Paso C (decidido por Camilo): se borra el Cart como el panel. La FK SET NULL deja
     * borradores.cart_id en NULL; orders.cart_id conserva el id. El pedido ya esta enlazado:
     * si el borrado falla, el carrito queda huerfano (inofensivo) y solo se registra.
     */
    protected function retirarCarrito(int $cartId, int $orderId): void
    {
        try {
            $this->carritosBagisto->delete($cartId);
        } catch (Throwable $e) {
            Log::warning('ventas_asesor.confirmacion.carrito_no_retirado', [
                'cart_id' => $cartId, 'order_id' => $orderId, 'excepcion' => $e::class,
            ]);
        }
    }

    protected function resultado(int $borradorId, int $orderId, bool $yaExistia): ConfirmacionVenta
    {
        $pedido = OrderProxy::modelClass()::query()->with(['payment', 'invoices'])->findOrFail($orderId);
        $factura = $pedido->invoices->sortBy('id')->first();
        [$estadoPago, $saldo] = $this->estadoPago($pedido);

        return new ConfirmacionVenta(
            borradorId: $borradorId,
            orderId: (int) $pedido->id,
            incrementId: (string) $pedido->increment_id,
            cliente: [
                'customer_id' => $pedido->customer_id === null ? null : (int) $pedido->customer_id,
                'nombre' => trim($pedido->customer_first_name.' '.$pedido->customer_last_name),
            ],
            total: (float) $pedido->grand_total,
            moneda: (string) $pedido->order_currency_code,
            metodoPago: ['codigo' => (string) $pedido->payment?->method, 'titulo' => $pedido->payment?->method_title],
            entrega: $pedido->shipping_method === null ? null : ['codigo' => $pedido->shipping_method, 'titulo' => $pedido->shipping_title],
            factura: $factura ? ['invoice_id' => (int) $factura->id, 'estado' => (string) $factura->state] : null,
            estadoPago: $estadoPago,
            saldo: $saldo,
            yaExistia: $yaExistia,
        );
    }

    /**
     * Derivado, sin persistir (Doc. 05 §34.6 y §34.11): pagado = Σ transacciones por factura;
     * una factura `paid` sin transacciones cuenta como pagada SIN evidencia → `revisar`.
     *
     * @return array{0: string, 1: float}
     */
    protected function estadoPago(Order $pedido): array
    {
        if (in_array($pedido->status, ['canceled', 'closed', 'fraud'], true)) {
            return [ConfirmacionVenta::PAGO_NO_APLICA, 0.0];
        }

        $pagado = 0.0;
        $sinEvidencia = false;

        foreach ($pedido->invoices as $factura) {
            $transacciones = (float) DB::table('order_transactions')->where('invoice_id', $factura->id)->sum('amount');

            if ($transacciones > 0) {
                $pagado += $transacciones;
            } elseif ($factura->state === 'paid') {
                $pagado += (float) $factura->base_grand_total;
                $sinEvidencia = true;
            }
        }

        $total = (float) $pedido->base_grand_total;
        $saldo = max(0.0, round($total - $pagado, 2));

        $estado = match (true) {
            $sinEvidencia => ConfirmacionVenta::PAGO_REVISAR,
            $pagado <= 0 => ConfirmacionVenta::PAGO_PENDIENTE,
            $saldo > 0 => ConfirmacionVenta::PAGO_PARCIAL,
            default => ConfirmacionVenta::PAGO_PAGADO,
        };

        return [$estado, $saldo];
    }

    protected function normalizarTotal(int|float|string $total): float
    {
        if (is_string($total) && ! preg_match('/^\d+(\.\d{1,4})?$/', trim($total))) {
            throw new Rechazo(Rechazo::BORRADOR_NO_CONFIRMABLE, ['motivo' => 'total_esperado_invalido']);
        }

        $valor = round((float) $total, 2);

        if ($valor < 0 || ! is_finite($valor)) {
            throw new Rechazo(Rechazo::BORRADOR_NO_CONFIRMABLE, ['motivo' => 'total_esperado_invalido']);
        }

        return $valor;
    }

    /**
     * G-11 (COM-D-15, bloques 21-23): sin valor = `venta_normal` (nunca se infiere de precio, cliente ni comentario).
     * Muestra / reposicion / garantia exigen `ventas_asesor.pedidos.tipo_operacion`; el asesor solo crea venta normal.
     */
    protected function tipoOperacionPermitido(Admin $actor, TipoOperacionPedido|string|null $tipo): TipoOperacionPedido
    {
        $tipo = match (true) {
            $tipo instanceof TipoOperacionPedido => $tipo,
            $tipo === null || $tipo === '' => TipoOperacionPedido::VENTA_NORMAL,
            default => TipoOperacionPedido::tryFrom($tipo) ?? throw new Rechazo(Rechazo::TIPO_OPERACION_INVALIDO),
        };

        if (! $this->tiposOperacion->puedeElegir($actor, $tipo)) {
            throw new Rechazo(Rechazo::TIPO_OPERACION_SIN_PERMISO, ['tipo_operacion' => $tipo->value]);
        }

        return $tipo;
    }

    protected function exigirPermiso(Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, BorradorVentaService::PERMISO)
            && ! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_COMPRAR)) {
            throw new BorradorInvalido(BorradorInvalido::SIN_PERMISO);
        }

        // ASESOR-01: el perfil puede haberse inactivado entre el borrador y la confirmacion; falla cerrado.
        if (! $this->asesorActual->operable($actor)) {
            throw new BorradorInvalido(BorradorInvalido::ASESOR_SIN_ASOCIACION);
        }
    }
}
