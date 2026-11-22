<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use ByLopez\Logistica\Services\Tarifas\ZonasLocalesService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Logistica › Zonas locales y tarifas: destinos LOCALES por bodega de origen y tarifas sugeridas de los operadores, en una sola
 * pantalla. Lectura con `logistica.zonas_locales`; cualquier cambio con `logistica.transportadoras.manage` (se vuelve a comprobar aqui
 * y en el servicio: ocultar un boton no autoriza nada).
 */
class ZonasLocalesController extends Controller
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ZonasLocalesService $zonas,
        protected ZonaLocalDeOrigen $locales,
    ) {}

    public function index(): View
    {
        $admin = auth()->guard('admin')->user();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_ZONAS_LOCALES), 401);

        return view('logistica::admin.zonas-locales.index', [
            'estado' => $this->zonas->estado(),
            'puedeGestionar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR),
        ]);
    }

    public function estado(): JsonResponse
    {
        $this->exigirLectura();

        return response()->json($this->zonas->estado());
    }

    public function municipios(Request $request): JsonResponse
    {
        $this->exigirLectura();
        $texto = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['resultados' => $this->zonas->buscarMunicipios($texto)]);
    }

    public function agregarCiudad(int $origen, Request $request): JsonResponse
    {
        return $this->mutar(fn ($admin) => $this->zonas->agregarCiudad($admin, $origen, (string) $request->input('municipio_codigo', '')), 'ciudad-agregada');
    }

    public function quitarCiudad(int $origen, int $zona): JsonResponse
    {
        return $this->mutar(fn ($admin) => $this->zonas->quitarCiudad($admin, $origen, $zona), 'ciudad-quitada');
    }

    public function crearTarifa(int $origen, Request $request): JsonResponse
    {
        return $this->mutar(fn ($admin) => $this->zonas->crearTarifa($admin, $origen, $request->only(['operador_id', 'zona_id', 'valor', 'vigente_desde'])), 'tarifa-creada');
    }

    /** Compatibilidad: el formulario de casillas anterior (reemplaza las zonas de la bodega). */
    public function guardar(int $origen, Request $request): RedirectResponse
    {
        $admin = auth()->guard('admin')->user();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR), 403);
        abort_unless(DB::table('inventory_sources')->where('id', $origen)->exists(), 404);

        $this->locales->asignar($origen, (array) $request->input('zonas', []));

        return redirect()->route('admin.logistica.zonas_locales.index')->with('success', trans('logistica::app.zonas_locales.guardado'));
    }

    private function exigirLectura(): void
    {
        abort_unless($this->autorizacion->tienePermiso(auth()->guard('admin')->user(), AutorizacionLogistica::PERMISO_ZONAS_LOCALES), 401);
    }

    /** Ejecuta un cambio y devuelve el estado fresco; los rechazos del dominio son 422 (permiso: 403) con su texto. */
    private function mutar(Closure $accion, string $mensaje): JsonResponse
    {
        $admin = auth()->guard('admin')->user();

        try {
            $accion($admin);
        } catch (Invalida $e) {
            abort_if($e->codigo === Invalida::SIN_PERMISO, 403);

            return response()->json(['message' => trans('logistica::app.errores.'.$e->codigo, ['codigo' => (string) ($e->detalles['codigo'] ?? '')]), 'codigo' => $e->codigo], 422);
        }

        return response()->json(['message' => trans('logistica::app.zonas_locales.'.$mensaje), 'estado' => $this->zonas->estado()]);
    }
}
