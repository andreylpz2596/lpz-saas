<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenTarifa;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tarifa por combinacion tipo de tela + referencia DENTRO de una orden (Doc. 05).
 *
 * Como se identifica la combinacion (sin texto, solo relaciones existentes):
 *  - Tipo de tela: `rollo.tipo_tela_id` de los rollos de la orden de corte (la de confeccion usa los de su orden de
 *    corte). Una orden de corte exige un unico tipo de tela; si hubiera mas de uno, la tela queda sin determinar.
 *  - Referencia: `products.attribute_family_id` del `product_id` de cada detalle (corte: detalles; confeccion: detalles).
 *
 * Una orden mixta conserva una fila por combinacion. La fila se crea cuando la combinacion aparece en la orden y copia la
 * tarifa vigente de la ficha en ese momento (o queda «Sin tarifa»). Despues solo cambia si alguien con permiso la pacta
 * para esa orden, con motivo. Cambiar la ficha no toca estas filas.
 */
class OrdenTarifaService
{
    public function __construct(protected TarifaPrestadorService $tarifaService) {}

    /**
     * Crea la fila de las combinaciones de la orden que aun no la tienen. Idempotente; llamar dentro de la misma
     * transaccion que crea la orden o agrega detalles.
     */
    public function sincronizar(OrdenConfeccion|OrdenCorte $orden): void
    {
        $prestador = $this->prestadorDe($orden);

        if (! $prestador) {
            return;
        }

        foreach ($this->combinaciones($orden)['lineas'] as $linea) {
            $existente = $orden->tarifas()
                ->where('tipo_tela_id', $linea['tipo_tela_id'])
                ->where('attribute_family_id', $linea['attribute_family_id'])
                ->exists();

            if ($existente) {
                continue;
            }

            $tarifa = $this->tarifaService->vigente($prestador, $linea['tipo_tela_id'], $linea['attribute_family_id']);

            $orden->tarifas()->create([
                'tipo_tela_id' => $linea['tipo_tela_id'],
                'attribute_family_id' => $linea['attribute_family_id'],
                'tarifa_unitaria' => $tarifa?->valor_unitario,
                'tarifa_prestador_id' => $tarifa?->id,
                'fijada_por_admin_id' => auth()->guard('admin')->id(),
                'fijada_en' => now(),
            ]);
        }
    }

    /**
     * Combinaciones presentes en la orden con su cantidad, mas lo que no se pudo identificar.
     *
     * @return array{lineas: list<array{tipo_tela_id: int, attribute_family_id: int, cantidad: int}>, sin_identificar: list<string>}
     */
    public function combinaciones(OrdenConfeccion|OrdenCorte $orden): array
    {
        $ordenCorte = $orden instanceof OrdenConfeccion ? $orden->ordenCorte : $orden;

        $tipoTelaId = $this->tipoTelaDe($ordenCorte);

        $detalles = $orden instanceof OrdenConfeccion
            ? $orden->detalles()->selectRaw('product_id, attribute_family_id, '.OrdenConfeccionDetalle::SQL_VIGENTE.' as cantidad')->get()
            : $orden->detalles()->get(['product_id', 'attribute_family_id', 'cantidad_real_cortada as cantidad']);

        $familias = DB::table('products')
            ->whereIn('id', $detalles->pluck('product_id')->filter()->unique())
            ->pluck('attribute_family_id', 'id');

        $sinIdentificar = [];

        if (! $tipoTelaId) {
            $sinIdentificar[] = 'tipo de tela: los rollos de la orden de corte no tienen un unico tipo de tela vinculado';
        }

        if ($detalles->contains(fn ($detalle) => ! $detalle->product_id)) {
            $sinIdentificar[] = 'referencia: hay detalles sin product_id';
        }

        $lineas = $tipoTelaId
            ? $detalles
                // H07: la familia CONSERVADA al crear el detalle manda; la actual del catalogo solo es respaldo.
                ->filter(fn ($detalle) => $detalle->product_id && ($detalle->attribute_family_id ?: $familias->get($detalle->product_id)))
                ->groupBy(fn ($detalle) => (int) ($detalle->attribute_family_id ?: $familias->get($detalle->product_id)))
                ->map(fn (Collection $grupo, $familiaId) => [
                    'tipo_tela_id' => $tipoTelaId,
                    'attribute_family_id' => (int) $familiaId,
                    'cantidad' => (int) $grupo->sum('cantidad'),
                ])
                ->values()
                ->all()
            : [];

        return ['lineas' => $lineas, 'sin_identificar' => $sinIdentificar];
    }

    /**
     * Datos listos para la vista: una linea por combinacion con su tarifa conservada, cantidad e importe referencial.
     * La cantidad es la asignada (confeccion) o cortada (corte); la cantidad PAGABLE la define la recepcion de Bodega
     * (Fase 3), por eso el total es solo referencial.
     *
     * @return array{lineas: list<array<string, mixed>>, sin_identificar: list<string>, total_referencial: float, lineas_sin_tarifa: int, cantidad_label: string}
     */
    public function resumen(OrdenConfeccion|OrdenCorte $orden): array
    {
        $combinaciones = $this->combinaciones($orden);
        $filas = $orden->tarifas()->get()->keyBy(fn (OrdenTarifa $t) => $t->tipo_tela_id.':'.$t->attribute_family_id);

        $nombresTela = DB::table('attribute_options')->whereIn('id', collect($combinaciones['lineas'])->pluck('tipo_tela_id'))->pluck('admin_name', 'id');
        $nombresFamilia = DB::table('attribute_families')->whereIn('id', collect($combinaciones['lineas'])->pluck('attribute_family_id'))->pluck('name', 'id');

        $lineas = collect($combinaciones['lineas'])->map(function (array $linea) use ($filas, $nombresTela, $nombresFamilia) {
            $fila = $filas->get($linea['tipo_tela_id'].':'.$linea['attribute_family_id']);
            $tarifa = $fila?->tarifa_unitaria !== null ? (float) $fila->tarifa_unitaria : null;

            return [
                'tipo_tela_id' => $linea['tipo_tela_id'],
                'attribute_family_id' => $linea['attribute_family_id'],
                'tipo_tela' => $nombresTela->get($linea['tipo_tela_id']),
                'referencia' => $nombresFamilia->get($linea['attribute_family_id']),
                'cantidad' => $linea['cantidad'],
                'tarifa' => $tarifa,
                'origen' => $fila?->origen() ?? 'sin_tarifa',
                'motivo' => $fila?->motivo,
                'importe' => $tarifa !== null ? round($tarifa * $linea['cantidad'], 2) : null,
            ];
        })->values();

        return [
            'lineas' => $lineas->all(),
            'sin_identificar' => $combinaciones['sin_identificar'],
            'total_referencial' => (float) $lineas->sum(fn (array $linea) => $linea['importe'] ?? 0),
            'lineas_sin_tarifa' => $lineas->where('tarifa', null)->count(),
            'cantidad_label' => $orden instanceof OrdenConfeccion ? 'Asignadas' : 'Cortadas',
        ];
    }

    /**
     * Pacta o cambia la tarifa de UNA combinacion de la orden (p. ej. descuento por volumen) sin tocar la ficha ni otras
     * ordenes. Tambien fija la tarifa de una combinacion que quedo «Sin tarifa». Motivo obligatorio, queda auditado.
     */
    public function pactar(OrdenConfeccion|OrdenCorte $orden, int $tipoTelaId, int $attributeFamilyId, float|string $valor, string $motivo, ?int $actorId): void
    {
        DB::transaction(function () use ($orden, $tipoTelaId, $attributeFamilyId, $valor, $motivo, $actorId) {
            $orden = $orden::query()->lockForUpdate()->findOrFail($orden->id);

            if (! TarifaPrestadorGate::ordenAdmiteTarifa($orden)) {
                throw ValidationException::withMessages(['valor_unitario' => trans('corteconfeccion::app.errors.tarifa-orden-anulada')]);
            }

            $presente = collect($this->combinaciones($orden)['lineas'])
                ->contains(fn (array $linea) => $linea['tipo_tela_id'] === $tipoTelaId && $linea['attribute_family_id'] === $attributeFamilyId);

            if (! $presente) {
                throw ValidationException::withMessages(['attribute_family_id' => trans('corteconfeccion::app.errors.tarifa-combinacion-no-en-orden')]);
            }

            $fila = $orden->tarifas()
                ->where('tipo_tela_id', $tipoTelaId)
                ->where('attribute_family_id', $attributeFamilyId)
                ->lockForUpdate()
                ->first();

            $anterior = $fila?->tarifa_unitaria !== null ? (float) $fila->tarifa_unitaria : null;

            if ($anterior !== null && abs($anterior - (float) $valor) < 0.00005) {
                throw ValidationException::withMessages(['valor_unitario' => trans('corteconfeccion::app.errors.tarifa-sin-cambio')]);
            }

            $datos = [
                'tarifa_unitaria' => $valor,
                'motivo' => trim($motivo),
                'fijada_por_admin_id' => $actorId,
                'fijada_en' => now(),
            ];

            // Se conserva `tarifa_prestador_id` (de que tarifa de la ficha se partio) para trazabilidad.
            $fila
                ? $fila->update($datos)
                : $orden->tarifas()->create($datos + ['tipo_tela_id' => $tipoTelaId, 'attribute_family_id' => $attributeFamilyId]);

            $prestador = $this->prestadorDe($orden);

            if ($prestador) {
                $this->tarifaService->evento($prestador, 'tarifa_orden_modificada', $actorId, $motivo, array_merge(
                    app(TarifaReferenciaCatalogo::class)->nombres($tipoTelaId, $attributeFamilyId),
                    ['anterior' => $anterior, 'nuevo' => (float) $valor]
                ), [
                    ($orden instanceof OrdenConfeccion ? 'orden_confeccion_id' : 'orden_corte_id') => $orden->id,
                    'tarifa_prestador_id' => $fila?->tarifa_prestador_id,
                ]);
            }
        });
    }

    /**
     * En las ordenes de corte `cortador_id` es el id del USUARIO administrador; la ficha se resuelve por
     * `cortadores.usuario_id`. Sin ficha no hay tarifa posible.
     */
    public function prestadorDe(OrdenConfeccion|OrdenCorte $orden): Taller|Cortador|null
    {
        if ($orden instanceof OrdenConfeccion) {
            return Taller::query()->find($orden->taller_id);
        }

        return $orden->cortador_id ? Cortador::query()->where('usuario_id', $orden->cortador_id)->first() : null;
    }

    /** Tipo de tela unico de los rollos de la orden de corte; null si no hay o hay mas de uno. */
    protected function tipoTelaDe(?OrdenCorte $ordenCorte): ?int
    {
        if (! $ordenCorte) {
            return null;
        }

        $tipos = DB::table('bylopez_cc_orden_corte_rollos as ocr')
            ->join('bylopez_cc_rollos as r', 'r.id', '=', 'ocr.rollo_id')
            ->where('ocr.orden_corte_id', $ordenCorte->id)
            ->whereNotNull('r.tipo_tela_id')
            ->distinct()
            ->pluck('r.tipo_tela_id');

        return $tipos->count() === 1 ? (int) $tipos->first() : null;
    }
}
