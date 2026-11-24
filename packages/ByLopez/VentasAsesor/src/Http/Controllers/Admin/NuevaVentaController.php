<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AgregarDireccionNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\AplicarTallasNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\CambiarGrupoNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\BuscarClienteNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ColoresNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\ConfirmarNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\CrearClienteNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\EntregaNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarConClienteNuevoNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarConTallasNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\IniciarNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\MatricesNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\MatrizNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\PagoNuevaVentaRequest;
use ByLopez\VentasAsesor\Http\Requests\NuevaVenta\RestaurarGrupoNuevaVentaRequest;
use ByLopez\VentasAsesor\Services\Asesores\AsesorActualService;
use ByLopez\VentasAsesor\Services\Clientes\BuscarClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Comisiones\TipoOperacionPedidoService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\Dictado\ResolutorDictado;
use ByLopez\VentasAsesor\Services\Venta\GrupoVentaService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Http\RespuestaErrorNuevaVenta as Respuesta;
use ByLopez\VentasAsesor\Support\Logistica\MunicipioDesdeCatalogo;
use ByLopez\VentasAsesor\Support\Venta\VenderPara;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\CustomerAddress;
use Webkul\Customer\Models\CustomerGroup;
use Webkul\User\Contracts\Admin;

/**
 * API de "Nueva venta rapida" (03G-C): Request → autorizacion/estructura → los servicios
 * de dominio ya existentes (03B-03G-B2) → JSON. Ninguna regla de precio, stock, grupo,
 * variantes, solicitudes, Cart, envio, pago, confirmacion o atribucion vive aqui: todas
 * siguen en NuevaVentaService y los servicios que orquesta.
 *
 * Autorizacion: el permiso ACL de la ruta ('ventas_asesor.pedidos.create', Bouncer) mas
 * la del propio dominio (BorradorVentaService::PERMISO, propiedad del borrador, ventana
 * de reclamo) que cada metodo de NuevaVentaService ya exige antes de tocar nada. Un
 * borrador ajeno lanza BorradorVentaInvalidoException::NO_ES_PROPIETARIO (409), igual que
 * en el panel; esta capa no relaja ni repite esa regla.
 */
class NuevaVentaController extends Controller
{
    public function __construct(
        protected NuevaVentaService $nuevaVenta,
        protected CatalogoVentaService $catalogo,
        protected BuscarClienteComercialService $buscarCliente,
        protected CrearClienteComercialService $crearCliente,
        protected BorradorVentaService $borradores,
        protected AsesorActualService $asesorActual,
        protected GrupoVentaService $grupos,
    ) {}

    /**
     * 03G-D: la pantalla desktop. Los datos de LA venta (cliente, catalogo, matriz, estado)
     * los pide el Vue de la vista via la API 03G-C; aqui solo se pasan los grupos
     * comerciales (catalogo estatico de Bagisto, sin invitado) para el select de "crear
     * cliente nuevo", igual que hace CustomerCreateForm del core.
     */
    public function pantalla(): View
    {
        $grupos = CustomerGroup::query()
            ->where('code', '!=', 'guest')
            ->orderBy('name')
            ->get(['id', 'name']);

        // Rediseno 03G-D §23: departamentos REALES de Bagisto (country_states), no inventados.
        // Bagisto no tiene catalogo de municipios/ciudades (Schema sin tabla 'cities'); por eso
        // Ciudad sigue siendo texto libre en el formulario, decision documentada en el informe.
        // 03G-D (modal de direccion): Bagisto guarda en addresses.state el CODIGO de country_states
        // (asi lo hace su checkout), no el nombre; el select usa codigo como valor.
        $departamentos = DB::table('country_states')
            ->where('country_code', 'CO')
            ->orderBy('default_name')
            ->get(['code', 'default_name'])
            ->map(fn ($e) => ['codigo' => (string) $e->code, 'nombre' => (string) $e->default_name])
            ->all();

        // 03G-D (entrega): la opcion "recogida" usa el titulo/descripcion REALES del carrier pickup
        // (core_config de bagisto-eu/pickup), los mismos que Bagisto guarda en la tarifa.
        $recogida = [
            'titulo' => (string) (core()->getConfigData('sales.carriers.pickup.title') ?: 'Recogida en tienda'),
            'descripcion' => (string) (core()->getConfigData('sales.carriers.pickup.description') ?: ''),
        ];

        return view('ventasasesor::admin.nueva-venta.index', [
            'grupos' => $grupos, 'departamentos' => $departamentos, 'recogida' => $recogida,
            'postcodeObligatorio' => core()->isPostCodeRequired(),
            // ASESOR-01: aviso visible; la autoridad es BorradorVentaService (rechaza `asesor_sin_asociacion`).
            'asesorSinAsociacion' => ! $this->asesorActual->operable($this->actor()),
            // G-11: selector de tipo de operacion solo con permiso (el servicio vuelve a validar al confirmar).
            'puedeClasificar' => app(TipoOperacionPedidoService::class)->puedeClasificar($this->actor()),
            // GRP-01: la accion «Cambiar para esta venta» solo se ofrece con permiso (el servicio y el ACL lo vuelven a exigir).
            'puedeCambiarGrupo' => $this->grupos->puedeCambiar($this->actor()),
            // R2: «Vender para» solo con `pedidos.assign` (el servicio lo vuelve a exigir al confirmar).
            'puedeVenderPara' => app(VenderPara::class)->puedeElegir($this->actor()),
            // Doc. 33 P1: «Habilitar acceso» al crear un cliente solo con la clave de administrar accesos.
            'puedeAdministrarAcceso' => app(AutorizacionVentasAsesor::class)->tienePermiso($this->actor(), \ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService::PERMISO),
            'asesoresVenta' => app(VenderPara::class)->puedeElegir($this->actor()) ? app(VenderPara::class)->opciones() : [],
        ]);
    }

    /** 03G-D §23: borradores activos del asesor (BorradorVentaService::listarDelPropietario, sin logica nueva). */
    public function enCurso(): JsonResponse
    {
        return Respuesta::envolver(fn () => array_map(
            fn ($v) => get_object_vars($v),
            $this->borradores->listarDelPropietario((int) $this->actor()->id, soloConLineas: true),
        ));
    }

    /**
     * Rediseno 03G-D: eliminar una venta en curso = BorradorVentaService::descartar(), que
     * YA exige ser propietario y borra el Cart en cascada. Ninguna logica nueva aqui.
     */
    public function eliminar(int $borrador): JsonResponse
    {
        return Respuesta::envolver(fn () => get_object_vars($this->borradores->descartar($borrador, $this->actor())));
    }

    /**
     * Rediseno 03G-D: direcciones reales del cliente (Webkul\Customer\Models\CustomerAddress),
     * solo lectura, para precargar Entrega en vez de pedir que el asesor las vuelva a escribir.
     * Nunca se persiste nada aqui; usarla para la venta es responsabilidad de EntregaNuevaVentaRequest.
     */
    public function direccionesCliente(int $cliente): JsonResponse
    {
        // P0: solo las direcciones de un cliente con el que el actor tiene una venta propia (antes: cualquier cliente).
        return Respuesta::envolver(function () use ($cliente) {
            if (! $this->borradores->actorTrabajaConCliente($cliente, $this->actor())) {
                throw new BorradorVentaInvalidoException(BorradorVentaInvalidoException::NO_ES_PROPIETARIO);
            }

            return $this->listaDirecciones($cliente);
        });
    }

    protected function listaDirecciones(int $cliente): array
    {
        return CustomerAddress::query()
            ->where('customer_id', $cliente)
            ->orderByDesc('default_address')
            ->get(['id', 'first_name', 'last_name', 'address', 'city', 'state', 'country', 'postcode', 'phone', 'default_address'])
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'nombre' => $d->first_name,
                'apellido' => $d->last_name,
                'direccion' => $d->address,
                'ciudad' => $d->city,
                'departamento' => $d->state,
                'pais' => $d->country,
                'telefono' => $d->phone,
                'predeterminada' => (bool) $d->default_address,
                // Para «Editar»: datos tal como estan guardados; el municipio solo si coincide exacto con el catalogo DIVIPOLA.
                'codigo_postal' => $d->postcode,
                'municipio_codigo' => app(MunicipioDesdeCatalogo::class)->municipioDeDireccion($d->city, $d->state, $d->country),
            ])
            ->values()
            ->all();
    }

    /**
     * 03G-D: agrega una direccion reutilizable al cliente DEL BORRADOR (RT-04, via
     * NuevaVentaService::agregarDireccionCliente, que exige ser propietario). Devuelve la
     * nueva y la lista actualizada; usarla en la venta es la llamada normal de entrega.
     */
    public function agregarDireccion(int $borrador, AgregarDireccionNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($borrador, $request) {
            $nueva = $this->nuevaVenta->agregarDireccionCliente($borrador, $request->aDireccion(), $this->actor());

            return [
                'direccion_id' => (int) $nueva->id,
                'direcciones' => $this->listaDirecciones((int) $nueva->customer_id),
            ];
        });
    }

    /** Editar una direccion guardada del cliente del borrador (misma validacion y propiedad que agregar). */
    public function editarDireccion(int $borrador, int $direccion, AgregarDireccionNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($borrador, $direccion, $request) {
            $editada = $this->nuevaVenta->editarDireccionCliente($borrador, $direccion, $request->aDireccion(), $this->actor());

            return [
                'direccion_id' => (int) $editada->id,
                'direcciones' => $this->listaDirecciones((int) $editada->customer_id),
            ];
        });
    }

    /** A. Bootstrap: canales y accesos rapidos permitidos, sin borrador todavia. */
    public function estadoInicial(): JsonResponse
    {
        return Respuesta::envolver(fn () => [
            'canales' => array_values((array) config('ventasasesor.nueva_venta.canales', [])),
            'accesos_rapidos' => array_map(fn ($a) => $a->toArray(), $this->catalogo->accesosRapidos()),
        ]);
    }

    /** B. Buscar cliente por telefono, email o nombre (identidad fuerte: telefono). */
    public function buscarCliente(BuscarClienteNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->buscarCliente->buscar($request->aConsulta())->toArray());
    }

    /** C. Alta minima de cliente comercial (RT-04), sin iniciar venta todavia. */
    public function crearCliente(CrearClienteNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($request) {
            $cliente = $this->crearCliente->crear($request->aDatos());

            return ['customer_id' => (int) $cliente->id];
        });
    }

    /** D. Iniciar venta con un cliente ya identificado. Sin Cart hasta tener cliente valido. */
    public function iniciar(IniciarNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->iniciar(
            (int) $request->integer('customer_id'),
            $this->actor(),
            (string) $request->string('canal'),
            $request->string('referencia_externa')->trim()->value() ?: null,
        )->toArray());
    }

    /** D. Alta minima de cliente + venta nueva en una transaccion (telefono no encontrado). */
    /** P7: la venta nace con la primera prenda (idempotente por referencia); consultar productos no crea borradores. */
    public function iniciarConTallas(IniciarConTallasNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->iniciarConTallas(
            (int) $request->integer('customer_id'),
            $this->actor(),
            (string) $request->string('canal'),
            (string) $request->string('referencia'),
            (int) $request->integer('producto_id'),
            $request->cantidadesPorVariante(),
        )->toArray());
    }

    public function iniciarConClienteNuevo(IniciarConClienteNuevoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->iniciarConClienteNuevo(
            $request->aDatos(),
            $this->actor(),
            (string) $request->string('canal'),
            $request->string('referencia_externa')->trim()->value() ?: null,
        )->toArray());
    }

    /**
     * Doc. 34: pedido por voz. Interpreta el texto dictado y valida cada linea contra el catalogo (solo lectura: no toca la
     * venta). La pantalla agrega lo resuelto con «aplicar tallas», igual que la matriz.
     */
    public function interpretarDictado(Request $request): JsonResponse
    {
        abort_unless((bool) config('ventasasesor.dictado.activo', true), 404);
        $datos = $request->validate(['texto' => ['required', 'string', 'max:3000']]);

        return Respuesta::envolver(fn () => ['lineas' => app(ResolutorDictado::class)->interpretar((string) $datos['texto'])]);
    }

    /** E. Colores de un acceso rapido (linea x color), resuelto por config, nunca por id. */
    public function colores(string $clave, ColoresNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => array_map(
            fn ($c) => $c->toArray(),
            $this->catalogo->coloresDeAcceso($clave, $request->string('q')->trim()->value() ?: null, (int) $request->input('limite', 50)),
        ));
    }

    /** G. Matriz de tallas/variantes de un color (producto de primer nivel). */
    public function matriz(int $producto, MatrizNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($producto, $request) {
            $this->exigirBorradorDeLaConsulta($request->integer('borrador_id') ?: null, (int) $request->integer('customer_id'));

            return $this->catalogo->matriz(
                $producto,
                (int) $request->integer('customer_id'),
                $this->actor(),
                1,
                $request->integer('borrador_id') ?: null,
            )->toArray();
        });
    }

    /**
     * P0: si la consulta de precios trae un borrador, debe ser del actor y del mismo cliente; nunca se consulta ni se
     * precia con el borrador de otro. Sin borrador (consulta previa a la venta) no hay nada que verificar aqui.
     */
    protected function exigirBorradorDeLaConsulta(?int $borradorId, int $customerId): void
    {
        if ($borradorId !== null) {
            $this->borradores->exigirDelPropietario($borradorId, $this->actor(), $customerId);
        }
    }

    /**
     * G2. Matrices de VARIAS combinaciones linea x color, cada una independiente: las que no se pueden vender vienen en
     * `excluidas` (con motivo) y no bloquean a las demas.
     */
    public function matrices(MatricesNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($request) {
            $this->exigirBorradorDeLaConsulta($request->integer('borrador_id') ?: null, (int) $request->integer('customer_id'));

            return $this->catalogo->matrices(
                array_map('intval', (array) $request->input('productos')),
                (int) $request->integer('customer_id'),
                $this->actor(),
                $request->integer('borrador_id') ?: null,
                $request->has('solo_vendibles') ? $request->boolean('solo_vendibles') : true,
            );
        });
    }

    /** F. Estado/resumen actual de la venta (K). */
    public function estado(int $borrador): JsonResponse
    {
        // P0: el estado solo lo lee el propietario del borrador (antes: cualquier usuario con acceso a Nueva venta).
        return Respuesta::envolver(function () use ($borrador) {
            $this->borradores->exigirDelPropietario($borrador, $this->actor());

            return $this->nuevaVenta->estado($borrador, conMetodosDePago: (bool) request()->boolean('con_metodos_pago'))->toArray();
        });
    }

    /** H. Lote de cantidades por variante (M6/L6): todo o nada. */
    public function aplicarTallas(int $borrador, AplicarTallasNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->aplicarTallas(
            $borrador,
            (int) $request->integer('producto_id'),
            $request->cantidadesPorVariante(),
            $this->actor(),
        )->toArray());
    }

    /** I. Facturacion + envio + metodo en un solo viaje. */
    public function entrega(int $borrador, EntregaNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->entregaRapida(
            $borrador,
            $this->actor(),
            $request->envioComoOrigen(),
            $request->string('metodo')->trim()->value() ?: null,
            $request->facturacionComoOrigen(),
        )->toArray());
    }

    /** Cierre F1 (D1): domiciliario que entrega un domicilio local (opcional; vacio = sin elegir). */
    public function domiciliario(int $borrador): JsonResponse
    {
        $datos = request()->validate(['transportadora_id' => ['nullable', 'integer', 'min:1']]);

        return Respuesta::envolver(fn () => $this->nuevaVenta->elegirDomiciliario(
            $borrador,
            isset($datos['transportadora_id']) ? (int) $datos['transportadora_id'] : null,
            $this->actor(),
        )->toArray());
    }

    /** J. Seleccionar metodo de pago (los ofrecidos se resuelven una vez). */
    public function pago(int $borrador, PagoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->seleccionarPago(
            $borrador,
            (string) $request->string('metodo'),
            $this->actor(),
        )->toArray());
    }

    /** L. Confirmar con el total que el asesor vio en el estado. M. Resultado: Order real. */
    public function confirmar(int $borrador, ConfirmarNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->nuevaVenta->confirmar(
            $borrador,
            $this->actor(),
            (float) $request->input('total_esperado'),
            // R2: «Vender para» (solo con `pedidos.assign`; sin valor = regla automatica de siempre).
            seleccion: app(VenderPara::class)->seleccion($this->actor(), $request->input('vender_para')),
            tipoOperacion: $request->input('tipo_operacion'),
        )->toArray());
    }

    /**
     * R2: advertencia de rentabilidad del borrador (se pide tras cada cambio del estado y al cambiar «Vender para»). Al
     * asesor solo le dice QUE referencia no alcanza su minimo; el detalle numerico solo con `rentabilidad.view`. La
     * confirmacion vuelve a evaluar en el servidor.
     */
    public function rentabilidad(int $borrador, Request $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($borrador, $request) {
            $actor = $this->actor();
            $fila = BorradorVenta::query()->find($borrador);

            if ($fila === null || $fila->propietario_admin_id !== (int) $actor->id || $fila->cart_id === null) {
                return ['resultado' => 'no_aplica', 'bloqueo_activo' => false, 'referencias' => [], 'configuracion_pendiente' => false, 'costos_pedido' => false];
            }

            $vender = app(VenderPara::class);
            [$propiedad, $asesorId] = $vender->propietario($actor, $vender->seleccion($actor, $request->query('vender_para')));
            $tipo = TipoOperacionPedido::tryFrom((string) $request->query('tipo_operacion')) ?? TipoOperacionPedido::VENTA_NORMAL;
            $servicio = app(RentabilidadVentaService::class);
            $r = $servicio->deCarrito((int) $fila->cart_id, $propiedad, $asesorId, $tipo);

            $salida = $servicio->paraAsesor($r);

            if (app(AutorizacionVentasAsesor::class)->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_RENTABILIDAD_VER)) {
                $salida['detalle'] = array_map(fn ($l) => [
                    'producto' => $l['producto'], 'resultado' => $l['resultado'], 'precio' => $l['precio_neto'],
                    'minimo' => $l['precio_minimo'], 'faltante' => $l['faltante'],
                ], $r['lineas']);
            }

            return $salida;
        });
    }

    /**
     * GRP-01: grupo comercial TEMPORAL de la venta. Aplica el override, recalcula todo el carrito en una transaccion
     * (GrupoVentaService) y devuelve el estado completo ya recalculado. Nunca modifica al cliente.
     */
    public function cambiarGrupo(int $borrador, CambiarGrupoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($borrador, $request) {
            $this->grupos->cambiar($borrador, (int) $request->integer('grupo_id'), (string) $request->input('motivo', ''), $this->actor());

            return $this->nuevaVenta->estado($borrador)->toArray();
        });
    }

    /** GRP-01: vuelve al grupo habitual del cliente (motivo obligatorio) y recalcula todo el carrito. */
    public function restaurarGrupo(int $borrador, RestaurarGrupoNuevaVentaRequest $request): JsonResponse
    {
        return Respuesta::envolver(function () use ($borrador, $request) {
            $this->grupos->restaurar($borrador, (string) $request->input('motivo', ''), $this->actor());

            return $this->nuevaVenta->estado($borrador)->toArray();
        });
    }

    /** GRP-01: historial de grupo de la venta (solo lectura). */
    public function historialGrupo(int $borrador): JsonResponse
    {
        return Respuesta::envolver(fn () => ['historial' => $this->grupos->historial($borrador, $this->actor())]);
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
