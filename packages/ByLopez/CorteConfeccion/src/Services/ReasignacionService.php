<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoReasignacion;
use ByLopez\CorteConfeccion\Enums\SituacionFisicaReasignacion;
use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\Reasignacion;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Repositories\OrdenConfeccionRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Reasignacion de prendas PENDIENTES de un taller a otro (plan Corte y Confeccion, seccion 6).
 *
 * Modelo (ver migracion 2026_10_29_000001):
 *  - La asignacion original NO se edita. El taller origen conserva `cantidad_entregada` y registra lo cedido en
 *    `cantidad_reasignada_salida`; la responsabilidad vigente es la diferencia (OrdenConfeccionDetalle::cantidad_vigente).
 *  - El destino recibe una orden de confeccion NUEVA vinculada (no se fusiona ni se reabre otra), con su propio
 *    consecutivo, su tarifa vigente (o «Sin tarifa») y sus ENT. Asi hay una sola representacion del ingreso: el detalle
 *    destino; no existe un segundo conteo de «entrada de traslado».
 *  - Por variante, Σ vigentes + saldo sin asignar = aptas, antes y despues. No toca inventario terminado ni tela.
 *  - Traslado fisico no simultaneo: la reasignacion nace `en_transito` (el destino la tiene contablemente pero
 *    `cantidad_en_transito` la hace no reportable) hasta confirmarRecepcion().
 *
 * Orden de bloqueo (igual que distribuir/cancelar): recepcion del corte -> orden origen -> orden destino, y la PRIMERA
 * lectura consistente va DESPUES de los bloqueos para que el snapshot REPEATABLE READ no sea anterior a un commit ajeno.
 */
class ReasignacionService
{
    public const EVENTO_DESCONTADO = 'bylopez.corteconfeccion.reasignacion.descontado';

    public function __construct(
        protected OrdenConfeccionRepository $ordenConfeccionRepository,
        protected OrdenConfeccionService $ordenConfeccionService,
        protected OrdenTarifaService $ordenTarifaService,
        protected TarifaPrestadorService $tarifaPrestadorService,
        protected PrioridadConfeccionService $prioridadConfeccionService,
    ) {}

    /**
     * Estado de cada detalle del taller origen para decidir cuanto se puede mover. UNICA definicion de «elegible»:
     * OrdenConfeccionDetalle::pendientePorReportar().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function elegibles(OrdenConfeccion $orden): Collection
    {
        return $orden->detalles()->with('retornosDetalles')->orderBy('id')->get()->map(fn (OrdenConfeccionDetalle $detalle) => [
            'detalle_id' => $detalle->id,
            'product_id' => $detalle->product_id,
            'attribute_family_id' => $detalle->attribute_family_id,
            'sku' => $detalle->producto_sku,
            'nombre' => $detalle->producto_nombre,
            'talla' => $detalle->talla,
            'original' => (int) $detalle->cantidad_entregada,
            'cedida' => (int) $detalle->cantidad_reasignada_salida,
            'vigente' => $detalle->cantidad_vigente,
            'reportada' => $detalle->cantidadReportada(),
            'en_transito' => (int) $detalle->cantidad_en_transito,
            'elegible' => $detalle->pendientePorReportar(),
            // Saldo por prioridad de lo elegible: base del reparto que se traslada (P1).
            'prioridades' => $detalle->prioridadesPorConfeccionar(),
        ]);
    }

    /**
     * Todo lo que necesita el modal «Reasignar prendas»: saldo real por variante, talleres destino con su
     * responsabilidad actual en ESTE corte (para mostrar antes/despues) y la tarifa de cada taller por referencia
     * (para mostrar el impacto). Lectura pura: no persiste nada.
     *
     * @return array<string, mixed>
     */
    public function contexto(OrdenConfeccion $orden): array
    {
        $orden->loadMissing(['taller', 'ordenCorte']);

        $lineas = $this->elegibles($orden);

        $talleres = Taller::query()->where('estado', 'activo')->where('id', '!=', $orden->taller_id)->orderBy('nombre')->get(['id', 'nombre']);

        $totalesPorTaller = DB::table('bylopez_cc_ordenes_confeccion_detalles as d')
            ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
            ->where('o.orden_corte_id', $orden->orden_corte_id)
            ->where('o.estado', '!=', EstadoOrdenConfeccion::Anulada->value)
            ->groupBy('o.taller_id')
            ->selectRaw('o.taller_id, SUM(d.cantidad_entregada - d.cantidad_reasignada_salida) as total')
            ->pluck('total', 'taller_id');

        $combinaciones = collect($this->ordenTarifaService->combinaciones($orden)['lineas']);
        $tarifasOrden = $orden->tarifas()->get()->keyBy(fn ($t) => $t->tipo_tela_id.':'.$t->attribute_family_id);

        $tarifas = $combinaciones->map(function (array $combo) use ($talleres, $tarifasOrden) {
            $fila = $tarifasOrden->get($combo['tipo_tela_id'].':'.$combo['attribute_family_id']);

            return [
                'attribute_family_id' => $combo['attribute_family_id'],
                'origen' => $fila?->tarifa_unitaria !== null ? (float) $fila->tarifa_unitaria : null,
                'destinos' => $talleres->mapWithKeys(fn (Taller $taller) => [
                    $taller->id => ($v = $this->tarifaPrestadorService->vigente($taller, $combo['tipo_tela_id'], $combo['attribute_family_id'])?->valor_unitario) !== null ? (float) $v : null,
                ])->all(),
            ];
        })->values();

        return [
            'orden' => ['id' => $orden->id, 'codigo' => $orden->codigo, 'estado' => $orden->estado, 'taller_id' => $orden->taller_id, 'taller' => $orden->taller?->nombre, 'orden_corte' => $orden->ordenCorte?->codigo],
            'reasignable' => in_array($orden->estado, [EstadoOrdenConfeccion::Activa->value, EstadoOrdenConfeccion::EnConfeccion->value], true) && $lineas->sum('elegible') > 0,
            'lineas' => $lineas->values()->all(),
            'total_origen' => (int) ($totalesPorTaller[$orden->taller_id] ?? 0),
            'talleres' => $talleres->map(fn (Taller $taller) => ['id' => $taller->id, 'nombre' => $taller->nombre, 'total' => (int) ($totalesPorTaller[$taller->id] ?? 0)])->values()->all(),
            'tarifas' => $tarifas->all(),
        ];
    }

    /**
     * @param  array{
     *     orden_confeccion_origen_id: int,
     *     taller_destino_id: int,
     *     idempotency_key: string,
     *     motivo: string,
     *     observacion?: ?string,
     *     situacion_fisica: string,
     *     confirmacion_fisica?: bool,
     *     traslado?: string,
     *     lineas: array<int, array{detalle_id: int, cantidad: int|string, cantidad_en_proceso?: int|string}>
     * }  $data
     */
    public function reasignar(array $data): Reasignacion
    {
        $normalizado = $this->normalizar($data);

        $ordenCorteId = (int) OrdenConfeccion::query()->whereKey($normalizado['origen_id'])->value('orden_corte_id');

        if (! $ordenCorteId) {
            throw ValidationException::withMessages(['orden_confeccion_origen_id' => trans('corteconfeccion::app.errors.reasignacion-origen-no-reasignable')]);
        }

        return DB::transaction(function () use ($normalizado, $ordenCorteId) {
            // Primera sentencia: bloqueo de la recepcion (serializa contra distribuir/cancelar/otras reasignaciones).
            RecepcionCorte::query()->where('orden_corte_id', $ordenCorteId)->lockForUpdate()->first();

            $origen = OrdenConfeccion::query()->lockForUpdate()->findOrFail($normalizado['origen_id']);

            // Idempotencia: DESPUES de los bloqueos, para ver lo que haya confirmado una peticion concurrente.
            $previa = Reasignacion::query()->where('idempotency_key', $normalizado['key'])->first();

            if ($previa) {
                if (! hash_equals($previa->payload_hash, $normalizado['hash'])) {
                    throw new ReasignacionConflictoException(trans('corteconfeccion::app.errors.reasignacion-llave-en-conflicto'));
                }

                return $previa->load(['lineas', 'ordenOrigen', 'ordenDestino', 'tallerOrigen', 'tallerDestino']);
            }

            if (! in_array($origen->estado, [EstadoOrdenConfeccion::Activa->value, EstadoOrdenConfeccion::EnConfeccion->value], true)) {
                throw ValidationException::withMessages(['orden_confeccion_origen_id' => trans('corteconfeccion::app.errors.reasignacion-origen-no-reasignable')]);
            }

            $destinoTaller = Taller::query()->find($normalizado['taller_destino_id']);

            if (! $destinoTaller || $destinoTaller->estado !== 'activo') {
                throw ValidationException::withMessages(['taller_destino_id' => trans('corteconfeccion::app.errors.taller-inactivo')]);
            }

            if ((int) $destinoTaller->id === (int) $origen->taller_id) {
                throw ValidationException::withMessages(['taller_destino_id' => trans('corteconfeccion::app.errors.reasignacion-mismo-taller')]);
            }

            $detalles = OrdenConfeccionDetalle::query()
                ->where('orden_confeccion_id', $origen->id)
                ->with('retornosDetalles')
                ->get()
                ->keyBy('id');

            $this->validarLineas($normalizado['lineas'], $detalles);

            // P1: cantidades por prioridad que se trasladan (elegidas o sugeridas Urgente -> Alta -> Normal), validadas
            // contra el saldo por nivel del origen bajo el bloqueo y ANTES de descontar.
            $repartos = [];

            foreach ($normalizado['lineas'] as $linea) {
                $repartos[$linea['detalle_id']] = $this->prioridadConfeccionService->resolverReparto(
                    $detalles->get($linea['detalle_id']), $linea['cantidad'], $linea['reparto'], 'lineas'
                );
            }

            $enTransito = $normalizado['traslado'] === 'en_transito';

            $destino = $this->ordenConfeccionRepository->create([
                'codigo' => $this->ordenConfeccionService->generarCodigo(),
                'orden_corte_id' => $ordenCorteId,
                'taller_id' => $destinoTaller->id,
                'fecha_entrega' => now()->toDateString(),
                'estado' => EstadoOrdenConfeccion::Activa->value,
                'observacion' => trans('corteconfeccion::app.messages.reasignacion-observacion-destino', ['origen' => $origen->codigo]),
            ]);

            $reasignacion = Reasignacion::query()->create([
                'codigo' => $this->generarCodigo(),
                'orden_corte_id' => $ordenCorteId,
                'taller_origen_id' => $origen->taller_id,
                'taller_destino_id' => $destinoTaller->id,
                'orden_confeccion_origen_id' => $origen->id,
                'orden_confeccion_destino_id' => $destino->id,
                'estado' => $enTransito ? EstadoReasignacion::EnTransito->value : EstadoReasignacion::Aplicada->value,
                'situacion_fisica' => $normalizado['situacion_fisica'],
                'motivo' => $normalizado['motivo'],
                'observacion' => $normalizado['observacion'],
                'idempotency_key' => $normalizado['key'],
                'payload_hash' => $normalizado['hash'],
                'reasignada_por' => auth()->guard('admin')->id(),
            ]);

            $destino->update(['reasignacion_origen_id' => $reasignacion->id]);

            // 1) Descuenta la responsabilidad del origen. Las cantidades prioritarias elegidas viajan con las unidades
            //    cedidas: no se duplican ni se pierden.
            foreach ($normalizado['lineas'] as $linea) {
                $detalle = $detalles->get($linea['detalle_id']);
                $detalle->update(['cantidad_reasignada_salida' => (int) $detalle->cantidad_reasignada_salida + $linea['cantidad']]);
                $this->prioridadConfeccionService->descontar($detalle, $repartos[$detalle->id]);
            }

            // Punto de extension (y de fallo inducido en pruebas): entre el descuento y la creacion del destino.
            Event::dispatch(self::EVENTO_DESCONTADO, [$reasignacion]);

            // 2) Crea el detalle destino de cada variante y la linea de auditoria.
            foreach ($normalizado['lineas'] as $linea) {
                $detalle = $detalles->get($linea['detalle_id']);

                $detalleDestino = $destino->detalles()->create([
                    'product_id' => $detalle->product_id,
                    'producto_sku' => $detalle->producto_sku,
                    'producto_nombre' => $detalle->producto_nombre,
                    'talla' => $detalle->talla,
                    'prioridad' => $detalle->prioridad,
                    'cantidad_urgente' => $repartos[$detalle->id]['urgente'],
                    'cantidad_alta' => $repartos[$detalle->id]['alta'],
                    'cantidad_entregada' => $linea['cantidad'],
                    'cantidad_en_transito' => $enTransito ? $linea['cantidad'] : 0,
                ]);

                $reasignacion->lineas()->create([
                    'detalle_origen_id' => $detalle->id,
                    'detalle_destino_id' => $detalleDestino->id,
                    'product_id' => $detalle->product_id,
                    'producto_sku' => $detalle->producto_sku,
                    'producto_nombre' => $detalle->producto_nombre,
                    'talla' => $detalle->talla,
                    'cantidad' => $linea['cantidad'],
                    'cantidad_urgente' => $repartos[$detalle->id]['urgente'],
                    'cantidad_alta' => $repartos[$detalle->id]['alta'],
                    'cantidad_en_proceso_declarada' => $linea['en_proceso'],
                ]);
            }

            // El destino usa SU tarifa vigente (o queda «Sin tarifa»); nunca hereda la del origen.
            $this->ordenTarifaService->sincronizar($destino);

            $this->ordenConfeccionService->recalcularEstado($origen);
            $this->ordenConfeccionService->recalcularEstado($destino);

            return $reasignacion->refresh()->load(['lineas', 'ordenOrigen', 'ordenDestino', 'tallerOrigen', 'tallerDestino']);
        }, 3);
    }

    /** El destino confirma (bodega registra) que las prendas en transito llegaron: pasan a ser reportables. */
    public function confirmarRecepcion(Reasignacion $reasignacion): Reasignacion
    {
        return $this->bajoBloqueo($reasignacion, function (Reasignacion $fresca, OrdenConfeccion $origen, OrdenConfeccion $destino) {
            if ($fresca->estado !== EstadoReasignacion::EnTransito->value) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.reasignacion-no-en-transito')]);
            }

            foreach ($fresca->lineas as $linea) {
                $detalleDestino = OrdenConfeccionDetalle::query()->findOrFail($linea->detalle_destino_id);
                $detalleDestino->update(['cantidad_en_transito' => max((int) $detalleDestino->cantidad_en_transito - $linea->cantidad, 0)]);
            }

            $fresca->update([
                'estado' => EstadoReasignacion::Aplicada->value,
                'recibida_en' => now(),
                'recibida_por' => auth()->guard('admin')->id(),
            ]);

            return $fresca;
        });
    }

    /**
     * Compensa la reasignacion: las unidades vuelven al origen (cancelada si aun estaban en transito, revertida si ya
     * se habian aplicado). Solo mientras el destino conserve el saldo sin reportar. No borra ENT ni ingresos.
     */
    public function revertir(Reasignacion $reasignacion, string $motivo, ?string $constanciaDevolucion = null, bool $devolucionFisicaConfirmada = false): Reasignacion
    {
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => trans('corteconfeccion::app.errors.reasignacion-motivo-requerido')]);
        }

        return $this->bajoBloqueo($reasignacion, function (Reasignacion $fresca, OrdenConfeccion $origen, OrdenConfeccion $destino) use ($motivo, $constanciaDevolucion, $devolucionFisicaConfirmada) {
            $estado = EstadoReasignacion::tryFrom((string) $fresca->estado);

            if (! $estado?->vigente()) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.reasignacion-no-revertible')]);
            }

            // Custodia fisica: si las prendas SALIERON del taller origen, devolverlas al saldo operativo del origen exige
            // la constancia de que estan de vuelta (quien/que traslado) y la confirmacion explicita; pulsar el boton no basta.
            $salieronFisicamente = $fresca->situacion_fisica === SituacionFisicaReasignacion::EntregadaVerificada->value;
            $constancia = trim((string) $constanciaDevolucion);

            if ($salieronFisicamente) {
                if (! $devolucionFisicaConfirmada) {
                    throw ValidationException::withMessages(['devolucion_fisica_confirmada' => trans('corteconfeccion::app.errors.reasignacion-devolucion-sin-confirmar')]);
                }

                if ($constancia === '') {
                    throw ValidationException::withMessages(['constancia_devolucion' => trans('corteconfeccion::app.errors.reasignacion-constancia-requerida')]);
                }
            }

            // R18: no se devuelve a una orden cerrada/anulada ni se reabre nada.
            if (in_array($origen->estado, [
                EstadoOrdenConfeccion::Cerrada->value,
                EstadoOrdenConfeccion::Anulada->value,
                EstadoOrdenConfeccion::RecibidaBodega->value,
            ], true)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.reasignacion-origen-cerrado')]);
            }

            $enTransito = $estado === EstadoReasignacion::EnTransito;

            $detallesDestino = OrdenConfeccionDetalle::query()
                ->whereIn('id', $fresca->lineas->pluck('detalle_destino_id'))
                ->with('retornosDetalles')
                ->get()
                ->keyBy('id');

            // R17: si el destino ya trabajo/reporto (o cedio a un tercero) las unidades, no hay reversion simple.
            foreach ($fresca->lineas as $linea) {
                $detalleDestino = $detallesDestino->get($linea->detalle_destino_id);
                $libre = $enTransito
                    ? min((int) $detalleDestino->cantidad_en_transito, $detalleDestino->cantidad_vigente)
                    : $detalleDestino->pendientePorReportar();

                if ($linea->cantidad > $libre) {
                    throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.reasignacion-destino-ya-trabajo')]);
                }
            }

            foreach ($fresca->lineas as $linea) {
                $detalleDestino = $detallesDestino->get($linea->detalle_destino_id);
                $detalleDestino->update([
                    'cantidad_reasignada_salida' => (int) $detalleDestino->cantidad_reasignada_salida + $linea->cantidad,
                    'cantidad_en_transito' => $enTransito ? max((int) $detalleDestino->cantidad_en_transito - $linea->cantidad, 0) : (int) $detalleDestino->cantidad_en_transito,
                ]);

                // P1: vuelve al origen el reparto REGISTRADO en la linea (no las prioridades que el destino tenga hoy),
                // sin superar el saldo del origen. Lineas anteriores a P1 (null): lo que el destino pierde, como antes.
                if ($linea->cantidad_urgente !== null || $linea->cantidad_alta !== null) {
                    $registrado = ['urgente' => (int) $linea->cantidad_urgente, 'alta' => (int) $linea->cantidad_alta];
                    $this->prioridadConfeccionService->descontar($detalleDestino, $registrado);
                    $prioridadDevuelta = $registrado;
                } else {
                    $prioridadDevuelta = $this->prioridadConfeccionService->recortar($detalleDestino);
                }

                $detalleOrigen = OrdenConfeccionDetalle::query()->findOrFail($linea->detalle_origen_id);
                $detalleOrigen->update(['cantidad_reasignada_salida' => max((int) $detalleOrigen->cantidad_reasignada_salida - $linea->cantidad, 0)]);
                $this->prioridadConfeccionService->sumar($detalleOrigen, $prioridadDevuelta);
            }

            $fresca->update([
                'estado' => $enTransito ? EstadoReasignacion::Cancelada->value : EstadoReasignacion::Revertida->value,
                'cerrada_en' => now(),
                'cerrada_por' => auth()->guard('admin')->id(),
                'motivo_cierre' => trim($motivo),
                'constancia_cierre' => $constancia !== '' ? $constancia : null,
            ]);

            return $fresca;
        });
    }

    /**
     * Bloquea recepcion -> ordenes (id ascendente, orden determinista) y entrega la reasignacion FRESCA.
     *
     * @param  callable(Reasignacion, OrdenConfeccion, OrdenConfeccion): Reasignacion  $operacion
     */
    protected function bajoBloqueo(Reasignacion $reasignacion, callable $operacion): Reasignacion
    {
        return DB::transaction(function () use ($reasignacion, $operacion) {
            RecepcionCorte::query()->where('orden_corte_id', $reasignacion->orden_corte_id)->lockForUpdate()->first();

            $ids = collect([$reasignacion->orden_confeccion_origen_id, $reasignacion->orden_confeccion_destino_id])->sort()->values();

            $ordenes = OrdenConfeccion::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $fresca = Reasignacion::query()->lockForUpdate()->with('lineas')->findOrFail($reasignacion->id);

            $origen = $ordenes->get($fresca->orden_confeccion_origen_id);
            $destino = $ordenes->get($fresca->orden_confeccion_destino_id);

            $resultado = $operacion($fresca, $origen, $destino);

            $this->ordenConfeccionService->recalcularEstado($origen->refresh());
            $this->ordenConfeccionService->recalcularEstado($destino->refresh());

            return $resultado->refresh()->load(['lineas', 'ordenOrigen', 'ordenDestino', 'tallerOrigen', 'tallerDestino']);
        }, 3);
    }

    /**
     * Validaciones que no dependen de la base: forma del payload, enteros estrictos, motivo, situacion fisica y hash
     * canonico de idempotencia.
     *
     * @return array<string, mixed>
     */
    protected function normalizar(array $data): array
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '' || mb_strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => trans('corteconfeccion::app.errors.reasignacion-llave-invalida')]);
        }

        $motivo = trim((string) ($data['motivo'] ?? ''));

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => trans('corteconfeccion::app.errors.reasignacion-motivo-requerido')]);
        }

        $situacion = SituacionFisicaReasignacion::tryFrom((string) ($data['situacion_fisica'] ?? ''));

        if (! $situacion) {
            throw ValidationException::withMessages(['situacion_fisica' => trans('corteconfeccion::app.errors.reasignacion-situacion-invalida')]);
        }

        $traslado = (string) ($data['traslado'] ?? 'completado');

        if (! in_array($traslado, ['completado', 'en_transito'], true)) {
            throw ValidationException::withMessages(['traslado' => trans('corteconfeccion::app.errors.reasignacion-situacion-invalida')]);
        }

        if ($situacion === SituacionFisicaReasignacion::SinEntregaFisica) {
            // Sin entrega fisica no hay nada que trasladar: no existe «en transito».
            $traslado = 'completado';
        } elseif (($data['confirmacion_fisica'] ?? false) !== true && ($data['confirmacion_fisica'] ?? null) !== '1' && ($data['confirmacion_fisica'] ?? null) !== 1) {
            throw ValidationException::withMessages(['confirmacion_fisica' => trans('corteconfeccion::app.errors.reasignacion-confirmacion-requerida')]);
        }

        $entradas = array_values((array) ($data['lineas'] ?? []));

        if ($entradas === []) {
            throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.reasignacion-sin-lineas')]);
        }

        $lineas = [];

        foreach ($entradas as $entrada) {
            $detalleId = $this->entero($entrada['detalle_id'] ?? null);
            $cantidad = $this->entero($entrada['cantidad'] ?? null);
            $enProceso = array_key_exists('cantidad_en_proceso', $entrada) && $entrada['cantidad_en_proceso'] !== '' && $entrada['cantidad_en_proceso'] !== null
                ? $this->entero($entrada['cantidad_en_proceso'])
                : 0;

            if (! $detalleId || ! $cantidad || $cantidad < 1 || $enProceso === null) {
                throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.reasignacion-cantidad-invalida')]);
            }

            if (isset($lineas[$detalleId])) {
                throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.reasignacion-detalle-repetido')]);
            }

            // Reparto por prioridad tal como llego (lo valida PrioridadConfeccionService bajo bloqueo); entra al hash.
            $reparto = array_filter(
                array_intersect_key($entrada, array_flip(['urgente', 'alta', 'normal', 'base'])),
                fn ($valor) => is_scalar($valor) && $valor !== ''
            );
            ksort($reparto);

            $lineas[$detalleId] = ['detalle_id' => $detalleId, 'cantidad' => $cantidad, 'en_proceso' => $enProceso, 'reparto' => array_map('strval', $reparto)];
        }

        ksort($lineas);

        $origenId = $this->entero($data['orden_confeccion_origen_id'] ?? null) ?: 0;
        $destinoId = $this->entero($data['taller_destino_id'] ?? null) ?: 0;
        $observacion = isset($data['observacion']) && trim((string) $data['observacion']) !== '' ? trim((string) $data['observacion']) : null;

        $hash = hash('sha256', json_encode([
            'origen' => $origenId,
            'destino' => $destinoId,
            'situacion' => $situacion->value,
            'traslado' => $traslado,
            'motivo' => $motivo,
            'observacion' => $observacion,
            'lineas' => array_values($lineas),
        ], JSON_UNESCAPED_UNICODE));

        return [
            'origen_id' => $origenId,
            'taller_destino_id' => $destinoId,
            'key' => $key,
            'motivo' => $motivo,
            'observacion' => $observacion,
            'situacion_fisica' => $situacion->value,
            'traslado' => $traslado,
            'lineas' => array_values($lineas),
            'hash' => $hash,
        ];
    }

    /**
     * Cantidades por linea contra el estado fresco (bajo bloqueo): detalle propio de la orden, variante simple y
     * cantidad + en proceso declarado <= elegible. Todo o nada: cualquier linea invalida rechaza la operacion.
     *
     * @param  array<int, array{detalle_id: int, cantidad: int, en_proceso: int}>  $lineas
     */
    protected function validarLineas(array $lineas, Collection $detalles): void
    {
        foreach ($lineas as $linea) {
            if (! $detalles->has($linea['detalle_id'])) {
                throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.detalle-no-pertenece-orden')]);
            }
        }

        $this->ordenConfeccionService->validarProductosSimples(
            collect($lineas)->map(fn (array $linea) => $detalles->get($linea['detalle_id'])->product_id)
        );

        foreach ($lineas as $linea) {
            $detalle = $detalles->get($linea['detalle_id']);

            if (! $detalle->product_id) {
                throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.detalle-no-pertenece-orden')]);
            }

            if ($detalle->pendientePorReportar() < $linea['cantidad'] + $linea['en_proceso']) {
                throw ValidationException::withMessages(['lineas' => trans('corteconfeccion::app.errors.reasignacion-excede-elegible')]);
            }
        }
    }

    /** Entero estricto: acepta int o texto de solo digitos; rechaza decimales, negativos y basura. */
    protected function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 0 ? $valor : null;
        }

        if (is_string($valor) && preg_match('/^\d{1,9}$/', trim($valor))) {
            return (int) trim($valor);
        }

        return null;
    }

    protected function generarCodigo(): string
    {
        $ultimo = (int) DB::table('bylopez_cc_reasignaciones')
            ->where('codigo', 'regexp', '^RA-[0-9]{6}$')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)) as consecutivo")
            ->lockForUpdate()
            ->value('consecutivo');

        do {
            $ultimo++;
            $codigo = sprintf('RA-%06d', $ultimo);
        } while (DB::table('bylopez_cc_reasignaciones')->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
