<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\RecepcionCorteDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreRecepcionCorteRequest;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RecepcionCorteRepository;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class RecepcionCorteController extends Controller
{
    public function __construct(
        protected RecepcionCorteRepository $recepcionCorteRepository,
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RecepcionCorteService $recepcionCorteService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(RecepcionCorteDataGrid::class)->toJson();
        }

        return view('corteconfeccion::admin.recepciones-corte.index');
    }

    /**
     * F0 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2): esta pantalla
     * independiente dejaba elegir CUALQUIER producto del catalogo por fila
     * (select libre en recepciones-corte/create.blade.php), sin validar que
     * perteneciera a la orden -- exactamente el hueco que F0 cierra. Ya
     * estaba retirada del menu desde antes (ver comentario en
     * src/Config/menu.php) y no hay ningun link ni test que dependa de
     * renderizar este formulario; el unico flujo real es el card de
     * recepcion embebido en ordenes-corte/show.blade.php. En vez de
     * adaptarla para seguir permitiendo seleccion manual de producto, se
     * redirige a la vista de la orden, que ya tiene el card de recepcion
     * (ahora corregido) como unico punto de entrada. La ruta y el
     * controlador se conservan (no se eliminan) porque el ACL y el
     * historico de la ruta se mantienen; recepciones-corte/create.blade.php
     * queda sin uso, ver comentario en ese archivo.
     */
    public function create(int $ordenCorteId)
    {
        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $ordenCorteId);
    }

    public function store(StoreRecepcionCorteRequest $request, int $ordenCorteId)
    {
        $orden = $this->ordenCorteRepository->with('detalles')->findOrFail($ordenCorteId);
        $data = $request->validated();

        if ($this->payloadCambiaCantidadRecibida($data, $orden) && ! CantidadCortadaGate::userCanEdit(null, $orden)) {
            throw ValidationException::withMessages([
                'detalles' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
            ]);
        }

        $this->recepcionCorteService->create($orden, $data);

        return redirect()->route('admin.corte_confeccion.ordenes_corte.show', $ordenCorteId)
            ->with('success', trans('corteconfeccion::app.messages.created'));
    }

    public function show(int $id)
    {
        $recepcion = $this->recepcionCorteRepository->with(['ordenCorte.detalles', 'detalles'])->findOrFail($id);

        return view('corteconfeccion::admin.recepciones-corte.show', compact('recepcion'));
    }

    /**
     * Identidad por orden_corte_detalle_id, no por posicion dentro del
     * array (ver AUD-02 §12.2: el bug original comparaba
     * $orden->detalles->values()[$index], que no correspondia
     * necesariamente a la fila que el navegador envio en esa posicion).
     */
    protected function payloadCambiaCantidadRecibida(array $data, $orden): bool
    {
        $detallesPorId = $orden->detalles->keyBy('id');

        foreach (($data['detalles'] ?? []) as $detalle) {
            $detalleOrden = $detallesPorId->get((int) ($detalle['orden_corte_detalle_id'] ?? 0));
            $actual = (int) ($detalleOrden->cantidad_real_cortada ?? 0);
            $nuevo = (int) ($detalle['cantidad_recibida'] ?? 0);

            if ($nuevo !== $actual) {
                return true;
            }
        }

        return false;
    }
}
