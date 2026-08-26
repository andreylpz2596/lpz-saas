<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Repositories\RecepcionCorteRepository;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecepcionCorteService
{
    public function __construct(
        protected RecepcionCorteRepository $recepcionCorteRepository,
        protected ProductLookupService $productLookupService,
        protected CantidadCortadaAuditService $cantidadCortadaAuditService,
    ) {}

    public function create(OrdenCorte $orden, array $data): RecepcionCorte
    {
        return DB::transaction(function () use ($orden, $data) {
            $orden->load(['recepcion', 'detalles']);

            if (in_array($orden->estado, [EstadoOrdenCorte::Anulado->value, EstadoOrdenCorte::Cerrado->value], true)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.no-recibir-terminal')]);
            }

            if ($orden->recepcion) {
                throw ValidationException::withMessages(['orden_corte_id' => trans('corteconfeccion::app.errors.recepcion-existente')]);
            }

            $pesoBase = (float) $orden->peso_entregado > 0
                ? (float) $orden->peso_entregado
                : (float) $orden->peso_total_rollos;

            $mermaTotal = (float) $data['peso_retasos'] + (float) $data['peso_desperdicio'];
            $pesoReportado = (float) $data['peso_sobrante_usable'] + $mermaTotal;

            if ($pesoReportado > $pesoBase) {
                throw ValidationException::withMessages(['peso_sobrante_usable' => trans('corteconfeccion::app.errors.peso-recepcion-excede')]);
            }

            $detalles = $data['detalles'];
            unset($data['detalles']);

            $data['orden_corte_id'] = $orden->id;
            $data['merma_total'] = $mermaTotal;

            $recepcion = $this->recepcionCorteRepository->create($data);

            foreach ($detalles as $detalle) {
                $recepcion->detalles()->create(array_merge(
                    $this->productLookupService->snapshot((int) ($detalle['product_id'] ?? 0)),
                    [
                        'talla'                 => $detalle['talla'],
                        'cantidad_recibida'     => $detalle['cantidad_recibida'],
                        'cantidad_defectuosa'   => $detalle['cantidad_defectuosa'],
                        'observacion'           => $detalle['observacion'] ?? null,
                    ]
                ));
            }

            foreach ($orden->detalles as $index => $detalleOrden) {
                if (! isset($detalles[$index])) {
                    continue;
                }

                $valorAnterior = (int) $detalleOrden->cantidad_real_cortada;
                $valorNuevo = (int) $detalles[$index]['cantidad_recibida'];

                if ($valorAnterior !== $valorNuevo && ! CantidadCortadaGate::userCanEdit(null, $orden)) {
                    throw ValidationException::withMessages([
                        'cantidad_cortada' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
                    ]);
                }

                $detalleOrden->update([
                    'cantidad_real_cortada' => $valorNuevo,
                    'observacion'           => $detalles[$index]['observacion'] ?? $detalleOrden->observacion,
                ]);

                $this->cantidadCortadaAuditService->record($detalleOrden, $valorAnterior, $valorNuevo);
            }

            $totalPiezas = (int) collect($detalles)->sum(fn ($detalle) => (int) $detalle['cantidad_recibida']);
            $pesoConsumido = round($pesoBase - (float) $data['peso_sobrante_usable'] - $mermaTotal, 3);

            $orden->update([
                'peso_sobrante_total'    => $data['peso_sobrante_usable'],
                'merma_total'            => $mermaTotal,
                'peso_consumido_total'   => $pesoConsumido,
                'total_piezas_cortadas'  => $totalPiezas,
                'peso_promedio_pieza'    => $totalPiezas > 0 ? round($pesoConsumido / $totalPiezas, 6) : 0,
                'estado'                 => EstadoOrdenCorte::RecibidoBodega->value,
            ]);

            return $recepcion->refresh();
        });
    }
}
