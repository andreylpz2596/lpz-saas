<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AutoservicioInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AgregarDireccionNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AplicarTallasNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ColoresNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\EntregaNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarConTallasAutoservicioRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\MatricesAutoservicioRequest;
use ByLopez\VentasAsesor\Services\Autoservicio\ClienteActualService;
use ByLopez\VentasAsesor\Services\Autoservicio\PedidosClienteService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Autoservicio\LimiteInventarioCliente;
use ByLopez\VentasAsesor\Support\Http\RespuestaErrorNuevaVenta as Respuesta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use Webkul\Customer\Models\CustomerAddress;
use Webkul\User\Contracts\Admin;

/**
 * Doc. 33 P3 — Nueva venta para el CLIENTE FRECUENTE. Mismos servicios que la venta de un asesor; lo que cambia es la FRONTERA:
 *  - el cliente sale SIEMPRE del vinculo (ClienteActualService); `customer_id`, grupo o asesor que lleguen se ignoran;
 *  - cada borrador se verifica como propio antes de tocarlo;
 *  - ninguna respuesta lleva stock exacto (>10), produccion, grupo comercial, costos, margenes ni datos de otras ventas;
 *  - los errores son comerciales y neutros.
 */
class AutoservicioController extends Controller
{
    /** Solo el envio gratuito queda fuera del cliente (el equipo lo concede). Mismo criterio visual que la pantalla del asesor. */
    protected const PATRON_ENVIO_GRATIS = '/free|gratu|gratis/i';

    public function __construct(
        protected ClienteActualService $clientes,
        protected NuevaVentaService $nuevaVenta,
        protected CatalogoVentaService $catalogo,
        protected BorradorVentaService $borradores,
        protected PedidosClienteService $pedidos,
    ) {}

    /** La MISMA vista de Nueva venta en modo cliente: sin buscador, grupos, rentabilidad, cotizaciones ni cartera. */
    public function pantalla(): View
    {
        $this->clientes->de($this->actor());

        return app(NuevaVentaController::class)->pantalla()->with([
            'modoCliente' => true,
            'grupos' => [],
            'asesoresVenta' => [],
            'puedeClasificar' => false,
            'puedeCambiarGrupo' => false,
            'puedeVenderPara' => false,
            'puedeAdministrarAcceso' => false,
            'asesorSinAsociacion' => false,
        ]);
    }

    /** P5: «Mis pedidos» (pantalla). Los datos los pide la propia pagina a pedidos.data. */
    public function pedidosPantalla(): View
    {
        $this->clientes->de($this->actor(), AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_PEDIDOS);

        return view('ventasasesor::admin.autoservicio.pedidos');
    }

    public function pedidosData(Request $request): JsonResponse
    {
        return $this->responder(fn () => $this->pedidos->listar($this->clientes->de($this->actor(), AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_PEDIDOS), max(1, (int) $request->query('pagina', 1))));
    }

    public function pedidoDetalle(int $pedido): JsonResponse
    {
        return $this->responder(function () use ($pedido) {
            $detalle = $this->pedidos->detalle($this->clientes->de($this->actor(), AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_PEDIDOS), $pedido);

            // Ajeno e inexistente son indistinguibles: el mismo 404.
            return $detalle ?? response()->json(['codigo' => 'no_encontrado', 'mensaje' => ''], 404);
        });
    }

    public function bootstrap(): JsonResponse
    {
        return $this->responder(function () {
            $c = $this->clientes->de($this->actor());

            return [
                'cliente' => ['nombre' => trim($c->first_name.' '.$c->last_name)],
                'accesos_rapidos' => array_map(fn ($a) => $a->toArray(), $this->catalogo->accesosRapidos()),
            ];
        });
    }

    public function colores(string $clave, ColoresNuevaVentaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($clave, $request) {
            $this->clientes->de($this->actor());

            return array_map(
                fn ($c) => $c->toArray(),
                $this->catalogo->coloresDeAcceso($clave, $request->string('q')->trim()->value() ?: null, (int) $request->input('limite', 50)),
            );
        });
    }

    public function matriz(int $producto): JsonResponse
    {
        return $this->responder(function () use ($producto) {
            $cliente = $this->clientes->de($this->actor());
            $borrador = request()->integer('borrador_id') ?: null;
            $this->exigirBorradorPropio($borrador, (int) $cliente->id);

            return $this->catalogo->matriz($producto, (int) $cliente->id, $this->actor(), 1, $borrador)->toArray();
        });
    }

    public function matrices(MatricesAutoservicioRequest $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $cliente = $this->clientes->de($this->actor());
            $borrador = $request->integer('borrador_id') ?: null;
            $this->exigirBorradorPropio($borrador, (int) $cliente->id);

            return $this->catalogo->matrices(
                array_map('intval', (array) $request->input('productos')),
                (int) $cliente->id,
                $this->actor(),
                $borrador,
                $request->has('solo_vendibles') ? $request->boolean('solo_vendibles') : true,
            );
        });
    }

    public function iniciarConTallas(IniciarConTallasAutoservicioRequest $request): JsonResponse
    {
        return $this->responder(function () use ($request) {
            $cliente = $this->clientes->de($this->actor());

            return $this->nuevaVenta->iniciarConTallas(
                (int) $cliente->id,
                $this->actor(),
                'ecommerce',
                (string) $request->string('referencia'),
                (int) $request->integer('producto_id'),
                $request->cantidadesPorVariante(),
                autoservicio: true,
            )->toArray();
        });
    }

    public function estado(int $borrador): JsonResponse
    {
        return $this->responder(function () use ($borrador) {
            $this->propio($borrador);

            return $this->nuevaVenta->estado($borrador)->toArray();
        });
    }

    public function aplicarTallas(int $borrador, AplicarTallasNuevaVentaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($borrador, $request) {
            $this->propio($borrador);

            return $this->nuevaVenta->aplicarTallas(
                $borrador, (int) $request->integer('producto_id'), $request->cantidadesPorVariante(), $this->actor(),
            )->toArray();
        });
    }

    /**
     * P4: aplica los valores por defecto del autoservicio (RECOGIDA EN TIENDA y TRANSFERENCIA como metodo, sin dinero recibido)
     * y devuelve el resumen con el total que se va a confirmar. Es idempotente. El cliente no elige entrega, domiciliario ni pago.
     */
    public function preparar(int $borrador, Request $request): JsonResponse
    {
        return $this->responder(function () use ($borrador, $request) {
            $this->propio($borrador);

            return $this->aplicarPorDefecto($borrador, ! $request->boolean('solo_pago'))->toArray();
        });
    }

    /** Direcciones del cliente vinculado (nunca las de otro). */
    public function direcciones(): JsonResponse
    {
        return $this->responder(fn () => app(NuevaVentaController::class)->listaDirecciones((int) $this->clientes->de($this->actor())->id));
    }

    /**
     * El cliente agrega una direccion a SU ficha (RT-04, mismo servicio y validacion que el asesor), hasta un maximo. Corregir o
     * borrar las existentes sigue siendo del equipo. Devuelve la nueva y la lista, como la pantalla del asesor.
     */
    public function agregarDireccion(int $borrador, AgregarDireccionNuevaVentaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($borrador, $request) {
            $this->propio($borrador);
            $cliente = $this->clientes->de($this->actor());

            if ($this->direccionesDelCliente((int) $cliente->id)->count() >= (int) config('ventasasesor.autoservicio.max_direcciones', 5)) {
                return response()->json(['codigo' => 'limite_direcciones', 'mensaje' => ''], 403);
            }

            $nueva = $this->nuevaVenta->agregarDireccionCliente($borrador, $request->aDireccion(), $this->actor());

            return [
                'direccion_id' => (int) $nueva->id,
                'direcciones' => app(NuevaVentaController::class)->listaDirecciones((int) $cliente->id),
            ];
        });
    }

    /**
     * Entrega del cliente: RECOGIDA en tienda o DOMICILIO a una direccion SUYA, con el metodo de envio que elija. Reglas del servidor
     * (la pantalla solo las refleja): las direcciones deben ser del cliente; el envio gratuito no se ofrece; quien entrega un domicilio
     * local no lo elige el cliente (se aplica el sugerido por Logistica o el primero con tarifa); el pago lo resuelve `preparar`.
     */
    public function entrega(int $borrador, EntregaNuevaVentaRequest $request): JsonResponse
    {
        return $this->responder(function () use ($borrador, $request) {
            $this->propio($borrador);
            $cliente = (int) $this->clientes->de($this->actor())->id;
            $propias = $this->direccionesDelCliente($cliente)->pluck('id')->map(fn ($i) => (int) $i)->all();

            $tipoEnvio = (string) $request->input('envio.tipo');
            $envio = match ($tipoEnvio) {
                OrigenDireccion::PUNTO_RECOGIDA => OrigenDireccion::puntoRecogida(),
                OrigenDireccion::GUARDADA => OrigenDireccion::guardada((int) $request->input('envio.address_id')),
                default => null,
            };

            $facturacionId = $request->input('facturacion.tipo') === OrigenDireccion::GUARDADA ? (int) $request->input('facturacion.address_id') : null;

            if ($envio === null
                || ($tipoEnvio === OrigenDireccion::GUARDADA && ! in_array((int) $request->input('envio.address_id'), $propias, true))
                || (is_array($request->input('facturacion')) && ($facturacionId === null || ! in_array($facturacionId, $propias, true)))) {
                return response()->json(['codigo' => 'entrega_no_permitida', 'mensaje' => ''], 403);
            }

            $metodo = $request->string('metodo')->trim()->value() ?: null;

            if ($metodo !== null && preg_match(self::PATRON_ENVIO_GRATIS, $metodo)) {
                return response()->json(['codigo' => 'metodo_no_permitido', 'mensaje' => ''], 403);
            }

            $estado = $this->nuevaVenta->entregaRapida(
                $borrador, $this->actor(), $envio, $metodo, $facturacionId === null ? null : OrigenDireccion::guardada($facturacionId),
            );

            // Domicilio local: el domiciliario es obligatorio para confirmar y el cliente no lo elige.
            $dom = $estado->entrega->domiciliario;

            if ($dom && ($dom['requerido'] ?? false) && empty($dom['elegido_id'])) {
                $id = $dom['sugerido_id'] ?? collect($dom['opciones'] ?? [])->first(fn ($o) => ($o['tarifa'] ?? null) !== null)['id'] ?? null;

                if ($id !== null) {
                    $estado = $this->nuevaVenta->elegirDomiciliario($borrador, (int) $id, $this->actor());
                }
            }

            return $estado->toArray();
        });
    }

    protected function direccionesDelCliente(int $cliente)
    {
        return CustomerAddress::query()->where('customer_id', $cliente)->where('address_type', 'customer')->get(['id']);
    }
    /** P4: confirma con el total que el cliente vio. Doble clic / reintento = el mismo pedido (yaExistia), nunca dos. */
    public function confirmar(int $borrador, Request $request): JsonResponse
    {
        $datos = $request->validate(['total_esperado' => ['required', 'numeric', 'min:0']]);

        return $this->responder(function () use ($borrador, $datos) {
            $this->propio($borrador);
            $actor = $this->actor();

            // Un reintento sobre una venta ya confirmada no vuelve a tocar entrega ni pago.
            if ($this->borradores->obtener($borrador)->estado === 'activo') {
                $this->aplicarPorDefecto($borrador);
            }

            $c = $this->nuevaVenta->confirmar($borrador, $actor, $datos['total_esperado'], seleccion: SeleccionPropiedadComercial::ventaDeEmpresa());

            $metodo = (string) \Webkul\Sales\Models\Order::query()->whereKey($c->orderId)->value('shipping_method');
            $entrega = $metodo !== '' && ! str_contains($metodo, 'pickup') ? 'Envío a domicilio' : 'Recogida en tienda';

            // Solo lo que el cliente necesita: nada de atribucion, factura interna ni datos de pago.
            return [
                'order_id' => $c->orderId,
                'numero' => $c->incrementId,
                'total' => $c->total,
                'moneda' => $c->moneda,
                'entrega' => $entrega,
                'pago' => 'Transferencia bancaria (pendiente de confirmar)',
                'estado_pago' => $c->estadoPago,
                'ya_existia' => $c->yaExistia,
            ];
        });
    }

    /**
     * Pago por defecto (transferencia, sin dinero recibido). La entrega solo se pone por defecto (RECOGIDA) cuando el cliente aun no
     * eligio ninguna, o si la recogida quedo incompleta; un domicilio elegido por el cliente nunca se pisa.
     */
    protected function aplicarPorDefecto(int $borrador, bool $conEntregaPorDefecto = true)
    {
        $actor = $this->actor();
        $metodoEntrega = (string) config('ventasasesor.autoservicio.metodo_entrega', 'pickup');
        $metodoPago = (string) config('ventasasesor.autoservicio.metodo_pago', 'moneytransfer');

        $estado = $this->nuevaVenta->estado($borrador);
        $envio = $estado->entrega->envio;
        $esRecogida = $envio !== null && $envio->origen === 'punto_recogida';

        if ($conEntregaPorDefecto && ($envio === null || ($esRecogida && (! $estado->entrega->entregaCompleta || $estado->entrega->metodoSeleccionado?->codigo !== $metodoEntrega)))) {
            $this->nuevaVenta->entregaRapida($borrador, $actor, OrigenDireccion::puntoRecogida(), $metodoEntrega, null);
        }

        return $this->nuevaVenta->seleccionarPago($borrador, $metodoPago, $actor);
    }
    public function enCurso(): JsonResponse
    {
        return $this->responder(function () {
            $cliente = $this->clientes->de($this->actor());

            // Solo ventas con productos y de SU cliente (si el vinculo cambio, las de otro cliente no aparecen).
            return array_values(array_filter(
                array_map(fn ($v) => get_object_vars($v), $this->borradores->listarDelPropietario((int) $this->actor()->id, soloConLineas: true)),
                fn ($v) => (int) ($v['cliente']['customer_id'] ?? 0) === (int) $cliente->id,
            ));
        });
    }

    public function eliminar(int $borrador): JsonResponse
    {
        return $this->responder(function () use ($borrador) {
            $this->propio($borrador);

            return get_object_vars($this->borradores->descartar($borrador, $this->actor()));
        });
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Borrador propio Y del cliente vinculado; si no, el mismo rechazo que cualquier ajeno. */
    protected function propio(int $borrador): void
    {
        $cliente = $this->clientes->de($this->actor());
        $this->borradores->exigirDelPropietario($borrador, $this->actor(), (int) $cliente->id);
    }

    protected function exigirBorradorPropio(?int $borrador, int $customerId): void
    {
        if ($borrador !== null) {
            $this->borradores->exigirDelPropietario($borrador, $this->actor(), $customerId);
        }
    }

    /** Ejecuta, sanea (inventario/produccion/grupo) y neutraliza los errores. */
    protected function responder(callable $accion): JsonResponse
    {
        try {
            $respuesta = Respuesta::envolver(function () use ($accion) {
                try {
                    return $accion();
                } catch (AutoservicioInvalidoException $e) {
                    return response()->json(['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], 403);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['codigo' => 'error', 'mensaje' => 'No pudimos completar la operación. Inténtalo de nuevo.'], 500);
        }

        $datos = $this->paraCliente(LimiteInventarioCliente::sanear($respuesta->getData(true)));

        if ($respuesta->getStatusCode() >= 400) {
            $datos['mensaje'] = $this->mensajeNeutro((string) ($datos['codigo'] ?? ''), (string) ($datos['causa'] ?? ''));
            unset($datos['variante_id']);
        }

        return response()->json($datos, $respuesta->getStatusCode());
    }

    /** Lo que el cliente NO ve de la entrega: el envio gratuito y las opciones/tarifas internas del domiciliario. */
    protected function paraCliente(array $datos): array
    {
        if (isset($datos['entrega']['metodos_disponibles']) && is_array($datos['entrega']['metodos_disponibles'])) {
            $datos['entrega']['metodos_disponibles'] = array_values(array_filter(
                $datos['entrega']['metodos_disponibles'],
                fn ($m) => ! preg_match(self::PATRON_ENVIO_GRATIS, (string) ($m['codigo'] ?? '').' '.(string) ($m['titulo'] ?? '')),
            ));
        }

        if (isset($datos['entrega']['domiciliario']) && is_array($datos['entrega']['domiciliario'])) {
            unset($datos['entrega']['domiciliario']['opciones'], $datos['entrega']['domiciliario']['sugerido_id']);
        }

        return $datos;
    }

    protected function mensajeNeutro(string $codigo, string $causa): string
    {
        $clave = $codigo === 'lote_rechazado' && $causa !== '' ? $causa : $codigo;

        return match (true) {
            str_contains($clave, 'stock') => 'No hay suficientes unidades disponibles de esta talla.',
            $clave === 'no_encontrado' => 'No encontramos ese pedido.',
            in_array($clave, ['no_es_propietario', 'borrador_inexistente', 'cliente_no_coincide'], true) => 'No encontramos esa venta.',
            in_array($clave, ['borrador_no_editable'], true) => 'Esta venta ya no se puede modificar.',
            in_array($clave, ['rentabilidad_insuficiente', 'pedido_rechazado_por_cerco', 'cliente_no_confirmable', 'propiedad_comercial_invalida', 'tipo_operacion_sin_permiso'], true) => 'No podemos confirmar este pedido en línea. Comunícate con nuestro equipo para ayudarte.',
            $clave === 'direccion_facturacion_requerida' => 'Necesitamos una dirección para tu pedido. Agrégala en la sección de entrega.',
            $clave === 'limite_direcciones' => 'Ya tienes el máximo de direcciones guardadas. Comunícate con nuestro equipo para cambiarlas.',
            in_array($clave, ['entrega_no_permitida', 'metodo_no_permitido'], true) => 'Esa opción de entrega no está disponible. Elige otra.',
            $clave === 'datos_cliente_invalidos' => 'Revisa los datos de la dirección (nombre, teléfono, dirección y ciudad) e inténtalo de nuevo.',
            $clave === 'totales_cambiaron' => 'El total de tu pedido cambió. Revisa el resumen y confirma de nuevo.',
            $clave === 'carrito_vacio' => 'Agrega al menos un producto para confirmar tu pedido.',
            default => 'No pudimos completar la operación. Si continúa, comunícate con nuestro equipo.',
        };
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
