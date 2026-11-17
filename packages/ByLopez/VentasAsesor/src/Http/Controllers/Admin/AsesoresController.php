<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\AsesorInvalidoException;
use ByLopez\VentasAsesor\Services\Asesores\ConsultaAsesoresService;
use ByLopez\VentasAsesor\Services\Asesores\GuardarAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\AvanceAsesorService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ventas Asesor › Asesores (ASESOR-01). Una sola ruta GET como `pedidos`: sin parametros = listado (view_all);
 * `?asesor=ID` = ficha. Con `view_own` siempre se muestra la ficha propia (nunca un listado). Controlador delgado:
 * lectura en ConsultaAsesoresService y escritura en GuardarAsesorService.
 */
class AsesoresController extends Controller
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected ConsultaAsesoresService $consulta,
        protected GuardarAsesorService $guardar,
        protected AvanceAsesorService $avance,
    ) {}

    public function index(Request $request): View
    {
        $admin = auth()->guard('admin')->user();

        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO), 401);

        $alcance = $this->consulta->alcance($admin);

        abort_if($alcance === AlcanceVisibilidad::NINGUNO, 403);

        $puedeGestionar = $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_ASESORES_GESTIONAR);

        // view_own: directo a la ficha propia; sin perfil, el aviso (falla cerrado, sin listado).
        if ($alcance === AlcanceVisibilidad::PROPIOS) {
            $propio = $this->consulta->idDelPerfilDe($admin);

            return $propio === null
                ? view('ventasasesor::admin.asesores.sin-perfil')
                : view('ventasasesor::admin.asesores.detalle', $this->consulta->detalle($admin, $propio) + ['puedeGestionar' => false, 'verTodos' => false]);
        }

        if ($request->filled('asesor')) {
            $detalle = $this->consulta->detalle($admin, (int) $request->input('asesor'));

            abort_if($detalle === null, 404);

            return view('ventasasesor::admin.asesores.detalle', $detalle + ['puedeGestionar' => $puedeGestionar, 'verTodos' => true]);
        }

        $filtros = [
            'q' => ($q = trim((string) $request->input('q', ''))) === '' ? null : mb_substr($q, 0, 100),
            'estado' => in_array($request->input('estado'), ['activo', 'inactivo'], true) ? $request->input('estado') : null,
        ];

        $asesores = $this->consulta->listar($filtros, (int) $request->input('page', 1))->withQueryString();

        return view('ventasasesor::admin.asesores.index', [
            'asesores' => $asesores,
            'comparativo' => $this->comparativo($admin, $asesores->getCollection()->pluck('admin_id')->map(fn ($id) => (int) $id)->all()),
            'filtros' => $filtros,
            'indicadores' => $this->consulta->indicadores(),
            'elegibles' => $puedeGestionar ? $this->consulta->usuariosElegibles() : [],
            'puedeGestionar' => $puedeGestionar,
        ]);
    }

    /**
     * Avance del mes comparable por asesora. Es dato de COMISIONES: solo con `comisiones.view_all` (ver asesores no basta), y calculado
     * por el servidor con las mismas definiciones que la tarjeta de cada asesora.
     *
     * @param  list<int>  $adminIds
     * @return ?list<array<string, mixed>>
     */
    private function comparativo(?\Webkul\User\Contracts\Admin $admin, array $adminIds): ?array
    {
        if (! $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_COMISIONES_VER_TODAS)) {
            return null;
        }

        // Cada asesora en SU período en curso (día de ingreso): el comparativo no fuerza un mes común.
        return $this->avance->comparativo($adminIds);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($request) {
            $asesor = $this->guardar->crear(auth()->guard('admin')->user(), $request->only(['admin_id', 'nombre', 'telefono']) + ['activo' => $request->boolean('activo', true)]);

            return redirect()->route('admin.ventas_asesor.asesores.index', ['asesor' => $asesor->id])
                ->with('success', trans('ventasasesor::app.asesores.creado'));
        });
    }

    public function update(int $asesor, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($asesor, $request) {
            $campos = $request->has('fecha_ingreso') ? ['nombre', 'telefono', 'fecha_ingreso'] : ['nombre', 'telefono'];
            $this->guardar->actualizar(auth()->guard('admin')->user(), $asesor, $request->only($campos));
            $ciclo = $this->guardar->resultadoCiclo;
            $mensaje = trans('ventasasesor::app.asesores.actualizado');

            if ($ciclo !== null && $ciclo['modo'] === \ByLopez\VentasAsesor\Services\Comisiones\CicloComisionService::TRANSICION && $ciclo['desde'] !== null) {
                $mensaje .= ' '.trans('ventasasesor::app.asesores.periodo-transicion', ['desde' => $ciclo['desde'], 'periodo' => $ciclo['periodo_actual']['etiqueta']]);
            } elseif ($ciclo !== null && $ciclo['modo'] !== \ByLopez\VentasAsesor\Services\Comisiones\CicloComisionService::SIN_CAMBIO) {
                $mensaje .= ' '.trans('ventasasesor::app.asesores.periodo-aplicado', ['periodo' => $ciclo['periodo_actual']['etiqueta']]);
            }

            return redirect()->route('admin.ventas_asesor.asesores.index', ['asesor' => $asesor])->with('success', $mensaje);
        });
    }

    public function estado(int $asesor, Request $request): RedirectResponse
    {
        return $this->ejecutar(function () use ($asesor, $request) {
            $resultado = $this->guardar->cambiarEstado(auth()->guard('admin')->user(), $asesor, $request->boolean('activo'));

            return redirect()->route('admin.ventas_asesor.asesores.index', ['asesor' => $asesor])
                ->with('success', trans('ventasasesor::app.asesores.'.($resultado->activo ? 'activado' : 'inactivado')));
        });
    }

    private function ejecutar(callable $accion): RedirectResponse
    {
        try {
            return $accion();
        } catch (AsesorInvalidoException $e) {
            abort_if($e->codigo === AsesorInvalidoException::SIN_PERMISO, 403);
            abort_if($e->codigo === AsesorInvalidoException::INEXISTENTE, 404);

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
