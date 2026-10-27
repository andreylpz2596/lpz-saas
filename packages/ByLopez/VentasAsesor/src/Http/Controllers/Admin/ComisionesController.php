<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Services\Comisiones\AcuerdosComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\IncidenciasComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Webkul\User\Contracts\Admin;

/**
 * Ventas Asesor › Comisiones (Doc. 23, R3/R4). Controlador delgado: TODA autorizacion y regla vive en los servicios (que
 * vuelven a validar el permiso y el aislamiento del asesor); aqui solo se eligen la vista y los mensajes.
 *
 * Una sola pantalla con pestañas (`?vista=`): resumen del mes | acuerdos | liquidaciones | ajustes; `?pedido=ID` abre el
 * detalle de un pedido. Asi una sola ruta GET cubre las vistas (regla 3 del ACL).
 */
class ComisionesController extends Controller
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected ConsultaComisionesService $consulta,
        protected AcuerdosComisionService $acuerdos,
        protected EvaluarComisionesService $evaluar,
        protected LiquidacionesComisionService $liquidaciones,
        protected IncidenciasComisionService $incidencias,
        protected ReevaluacionComisionesService $reevaluacion,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor();
        $permisos = $this->permisos($actor);
        abort_unless($permisos['ver_todas'] || $permisos['ver_propias'], 403);

        $mes = MesComercial::valido($request->query('mes')) ? (string) $request->query('mes') : MesComercial::de(now());
        $vista = in_array($request->query('vista'), ['resumen', 'acuerdos', 'liquidaciones', 'ajustes'], true) ? $request->query('vista') : 'resumen';
        $asesor = ctype_digit((string) $request->query('asesor')) ? (int) $request->query('asesor') : null;
        $propio = $this->consulta->alcance($actor);

        $datos = ['vista' => $vista, 'mes' => $mes, 'asesor' => $propio ?? $asesor, 'permisos' => $permisos, 'asesores' => $propio === null ? $this->consulta->asesores() : []];

        if (ctype_digit((string) $request->query('pedido'))) {
            $detalle = $this->consulta->detallePedido($actor, (int) $request->query('pedido'));
            abort_if($detalle === null, 404);

            return view('ventasasesor::admin.comisiones.detalle', $datos + ['detalle' => $detalle]);
        }

        if ($vista === 'acuerdos') {
            abort_unless($permisos['configurar'], 403);

            return view('ventasasesor::admin.comisiones.acuerdos', $datos + ['panel' => $this->acuerdos->panel()]);
        }

        if ($vista === 'liquidaciones' || $vista === 'ajustes') {
            $cuentaDe = $propio ?? $asesor;

            return view('ventasasesor::admin.comisiones.liquidaciones', $datos + [
                'cuenta' => $cuentaDe === null ? null : $this->consulta->cuenta($actor, $cuentaDe),
                'clave' => (string) Str::uuid(),
            ]);
        }

        $estado = in_array($request->query('estado'), ['estimada', 'habilitada', 'anulada'], true) ? $request->query('estado') : null;

        return view('ventasasesor::admin.comisiones.index', $datos + [
            'bandeja' => $permisos['calcular'] ? ['pendientes' => $this->reevaluacion->pendientes(), 'agotadas' => $this->reevaluacion->agotadas()] : null,
            'estado' => $estado,
            'resumen' => $this->consulta->resumenMes($actor, $mes, $asesor),
            'pedidos' => $this->consulta->pedidosDelMes($actor, ['mes' => $mes, 'asesor' => $asesor, 'estado' => $estado]),
            've_costos' => $this->consulta->veCostos($actor),
        ]);
    }

    public function evaluar(Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($request) {
            $pedido = ctype_digit((string) $request->input('pedido')) ? (int) $request->input('pedido') : null;
            $r = $this->evaluar->evaluarComoActor($this->actor(), $pedido);

            // Recuperacion manual completa: tambien vacia la bandeja automatica, incluidas las que agotaron reintentos.
            if ($pedido === null) {
                $this->reevaluacion->reintentarAgotadas();
                $this->reevaluacion->procesar(null, 500);
            }

            return back()->with('success', trans('ventasasesor::comisiones.flash.evaluado', [
                'pedidos' => count($r), 'habilitadas' => array_sum(array_column($r, 'habilitadas')), 'ajustes' => array_sum(array_column($r, 'ajustes')),
            ]));
        });
    }

    public function guardarAcuerdo(int $asesor, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($asesor, $request) {
            $this->acuerdos->programar($this->actor(), $asesor, [
                'porcentaje' => $request->input('porcentaje'),
                'umbral' => $request->input('umbral'),
                'vigente_desde' => $this->fechaHora($request->input('vigente_desde')),
                'motivo' => (string) $request->input('motivo', ''),
            ]);

            return back()->with('success', trans('ventasasesor::comisiones.flash.acuerdo'));
        });
    }

    public function crearLiquidacion(Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($request) {
            $liq = $this->liquidaciones->crearBorrador(
                $this->actor(),
                (int) $request->input('asesor_id'),
                (string) $request->input('periodo_desde'),
                (string) $request->input('periodo_hasta'),
                array_map('intval', (array) $request->input('ajustes', [])),
                $request->input('observacion'),
                (string) $request->input('clave', ''),
            );

            return $this->aLiquidaciones($liq)->with('success', trans('ventasasesor::comisiones.flash.borrador', ['numero' => $liq->numero()]));
        });
    }

    public function aprobarLiquidacion(int $liquidacion): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->aLiquidaciones($l = $this->liquidaciones->aprobar($this->actor(), $liquidacion))
            ->with('success', trans('ventasasesor::comisiones.flash.aprobada', ['numero' => $l->numero()])));
    }

    public function anularLiquidacion(int $liquidacion, Request $request): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->aLiquidaciones($l = $this->liquidaciones->anular($this->actor(), $liquidacion, (string) $request->input('motivo', '')))
            ->with('success', trans('ventasasesor::comisiones.flash.anulada', ['numero' => $l->numero()])));
    }

    public function registrarAbono(int $liquidacion, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($liquidacion, $request) {
            $abono = $this->liquidaciones->registrarAbono(
                $this->actor(),
                $liquidacion,
                $request->only(['monto', 'fecha_pago', 'medio', 'referencia', 'observacion']),
                $request->file('comprobante'),
                (string) $request->input('clave', ''),
            );

            return $this->aLiquidaciones(ComisionLiquidacion::query()->findOrFail($abono->liquidacion_id))->with('success', trans('ventasasesor::comisiones.flash.abono'));
        });
    }

    public function revertirAbono(int $abono, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($abono, $request) {
            $r = $this->liquidaciones->revertirAbono($this->actor(), $abono, (string) $request->input('motivo', ''), (string) $request->input('clave', ''));

            return $this->aLiquidaciones(ComisionLiquidacion::query()->findOrFail($r->liquidacion_id))->with('success', trans('ventasasesor::comisiones.flash.reverso'));
        });
    }

    public function comprobante(int $abono): Response
    {
        try {
            return $this->liquidaciones->comprobante($this->actor(), $abono);
        } catch (Invalida) {
            abort(404);
        }
    }

    public function crearAjuste(Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($request) {
            $this->liquidaciones->crearAjuste(
                $this->actor(),
                (int) $request->input('asesor_id'),
                $request->input('monto'),
                (string) $request->input('motivo', ''),
                ctype_digit((string) $request->input('linea_id')) ? (int) $request->input('linea_id') : null,
                (string) $request->input('clave', ''),
            );

            return back()->with('success', trans('ventasasesor::comisiones.flash.ajuste'));
        });
    }

    public function resolverAjuste(int $ajuste, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($ajuste, $request) {
            $this->liquidaciones->resolverAjuste($this->actor(), $ajuste, $request->input('decision') === 'aprobar', (string) $request->input('motivo', ''));

            return back()->with('success', trans('ventasasesor::comisiones.flash.ajuste-resuelto'));
        });
    }

    public function resolverIncidencia(int $incidencia, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($incidencia, $request) {
            $this->incidencias->resolverMantener($this->actor(), $incidencia, (string) $request->input('motivo', ''));

            return back()->with('success', trans('ventasasesor::comisiones.flash.incidencia-resuelta'));
        });
    }

    /** @return array<string, bool> */
    protected function permisos(?Admin $actor): array
    {
        $p = fn (string $k) => $this->autorizacion->tienePermiso($actor, $k);

        return [
            'ver_todas' => $p(Autorizacion::PERMISO_COMISIONES_VER_TODAS),
            'ver_propias' => $p(Autorizacion::PERMISO_COMISIONES_VER_PROPIAS),
            'configurar' => $p(Autorizacion::PERMISO_COMISIONES_CONFIGURAR),
            'calcular' => $p(Autorizacion::PERMISO_COMISIONES_CALCULAR),
            'aprobar' => $p(Autorizacion::PERMISO_COMISIONES_APROBAR),
            'pagar' => $p(Autorizacion::PERMISO_COMISIONES_PAGAR),
            'ajustar' => $p(Autorizacion::PERMISO_COMISIONES_AJUSTAR),
            'revertir' => $p(Autorizacion::PERMISO_COMISIONES_REVERTIR),
            // La ruta del comprobante exige esta clave (ACL); el servicio ademas limita al asesor a sus abonos.
            'comprobantes' => $p('ventas_asesor.comisiones.comprobantes'),
        ];
    }

    protected function aLiquidaciones(ComisionLiquidacion $liq): RedirectResponse
    {
        return redirect()->route('admin.ventas_asesor.comisiones.index', ['vista' => 'liquidaciones', 'asesor' => $liq->asesor_admin_id]);
    }

    protected function fechaHora(mixed $valor): ?string
    {
        $v = trim((string) $valor);

        // <input type="datetime-local"> llega como 2026-11-01T00:00, hora de Bogota.
        return $v === '' ? null : CarbonImmutable::parse($v, MesComercial::zona())->setTimezone((string) config('app.timezone'))->toDateTimeString();
    }

    protected function ejecutar(callable $accion): RedirectResponse
    {
        try {
            return $accion();
        } catch (Invalida $e) {
            abort_if($e->codigo === Invalida::SIN_PERMISO, 403);

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
