<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Services\Rentabilidad\ConfiguracionRentabilidadService;
use ByLopez\VentasAsesor\Services\Rentabilidad\DiagnosticoRentabilidadService;
use ByLopez\VentasAsesor\Services\Rentabilidad\PanelMensualService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\User\Contracts\Admin;

/**
 * Ventas Asesor › Rentabilidad (Doc. 23, R1/R4). Pestañas (`?vista=`): diagnostico (precios por grupo frente al minimo y
 * configuracion faltante) | configuracion (politicas, costos directos, estado del costo) | panel (mensual). Solo
 * administracion: el rol ASESOR VENTAS no recibe estas claves (nunca ve costos ni margenes).
 */
class RentabilidadController extends Controller
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected ConfiguracionRentabilidadService $configuracion,
        protected DiagnosticoRentabilidadService $diagnostico,
        protected PanelMensualService $panel,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor();
        $permisos = [
            'ver' => $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_RENTABILIDAD_VER),
            'configurar' => $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_RENTABILIDAD_CONFIGURAR),
            'panel' => $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_KPIS_VER_TODOS),
        ];
        abort_unless(in_array(true, $permisos, true), 403);

        $vista = in_array($request->query('vista'), ['diagnostico', 'configuracion', 'panel'], true) ? $request->query('vista') : ($permisos['ver'] ? 'diagnostico' : ($permisos['configurar'] ? 'configuracion' : 'panel'));
        $base = ['vista' => $vista, 'permisos' => $permisos, 'familias' => $this->configuracion->familias()];

        return match ($vista) {
            'configuracion' => $this->exigir($permisos['configurar'], fn () => view('ventasasesor::admin.rentabilidad.configuracion', $base + ['panel' => $this->configuracion->panel()])),
            'panel' => $this->exigir($permisos['panel'], function () use ($request, $base) {
                $mes = MesComercial::valido($request->query('mes')) ? (string) $request->query('mes') : MesComercial::de(now());

                return view('ventasasesor::admin.rentabilidad.panel', $base + ['mes' => $mes, 'datos' => $this->panel->mes($mes)]);
            }),
            default => $this->exigir($permisos['ver'], function () use ($request, $base) {
                $filtros = [
                    'familia' => ctype_digit((string) $request->query('familia')) ? (int) $request->query('familia') : null,
                    'grupo' => ctype_digit((string) $request->query('grupo')) ? (int) $request->query('grupo') : null,
                    'solo_incumple' => $request->query('solo') === '1',
                ];

                return view('ventasasesor::admin.rentabilidad.diagnostico', $base + [
                    'filtros' => $filtros,
                    'precios' => $this->diagnostico->preciosPorGrupo($filtros),
                    'faltantes' => $this->diagnostico->faltantes(),
                    'grupos' => DB::table('customer_groups')->where('code', '!=', 'guest')->orderBy('name')->pluck('name', 'id')->all(),
                ]);
            }),
        };
    }

    public function guardarPolitica(Request $request): RedirectResponse
    {
        return $this->guardar(fn () => $this->configuracion->programarPolitica($this->actor(), $this->datos($request, ['familia_id', 'tipo_tela_id', 'ganancia_minima', 'margen_minimo', 'redondeo', 'motivo'])));
    }

    public function guardarCostoDirecto(Request $request): RedirectResponse
    {
        return $this->guardar(fn () => $this->configuracion->programarCostoDirecto($this->actor(), $this->datos($request, [
            'codigo', 'concepto', 'nombre', 'tipo_calculo', 'base_porcentaje', 'valor', 'familia_id', 'metodo_envio', 'metodo_pago', 'fuente_real', 'motivo',
        ])));
    }

    public function guardarEstadoCosto(Request $request): RedirectResponse
    {
        return $this->guardar(fn () => $this->configuracion->programarEstadoCosto($this->actor(), $this->datos($request, ['familia_id', 'estado', 'revisar_hasta', 'motivo'])));
    }

    /** @param  list<string>  $campos */
    protected function datos(Request $request, array $campos): array
    {
        $v = trim((string) $request->input('vigente_desde', ''));

        return $request->only($campos) + ['vigente_desde' => $v === '' ? null : CarbonImmutable::parse($v, MesComercial::zona())->setTimezone((string) config('app.timezone'))->toDateTimeString()];
    }

    protected function guardar(callable $accion): RedirectResponse
    {
        try {
            $accion();

            return redirect()->route('admin.ventas_asesor.rentabilidad.index', ['vista' => 'configuracion'])->with('success', trans('ventasasesor::comisiones.flash.configuracion'));
        } catch (Invalida $e) {
            abort_if($e->codigo === Invalida::SIN_PERMISO, 403);

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function exigir(bool $permitido, callable $vista): View
    {
        abort_unless($permitido, 403);

        return $vista();
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }
}
