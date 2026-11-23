<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\DataObjects\EstadoNuevaVenta;
use ByLopez\VentasAsesor\DataObjects\LineaBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\MetodoPagoDisponible;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\DataObjects\VistaBorradorVenta;
use ByLopez\VentasAsesor\DataObjects\VistaEntregaBorrador;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\EntregaBorradorInvalidaException as EntregaInvalida;
use ByLopez\VentasAsesor\Exceptions\NuevaVentaInvalidaException as Invalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Venta\CantidadVenta;
use ByLopez\VentasAsesor\Support\Venta\LoteCatalogo;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Customer\Contracts\CustomerAddress;
use Webkul\Customer\Models\CustomerAddressProxy;
use Webkul\User\Contracts\Admin;

/**
 * Orquestador de "Nueva venta rapida" (03G-B). NO es un motor comercial: cada regla
 * (precio RT-03, stock, D-39, entrega, pago, confirmacion, ventas simultaneas) sigue en el
 * servicio que la posee; aqui solo se ordenan sus operaciones en menos viajes.
 *
 * - Sin Request, Route, Session ni vistas: lo puede llamar un panel, una API, una voz o un
 *   flujo automatico.
 * - Lote de tallas: todo o nada. Una transaccion externa envuelve las primitivas
 *   `*SinVista` (sus transacciones pasan a ser savepoints); cualquier fallo revierte el
 *   lote entero y lanza LOTE_RECHAZADO con la variante y la causa. Nada se aplica parcial.
 * - El estado (EstadoNuevaVenta) se compone UNA vez al final de cada operacion.
 * - Solicitud != Carrito (D-39): cada talla se materializa en el orden que permiten las
 *   guardas (I-13): subir → solicitud primero; bajar → carrito primero.
 * - El metodo de entrega que Bagisto invalida al editar lineas se reaplica SOLO si sigue
 *   disponible; si no, se pide revision. Nunca se elige otro.
 */
class NuevaVentaService
{
    public function __construct(
        protected BorradorVentaService $borradores,
        protected CrearClienteComercialService $crearCliente,
        protected CatalogoVentaService $catalogo,
        protected SolicitudBorradorService $solicitudes,
        protected LineasBorradorService $lineas,
        protected EntregaBorradorService $entrega,
        protected PagoBorradorService $pagos,
        protected ConfirmarVentaService $confirmacion,
        protected AutorizacionVentasAsesor $autorizacion,
        protected GuardarDireccionClienteComercialService $direccionesCliente,
        protected DomiciliarioDeVenta $domiciliarios,
    ) {}

    /**
     * 03G-D: el asesor agrega una direccion REUTILIZABLE al cliente de SU borrador sin salir de
     * Nueva venta. La guarda el servicio de RT-04 (email opcional, telefono E.164, codigo postal
     * segun la configuracion de Bagisto, municipio DIVIPOLA resuelto en backend, eventos customer.addresses.*); aqui solo se autoriza (propietario
     * del borrador editable) y se fija el cliente: nunca uno que llegue en la peticion. Si el
     * cliente no tiene predeterminada, la nueva lo es (como el primer alta del core). No toca el
     * carrito: usarla en la venta es entregaRapida() con OrigenDireccion::guardada().
     */
    public function agregarDireccionCliente(int $borradorId, DireccionComercial $direccion, Admin $actor): CustomerAddress
    {
        return DB::transaction(function () use ($borradorId, $direccion, $actor) {
            [, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;

            if (! $direccion->predeterminada && $this->direccionPredeterminadaId($customerId) === null) {
                $direccion = $direccion->comoPredeterminada();
            }

            return $this->direccionesCliente->guardar($customerId, $direccion);
        });
    }

    /**
     * Corrige una direccion GUARDADA del cliente del borrador (p. ej. un error de digitacion). Mismas reglas que agregar;
     * la direccion debe ser de ese cliente (GuardarDireccionClienteComercialService lo exige). La predeterminada sigue
     * siendolo. Los pedidos ya creados no cambian: cada pedido guarda su propia copia de la direccion. No toca el carrito:
     * si la venta ya usaba esa direccion, la pantalla la vuelve a aplicar (entregaRapida) para copiar los datos nuevos.
     */
    public function editarDireccionCliente(int $borradorId, int $direccionId, DireccionComercial $direccion, Admin $actor): CustomerAddress
    {
        return DB::transaction(function () use ($borradorId, $direccionId, $direccion, $actor) {
            [, $carrito] = $this->borradores->bloquearParaEditar($borradorId, $actor);
            $customerId = (int) $carrito->customer_id;

            if (! $direccion->predeterminada && $this->direccionPredeterminadaId($customerId) === $direccionId) {
                $direccion = $direccion->comoPredeterminada();
            }

            return $this->direccionesCliente->guardar($customerId, $direccion, $direccionId);
        });
    }

    /** Venta nueva para un cliente existente. Sin carrito hasta tener cliente valido. */
    public function iniciar(
        int $customerId,
        Admin $actor,
        CanalVenta|string $canal,
        ?string $referenciaExterna = null,
        MecanismoCreacion $mecanismo = MecanismoCreacion::VENTAS_ASESOR,
    ): EstadoNuevaVenta {
        $canal = $this->canalPermitido($canal);

        $borrador = $this->borradores->crear($customerId, $actor, $canal, $mecanismo, $referenciaExterna);

        return $this->estado((int) $borrador->id);
    }

    /**
     * P7: la venta en curso NACE con la primera prenda. Consultar productos o inventario no crea nada; al agregar la
     * primera talla se crea el borrador y se aplican las cantidades en UNA transaccion (si el lote se rechaza no queda
     * un borrador vacio). Es idempotente: la referencia (generada por la pantalla, ligada al actor) hace que un
     * reintento, un doble clic o una respuesta perdida devuelvan la misma venta con las mismas cantidades finales.
     *
     * @param  array<int|string, mixed>  $cantidadesPorVariante  variante_id => cantidad final (al menos una > 0)
     */
    public function iniciarConTallas(
        int $customerId,
        Admin $actor,
        CanalVenta|string $canal,
        string $referencia,
        int $productoId,
        array $cantidadesPorVariante,
        MecanismoCreacion $mecanismo = MecanismoCreacion::VENTAS_ASESOR,
        bool $autoservicio = false,
    ): EstadoNuevaVenta {
        // Doc. 33 P3: el autoservicio vende por el canal ecommerce (no esta en la lista de canales de los asesores).
        $canal = $autoservicio ? CanalVenta::ECOMMERCE : $this->canalPermitido($canal);

        if (collect($cantidadesPorVariante)->filter(fn ($c) => is_numeric($c) && (int) $c > 0)->isEmpty()) {
            throw new Invalida(Invalida::LOTE_VACIO);
        }

        // La referencia es del actor: otro usuario que repita el mismo valor nunca recibe (ni toca) la venta ajena.
        $referenciaDelActor = 'nv:'.(int) $actor->id.':'.$referencia;

        return DB::transaction(function () use ($customerId, $actor, $canal, $referenciaDelActor, $productoId, $cantidadesPorVariante, $mecanismo) {
            $borrador = $this->borradores->crear($customerId, $actor, $canal, $mecanismo, $referenciaDelActor);

            return $this->aplicarTallas((int) $borrador->id, $productoId, $cantidadesPorVariante, $actor);
        });
    }

    /**
     * Alta minima del cliente (RT-04: grupo explicito, telefono como identidad, email
     * opcional) y venta nueva, en UNA transaccion: si algo falla no queda un cliente a medias.
     */
    public function iniciarConClienteNuevo(
        DatosClienteComercial $datos,
        Admin $actor,
        CanalVenta|string $canal,
        ?string $referenciaExterna = null,
        MecanismoCreacion $mecanismo = MecanismoCreacion::VENTAS_ASESOR,
    ): EstadoNuevaVenta {
        $canal = $this->canalPermitido($canal);

        if (! $this->autorizacion->tienePermiso($actor, BorradorVentaService::PERMISO)) {
            throw new BorradorInvalido(BorradorInvalido::SIN_PERMISO);
        }

        // Misma referencia externa = misma venta: no se vuelve a dar de alta al cliente.
        if ($existente = $this->borradores->existentePorReferencia($mecanismo, $referenciaExterna)) {
            return $this->estado((int) $existente->id);
        }

        $borradorId = DB::transaction(function () use ($datos, $actor, $canal, $referenciaExterna, $mecanismo) {
            $cliente = $this->crearCliente->crear($datos);

            return (int) $this->borradores->crear((int) $cliente->id, $actor, $canal, $mecanismo, $referenciaExterna)->id;
        });

        return $this->estado($borradorId);
    }

    /**
     * Aplica cantidades por variante de UN producto (linea x color) como un solo lote todo o
     * nada. Cantidad 0 = quitar la talla; las variantes omitidas no se tocan.
     *
     * @param  array<int|string, mixed>  $cantidadesPorVariante  variante_id => cantidad final
     */
    public function aplicarTallas(int $borradorId, int $productoId, array $cantidadesPorVariante, Admin $actor): EstadoNuevaVenta
    {
        if ($cantidadesPorVariante === []) {
            throw new Invalida(Invalida::LOTE_VACIO);
        }

        $objetivos = $this->objetivosValidados($productoId, $cantidadesPorVariante);
        $cartId = $this->cartIdDe($borradorId);
        $metodoPrevio = $cartId === null ? null : CartProxy::modelClass()::query()->whereKey($cartId)->value('shipping_method');
        $revision = false;

        DB::transaction(function () use ($borradorId, $productoId, $objetivos, $actor, $metodoPrevio, &$revision) {
            // Lecturas de catalogo una vez por lote y dentro de ESTA transaccion (03G-B2).
            $lote = new LoteCatalogo;

            foreach ($objetivos as $varianteId => $cantidad) {
                try {
                    $this->llevarVarianteA($borradorId, $productoId, $varianteId, $cantidad, $actor, $lote);
                } catch (Throwable $e) {
                    throw new Invalida(Invalida::LOTE_RECHAZADO, $varianteId, $this->causa($e), $e);
                }
            }

            $revision = $this->reaplicarEntrega($borradorId, $metodoPrevio, $actor);
        });

        return $this->estado($borradorId, requiereRevisionEntrega: $revision);
    }

    /**
     * Facturacion + envio + metodo en un solo viaje (03D). La facturacion es la direccion
     * REAL indicada o la predeterminada del cliente (D-30); sin ninguna se rechaza y no se
     * inventa una (la bodega de recogida es el envio, nunca la facturacion). Todo o nada.
     */
    public function entregaRapida(
        int $borradorId,
        Admin $actor,
        OrigenDireccion $envio,
        ?string $metodo = null,
        ?OrigenDireccion $facturacion = null,
    ): EstadoNuevaVenta {
        DB::transaction(function () use ($borradorId, $actor, $envio, $metodo, $facturacion) {
            // Primero la guarda del dominio (permiso, borrador EDITABLE, propietario): sobre un borrador
            // confirmado/confirmando el carrito ya no existe y, sin esto, la ausencia de facturacion se
            // reportaba como `direccion_facturacion_requerida` en lugar de `borrador_no_editable`.
            $this->borradores->bloquearParaEditar($borradorId, $actor);

            $carrito = $this->carritoDe($borradorId);

            if ($facturacion === null && $carrito?->billing_address === null) {
                $direccionId = $this->direccionPredeterminadaId((int) $carrito?->customer_id);

                if ($direccionId === null) {
                    throw new Invalida(Invalida::DIRECCION_FACTURACION_REQUERIDA);
                }

                $facturacion = OrigenDireccion::guardada($direccionId);
            }

            if ($facturacion !== null) {
                $this->entrega->establecerFacturacionSinVista($borradorId, $facturacion, $actor);
            }

            $this->entrega->establecerEnvioSinVista($borradorId, $envio, $actor);

            if ($metodo !== null) {
                $this->entrega->seleccionarMetodoSinVista($borradorId, $metodo, $actor);
            }
        });

        return $this->estado($borradorId);
    }

    /**
     * Cierre F1 (D1): el asesor indica QUIEN ENTREGA un domicilio local (obligatorio para confirmar; null = quitar la
     * eleccion). Se guarda el id en el borrador y, como la tarifa del domicilio depende de domiciliario + zona, se recalculan
     * las tarifas del carrito y se reaplica el metodo ya elegido (precio y total nuevos). Al confirmar se comunica a Logistica.
     */
    public function elegirDomiciliario(int $borradorId, ?int $transportadoraId, Admin $actor): EstadoNuevaVenta
    {
        $revision = false;

        DB::transaction(function () use ($borradorId, $transportadoraId, $actor, &$revision) {
            $this->borradores->bloquearParaEditar($borradorId, $actor);

            if ($transportadoraId !== null && ! $this->domiciliarios->esSeleccionable($transportadoraId)) {
                throw new Invalida(Invalida::DOMICILIARIO_NO_VALIDO);
            }

            BorradorVenta::query()->whereKey($borradorId)->update(['transportadora_id' => $transportadoraId]);

            $carrito = $this->carritoDe($borradorId);

            if ($carrito?->shipping_address === null || ! $this->tieneLineas($borradorId)) {
                return;
            }

            if ($carrito->shipping_method) {
                $revision = $this->reaplicarEntrega($borradorId, (string) $carrito->shipping_method, $actor);

                return;
            }

            // Sin metodo aun: se vuelven a pedir las tarifas para que el domicilio aparezca (o desaparezca) con su valor.
            try {
                $this->entrega->calcularMetodosSinVista($borradorId, $actor);
            } catch (EntregaInvalida) {
            }
        });

        return $this->estado($borradorId, requiereRevisionEntrega: $revision);
    }

    /** Elige el metodo de pago; los ofrecidos se resuelven UNA vez y se reutilizan en el estado. */
    public function seleccionarPago(int $borradorId, string $metodo, Admin $actor): EstadoNuevaVenta
    {
        $ofrecidos = DB::transaction(fn () => $this->pagos->seleccionarSinVista($borradorId, $metodo, $actor));

        return $this->estado($borradorId, ofrecidosPago: $ofrecidos);
    }

    /** Confirma con el total que vio el asesor (EstadoNuevaVenta::totalEsperado). */
    public function confirmar(
        int $borradorId,
        Admin $actor,
        int|float|string $totalEsperado,
        ?SeleccionPropiedadComercial $seleccion = null,
        ?string $tipoOperacion = null,
    ) {
        return $this->confirmacion->confirmar($borradorId, $actor, $totalEsperado, $seleccion, $tipoOperacion);
    }

    /**
     * Lectura. Los metodos de pago ofrecidos solo se resuelven si se piden (costo alto); si
     * no, `pago` trae unicamente el metodo ya elegido.
     *
     * @param  list<MetodoPagoDisponible>|null  $ofrecidosPago
     */
    public function estado(
        int $borradorId,
        bool $conMetodosDePago = false,
        bool $requiereRevisionEntrega = false,
        ?array $ofrecidosPago = null,
    ): EstadoNuevaVenta {
        // Una lectura del borrador y de las lineas, y catalogo por lote (03G-B2).
        $lote = new LoteCatalogo;
        $lineas = $this->lineas->listar($borradorId, $lote);
        $borrador = $lineas->borrador;
        $solicitudes = $this->solicitudes->listar($borradorId, $lote, $borrador);
        $entrega = $this->entrega->vista($borradorId, $lineas);
        $resueltos = $conMetodosDePago || $ofrecidosPago !== null;
        $pago = $this->pagos->vista($borradorId, $resueltos ? $ofrecidosPago : [], $borrador);

        $requiereFacturacion = $entrega->facturacion === null
            && $borrador->cliente !== null
            && $this->direccionPredeterminadaId((int) $borrador->cliente['customer_id']) === null;

        return new EstadoNuevaVenta(
            borrador: $borrador,
            lineas: $lineas->lineas,
            solicitudes: $solicitudes->solicitudes,
            entrega: $entrega,
            pago: $pago,
            metodosPagoResueltos: $resueltos,
            totalEsperado: $borrador->totales['grand_total'] ?? null,
            requiereDireccionFacturacion: $requiereFacturacion,
            requiereRevisionEntrega: $requiereRevisionEntrega,
            motivosNoConfirmable: $this->motivos($borrador, $lineas->lineas, $entrega, $pago, $requiereFacturacion, $requiereRevisionEntrega),
        );
    }

    /** Direccion predeterminada REAL del cliente (Bagisto: customer_addresses.default_address). */
    public function direccionPredeterminadaId(int $customerId): ?int
    {
        $id = CustomerAddressProxy::modelClass()::query()
            ->where('customer_id', $customerId)
            ->where('address_type', 'customer')
            ->where('default_address', 1)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Fija la cantidad final de UNA variante respetando las guardas (D-39/I-13):
     * T=0 → linea fuera y luego solicitud; T>solicitado → solicitud primero, luego carrito;
     * T<=solicitado → carrito primero, luego baja la solicitud.
     */
    protected function llevarVarianteA(int $borradorId, int $productoId, int $varianteId, int $objetivo, Admin $actor, ?LoteCatalogo $lote = null): void
    {
        $cartId = $this->cartIdDe($borradorId);
        $solicitud = $this->solicitudes->solicitudDeVariante($borradorId, $varianteId);

        if ($objetivo === 0) {
            if (($lineaId = $this->lineas->lineaDeVariante($cartId, $varianteId)) !== null) {
                $this->lineas->eliminarSinVista($borradorId, $lineaId, $actor);
            }

            if ($solicitud !== null) {
                $this->solicitudes->eliminarSinVista($borradorId, (int) $solicitud->id, $actor);
            }

            return;
        }

        if ($solicitud === null) {
            $this->solicitudes->crearSinVista($borradorId, $productoId, $varianteId, $objetivo, $actor, $lote);
        } elseif ($objetivo > (int) $solicitud->cantidad_solicitada) {
            $this->solicitudes->cambiarCantidadSinVista($borradorId, (int) $solicitud->id, $objetivo, $actor, $lote);
        }

        $this->fijarCarrito($borradorId, $productoId, $varianteId, $objetivo, $actor, $cartId, $lote);

        if ($solicitud !== null && $objetivo < (int) $solicitud->cantidad_solicitada) {
            $this->solicitudes->cambiarCantidadSinVista($borradorId, (int) $solicitud->id, $objetivo, $actor, $lote);
        }
    }

    protected function fijarCarrito(int $borradorId, int $productoId, int $varianteId, int $objetivo, Admin $actor, ?int $cartId, ?LoteCatalogo $lote = null): void
    {
        $actual = $this->lineas->cantidadEnCarrito($cartId, $varianteId);

        if ($actual === $objetivo) {
            return;
        }

        if ($actual === 0) {
            $this->lineas->agregarSinVista($borradorId, $productoId, $varianteId, $objetivo, $actor, $lote);

            return;
        }

        $this->lineas->cambiarCantidadSinVista($borradorId, (int) $this->lineas->lineaDeVariante($cartId, $varianteId), $objetivo, $actor);
    }

    /**
     * El metodo previo se reaplica solo si aun es valido (misma tarifa o tarifa nueva
     * aceptada). Devuelve true si hay que revisar la entrega (nunca elige otro metodo).
     */
    protected function reaplicarEntrega(int $borradorId, ?string $metodoPrevio, Admin $actor): bool
    {
        if ($metodoPrevio === null || $metodoPrevio === '' || ! $this->tieneLineas($borradorId)) {
            return false;
        }

        try {
            $this->entrega->seleccionarMetodoSinVista($borradorId, $metodoPrevio, $actor);

            return false;
        } catch (EntregaInvalida) {
            // El intento revirtio sus tarifas: se recalculan para que el estado no muestre las viejas.
            try {
                $this->entrega->calcularMetodosSinVista($borradorId, $actor);
            } catch (EntregaInvalida) {
            }

            return true;
        }
    }

    /**
     * Valida TODO el lote antes de tocar nada: cantidades enteras >= 0 y variantes del
     * producto (I-5).
     *
     * @return array<int, int>
     */
    protected function objetivosValidados(int $productoId, array $cantidadesPorVariante): array
    {
        $producto = $this->catalogo->productoVendible($productoId);
        $objetivos = [];

        foreach ($cantidadesPorVariante as $varianteId => $cantidad) {
            $varianteId = (int) $varianteId;

            try {
                $this->catalogo->hijoVerificado($producto, $varianteId);
            } catch (VarianteInvalidaException $e) {
                throw new Invalida(Invalida::LOTE_RECHAZADO, $varianteId, $e->codigo, $e);
            }

            $normalizada = is_string($cantidad) && preg_match('/^[0-9]{1,7}$/', $cantidad) ? (int) $cantidad : $cantidad;

            if (! is_int($normalizada) || $normalizada < 0 || $normalizada > CantidadVenta::MAXIMA) {
                throw new Invalida(Invalida::LOTE_RECHAZADO, $varianteId, VarianteInvalidaException::CANTIDAD_INVALIDA);
            }

            $objetivos[$varianteId] = $normalizada;
        }

        return $objetivos;
    }

    protected function canalPermitido(CanalVenta|string $canal): CanalVenta
    {
        $valor = $canal instanceof CanalVenta ? $canal->value : trim($canal);
        $permitidos = array_map('strval', (array) config('ventasasesor.nueva_venta.canales', []));
        $enum = in_array($valor, $permitidos, true) ? CanalVenta::tryFrom($valor) : null;

        return $enum ?? throw new Invalida(Invalida::CANAL_NO_PERMITIDO, causa: $valor);
    }

    protected function causa(Throwable $e): string
    {
        return property_exists($e, 'codigo') && is_string($e->codigo) ? $e->codigo : class_basename($e);
    }

    protected function cartIdDe(int $borradorId): ?int
    {
        $cartId = BorradorVenta::query()->whereKey($borradorId)->value('cart_id');

        return $cartId === null ? null : (int) $cartId;
    }

    protected function tieneLineas(int $borradorId): bool
    {
        $cartId = $this->cartIdDe($borradorId);

        return $cartId !== null && CartProxy::modelClass()::query()->whereKey($cartId)->whereHas('items')->exists();
    }

    protected function carritoDe(int $borradorId)
    {
        $cartId = $this->cartIdDe($borradorId);

        return $cartId === null ? null : CartProxy::modelClass()::query()->with('billing_address')->find($cartId);
    }

    /**
     * @param  list<LineaBorradorVenta>  $lineas
     * @return list<string>
     */
    protected function motivos(VistaBorradorVenta $borrador, array $lineas, $entrega, $pago, bool $requiereFacturacion, bool $revision): array
    {
        $motivos = [];

        if ($borrador->estado !== 'activo') {
            $motivos[] = EstadoNuevaVenta::MOTIVO_BORRADOR_NO_EDITABLE;
        }

        if (! $borrador->clienteConfirmable) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_CLIENTE_NO_CONFIRMABLE;
        }

        if ($lineas === []) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_SIN_LINEAS;
        }

        if (in_array(VistaEntregaBorrador::AVISO_STOCK_INSUFICIENTE, $entrega->avisos, true)) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_STOCK_INSUFICIENTE;
        }

        if ($requiereFacturacion) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_DIRECCION_FACTURACION;
        }

        if ($revision) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_REVISAR_ENTREGA;
        } elseif (! $entrega->entregaCompleta) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_ENTREGA_INCOMPLETA;
        }

        // Domicilio local: sin domiciliario valido con tarifa en la zona no se confirma (el servidor lo vuelve a exigir).
        if (($entrega->domiciliario['requerido'] ?? false) && ! ($entrega->domiciliario['listo'] ?? false)) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_DOMICILIARIO_REQUERIDO;
        }

        if ($pago->metodoSeleccionado === null) {
            $motivos[] = EstadoNuevaVenta::MOTIVO_SIN_PAGO;
        }

        return $motivos;
    }
}
