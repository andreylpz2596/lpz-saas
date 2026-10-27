<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\RolloDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Http\Requests\StoreRolloRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateRolloRequest;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Services\RolloService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;

class RolloController extends Controller
{
    public function __construct(
        protected RolloRepository $rolloRepository,
        protected RolloService $rolloService,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(RolloDataGrid::class)->toJson();
        }

        $resumen = $this->resumenPorEstado();
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.rollos.index', compact('resumen', 'coloresTela'));
    }

    /**
     * Cantidad de rollos y suma de peso_disponible agrupados por estado, en
     * una unica consulta (COUNT + SUM + GROUP BY), para las cards de resumen.
     */
    protected function resumenPorEstado(): array
    {
        $porEstado = DB::table('bylopez_cc_rollos')
            ->select('estado', DB::raw('COUNT(*) as cantidad'), DB::raw('SUM(peso_disponible) as peso'))
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        $bucket = fn (string ...$estados) => [
            'cantidad' => (int) $porEstado->only($estados)->sum('cantidad'),
            'peso' => (float) $porEstado->only($estados)->sum('peso'),
        ];

        return [
            'total' => $bucket(...array_column(EstadoRollo::cases(), 'value')),
            'disponible' => $bucket(EstadoRollo::Disponible->value),
            'reservado_en_corte' => $bucket(EstadoRollo::ReservadoEnCorte->value),
            'parcialmente_usado' => $bucket(EstadoRollo::ParcialmenteUsado->value),
            'agotado_cerrado' => $bucket(EstadoRollo::Agotado->value, EstadoRollo::Anulado->value),
        ];
    }

    public function create()
    {
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.rollos.create', compact('coloresTela'));
    }

    public function store(StoreRolloRequest $request)
    {
        $this->rolloService->create($request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.created'));

        return redirect()->route('admin.corte_confeccion.rollos.index');
    }

    public function show(int $id)
    {
        $rollo = $this->rolloRepository->with('ordenesCorte')->findOrFail($id);

        return view('corteconfeccion::admin.rollos.show', compact('rollo'));
    }

    public function edit(int $id)
    {
        $rollo = $this->rolloRepository->findOrFail($id);
        $coloresTela = $this->coloresTela();

        return view('corteconfeccion::admin.rollos.edit', compact('rollo', 'coloresTela'));
    }

    public function update(UpdateRolloRequest $request, int $id)
    {
        $rollo = $this->rolloRepository->findOrFail($id);

        $this->rolloService->update($rollo, $request->validated());

        if ($request->ajax()) {
            return response()->json([
                'message' => trans('corteconfeccion::app.messages.updated'),
                'data' => [
                    'rollo' => $rollo->refresh(),
                ],
            ]);
        }

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.rollos.index');
    }

    public function cancel(int $id)
    {
        abort_if(! bouncer()->hasPermission('corte_confeccion.rollos.edit'), 403);

        $rollo = $this->rolloRepository->findOrFail($id);

        $this->rolloService->cancel($rollo);

        session()->flash('success', trans('corteconfeccion::app.messages.cancelled'));

        return back();
    }

    /**
     * F1: opciones reales del atributo Bagisto `code = 'color'`. Mismo
     * patron que ProveedorTelaController::coloresTela().
     */
    protected function coloresTela()
    {
        return Attribute::query()
            ->where('code', 'color')
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('admin_name')])
            ->first()
            ?->options
            ?? collect();
    }
}
