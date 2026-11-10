<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Repositories\OrdenCorteRepository;
use ByLopez\CorteConfeccion\Repositories\RolloRepository;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CompatibilidadRollosCorte;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrdenCorteService
{
    public function __construct(
        protected OrdenCorteRepository $ordenCorteRepository,
        protected RolloRepository $rolloRepository,
        protected RolloService $rolloService,
        protected ProductLookupService $productLookupService,
        protected CantidadCortadaAuditService $cantidadCortadaAuditService,
        protected SeleccionOrdenCorteService $seleccionService,
        protected OrdenTarifaService $ordenTarifaService,
    ) {}

    /**
     * F4: creacion V2 de la Orden de Corte. Define QUE se va a cortar
     * (productos exactos), no cuantas prendas: cantidad_proyectada = 0 siempre.
     *
     * El payload NO trae product_id: trae rollo_ids y `seleccion[]`
     * (attribute_family_id, color_option_id, eje_option_id, filtros?). Todo el
     * dominio se revalida aqui dentro de UNA transaccion (el FormRequest solo
     * valida estructura):
     *  1. bloqueo (lockForUpdate) y carga de los rollos;
     *  2. existencia, disponibilidad/reserva, tipo_tela_id y color_option_id;
     *  3. mismo tipo_tela_id; colores permitidos = solo los de esos rollos;
     *  4. duplicados y colores ajenos;
     *  5. resolverLote() de TODA la seleccion (una carga de catalogo);
     *  6. cualquier error bloquea y revierte TODO (ni orden, ni pivotes, ni
     *     reservas parciales);
     *  7. orden, pivotes, reserva de rollos y un OrdenCorteDetalle por simple.
     */
    public function crearDesdeSeleccion(array $data): OrdenCorte
    {
        $this->validarAutorizacionSolicitud();

        return DB::transaction(function () use ($data) {
            $rollos = $this->rollosValidadosParaCorte($data['rollo_ids'] ?? [], bloquear: true);

            $resolucion = $this->seleccionService->resolver($rollos, (array) ($data['seleccion'] ?? []));

            if ($resolucion['errores']) {
                throw ValidationException::withMessages(['seleccion' => $resolucion['errores']]);
            }

            $filas = collect($resolucion['filas'])->map(fn (array $fila) => [
                'product_id' => $fila['product_id'],
                'variant_id' => null,
                'producto_sku' => $fila['sku'],
                'producto_nombre' => $fila['nombre'],
                'genero_linea' => $fila['linea'],
                'color' => $fila['color'],
                'talla' => (string) $fila['talla'],
                'cantidad_proyectada' => 0,
                'observacion' => null,
            ])->all();

            $lineas = collect($resolucion['filas'])->pluck('linea')->unique()->implode(', ');

            return $this->persistirOrden($rollos, $data, $filas, $lineas);
        });
    }

    /**
     * Contrato INTERNO legacy: detalles[] con product_id + cantidad_proyectada
     * (lo usan los fixtures de tests y llamadas internas). El flujo oficial de
     * la pantalla es crearDesdeSeleccion(). Comparte con el V2 la validacion de
     * rollos (mismo tipo_tela_id) y la persistencia.
     */
    public function create(array $data): OrdenCorte
    {
        $this->validarAutorizacionSolicitud();

        return DB::transaction(function () use ($data) {
            $rollos = $this->rollosValidadosParaCorte($data['rollo_ids'], bloquear: true);

            $detalles = $data['detalles'];

            $filas = collect($detalles)->map(fn (array $detalle) => array_merge(
                $this->productLookupService->snapshot((int) ($detalle['product_id'] ?? 0)),
                [
                    'variant_id' => $detalle['variant_id'] ?? null,
                    'genero_linea' => $detalle['genero_linea'] ?? null,
                    'color' => null,
                    'talla' => $detalle['talla'] ?? '',
                    'cantidad_proyectada' => $detalle['cantidad_proyectada'] ?? 0,
                    'observacion' => $detalle['observacion'] ?? null,
                ]
            ))->all();

            return $this->persistirOrden($rollos, $data, $filas, $this->resumenGeneroLinea($detalles));
        });
    }

    protected function validarAutorizacionSolicitud(): void
    {
        if (! CantidadCortadaGate::userCanGestionarSolicitud()) {
            throw ValidationException::withMessages([
                'solicitud' => trans('corteconfeccion::app.errors.solicitud-no-autorizada'),
            ]);
        }
    }

    /**
     * Rollos de la orden ya BLOQUEADOS y validados: existen, estan disponibles
     * y no reservados en otra orden abierta, tienen tipo_tela_id y
     * color_option_id, y comparten el mismo tipo_tela_id. Tambien lo usan la
     * matriz/previsualizacion (sin bloqueo).
     */
    public function rollosValidadosParaCorte(array $rolloIds, bool $bloquear = false): Collection
    {
        $ids = collect($rolloIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
        }

        $rollos = $this->rollosSeleccionados($ids, $bloquear);

        $this->validarRollosDisponibles($rollos, $ids);
        $this->validarCompatibilidadRollos($rollos);

        return $rollos;
    }

    /**
     * Crea la orden, los pivotes (peso completo del rollo), reserva los rollos y
     * crea los detalles. Debe llamarse dentro de la transaccion del create.
     *
     * OrdenCorte.color y OrdenCorte.genero_linea son RESUMENES de lectura
     * (colores de los rollos / lineas de los detalles); la fuente normativa son
     * los rollos (pivote) y OrdenCorteDetalle.
     *
     * @param  list<array<string, mixed>>  $filas  cada una: product_id, variant_id, producto_sku, producto_nombre, genero_linea, color, talla, cantidad_proyectada, observacion
     */
    protected function persistirOrden(Collection $rollos, array $data, array $filas, string $resumenLineas): OrdenCorte
    {
        $pesoTotalRollos = round((float) $rollos->sum(fn ($rollo) => (float) $rollo->peso_disponible), 3);
        $primerRollo = $rollos->first();
        $cortador = CortadorResolver::find((int) ($data['cortador_id'] ?? 0));

        if (! $cortador) {
            throw ValidationException::withMessages([
                'cortador_id' => 'El cortador seleccionado no tiene el rol cortador.',
            ]);
        }

        $cortadorNombreSnapshot = CortadorResolver::snapshotName($cortador);

        $orden = $this->ordenCorteRepository->create([
            'cortador_id' => $data['cortador_id'],
            'observacion' => $data['observacion'] ?? null,
            'codigo' => $this->generarCodigoOrden($cortadorNombreSnapshot),
            'rollo_id' => $primerRollo->id,
            'cortador_nombre' => $cortadorNombreSnapshot,
            'cortador_nombre_snapshot' => $cortadorNombreSnapshot,
            'color' => Str::limit($rollos->pluck('color')->filter()->unique()->implode(', '), 250, ''),
            'genero_linea' => Str::limit($resumenLineas !== '' ? $resumenLineas : 'Mixto', 250, ''),
            'fecha_solicitud' => now()->toDateString(),
            'fecha_entrega' => null,
            'estado' => EstadoOrdenCorte::PendienteEntrega->value,
            'peso_entregado' => 0,
            'peso_total_rollos' => $pesoTotalRollos,
            'peso_sobrante_total' => 0,
            'merma_total' => 0,
            'peso_consumido_total' => 0,
            'total_piezas_cortadas' => 0,
            'peso_promedio_pieza' => 0,
            'creado_por_id' => auth()->guard('admin')->id(),
        ]);

        foreach ($rollos as $rollo) {
            $orden->rollosCorte()->create([
                'rollo_id' => $rollo->id,
                'peso_inicial_usado' => $rollo->peso_disponible,
                'entregado_en' => null,
                'peso_sobrante' => 0,
                'peso_consumido' => 0,
            ]);

            $rollo->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);
        }

        foreach ($filas as $fila) {
            $orden->detalles()->create(array_merge($fila, [
                'cantidad_real_cortada' => 0,
                'es_adicional' => false,
            ]));
        }

        $this->ordenTarifaService->sincronizar($orden);

        return $orden->refresh();
    }

    /**
     * Registra el RESULTADO REAL del corte, reportado por el cortador:
     * - "resultados": cantidad_real_cortada para filas YA EXISTENTES de la
     *   solicitud (identificadas por detalle_id). Nunca se permite cambiar
     *   producto/talla/genero de esas filas aqui, solo la cantidad: asi se
     *   garantiza por diseno que el cortador no modifica la solicitud original.
     * - "adicionales": filas NUEVAS (producto/talla no solicitados), creadas
     *   con cantidad_proyectada=0 y es_adicional=true.
     * Transiciona la orden de en_corte a reportado_por_cortador.
     */
    public function reportarResultado(OrdenCorte $orden, array $data): OrdenCorte
    {
        return DB::transaction(function () use ($orden, $data) {
            // H10: estado FRESCO bajo bloqueo de la fila: una instancia cargada antes de otra transaccion
            // (doble envio, dos usuarios) no puede decidir con datos viejos.
            OrdenCorte::query()->lockForUpdate()->findOrFail($orden->id);
            $orden->refresh();
            $orden->load('detalles');

            if (! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, EstadoOrdenCorte::ReportadoPorCortador->value)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.transicion-invalida')]);
            }

            if (! CantidadCortadaGate::userCanEdit(null, $orden)) {
                throw ValidationException::withMessages([
                    'cantidad_cortada' => trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'),
                ]);
            }

            $detallesPorId = $orden->detalles->keyBy('id');

            // F5: los adicionales se resuelven ANTES de escribir cualquier cosa
            // (si algo falla, ni cantidades ni detalles ni estado cambian).
            $filasAdicionales = $this->resolverAdicionales($orden, (array) ($data['adicionales'] ?? []));

            foreach ($data['resultados'] ?? [] as $resultado) {
                $detalleOrden = $detallesPorId->get((int) ($resultado['detalle_id'] ?? 0));

                if (! $detalleOrden) {
                    throw ValidationException::withMessages([
                        'resultados' => 'Una de las filas reportadas no pertenece a esta orden.',
                    ]);
                }

                $valorAnterior = (int) $detalleOrden->cantidad_real_cortada;
                $valorNuevo = (int) ($resultado['cantidad_real_cortada'] ?? 0);

                $detalleOrden->update(['cantidad_real_cortada' => $valorNuevo]);

                $this->cantidadCortadaAuditService->record($detalleOrden, $valorAnterior, $valorNuevo);
            }

            foreach ($filasAdicionales as $fila) {
                $detalleAdicional = $orden->detalles()->create($fila);

                $this->cantidadCortadaAuditService->record($detalleAdicional, 0, (int) $fila['cantidad_real_cortada']);
            }

            // Un adicional puede traer una referencia que la solicitud original no tenia.
            $this->ordenTarifaService->sincronizar($orden);

            $orden->update([
                'estado' => EstadoOrdenCorte::ReportadoPorCortador->value,
                'reportado_por_id' => auth()->guard('admin')->id(),
                'reportado_en' => now(),
            ]);

            return $orden->refresh()->load('detalles');
        });
    }

    /**
     * F5: resuelve los adicionales del cortador con la semantica de F4
     * (SeleccionOrdenCorteService -> resolverLote, una carga de catalogo).
     * Tela y colores permitidos salen de los rollos de la orden; product_id no
     * se lee. Un adicional repetido (misma linea/color/talla) se RECHAZA.
     * Devuelve filas listas para crear (mismos snapshots que F4) o lanza
     * ValidationException.
     *
     * @param  list<array<string, mixed>>  $adicionales
     * @return list<array<string, mixed>>
     */
    public function resolverAdicionales(OrdenCorte $orden, array $adicionales): array
    {
        if ($adicionales === []) {
            return [];
        }

        $adicionales = array_values($adicionales);

        foreach ($adicionales as $i => $adicional) {
            if ((int) ($adicional['cantidad_real_cortada'] ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'adicionales' => trans('corteconfeccion::app.errors.adicional-cantidad-invalida', ['fila' => $i + 1]),
                ]);
            }
        }

        $resolucion = $this->seleccionService->resolver($this->rollosDeOrden($orden), $adicionales);

        if ($resolucion['errores']) {
            throw ValidationException::withMessages(['adicionales' => $resolucion['errores']]);
        }

        return collect($resolucion['filas'])->map(fn (array $fila) => [
            'product_id' => $fila['product_id'],
            'variant_id' => null,
            'producto_sku' => $fila['sku'],
            'producto_nombre' => $fila['nombre'],
            'genero_linea' => $fila['linea'],
            'color' => $fila['color'],
            'talla' => (string) $fila['talla'],
            'cantidad_proyectada' => 0,
            'cantidad_real_cortada' => (int) $adicionales[$fila['indice']]['cantidad_real_cortada'],
            'es_adicional' => true,
            'observacion' => null,
        ])->all();
    }

    /** Rollos de la orden para consultar adicionales; solo si aun se puede reportar (maquina de estados). */
    public function rollosParaAdicionales(OrdenCorte $orden): Collection
    {
        if (! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, EstadoOrdenCorte::ReportadoPorCortador->value)) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.transicion-invalida')]);
        }

        return $this->rollosDeOrden($orden);
    }

    /** Rollos vinculados a la orden (fuente de tela y colores permitidos). */
    public function rollosDeOrden(OrdenCorte $orden): Collection
    {
        return $orden->rollosCorte()->with('rollo')->get()->pluck('rollo')->filter()->unique('id')->values();
    }

    /**
     * Entrega la orden al cortador. El peso entregado ya no es un dato que el
     * usuario escribe: siempre es la suma del peso reservado de los rollos
     * asignados a la orden (el sistema ya lo conoce).
     */
    public function entregar(OrdenCorte $orden): OrdenCorte
    {
        return DB::transaction(function () use ($orden) {
            $orden->load(['rollo', 'rollosCorte.rollo']);

            if (! $this->puedeEntregarRollos($orden)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-no-pendiente')]);
            }

            $rollosPendientes = $orden->rollosCorte
                ->filter(fn ($ordenRollo) => ! $ordenRollo->entregado_en)
                ->values();

            if ($rollosPendientes->isEmpty()) {
                throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
            }

            $rolloInvalido = $rollosPendientes->contains(
                fn ($ordenRollo) => ! $ordenRollo->rollo
                    || $ordenRollo->rollo->estado !== EstadoRollo::ReservadoEnCorte->value
                    || (float) $ordenRollo->peso_inicial_usado <= 0
            );

            if ($rolloInvalido) {
                throw ValidationException::withMessages(['rollos' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
            }

            $entregadoEn = now();

            foreach ($rollosPendientes as $ordenRollo) {
                $ordenRollo->update(['entregado_en' => $entregadoEn]);
            }

            $orden->load('rollosCorte');

            $orden->update([
                'peso_entregado' => $this->pesoEntregadoOrden($orden),
                'fecha_entrega' => $orden->fecha_entrega ?: $entregadoEn->toDateString(),
                'estado' => $orden->estado === EstadoOrdenCorte::PendienteEntrega->value
                    ? EstadoOrdenCorte::EntregadoACortador->value
                    : $orden->estado,
            ]);

            return $orden->refresh();
        });
    }

    public function agregarDetalle(OrdenCorte $orden, array $data): OrdenCorte
    {
        return DB::transaction(function () use ($orden, $data) {
            $orden->load('detalles');

            $this->validarSolicitudEditable($orden);

            // H05: este camino antiguo no puede esquivar el contrato V2 (variante simple de la tela y color de los rollos).
            $this->validarProductoDelContrato($orden, (int) ($data['product_id'] ?? 0));

            $orden->detalles()->create(array_merge(
                $this->productLookupService->snapshot((int) ($data['product_id'] ?? 0)),
                [
                    'variant_id' => $data['variant_id'] ?? null,
                    'genero_linea' => $data['genero_linea'] ?? null,
                    'talla' => $data['talla'] ?? '',
                    'cantidad_proyectada' => $data['cantidad_proyectada'] ?? 0,
                    'cantidad_real_cortada' => 0,
                    'es_adicional' => false,
                    'observacion' => $data['observacion'] ?? null,
                ]
            ));

            $orden->update([
                'genero_linea' => $this->resumenGeneroLinea($orden->fresh('detalles')->detalles->toArray()),
            ]);

            $this->ordenTarifaService->sincronizar($orden);

            return $orden->refresh();
        });
    }

    /**
     * H05: un detalle agregado por el camino antiguo (POST view/{id}/detalles) debe ser una variante SIMPLE (hija de un
     * configurable) cuyo tipo de tela (en el padre) y color (en la variante) coincidan con los rollos de la orden.
     * Si el atributo no se puede verificar, falla cerrado.
     */
    protected function validarProductoDelContrato(OrdenCorte $orden, int $productId): void
    {
        $falla = fn () => ValidationException::withMessages([
            'product_id' => trans('corteconfeccion::app.errors.producto-fuera-del-contrato-corte'),
        ]);

        $producto = DB::table('products')->where('id', $productId)->first(['id', 'type', 'parent_id']);

        if (! $producto || $producto->type !== 'simple' || ! $producto->parent_id) {
            throw $falla();
        }

        $rollos = $this->rollosDeOrden($orden);
        $atributos = DB::table('attributes')->whereIn('code', ['color', 'tipo_tela'])->pluck('id', 'code');

        $valores = fn (int $id, string $code) => DB::table('product_attribute_values')
            ->where('product_id', $id)
            ->where('attribute_id', $atributos[$code] ?? 0)
            ->whereNotNull('integer_value')
            ->pluck('integer_value')
            ->map(fn ($v) => (int) $v);

        $coloresRollos = $rollos->pluck('color_option_id')->filter()->map(fn ($v) => (int) $v)->unique();
        $telasRollos = $rollos->pluck('tipo_tela_id')->filter()->map(fn ($v) => (int) $v)->unique();

        if ($coloresRollos->isNotEmpty() && $coloresRollos->intersect($valores($producto->id, 'color'))->isEmpty()) {
            throw $falla();
        }

        if ($telasRollos->isNotEmpty() && $telasRollos->intersect($valores((int) $producto->parent_id, 'tipo_tela'))->isEmpty()) {
            throw $falla();
        }
    }

    public function agregarRollo(OrdenCorte $orden, int $rolloId): OrdenCorte
    {
        return DB::transaction(function () use ($orden, $rolloId) {
            $orden->load(['rollosCorte.rollo']);

            $this->validarSolicitudEditable($orden);

            // PRE-F4.1: agregar rollos comparte el punto de no retorno del retiro:
            // desde en_corte la orden ya no cambia su conjunto de rollos.
            if (! OrdenCorteEstadoMachine::antesDeEnCorte($orden->estado)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.agregar-rollo-orden-iniciada')]);
            }

            $rollo = Rollo::query()->lockForUpdate()->find($rolloId);

            if (! $rollo) {
                throw ValidationException::withMessages(['rollo_id' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
            }

            $this->validarRollosDisponibles(collect([$rollo]), collect([$rollo->id]));
            $this->validarCompatibilidadNuevoRollo($orden, $rollo);

            $orden->rollosCorte()->create([
                'rollo_id' => $rollo->id,
                'peso_inicial_usado' => $rollo->peso_disponible,
                'entregado_en' => null,
                'peso_sobrante' => 0,
                'peso_consumido' => 0,
            ]);

            $rollo->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);

            $orden->update([
                'peso_total_rollos' => round((float) $orden->peso_total_rollos + (float) $rollo->peso_disponible, 3),
            ]);

            return $orden->refresh();
        });
    }

    /**
     * PRE-F4: retiro FORMAL de un rollo de la orden (unica forma de liberar un
     * rollo reservado para editarlo desde Compra de Tela). Solo antes del punto
     * de no retorno: mientras la orden aun puede llegar a "en_corte" (derivado de
     * OrdenCorteEstadoMachine::antesDeEnCorte, no de una lista aparte); desde
     * en_corte el rollo queda historicamente bloqueado.
     *
     * - Elimina el pivote (nunca un detach silencioso: es una accion explicita).
     * - El rollo vuelve al estado que le corresponde por peso (disponible o
     *   parcialmente_usado) solo si no sigue reservado en otra orden abierta;
     *   su peso_disponible no cambia (reservar nunca lo descuenta).
     * - Recalcula peso_total_rollos y peso_entregado con los rollos restantes.
     * - `ordenes_corte.rollo_id` es NOT NULL y apunta al primer rollo: si se retira
     *   ese, se reasigna al primer rollo restante (y se actualiza el snapshot
     *   `color`). El ultimo rollo NO puede retirarse: para eso se anula la orden.
     */
    public function retirarRollo(OrdenCorte $orden, int $rolloId): OrdenCorte
    {
        return DB::transaction(function () use ($orden, $rolloId) {
            // Estado fresco y bloqueado: no se retira contra un estado desactualizado.
            $orden = OrdenCorte::query()->lockForUpdate()->findOrFail($orden->id);

            if (! OrdenCorteEstadoMachine::antesDeEnCorte($orden->estado)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.retiro-rollo-orden-iniciada')]);
            }

            $pivotes = $orden->rollosCorte()->orderBy('id')->get();
            $pivote = $pivotes->firstWhere('rollo_id', $rolloId);

            if (! $pivote) {
                throw ValidationException::withMessages(['rollo_id' => trans('corteconfeccion::app.errors.retiro-rollo-no-pertenece')]);
            }

            if ($pivotes->count() <= 1) {
                throw ValidationException::withMessages(['rollo_id' => trans('corteconfeccion::app.errors.retiro-rollo-ultimo')]);
            }

            $rollo = Rollo::query()->lockForUpdate()->findOrFail($rolloId);

            $pivote->delete();

            $reservadoEnOtraOrden = DB::table('bylopez_cc_orden_corte_rollos as ocr')
                ->join('bylopez_cc_ordenes_corte as oc', 'ocr.orden_corte_id', '=', 'oc.id')
                ->where('ocr.rollo_id', $rolloId)
                ->whereNotIn('oc.estado', [EstadoOrdenCorte::Cerrado->value, EstadoOrdenCorte::Anulado->value])
                ->exists();

            if (! $reservadoEnOtraOrden && $rollo->estado === EstadoRollo::ReservadoEnCorte->value) {
                $rollo->update([
                    'estado' => $this->rolloService->estadoPorPeso((float) $rollo->peso_disponible, (float) $rollo->peso_inicial),
                ]);
            }

            $restantes = $pivotes->reject(fn ($ordenRollo) => $ordenRollo->id === $pivote->id)->values();

            $cambios = [
                'peso_total_rollos' => round((float) $restantes->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3),
                'peso_entregado' => round((float) $restantes
                    ->filter(fn ($ordenRollo) => (bool) $ordenRollo->entregado_en)
                    ->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3),
            ];

            if ((int) $orden->rollo_id === $rolloId) {
                $nuevoPrimero = $restantes->first();

                $cambios['rollo_id'] = $nuevoPrimero->rollo_id;
                $cambios['color'] = Rollo::query()->find($nuevoPrimero->rollo_id)?->color ?? $orden->color;
            }

            $orden->update($cambios);

            return $orden->refresh();
        });
    }

    public function cambiarEstado(OrdenCorte $orden, string $estadoEsperado, string $nuevoEstado): OrdenCorte
    {
        if ($orden->estado !== $estadoEsperado || ! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, $nuevoEstado)) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.transicion-invalida')]);
        }

        $orden->update(['estado' => $nuevoEstado]);

        return $orden->refresh();
    }

    public function cerrar(OrdenCorte $orden): OrdenCorte
    {
        return DB::transaction(function () use ($orden) {
            // H10: estado FRESCO bajo bloqueo de la fila: una instancia cargada antes de otra transaccion
            // (doble envio, dos usuarios) no puede decidir con datos viejos.
            OrdenCorte::query()->lockForUpdate()->findOrFail($orden->id);
            $orden->refresh();
            $orden->load(['rollosCorte.rollo', 'recepcion.detalles', 'detalles']);

            if (in_array($orden->estado, [EstadoOrdenCorte::Anulado->value, EstadoOrdenCorte::Cerrado->value], true)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-terminal')]);
            }

            if (! $orden->recepcion || ! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, EstadoOrdenCorte::Cerrado->value)) {
                throw ValidationException::withMessages(['recepcion' => trans('corteconfeccion::app.errors.orden-sin-recepcion')]);
            }

            // H08: lo VERIFICADO por bodega manda (es lo que la recepcion ya dejo en la orden); lo reportado por el
            // cortador permanece intacto en cada detalle y solo es el respaldo cuando no hay piezas recibidas.
            $totalPiezas = (int) $orden->recepcion->detalles->sum('cantidad_recibida');

            if ($totalPiezas <= 0) {
                $totalPiezas = (int) $orden->detalles->sum('cantidad_real_cortada');
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
                'peso_consumido_total' => $pesoConsumido,
                'total_piezas_cortadas' => $totalPiezas,
                'peso_promedio_pieza' => $totalPiezas > 0 ? round($pesoConsumido / $totalPiezas, 6) : 0,
                'estado' => EstadoOrdenCorte::Cerrado->value,
            ]);

            $this->actualizarRollosAlCerrar($orden->refresh());

            return $orden->refresh();
        });
    }

    public function cancel(OrdenCorte $orden): OrdenCorte
    {
        return DB::transaction(function () use ($orden) {
            $orden->load('rollosCorte.rollo');

            if (! OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, EstadoOrdenCorte::Anulado->value)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.orden-cerrada')]);
            }

            // PRE-F4.1: desde en_corte ya puede haber consumo fisico de tela; anular
            // devolveria los rollos a disponible con su peso original. No hay flujo
            // de reversion fisica todavia, asi que la anulacion queda bloqueada.
            if (OrdenCorteEstadoMachine::haIniciadoCorte($orden->estado)) {
                throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.anular-orden-iniciada')]);
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

    protected function rollosSeleccionados(Collection $rolloIds, bool $bloquear = true): Collection
    {
        return Rollo::query()
            ->whereIn('id', $rolloIds)
            ->when($bloquear, fn ($query) => $query->lockForUpdate())
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

    public function validarRollosDisponibles(Collection $rollos, Collection $rolloIds): void
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
        // F4: compatible = MISMO tipo_tela_id (color, gramaje, referencia, compra o
        // proveedor pueden variar); ademas tipo_tela_id y color_option_id obligatorios.
        CompatibilidadRollosCorte::validar($rollos);
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

    /**
     * Publico: tambien lo usa el controller para mostrar el peso a entregar
     * como dato informativo/calculado antes de confirmar la entrega.
     */
    public function pesoReservadoOrden(OrdenCorte $orden): float
    {
        if ($orden->rollosCorte->isNotEmpty()) {
            return round((float) $orden->rollosCorte->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
        }

        return (float) $orden->rollo?->peso_disponible;
    }

    public function pesoPendienteEntregaOrden(OrdenCorte $orden): float
    {
        return round((float) $orden->rollosCorte
            ->filter(fn ($ordenRollo) => ! $ordenRollo->entregado_en)
            ->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
    }

    protected function pesoEntregadoOrden(OrdenCorte $orden): float
    {
        return round((float) $orden->rollosCorte
            ->filter(fn ($ordenRollo) => (bool) $ordenRollo->entregado_en)
            ->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
    }

    protected function validarSolicitudEditable(OrdenCorte $orden): void
    {
        if (! $this->puedeEditarSolicitud($orden)) {
            throw ValidationException::withMessages(['estado' => trans('corteconfeccion::app.errors.transicion-invalida')]);
        }
    }

    public function puedeEditarSolicitud(OrdenCorte $orden): bool
    {
        return in_array($orden->estado, [
            EstadoOrdenCorte::PendienteEntrega->value,
            EstadoOrdenCorte::EntregadoACortador->value,
            EstadoOrdenCorte::EnCorte->value,
        ], true);
    }

    public function puedeEntregarRollos(OrdenCorte $orden): bool
    {
        return $this->puedeEditarSolicitud($orden);
    }

    protected function validarCompatibilidadNuevoRollo(OrdenCorte $orden, Rollo $rollo): void
    {
        $existentes = $orden->rollosCorte->map(fn ($ordenRollo) => $ordenRollo->rollo)->filter();

        if ($existentes->isEmpty()) {
            return;
        }

        // Misma regla unica que en la creacion (F4): mismo tipo_tela_id.
        CompatibilidadRollosCorte::validar($existentes->push($rollo));
    }

    protected function pesoBaseCierre(OrdenCorte $orden): float
    {
        $pesoBase = $orden->pesoBase();

        return $pesoBase > 0 ? $pesoBase : $this->pesoReservadoOrden($orden);
    }

    /**
     * Ordenes con recepcion F3 (`merma_unica`): la merma es UN total de la
     * orden y NO vuelve tela a ningun rollo. Ordenes historicas: cascada
     * original del sobrante usable. La bifurcacion es por el marcador
     * `merma_unica`, nunca por el valor de merma/sobrante (0 es un valor real).
     */
    protected function actualizarRollosAlCerrar(OrdenCorte $orden): void
    {
        $orden->loadMissing('rollosCorte.rollo');

        if ($orden->merma_unica) {
            $this->cerrarRollosSinRetorno($orden);

            return;
        }

        $this->repartirSobranteHistorico($orden);
    }

    /**
     * F3. Cada OrdenCorteRollo.peso_inicial_usado es el peso_disponible
     * COMPLETO del rollo al elegirlo, asi que todo ese peso salio fisicamente
     * del rollo. No existe sobrante reutilizable identificado, y la merma de
     * la orden NUNCA se atribuye a un rollo concreto sin evidencia fisica:
     * el rollo no recupera peso (peso_disponible queda en 0 -> agotado) y en
     * el pivote peso_consumido = todo lo que salio del rollo (incluye su parte
     * indeterminada de la merma; la merma total vive solo en la orden).
     */
    protected function cerrarRollosSinRetorno(OrdenCorte $orden): void
    {
        foreach ($orden->rollosCorte as $ordenRollo) {
            if (! $ordenRollo->rollo) {
                continue;
            }

            $ordenRollo->update([
                'peso_sobrante' => 0,
                'peso_consumido' => (float) $ordenRollo->peso_inicial_usado,
            ]);

            $ordenRollo->rollo->update([
                'peso_disponible' => 0,
                'estado' => $this->rolloService->estadoPorPeso(0, (float) $ordenRollo->rollo->peso_inicial),
            ]);
        }
    }

    /**
     * SOLO ordenes historicas (recepcion previa a F3, `merma_unica = false`):
     * `peso_sobrante_total` es el sobrante usable capturado por Bodega y se
     * reparte en cascada entre los rollos. Es inocuo unicamente porque en
     * esas ordenes todos los rollos eran fisicamente intercambiables.
     */
    protected function repartirSobranteHistorico(OrdenCorte $orden): void
    {
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
                'peso_sobrante' => $pesoSobrante,
                'peso_consumido' => $pesoConsumido,
            ]);

            $ordenRollo->rollo->update([
                'peso_disponible' => $pesoSobrante,
                'estado' => $this->rolloService->estadoPorPeso($pesoSobrante, (float) $ordenRollo->rollo->peso_inicial),
            ]);
        }
    }
}
