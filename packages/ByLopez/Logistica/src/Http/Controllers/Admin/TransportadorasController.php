<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Enums\ModoGuia;
use ByLopez\Logistica\Enums\TipoTransportadora;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/** Logistica › Transportadoras (LOG-D-04). Lectura con `logistica.transportadoras`; alta y activacion con `.manage`. */
class TransportadorasController extends Controller
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected TransportadorasService $transportadoras,
        protected ZonasYTarifasService $tarifas,
    ) {}

    public function index(): View
    {
        $admin = auth()->guard('admin')->user();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS), 401);

        return view('logistica::admin.transportadoras.index', [
            'transportadoras' => $this->transportadoras->todas(),
            'historicos' => $this->transportadoras->titulosHistoricosSinCodigo(),
            'tipos' => TipoTransportadora::cases(),
            'puedeGestionar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->transportadoras->crear(auth()->guard('admin')->user(), $request->only(['codigo', 'nombre', 'tipo', 'liquida_cuenta_corriente', 'genera_guia_interna', 'modo_guia'])), 'creada');
    }

    public function estado(int $transportadora, Request $request): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->transportadoras->cambiarActivo(auth()->guard('admin')->user(), $transportadora, $request->boolean('activo')), 'actualizada');
    }

    /**
     * Capacidades independientes del tipo (F1) y modo de guia (externa | interna, excluyente). Solo afectan despachos nuevos:
     * cada guia conserva su origen. Sin `modo_guia` rige el booleano `genera_guia_interna` (compatibilidad).
     */
    public function capacidades(int $transportadora, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($transportadora, $request) {
            $modo = $request->has('modo_guia')
                ? (ModoGuia::tryFrom((string) $request->input('modo_guia')) ?? throw new Invalida(Invalida::DATOS_INVALIDOS))
                : ModoGuia::deCapacidad($request->boolean('genera_guia_interna'));

            $this->transportadoras->cambiarCapacidades(
                auth()->guard('admin')->user(), $transportadora, $request->boolean('liquida_cuenta_corriente'), $modo->generaGuiaInterna(),
            );
        }, 'actualizada');
    }

    /** Nueva tarifa sugerida (nunca se edita una existente). */
    public function tarifa(Request $request): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->tarifas->crearTarifa(auth()->guard('admin')->user(), $request->only(['transportadora_id', 'zona_id', 'valor', 'vigente_desde'])), 'tarifa-creada');
    }

    private function ejecutar(Closure $accion, string $mensaje): RedirectResponse
    {
        try {
            $accion();
        } catch (Invalida $e) {
            abort_if($e->codigo === Invalida::SIN_PERMISO, 403);

            return redirect()->route('admin.logistica.transportadoras.index')->withInput()
                ->with('error', trans('logistica::app.errores.'.$e->codigo, ['codigo' => (string) ($e->detalles['codigo'] ?? '')]));
        }

        return redirect()->route('admin.logistica.transportadoras.index')->with('success', trans('logistica::app.transportadoras.'.$mensaje));
    }
}
