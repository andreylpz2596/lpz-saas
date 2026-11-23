<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AutoservicioInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AplicarTallasNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ColoresNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarConTallasAutoservicioRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\MatricesNuevaVentaRequest;
use ByLopez\VentasAsesor\Services\Autoservicio\ClienteActualService;
use ByLopez\VentasAsesor\Services\Autoservicio\PedidosClienteService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Support\Autoservicio\LimiteInventarioCliente;
use ByLopez\VentasAsesor\Support\Http\RespuestaErrorNuevaVenta as Respuesta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
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
        $this->clientes->de($this->actor());

        return view('ventasasesor::admin.autoservicio.pedidos');
    }

    public function pedidosData(Request $request): JsonResponse
    {
        return $this->responder(fn () => $this->pedidos->listar($this->clientes->de($this->actor()), max(1, (int) $request->query('pagina', 1))));
    }

    public function pedidoDetalle(int $pedido): JsonResponse
    {
        return $this->responder(function () use ($pedido) {
            $detalle = $this->pedidos->detalle($this->clientes->de($this->actor()), $pedido);

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
    public function preparar(int $borrador): JsonResponse
    {
        return $this->responder(function () use ($borrador) {
            $this->propio($borrador);

            return $this->aplicarPorDefecto($borrador)->toArray();
        });
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

            // Solo lo que el cliente necesita: nada de atribucion, factura interna ni datos de pago.
            return [
                'order_id' => $c->orderId,
                'numero' => $c->incrementId,
                'total' => $c->total,
                'moneda' => $c->moneda,
                'entrega' => 'Recogida en tienda',
                'pago' => 'Transferencia bancaria (pendiente de confirmar)',
                'estado_pago' => $c->estadoPago,
                'ya_existia' => $c->yaExistia,
            ];
        });
    }

    protected function aplicarPorDefecto(int $borrador)
    {
        $actor = $this->actor();
        $metodoEntrega = (string) config('ventasasesor.autoservicio.metodo_entrega', 'pickup');
        $metodoPago = (string) config('ventasasesor.autoservicio.metodo_pago', 'moneytransfer');

        $estado = $this->nuevaVenta->estado($borrador);

        if ($estado->entrega->metodoSeleccionado?->codigo !== $metodoEntrega || ! $estado->entrega->entregaCompleta) {
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

        $datos = LimiteInventarioCliente::sanear($respuesta->getData(true));

        if ($respuesta->getStatusCode() >= 400) {
            $datos['mensaje'] = $this->mensajeNeutro((string) ($datos['codigo'] ?? ''), (string) ($datos['causa'] ?? ''));
            unset($datos['variante_id']);
        }

        return response()->json($datos, $respuesta->getStatusCode());
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
            $clave === 'direccion_facturacion_requerida' => 'Necesitamos una dirección registrada para tu pedido. Comunícate con nuestro equipo para ayudarte.',
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
