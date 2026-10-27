<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Logistica › Zonas locales por origen. Solo define que zonas (F1) son LOCALES para cada bodega; las tarifas y quien entrega
 * se siguen configurando en Transportadoras. Lectura con `logistica.zonas_locales`; guardar con `logistica.transportadoras.manage`.
 */
class ZonasLocalesController extends Controller
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ZonasYTarifasService $zonas,
        protected ZonaLocalDeOrigen $locales,
    ) {}

    public function index(): View
    {
        $admin = auth()->guard('admin')->user();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_ZONAS_LOCALES), 401);

        $bodegas = DB::table('inventory_sources')->where('status', 1)->orderBy('priority')->orderBy('id')->get(['id', 'name', 'city', 'state']);

        return view('logistica::admin.zonas-locales.index', [
            'bodegas' => $bodegas,
            'zonas' => $this->zonas->zonasActivas(),
            'asignadas' => $bodegas->mapWithKeys(fn ($b) => [(int) $b->id => $this->locales->zonaIdsDeOrigen((int) $b->id)])->all(),
            'puedeGestionar' => $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR),
        ]);
    }

    public function guardar(int $origen, Request $request): RedirectResponse
    {
        $admin = auth()->guard('admin')->user();
        abort_unless($this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_TRANSPORTADORAS_GESTIONAR), 403);
        abort_unless(DB::table('inventory_sources')->where('id', $origen)->exists(), 404);

        $this->locales->asignar($origen, (array) $request->input('zonas', []));

        return redirect()->route('admin.logistica.zonas_locales.index')->with('success', trans('logistica::app.zonas_locales.guardado'));
    }
}
