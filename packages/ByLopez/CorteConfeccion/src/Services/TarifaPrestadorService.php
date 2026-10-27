<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\CuentaEvento;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Models\TarifaPrestador;
use ByLopez\CorteConfeccion\Repositories\TarifaPrestadorRepository;
use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\User\Models\Admin;

/**
 * Tarifas por prenda de talleres y cortadores (Doc. 05). Cada tarifa es de una combinacion
 * prestador + tipo de tela + referencia del producto (familia Bagisto).
 *
 * - Historial inmutable por combinacion: cambiar una tarifa crea una vigencia nueva; corregir es anular y crear.
 * - Una vigencia rige desde `vigente_desde` hasta el dia anterior a la siguiente activa de la MISMA combinacion; la nueva
 *   siempre debe ser posterior a la ultima activa, por lo que nunca hay vigencias superpuestas.
 * - Las ordenes guardan su propia copia por combinacion (OrdenTarifaService): nada de lo que se haga aqui las altera.
 * - Las tarifas generales del primer intento (sin tela ni referencia) se conservan como historial y no se aplican nunca.
 */
class TarifaPrestadorService
{
    public function __construct(
        protected TarifaPrestadorRepository $tarifaRepository,
        protected TarifaReferenciaCatalogo $catalogo,
    ) {}

    /** Tarifa activa que rige hoy (o en la fecha dada) para la combinacion; null = «Sin tarifa». */
    public function vigente(Taller|Cortador $prestador, int $tipoTelaId, int $attributeFamilyId, ?CarbonInterface $fecha = null): ?TarifaPrestador
    {
        return $this->combinacion($prestador, $tipoTelaId, $attributeFamilyId)
            ->whereNull('anulada_en')
            ->whereDate('vigente_desde', '<=', ($fecha ?? now())->toDateString())
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Nueva vigencia de una combinacion (agregar la referencia o cambiar su tarifa).
     *
     * @param  array{tipo_tela_id: int|string, attribute_family_id: int|string, valor_unitario: float|string, vigente_desde: string, observacion?: ?string}  $data
     */
    public function crearVigencia(Taller|Cortador $prestador, array $data, ?int $actorId): TarifaPrestador
    {
        $tipoTelaId = (int) $data['tipo_tela_id'];
        $familiaId = (int) $data['attribute_family_id'];

        if (! $this->catalogo->existeCombinacion($tipoTelaId, $familiaId)) {
            throw ValidationException::withMessages([
                'attribute_family_id' => trans('corteconfeccion::app.errors.tarifa-combinacion-invalida'),
            ]);
        }

        return DB::transaction(function () use ($prestador, $data, $tipoTelaId, $familiaId, $actorId) {
            // Serializa las altas del mismo prestador para validar la vigencia sin carreras.
            $prestador->newQuery()->whereKey($prestador->getKey())->lockForUpdate()->first();

            $ultima = $this->combinacion($prestador, $tipoTelaId, $familiaId)
                ->whereNull('anulada_en')
                ->orderByDesc('vigente_desde')
                ->first();

            if ($ultima && $data['vigente_desde'] <= $ultima->vigente_desde->toDateString()) {
                throw ValidationException::withMessages([
                    'vigente_desde' => trans('corteconfeccion::app.errors.tarifa-vigencia-no-posterior', [
                        'fecha' => $ultima->vigente_desde->toDateString(),
                    ]),
                ]);
            }

            $tarifa = $this->tarifaRepository->create([
                $this->columna($prestador) => $prestador->getKey(),
                'tipo_tela_id' => $tipoTelaId,
                'attribute_family_id' => $familiaId,
                'valor_unitario' => $data['valor_unitario'],
                'vigente_desde' => $data['vigente_desde'],
                'observacion' => $this->limpiar($data['observacion'] ?? null),
                'creado_por_admin_id' => $actorId,
            ]);

            $this->evento($prestador, 'tarifa_referencia_creada', $actorId, null, array_merge($this->catalogo->nombres($tipoTelaId, $familiaId), [
                'valor_unitario' => (float) $tarifa->valor_unitario,
                'vigente_desde' => $tarifa->vigente_desde->toDateString(),
                'vigencia_anterior' => $ultima?->only(['id', 'valor_unitario']),
            ]), ['tarifa_prestador_id' => $tarifa->id]);

            return $tarifa;
        });
    }

    public function anular(TarifaPrestador $tarifa, string $motivo, ?int $actorId): TarifaPrestador
    {
        return DB::transaction(function () use ($tarifa, $motivo, $actorId) {
            $tarifa = TarifaPrestador::query()->lockForUpdate()->findOrFail($tarifa->id);

            if ($tarifa->estaAnulada()) {
                throw ValidationException::withMessages(['motivo' => trans('corteconfeccion::app.errors.tarifa-ya-anulada')]);
            }

            $tarifa->update([
                'anulada_en' => now(),
                'anulada_por_admin_id' => $actorId,
                'motivo_anulacion' => $this->limpiar($motivo),
            ]);

            $datos = ['valor_unitario' => (float) $tarifa->valor_unitario, 'vigente_desde' => $tarifa->vigente_desde->toDateString()];

            if (! $tarifa->esLegacyGeneral()) {
                $datos = array_merge($this->catalogo->nombres($tarifa->tipo_tela_id, $tarifa->attribute_family_id), $datos);
            }

            $this->evento($this->prestadorDe($tarifa), 'tarifa_referencia_anulada', $actorId, $motivo, $datos, ['tarifa_prestador_id' => $tarifa->id]);

            return $tarifa->refresh();
        });
    }

    /**
     * Todas las vigencias (activas y anuladas) de una combinacion, de la mas reciente a la mas antigua.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historialCombinacion(Taller|Cortador $prestador, int $tipoTelaId, int $attributeFamilyId): array
    {
        $tarifas = $this->combinacion($prestador, $tipoTelaId, $attributeFamilyId)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get();

        $vigenteId = $this->vigente($prestador, $tipoTelaId, $attributeFamilyId)?->id;
        $hoy = now()->toDateString();

        $nombres = Admin::query()
            ->whereIn('id', $tarifas->pluck('creado_por_admin_id')->merge($tarifas->pluck('anulada_por_admin_id'))->filter()->unique())
            ->pluck('name', 'id');

        return $tarifas->map(fn (TarifaPrestador $tarifa) => [
            'id' => $tarifa->id,
            'valor_unitario' => (float) $tarifa->valor_unitario,
            'vigente_desde' => $tarifa->vigente_desde->toDateString(),
            'observacion' => $tarifa->observacion,
            'estado' => match (true) {
                $tarifa->estaAnulada() => 'anulada',
                $tarifa->id === $vigenteId => 'vigente',
                $tarifa->vigente_desde->toDateString() > $hoy => 'programada',
                default => 'anterior',
            },
            'creado_por' => $nombres->get($tarifa->creado_por_admin_id),
            'anulada_en' => $tarifa->anulada_en?->format('Y-m-d H:i'),
            'anulada_por' => $nombres->get($tarifa->anulada_por_admin_id),
            'motivo_anulacion' => $tarifa->motivo_anulacion,
        ])->values()->all();
    }

    /**
     * Tarifa general anterior (sin tela ni referencia) todavia activa: no se aplica a nada, solo sirve para avisar
     * que las referencias deben configurarse de forma explicita.
     */
    public function tarifaGeneralAnterior(Taller|Cortador $prestador): ?TarifaPrestador
    {
        return TarifaPrestador::query()
            ->where($this->columna($prestador), $prestador->getKey())
            ->whereNull('attribute_family_id')
            ->whereNull('anulada_en')
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /** Telas con sus referencias para el modal; solo se calcula para quien puede administrar tarifas. */
    public function opcionesParaModal(string $tipo): array
    {
        return TarifaPrestadorGate::puedeGestionar($tipo) ? $this->catalogo->opciones() : [];
    }

    /** @return array{valor_unitario: float, vigente_desde: string}|null */
    public function avisoTarifaGeneral(Taller|Cortador $prestador): ?array
    {
        $general = $this->tarifaGeneralAnterior($prestador);

        return $general ? [
            'valor_unitario' => (float) $general->valor_unitario,
            'vigente_desde' => $general->vigente_desde->toDateString(),
        ] : null;
    }

    public function prestadorDe(TarifaPrestador $tarifa): Taller|Cortador
    {
        return $tarifa->taller_id ? Taller::query()->findOrFail($tarifa->taller_id) : Cortador::query()->findOrFail($tarifa->cortador_id);
    }

    public function evento(Taller|Cortador $prestador, string $tipo, ?int $actorId, ?string $motivo, array $datos, array $extra = []): void
    {
        CuentaEvento::query()->create(array_merge([
            $this->columna($prestador) => $prestador->getKey(),
            'tipo' => $tipo,
            'actor_admin_id' => $actorId,
            'motivo' => $this->limpiar($motivo),
            'datos' => $datos,
        ], $extra));
    }

    protected function combinacion(Taller|Cortador $prestador, int $tipoTelaId, int $attributeFamilyId): Builder
    {
        return TarifaPrestador::query()
            ->where($this->columna($prestador), $prestador->getKey())
            ->where('tipo_tela_id', $tipoTelaId)
            ->where('attribute_family_id', $attributeFamilyId);
    }

    protected function columna(Taller|Cortador $prestador): string
    {
        return $prestador instanceof Taller ? 'taller_id' : 'cortador_id';
    }

    protected function limpiar(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
