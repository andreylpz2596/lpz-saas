<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Http\Controllers\Concerns\ArmaSolicitudEvento;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Stock\ConsultaDefectuosasService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\FuentePanel;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Webkul\User\Contracts\Admin;

/**
 * Logistica › Entregas (LOG-01). Controlador delgado: lectura en ConsultaEntregaService; registro, retorno y anulacion
 * en sus servicios, que validan permiso, idempotencia y cantidades. La misma ruta GET es la bandeja y, con
 * `?envio=ID`, el detalle del envio (una sola clave ACL de lectura con ruta GET sin parametros).
 */
class EntregasController extends Controller
{
    use ArmaSolicitudEvento;

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaEntregaService $consulta,
        protected TransportadorasService $transportadoras,
        protected FuentePanel $fuentes,
        protected ConsultaDefectuosasService $defectuosas,
    ) {}

    public function index(Request $request): View
    {
        $admin = $this->actor();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_ENTREGAS), 401);

        $permisos = [
            'registrar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_REGISTRAR),
            'anular' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_ANULAR),
            'validar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_VALIDAR),
        ];

        // C5: inventario NO VENDIBLE. Misma ruta y misma clave ACL de lectura que la bandeja (pestana «Defectuosas»).
        if ($request->query('vista') === 'defectuosas') {
            $fecha = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? (string) $request->query($k) : null;
            $filtrosDefectuosas = [
                'desde' => $fecha('desde'), 'hasta' => $fecha('hasta'),
                'motivo' => $request->query('motivo') ? mb_substr((string) $request->query('motivo'), 0, 40) : null,
                'origen' => $request->query('origen') ? mb_substr((string) $request->query('origen'), 0, 40) : null,
                'estado' => in_array($request->query('estado'), ['pendiente_revision', 'recuperada', 'perdida'], true) ? $request->query('estado') : null,
            ];

            return view('logistica::admin.entregas.defectuosas', [
                'unidades' => $this->defectuosas->listar($filtrosDefectuosas, (int) $request->query('page', 1))->withQueryString(),
                'filtros' => $filtrosDefectuosas,
                'resumen' => $this->defectuosas->resumen(),
                'catalogos' => $this->defectuosas->catalogos(),
            ]);
        }

        if (ctype_digit((string) $request->query('envio'))) {
            $detalle = $this->consulta->envio((int) $request->query('envio'));
            abort_if($detalle === null, 404);

            return view('logistica::admin.entregas.envio', $detalle + [
                'permisos' => $permisos,
                'transportadora' => $this->transportadoras->nombreDe($detalle['envio']->carrier_code),
            ]);
        }

        $filtros = [
            'q' => ($q = trim((string) $request->query('q', ''))) === '' ? null : mb_substr($q, 0, 100),
            'estado' => in_array($request->query('estado'), ['pendientes', 'sin_entrega', 'por_validar', 'entregados', 'retornados', 'completos', 'todos'], true) ? $request->query('estado') : 'pendientes',
            'pedido' => ctype_digit((string) $request->query('pedido')) ? (int) $request->query('pedido') : null,
        ];

        return view('logistica::admin.entregas.index', [
            'envios' => $this->consulta->listar($filtros, (int) $request->query('page', 1))->withQueryString(),
            'filtros' => $filtros,
            'indicadores' => $this->consulta->indicadores(),
            'nombres' => $this->transportadoras->todas()->pluck('nombre', 'codigo'),
            'permisos' => $permisos,
        ]);
    }

    public function crearEntrega(int $envio): View
    {
        return $this->formulario($envio, 'entrega');
    }

    public function crearRetorno(int $envio): View
    {
        return $this->formulario($envio, 'retorno');
    }

    public function guardarEntrega(int $envio, Request $request, RegistrarEntregaService $servicio): RedirectResponse
    {
        return $this->ejecutar(function () use ($envio, $request, $servicio) {
            $admin = $this->actor();
            $detalle = $this->consulta->envio($envio) ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);
            $codigo = $detalle['envio']->carrier_code ?: $request->input('carrier_code');

            $r = $servicio->registrar($admin, $this->solicitudEvento($envio, $request,
                $this->fuentes->paraEntrega($admin, $codigo, $detalle['envio']->shipping_method)));

            return $this->alDetalle($envio, $r->yaExistia ? 'entrega-ya-registrada' : 'entrega-registrada');
        });
    }

    public function guardarRetorno(int $envio, Request $request, RegistrarRetornoTransportadoraService $servicio): RedirectResponse
    {
        return $this->ejecutar(function () use ($envio, $request, $servicio) {
            $admin = $this->actor();
            $r = $servicio->registrar($admin, $this->solicitudEvento($envio, $request, $this->fuentes->paraRetorno($admin)));

            return $this->alDetalle($envio, $r->yaExistia ? 'retorno-ya-registrado' : 'retorno-registrado');
        });
    }

    public function anularEntrega(int $entrega, Request $request, AnularEventoLogisticoService $servicio): RedirectResponse
    {
        return $this->ejecutar(function () use ($entrega, $request, $servicio) {
            $e = $servicio->anularEntrega($this->actor(), $entrega, $request->input('motivo'));

            return $this->alDetalle($e->shipment_id, 'entrega-anulada');
        });
    }

    public function anularRetorno(int $retorno, Request $request, AnularEventoLogisticoService $servicio): RedirectResponse
    {
        return $this->ejecutar(function () use ($retorno, $request, $servicio) {
            $r = $servicio->anularRetorno($this->actor(), $retorno, $request->input('motivo'));

            return $this->alDetalle($r->shipment_id, 'retorno-anulado');
        });
    }

    private function formulario(int $envio, string $modo): View
    {
        abort_unless($this->autorizacion->tienePermiso($this->actor(), AutorizacionLogistica::PERMISO_REGISTRAR), 401);

        $detalle = $this->consulta->envio($envio);
        abort_if($detalle === null, 404);

        return view('logistica::admin.entregas.formulario', $detalle + [
            'modo' => $modo,
            'transportadora' => $this->transportadoras->nombreDe($detalle['envio']->carrier_code),
            'transportadoras' => $this->transportadoras->activas(),
            'idempotencyKey' => old('idempotency_key', $modo.'-'.Str::uuid()),
        ]);
    }

    private function ejecutar(Closure $accion): RedirectResponse
    {
        try {
            return $accion();
        } catch (Invalida $e) {
            abort_if($e->codigo === Invalida::SIN_PERMISO, 403);

            $mensaje = trans('logistica::app.errores.'.$e->codigo, $e->detalles + ['pendientes' => '', 'solicitadas' => '']);

            return in_array($e->codigo, [Invalida::ENVIO_INEXISTENTE, Invalida::REGISTRO_INEXISTENTE], true)
                ? redirect()->route('admin.logistica.entregas.index')->with('error', $mensaje)
                : redirect()->back()->withInput()->with('error', $mensaje);
        }
    }

    private function alDetalle(int $envio, string $mensaje): RedirectResponse
    {
        return redirect()->route('admin.logistica.entregas.index', ['envio' => $envio])
            ->with('success', trans('logistica::app.entregas.flash.'.$mensaje));
    }

    private function actor(): ?Admin
    {
        return auth()->guard('admin')->user();
    }
}
