<?php

namespace ByLopez\CorteConfeccion\Http\Controllers\Admin;

use ByLopez\CorteConfeccion\DataGrids\RolloDataGrid;
use ByLopez\CorteConfeccion\Http\Requests\StoreRolloRequest;
use ByLopez\CorteConfeccion\Http\Requests\UpdateRolloRequest;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Services\RolloService;
use Illuminate\Routing\Controller;

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

        return view('corteconfeccion::admin.rollos.index');
    }

    public function create()
    {
        return view('corteconfeccion::admin.rollos.create');
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

        return view('corteconfeccion::admin.rollos.edit', compact('rollo'));
    }

    public function update(UpdateRolloRequest $request, int $id)
    {
        $rollo = $this->rolloRepository->findOrFail($id);

        $this->rolloService->update($rollo, $request->validated());

        session()->flash('success', trans('corteconfeccion::app.messages.updated'));

        return redirect()->route('admin.corte_confeccion.rollos.index');
    }

    public function cancel(int $id)
    {
        $rollo = $this->rolloRepository->findOrFail($id);

        $this->rolloService->cancel($rollo);

        session()->flash('success', trans('corteconfeccion::app.messages.cancelled'));

        return back();
    }
}
