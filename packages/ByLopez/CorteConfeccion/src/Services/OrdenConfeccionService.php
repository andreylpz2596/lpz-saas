<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Repositories\OrdenConfeccionRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdenConfeccionService
{
    public function __construct(
        protected OrdenConfeccionRepository $ordenConfeccionRepository,
        protected DisponibilidadConfeccionService $disponibilidadConfeccionService,
        protected ProductLookupService $productLookupService,
        protected InventarioMovimientoService $inventarioMovimientoService,
        protected OrdenTarifaService $ordenTarifaService,
    ) {}

    public function create(array $data): OrdenConfeccion
    {
        return DB::transaction(function () use ($data) {
            $ordenCorteId = (int) $data['orden_corte_id'];

            $recepcion = RecepcionCorte::query()
                ->where('orden_corte_id', $ordenCorteId)
                ->lockForUpdate()
                ->first();

            if (! $recepcion) {
                throw ValidationException::withMessages(['orden_corte_id' => trans('corteconfeccion::app.errors.orden-sin-recepcion')]);
            }

            $ordenCorte = OrdenCorte::query()->findOrFail($ordenCorteId);
            $taller = Taller::query()->findOrFail($data['taller_id']);

            if ($taller->estado !== 'activo') {
                throw ValidationException::withMessages(['taller_id' => trans('corteconfeccion::app.errors.taller-inactivo')]);
            }

            $this->validarOrdenConPendienteDistribucion($ordenCorte);

            $detalles = $data['detalles'];

            $this->validarProductosSimples(collect($detalles)->pluck('product_id'));

            foreach ($detalles as $detalle) {
                $disponible = $this->disponibilidadConfeccionService->disponiblePara(
                    $ordenCorte,
                    (int) $detalle['product_id']
                );

                if ((int) $detalle['cantidad_entregada'] > $disponible) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.cantidad-excede-disponible'),
                    ]);
                }
            }

            $orden = $this->ordenConfeccionRepository->create([
                'codigo' => $this->generarCodigo(),
                'orden_corte_id' => $ordenCorte->id,
                'taller_id' => $taller->id,
                'fecha_entrega' => $data['fecha_entrega'] ?? null,
                'estado' => EstadoOrdenConfeccion::Activa->value,
                'observacion' => $data['observacion'] ?? null,
            ]);

            foreach ($detalles as $detalle) {
                $orden->detalles()->create(array_merge(
                    $this->productLookupService->snapshot((int) ($detalle['product_id'] ?? 0)),
                    [
                        'talla' => $detalle['talla'] ?? '',
                        'cantidad_entregada' => $detalle['cantidad_entregada'],
                    ]
                ));
            }

            $this->ordenTarifaService->sincronizar($orden);

            return $orden->refresh()->load('detalles');
        });
    }

    /**
     * Distribuye la disponibilidad de UNA OrdenCorte entre uno o varios
     * Talleres en una sola operacion atomica: N asignaciones -> N
     * OrdenConfeccion (una por Taller, nunca una que mezcle talleres).
     *
     * Orden obligatorio dentro de la transaccion (docs/confeccion/02_MODELO_DISTRIBUCION.md
     * §8-§9, aprobado): lockForUpdate() sobre la RecepcionCorte ANTES de
     * cualquier lectura no bloqueante, para que el snapshot de lecturas
     * consistentes (REPEATABLE READ) quede establecido despues de obtener
     * el lock y no antes -- de lo contrario una segunda transaccion que
     * esperó el lock podria seguir viendo datos de disponibilidad
     * anteriores al commit de la primera.
     *
     * $data:
     *   orden_corte_id: int
     *   asignaciones: array<{ taller_id, fecha_entrega?, observacion?,
     *       detalles: array<{ product_id, cantidad, prioridad? }> }>
     *
     * @return Collection<int, OrdenConfeccion>
     */
    public function distribuir(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $ordenCorteId = (int) $data['orden_corte_id'];

            // Primera sentencia SQL de la transaccion, a proposito: ver
            // docblock de este metodo.
            $recepcion = RecepcionCorte::query()
                ->where('orden_corte_id', $ordenCorteId)
                ->lockForUpdate()
                ->first();

            if (! $recepcion) {
                throw ValidationException::withMessages([
                    'orden_corte_id' => trans('corteconfeccion::app.errors.orden-sin-recepcion'),
                ]);
            }

            $ordenCorte = OrdenCorte::query()->findOrFail($ordenCorteId);

            $asignaciones = $data['asignaciones'];

            $this->validarOrdenConPendienteDistribucion($ordenCorte);

            $tallerIdsCrudos = collect($asignaciones)->pluck('taller_id')->map(fn ($id) => (int) $id);
            $tallerIds = $tallerIdsCrudos->unique();

            // Defensa en profundidad: DistribuirConfeccionRequest ya valida
            // "distinct" sobre asignaciones.*.taller_id, pero el Service debe
            // seguir siendo seguro si se invoca directamente (como hacen los
            // tests, o cualquier futuro caller que no pase por el Request).
            // Una operacion de distribucion nunca genera mas de una
            // OrdenConfeccion por Taller (arquitectura aprobada).
            if ($tallerIdsCrudos->count() !== $tallerIds->count()) {
                throw ValidationException::withMessages([
                    'asignaciones' => trans('corteconfeccion::app.errors.taller-duplicado-en-distribucion'),
                ]);
            }

            $talleresPorId = Taller::query()->whereIn('id', $tallerIds)->get()->keyBy('id');

            foreach ($tallerIds as $tallerId) {
                $taller = $talleresPorId->get($tallerId);

                if (! $taller || $taller->estado !== 'activo') {
                    throw ValidationException::withMessages([
                        'asignaciones' => trans('corteconfeccion::app.errors.taller-inactivo'),
                    ]);
                }
            }

            $todosLosDetalles = collect($asignaciones)->flatMap(fn ($asignacion) => $asignacion['detalles']);

            $this->validarProductosSimples($todosLosDetalles->pluck('product_id'));

            // Disponibilidad recalculada EN SERVIDOR dentro de la transaccion
            // (nunca se confia en lo que trajo la pantalla). $ordenCorte se
            // acaba de cargar recien, asi que no arrastra relaciones cacheadas
            // de antes del lock.
            $disponibilidadPorProducto = $this->disponibilidadConfeccionService
                ->porOrdenCorte($ordenCorte)
                ->keyBy('product_id');

            $solicitadoPorProducto = $todosLosDetalles
                ->groupBy(fn ($detalle) => (int) $detalle['product_id'])
                ->map(fn ($items) => (int) $items->sum('cantidad'));

            foreach ($solicitadoPorProducto as $productId => $solicitado) {
                $disponible = (int) ($disponibilidadPorProducto[$productId]['cantidad_disponible_confeccion'] ?? 0);

                if ($solicitado > $disponible) {
                    throw ValidationException::withMessages([
                        'asignaciones' => trans('corteconfeccion::app.errors.cantidad-excede-disponible'),
                    ]);
                }
            }

            return collect($asignaciones)->map(function ($asignacion) use ($ordenCorte, $talleresPorId) {
                $taller = $talleresPorId->get((int) $asignacion['taller_id']);

                $orden = $this->ordenConfeccionRepository->create([
                    'codigo' => $this->generarCodigo(),
                    'orden_corte_id' => $ordenCorte->id,
                    'taller_id' => $taller->id,
                    'fecha_entrega' => $asignacion['fecha_entrega'] ?? null,
                    'estado' => EstadoOrdenConfeccion::Activa->value,
                    'observacion' => $asignacion['observacion'] ?? null,
                ]);

                foreach ($asignacion['detalles'] as $detalle) {
                    $orden->detalles()->create(array_merge(
                        $this->productLookupService->snapshot((int) $detalle['product_id']),
                        [
                            'talla' => $detalle['talla'] ?? '',
                            'cantidad_entregada' => $detalle['cantidad'],
                            'prioridad' => $detalle['prioridad'] ?? PrioridadOrdenConfeccion::Normal->value,
                        ]
                    ));
                }

                $this->ordenTarifaService->sincronizar($orden);

                return $orden->refresh()->load('detalles');
            })->values();
        });
    }

    public function cancel(OrdenConfeccion $orden): OrdenConfeccion
    {
        if ($orden->estado === EstadoOrdenConfeccion::Anulada->value) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-confeccion-ya-anulada')]);
        }

        if ($orden->estado !== EstadoOrdenConfeccion::Activa->value) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-confeccion-no-anulable')]);
        }

        $orden->update(['estado' => EstadoOrdenConfeccion::Anulada->value]);

        return $orden->refresh();
    }

    public function reportarTaller(OrdenConfeccion $orden, array $data): OrdenConfeccionRetorno
    {
        return DB::transaction(function () use ($orden, $data) {
            $orden = OrdenConfeccion::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);

            if (! in_array($orden->estado, [EstadoOrdenConfeccion::Activa->value, EstadoOrdenConfeccion::EnConfeccion->value], true)) {
                throw ValidationException::withMessages([
                    'estado' => trans('corteconfeccion::app.errors.orden-confeccion-no-reportable'),
                ]);
            }

            $orden->load(['detalles.retornosDetalles']);

            $detallesOrden = $orden->detalles->keyBy('id');
            $detallesInput = collect($data['detalles'] ?? []);

            $this->validarDetallesPertenecenOrden($detallesOrden, $detallesInput);

            $numero = ((int) $orden->retornos()->max('numero')) + 1;

            $retorno = $orden->retornos()->create([
                'codigo' => sprintf('%s-ENT-%03d', $orden->codigo, $numero),
                'taller_id' => $orden->taller_id,
                'numero' => $numero,
                'fecha_reporte_taller' => $data['fecha_reporte_taller'] ?? now(),
                'reportado_por' => auth()->guard('admin')->id(),
                'estado' => EstadoOrdenConfeccionRetorno::PendienteBodega->value,
                'observacion_taller' => $data['observacion_taller'] ?? null,
            ]);

            foreach ($detallesInput as $detalleInput) {
                $detalle = $detallesOrden->get((int) $detalleInput['id']);
                $reportada = (int) $detalleInput['cantidad'];
                $pendiente = $this->pendientePorReportar($detalle);

                if ($reportada <= 0) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.cantidad-retorno-invalida'),
                    ]);
                }

                if ($reportada > $pendiente) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.cantidad-retorno-excede-pendiente'),
                    ]);
                }

                $retorno->detalles()->create([
                    'orden_confeccion_detalle_id' => $detalle->id,
                    'product_id' => $detalle->product_id,
                    'producto_sku' => $detalle->producto_sku,
                    'producto_nombre' => $detalle->producto_nombre,
                    'talla' => $detalle->talla,
                    'cantidad_reportada' => $reportada,
                ]);
            }

            $this->actualizarEstadoProduccion($orden);

            return $retorno->refresh()->load(['ordenConfeccion', 'taller', 'detalles.ordenConfeccionDetalle']);
        });
    }

    public function recibirBodega(OrdenConfeccionRetorno $retorno, array $data): OrdenConfeccionRetorno
    {
        return DB::transaction(function () use ($retorno, $data) {
            $retorno = OrdenConfeccionRetorno::query()
                ->with(['ordenConfeccion', 'detalles'])
                ->lockForUpdate()
                ->findOrFail($retorno->id);

            if ($retorno->estado !== EstadoOrdenConfeccionRetorno::PendienteBodega->value) {
                throw ValidationException::withMessages([
                    'estado' => trans('corteconfeccion::app.errors.retorno-confeccion-no-recibible'),
                ]);
            }

            $orden = OrdenConfeccion::query()
                ->lockForUpdate()
                ->findOrFail($retorno->orden_confeccion_id);

            if (in_array($orden->estado, [EstadoOrdenConfeccion::Cerrada->value, EstadoOrdenConfeccion::Anulada->value], true)) {
                throw ValidationException::withMessages([
                    'estado' => trans('corteconfeccion::app.errors.orden-confeccion-no-recibible'),
                ]);
            }

            $detallesRetorno = $retorno->detalles->keyBy('id');
            $detallesInput = collect($data['detalles'] ?? []);

            $this->validarDetallesPertenecenOrden($detallesRetorno, $detallesInput);

            if ($detallesInput->pluck('id')->map(fn ($id) => (int) $id)->unique()->count() !== $detallesRetorno->count()) {
                throw ValidationException::withMessages([
                    'detalles' => trans('corteconfeccion::app.errors.detalles-incompletos'),
                ]);
            }

            foreach ($detallesInput as $detalleInput) {
                $detalle = $detallesRetorno->get((int) $detalleInput['id']);
                $recibida = (int) $detalleInput['cantidad_recibida'];
                $defectuosa = (int) $detalleInput['cantidad_defectuosa'];
                $faltante = (int) $detalleInput['cantidad_faltante'];
                $reportada = (int) $detalle->cantidad_reportada;

                if ($recibida < 0 || $defectuosa < 0 || $faltante < 0) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.cantidades-negativas-no-permitidas'),
                    ]);
                }

                if ($recibida + $defectuosa + $faltante !== $reportada) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.cantidades-recepcion-no-cuadran'),
                    ]);
                }

                if (($defectuosa > 0 || $faltante > 0) && blank($detalleInput['observacion_bodega'] ?? null)) {
                    throw ValidationException::withMessages([
                        'detalles' => trans('corteconfeccion::app.errors.observacion-requerida-faltante-bodega'),
                    ]);
                }

                $detalle->update([
                    'cantidad_recibida' => $recibida,
                    'cantidad_defectuosa' => $defectuosa,
                    'cantidad_faltante' => $faltante,
                    'observacion_bodega' => $detalleInput['observacion_bodega'] ?? null,
                ]);
            }

            $this->inventarioMovimientoService->registrarEntradaConfeccion($retorno->refresh(['ordenConfeccion', 'detalles']));

            $retorno->update([
                'estado' => EstadoOrdenConfeccionRetorno::RecibidaBodega->value,
                'fecha_recepcion_bodega' => $data['fecha_recepcion_bodega'] ?? now(),
                'recibido_por' => auth()->guard('admin')->id(),
                'observacion_bodega' => $data['observacion_bodega'] ?? null,
            ]);

            $this->actualizarEstadoProduccion($orden);

            return $retorno->refresh()->load(['ordenConfeccion', 'taller', 'detalles.ordenConfeccionDetalle']);
        });
    }

    /**
     * La clave operativa de variante es product_id (docs/confeccion/02_MODELO_DISTRIBUCION.md
     * §3): en Bagisto cada product_id de estos detalles ya deberia ser un
     * producto "simple" (variante exacta color+talla). Un producto
     * "configurable" (padre) NO identifica una variante unica y mezclaria
     * cantidades de tallas/colores distintos si se le permitiera participar
     * en el calculo de disponibilidad. Se rechaza toda la operacion si
     * cualquiera de los product_id recibidos es configurable.
     */
    protected function validarProductosSimples(Collection $productIds): void
    {
        $ids = $productIds->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $tieneConfigurable = DB::table('products')
            ->whereIn('id', $ids)
            ->where('type', 'configurable')
            ->exists();

        if ($tieneConfigurable) {
            throw ValidationException::withMessages([
                'detalles' => trans('corteconfeccion::app.errors.producto-configurable-no-valido'),
            ]);
        }
    }

    protected function validarOrdenConPendienteDistribucion(OrdenCorte $ordenCorte): void
    {
        if (! $this->disponibilidadConfeccionService->puedeDistribuir($ordenCorte)) {
            throw ValidationException::withMessages([
                'orden_corte_id' => trans('corteconfeccion::app.errors.orden-sin-pendiente-distribucion'),
            ]);
        }
    }

    protected function validarDetallesPertenecenOrden(Collection $detallesOrden, Collection $detallesInput): void
    {
        $idsInput = $detallesInput->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($idsInput->count() !== $detallesInput->count() || $idsInput->diff($detallesOrden->keys()->map(fn ($id) => (int) $id))->isNotEmpty()) {
            throw ValidationException::withMessages([
                'detalles' => trans('corteconfeccion::app.errors.detalle-no-pertenece-orden'),
            ]);
        }
    }

    protected function pendientePorReportar($detalle): int
    {
        return max((int) $detalle->cantidad_entregada - (int) $detalle->retornosDetalles->sum('cantidad_reportada'), 0);
    }

    protected function actualizarEstadoProduccion(OrdenConfeccion $orden): void
    {
        $orden->load(['detalles.retornosDetalles.movimientosInventario', 'retornos']);

        $totalEntregado = (int) $orden->detalles->sum('cantidad_entregada');
        $retornosDetalles = $orden->detalles->flatMap(fn ($detalle) => $detalle->retornosDetalles);
        $totalReportado = (int) $retornosDetalles->sum('cantidad_reportada');
        $totalRecibidoControlado = (int) $retornosDetalles
            ->sum(fn ($detalle) => (int) $detalle->cantidad_recibida + (int) $detalle->cantidad_defectuosa + (int) $detalle->cantidad_faltante);
        $retornosPendientes = $orden->retornos
            ->where('estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)
            ->count();

        $totalBuenas = (int) $retornosDetalles->sum('cantidad_recibida');
        $totalBuenasProcesadas = (int) $retornosDetalles
            ->flatMap(fn ($detalle) => $detalle->movimientosInventario)
            ->sum('cantidad_movimiento');

        $estado = match (true) {
            $totalEntregado > 0
                && $totalReportado >= $totalEntregado
                && $totalRecibidoControlado >= $totalReportado
                && $retornosPendientes === 0
                && $totalBuenasProcesadas >= $totalBuenas => EstadoOrdenConfeccion::Cerrada->value,
            $totalEntregado > 0 && $totalReportado >= $totalEntregado && $totalRecibidoControlado >= $totalReportado && $retornosPendientes === 0 => EstadoOrdenConfeccion::RecibidaBodega->value,
            $totalEntregado > 0 && $totalReportado >= $totalEntregado => EstadoOrdenConfeccion::TerminadaTaller->value,
            $totalReportado > 0 => EstadoOrdenConfeccion::EnConfeccion->value,
            default => EstadoOrdenConfeccion::Activa->value,
        };

        $orden->update(['estado' => $estado]);
    }

    protected function generarCodigo(): string
    {
        $ultimoConsecutivo = (int) DB::table('bylopez_cc_ordenes_confeccion')
            ->where('codigo', 'regexp', '^OF-[0-9]{6}$')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)) as consecutivo")
            ->lockForUpdate()
            ->value('consecutivo');

        do {
            $ultimoConsecutivo++;
            $codigo = sprintf('OF-%06d', $ultimoConsecutivo);
        } while (DB::table('bylopez_cc_ordenes_confeccion')->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
