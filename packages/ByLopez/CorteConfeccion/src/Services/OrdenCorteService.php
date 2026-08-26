<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdenCorteService
{
    public function __construct(
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RolloRepository $rolloRepository,
        protected RolloService $rolloService,
        protected ProductLookupService $productLookupService,
        protected CantidadCortadaAuditService $cantidadCortadaAuditService,
    ) {}

    public function create(array $data): OrdenCorte
    {
        return DB::transaction(function () use ($data) {
            $rolloIds = collect($data['rollo_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $rollos = $this->rollosSeleccionados($rolloIds);

            $this->validarRollosDisponibles($rollos, $rolloIds);
            $this->validarCompatibilidadRollos($rollos);

            $detalles = $data['detalles'];

            $this->validarPermisoCantidadCortada($detalles);

            $pesoTotalRollos = round((float) $rollos->sum(fn ($rollo) => (float) $rollo->peso_disponible), 3);
            $metricas = $this->calcularMetricasOrden($pesoTotalRollos, $data['peso_sobrante_total'] ?? null, $data['merma_total'] ?? null, $detalles);
            $primerRollo = $rollos->first();
            $cortador = CortadorResolver::find((int) ($data['cortador_id'] ?? 0));

            if (! $cortador) {
                throw ValidationException::withMessages([
                    'cortador_id' => 'El cortador seleccionado no tiene el rol cortador.',
                ]);
            }

            $cortadorNombreSnapshot = CortadorResolver::snapshotName($cortador);

            unset($data['codigo'], $data['cortador_nombre'], $data['cortador_nombre_snapshot'], $data['detalles'], $data['rollo_ids'], $data['peso_sobrante_total'], $data['merma_total']);

            $data = array_merge($data, $metricas, [
                'codigo'                    => $this->generarCodigoOrden($cortadorNombreSnapshot),
                'rollo_id'           => $primerRollo->id,
                'cortador_nombre'           => $cortadorNombreSnapshot,
                'cortador_nombre_snapshot'  => $cortadorNombreSnapshot,
                'color'                     => $primerRollo->color,
                'genero_linea'              => $this->resumenGeneroLinea($detalles),
                'estado'                    => EstadoOrdenCorte::PendienteEntrega->value,
                'peso_entregado'            => 0,
                'peso_total_rollos'         => $pesoTotalRollos,
            ]);

            $orden = $this->ordenCorteRepository->create($data);

            foreach ($rollos as $rollo) {
                $orden->rollosCorte()->create([
                    'rollo_id'            => $rollo->id,
                    'peso_inicial_usado'  => $rollo->peso_disponible,
                    'peso_sobrante'       => 0,
                    'peso_consumido'      => 0,
                ]);

                $rollo->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);
            }

            foreach ($detalles as $detalle) {
                $cantidadCortada = (int) ($detalle['cantidad_cortada'] ?? $detalle['cantidad_real_cortada'] ?? 0);

                $detalleOrden = $orden->detalles()->create(array_merge(
                    $this->productLookupService->snapshot((int) ($detalle['product_id'] ?? 0)),
                    [
                        'variant_id'              => $detalle['variant_id'] ?? null,
                        'genero_linea'            => $detalle['genero_linea'] ?? null,
                        'talla'                   => $detalle['talla'] ?? '',
                        'cantidad_proyectada'     => $detalle['cantidad_proyectada'] ?? 0,
                        'cantidad_real_cortada'   => $cantidadCortada,
                        'observacion'             => $detalle['observacion'] ?? null,
                    ]
                ));

                $this->cantidadCortadaAuditService->record($detalleOrden, 0, $cantidadCortada);
            }

            return $orden->refresh();
        });
    }

    public function entregar(OrdenCorte $orden, float $pesoEntregado): OrdenCorte
    {
        return DB::transaction(function () use ($orden, $pesoEntregado) {
            $orden->load(['rollo', 'rollosCorte.rollo']);

            if ($orden->estado !== EstadoOrdenCorte::PendienteEntrega->value) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-no-pendiente')]);
            }

            $pesoDisponible = $this->pesoReservadoOrden($orden);

            if ($pesoEntregado > $pesoDisponible) {
                throw ValidationException::withMessages(['peso_entregado' => trans('corteconfeccion::app.errors.peso-insuficiente')]);
            }

            $orden->update([
                'peso_entregado' => $pesoEntregado,
                'estado'         => EstadoOrdenCorte::EntregadoACortador->value,
            ]);

            return $orden->refresh();
        });
    }

    public function cambiarEstado(OrdenCorte $orden, string $estadoEsperado, string $nuevoEstado): OrdenCorte
    {
        if ($orden->estado !== $estadoEsperado) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.transicion-invalida')]);
        }

        $orden->update(['estado' => $nuevoEstado]);

        return $orden->refresh();
    }

    public function cerrar(OrdenCorte $orden): OrdenCorte
    {
        return DB::transaction(function () use ($orden) {
            $orden->load(['rollosCorte.rollo', 'recepcion.detalles', 'detalles']);

            if (in_array($orden->estado, [EstadoOrdenCorte::Anulado->value, EstadoOrdenCorte::Cerrado->value], true)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-terminal')]);
            }

            if (! $orden->recepcion || $orden->estado !== EstadoOrdenCorte::RecibidoBodega->value) {
                throw ValidationException::withMessages(['recepcion' => trans('corteconfeccion::app.errors.orden-sin-recepcion')]);
            }

            $totalPiezas = (int) $orden->detalles->sum('cantidad_real_cortada');

            if ($totalPiezas <= 0) {
                $totalPiezas = (int) $orden->recepcion->detalles->sum('cantidad_recibida');
            }

            if ($totalPiezas <= 0) {
                throw ValidationException::withMessages(['detalles' => trans('corteconfeccion::app.errors.orden-sin-cantidades-reales')]);
            }

            $pesoBase = $this->pesoBaseCierre($orden);
            $pesoSobrante = (float) $orden->peso_sobrante_total;
            $merma = (float) $orden->merma_total;

            if (($pesoSobrante + $merma) > $pesoBase) {
                throw ValidationException::withMessages(['peso_sobrante_total' => trans('corteconfeccion::app.errors.peso-recepcion-excede')]);
            }

            $pesoConsumido = round($pesoBase - $pesoSobrante - $merma, 3);

            $orden->update([
                'peso_consumido_total'   => $pesoConsumido,
                'total_piezas_cortadas'  => $totalPiezas,
                'peso_promedio_pieza'    => $totalPiezas > 0 ? round($pesoConsumido / $totalPiezas, 6) : 0,
                'estado'                 => EstadoOrdenCorte::Cerrado->value,
            ]);

            $this->actualizarRollosAlCerrar($orden->refresh());

            return $orden->refresh();
        });
    }

    public function cancel(OrdenCorte $orden): OrdenCorte
    {
        return DB::transaction(function () use ($orden) {
            $orden->load('rollosCorte.rollo');

            if ($orden->estado === EstadoOrdenCorte::Cerrado->value) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-cerrada')]);
            }

            $orden->update(['estado' => EstadoOrdenCorte::Anulado->value]);

            foreach ($orden->rollosCorte as $ordenRollo) {
                if (! $ordenRollo->rollo || $ordenRollo->rollo->estado !== EstadoRollo::ReservadoEnCorte->value) {
                    continue;
                }

                $ordenRollo->rollo->update([
                    'estado' => $this->rolloService->estadoPorPeso(
                        (float) $ordenRollo->rollo->peso_disponible,
                        (float) $ordenRollo->rollo->peso_inicial
                    ),
                ]);
            }

            return $orden->refresh();
        });
    }

    protected function rollosSeleccionados(Collection $rolloIds): Collection
    {
        return Rollo::query()
            ->whereIn('id', $rolloIds)
            ->lockForUpdate()
            ->get()
            ->sortBy(fn ($rollo) => $rolloIds->search($rollo->id))
            ->values();
    }

    protected function generarCodigoOrden(string $cortadorNombreSnapshot): string
    {
        $prefijoCortador = CortadorResolver::codePrefix($cortadorNombreSnapshot);
        $ultimoConsecutivo = (int) DB::table('bylopez_cc_ordenes_corte')
            ->where('codigo', 'regexp', '^OC-[A-Z0-9]{3}-[0-9]{6}$')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)) as consecutivo")
            ->lockForUpdate()
            ->value('consecutivo');

        do {
            $ultimoConsecutivo++;
            $codigo = sprintf('OC-%s-%06d', $prefijoCortador, $ultimoConsecutivo);
        } while (DB::table('bylopez_cc_ordenes_corte')->where('codigo', $codigo)->exists());

        return $codigo;
    }

    protected function validarRollosDisponibles(Collection $rollos, Collection $rolloIds): void
    {
        if ($rollos->count() !== $rolloIds->count()) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
        }

        $estadosPermitidos = [
            EstadoRollo::Disponible->value,
            EstadoRollo::ParcialmenteUsado->value,
        ];

        if ($rollos->contains(fn ($rollo) => ! in_array($rollo->estado, $estadosPermitidos, true))) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
        }

        $ordenAbierta = DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_ordenes_corte as oc', 'ocr.orden_corte_id', '=', 'oc.id')
            ->whereIn('ocr.rollo_id', $rolloIds)
            ->whereNotIn('oc.estado', [EstadoOrdenCorte::Cerrado->value, EstadoOrdenCorte::Anulado->value])
            ->exists();

        if ($ordenAbierta) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollo-reservado')]);
        }
    }

    protected function validarCompatibilidadRollos(Collection $rollos): void
    {
        $base = $rollos->first();

        $incompatible = $rollos->contains(function ($rollo) use ($base) {
            return (string) $rollo->tipo_tela_id !== (string) $base->tipo_tela_id
                || strtolower(trim((string) $rollo->color)) !== strtolower(trim((string) $base->color))
                || trim((string) $rollo->gramaje) !== trim((string) $base->gramaje);
        });

        if ($incompatible) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollos-incompatibles')]);
        }
    }

    protected function resumenGeneroLinea(array $detalles): string
    {
        return collect($detalles)
            ->pluck('genero_linea')
            ->filter()
            ->map(fn ($genero) => trim((string) $genero))
            ->unique()
            ->implode(' / ') ?: 'Mixto';
    }

    protected function calcularMetricasOrden(float $pesoTotal, mixed $pesoSobrante, mixed $merma, array $detalles): array
    {
        $tieneCierre = $pesoSobrante !== null || $merma !== null;
        $pesoSobrante = $pesoSobrante !== null ? (float) $pesoSobrante : 0;
        $merma = $merma !== null ? (float) $merma : 0;

        if (($pesoSobrante + $merma) > $pesoTotal) {
            throw ValidationException::withMessages(['peso_sobrante_total' => trans('corteconfeccion::app.errors.peso-recepcion-excede')]);
        }

        $totalPiezas = (int) collect($detalles)->sum(fn ($detalle) => (int) ($detalle['cantidad_cortada'] ?? $detalle['cantidad_real_cortada'] ?? 0));
        $pesoConsumido = $tieneCierre ? round($pesoTotal - $pesoSobrante - $merma, 3) : 0;

        return [
            'peso_sobrante_total'     => $pesoSobrante,
            'merma_total'             => $merma,
            'peso_consumido_total'    => $pesoConsumido,
            'total_piezas_cortadas'   => $totalPiezas,
            'peso_promedio_pieza'     => $totalPiezas > 0 && $pesoConsumido > 0 ? round($pesoConsumido / $totalPiezas, 6) : 0,
        ];
    }

    protected function pesoReservadoOrden(OrdenCorte $orden): float
    {
        if ($orden->rollosCorte->isNotEmpty()) {
            return round((float) $orden->rollosCorte->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
        }

        return (float) $orden->rollo?->peso_disponible;
    }

    protected function pesoBaseCierre(OrdenCorte $orden): float
    {
        if ((float) $orden->peso_entregado > 0) {
            return (float) $orden->peso_entregado;
        }

        if ((float) $orden->peso_total_rollos > 0) {
            return (float) $orden->peso_total_rollos;
        }

        return $this->pesoReservadoOrden($orden);
    }

    protected function actualizarRollosAlCerrar(OrdenCorte $orden): void
    {
        $orden->loadMissing('rollosCorte.rollo');

        $sobrantePorAsignar = (float) $orden->peso_sobrante_total;

        foreach ($orden->rollosCorte as $ordenRollo) {
            if (! $ordenRollo->rollo) {
                continue;
            }

            $pesoInicialUsado = (float) $ordenRollo->peso_inicial_usado;
            $pesoSobrante = min($pesoInicialUsado, $sobrantePorAsignar);
            $pesoConsumido = max($pesoInicialUsado - $pesoSobrante, 0);
            $sobrantePorAsignar = max($sobrantePorAsignar - $pesoSobrante, 0);

            $ordenRollo->update([
                'peso_sobrante'   => $pesoSobrante,
                'peso_consumido'  => $pesoConsumido,
            ]);

            $ordenRollo->rollo->update([
                'peso_disponible' => $pesoSobrante,
                'estado'          => $this->rolloService->estadoPorPeso($pesoSobrante, (float) $ordenRollo->rollo->peso_inicial),
            ]);
        }
    }

    protected function validarPermisoCantidadCortada(array $detalles, ?OrdenCorte $orden = null): void
    {
        if (CantidadCortadaGate::userCanEdit(null, $orden)) {
            return;
        }

        foreach ($detalles as $detalle) {
            $cantidad = $detalle['cantidad_cortada'] ?? $detalle['cantidad_real_cortada'] ?? null;

            if ($cantidad !== null && $cantidad !== '' && (int) $cantidad !== 0) {
                throw ValidationException::withMessages([
                    'cantidad_cortada' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
                ]);
            }
        }
    }
}
