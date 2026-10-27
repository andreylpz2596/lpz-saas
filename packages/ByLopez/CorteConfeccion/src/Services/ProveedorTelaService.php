<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Repositories\ProveedorTelaRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;

class ProveedorTelaService
{
    public function __construct(protected ProveedorTelaRepository $proveedorTelaRepository) {}

    public function create(array $data): ProveedorTela
    {
        return DB::transaction(function () use ($data) {
            $tipoTelaIds = $data['tipo_tela_ids'];
            $referencias = $data['referencias'] ?? [];

            unset($data['tipo_tela_ids'], $data['referencias']);

            $proveedor = $this->proveedorTelaRepository->create($this->datosProveedor($data, $tipoTelaIds));
            $proveedor->tiposTela()->sync($tipoTelaIds);
            $this->validarReferenciasDuplicadas($proveedor, $referencias);
            $this->sincronizarReferencias($proveedor, $referencias);

            return $proveedor;
        });
    }

    public function update(ProveedorTela $proveedor, array $data): ProveedorTela
    {
        return DB::transaction(function () use ($proveedor, $data) {
            $tipoTelaIds = $data['tipo_tela_ids'];
            $referencias = $data['referencias'] ?? [];

            unset($data['tipo_tela_ids'], $data['referencias']);

            $proveedor->update($this->datosProveedor($data, $tipoTelaIds));
            $proveedor->tiposTela()->sync($tipoTelaIds);
            $this->validarReferenciasDuplicadas($proveedor->refresh(), $referencias);
            $this->sincronizarReferencias($proveedor->refresh(), $referencias);

            return $proveedor->refresh()->load(['tiposTela', 'referenciasTela.tipoTela', 'referenciasTela.rollos']);
        });
    }

    protected function datosProveedor(array $data, array $tipoTelaIds): array
    {
        return array_merge($data, [
            'tipo' => $tipoTelaIds[0] ?? null,
        ]);
    }

    protected function sincronizarReferencias(ProveedorTela $proveedor, array $referencias): void
    {
        foreach ($referencias as $referenciaData) {
            $isDelete = filter_var($referenciaData['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! empty($referenciaData['id'])) {
                $referencia = $proveedor->referenciasTela()->findOrFail($referenciaData['id']);

                if ($isDelete) {
                    if ($referencia->rollos()->exists()) {
                        throw ValidationException::withMessages(['referencias' => trans('corteconfeccion::app.errors.referencia-usada')]);
                    }

                    $referencia->delete();

                    continue;
                }

                $referencia->update($this->datosReferencia($referenciaData));

                continue;
            }

            if ($isDelete) {
                continue;
            }

            $proveedor->referenciasTela()->create($this->datosReferencia($referenciaData));
        }
    }

    protected function datosReferencia(array $referenciaData): array
    {
        [$colorOptionId, $color] = $this->resolverColorReferencia($referenciaData);

        return [
            'tipo_tela_id' => $referenciaData['tipo_tela_id'],
            'color_option_id' => $colorOptionId,
            'color' => $color,
            'referencia' => $referenciaData['referencia'],
            'gramaje' => $referenciaData['gramaje'],
            'valor_kilo_referencia' => ($referenciaData['valor_kilo_referencia'] ?? '') === '' ? null : $referenciaData['valor_kilo_referencia'],
            'estado' => $referenciaData['estado'] ?? 'activo',
        ];
    }

    /**
     * F1 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §5.4): si llega un
     * color_option_id, es la UNICA fuente de verdad -- se resuelve la opcion
     * real y el snapshot `color` se deriva de su admin_name, ignorando
     * cualquier texto que haya llegado del navegador (nunca se confia en que
     * el cliente envie "color" = "Rojo" cuando ya hay un ID). Revalida aqui
     * (no solo en StoreProveedorTelaRequest) porque este Service tambien se
     * llama directamente sin pasar por el FormRequest (ver tests existentes,
     * mismo criterio de defensa en profundidad que RecepcionCorteService::create()
     * en F0).
     *
     * F1-A: `color_option_id` sigue siendo nullable en BD solo para datos
     * historicos. Toda referencia nueva o editada por este servicio debe traer
     * una opcion real de Bagisto; el texto libre ya no es identidad operativa.
     *
     * @return array{0: ?int, 1: string}
     */
    protected function resolverColorReferencia(array $referenciaData): array
    {
        $colorOptionId = (int) ($referenciaData['color_option_id'] ?? 0);

        if (! $colorOptionId) {
            throw ValidationException::withMessages([
                'referencias' => trans('corteconfeccion::app.errors.referencia-color-requerido'),
            ]);
        }

        $colorAttributeId = Attribute::query()->where('code', 'color')->value('id');

        $opcion = AttributeOption::query()
            ->where('id', $colorOptionId)
            ->where('attribute_id', $colorAttributeId ?: 0)
            ->first();

        if (! $opcion) {
            throw ValidationException::withMessages([
                'referencias' => 'El color seleccionado no es una opcion valida del atributo color de Bagisto.',
            ]);
        }

        return [$opcion->id, (string) $opcion->admin_name];
    }

    protected function validarReferenciasDuplicadas(ProveedorTela $proveedor, array $referencias): void
    {
        $keys = [];
        $idsEnviados = [];

        foreach ($referencias as $referenciaData) {
            $isDelete = filter_var($referenciaData['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($isDelete || $this->referenciaIncompleta($referenciaData)) {
                continue;
            }

            if (! empty($referenciaData['id'])) {
                $idsEnviados[] = (int) $referenciaData['id'];
            }

            $key = $this->referenciaKey($referenciaData);

            if (isset($keys[$key])) {
                throw ValidationException::withMessages([
                    'referencias' => trans('corteconfeccion::app.errors.referencia-duplicada'),
                ]);
            }

            $keys[$key] = true;
        }

        if (empty($keys)) {
            return;
        }

        $duplicadaExistente = $proveedor->referenciasTela()
            ->when($idsEnviados, fn ($query) => $query->whereNotIn('id', $idsEnviados))
            ->get(['tipo_tela_id', 'color', 'color_option_id', 'referencia', 'gramaje'])
            ->contains(fn ($referencia) => isset($keys[$this->referenciaKey($referencia->toArray())]));

        if ($duplicadaExistente) {
            throw ValidationException::withMessages([
                'referencias' => trans('corteconfeccion::app.errors.referencia-duplicada'),
            ]);
        }
    }

    protected function referenciaIncompleta(array $referenciaData): bool
    {
        return blank($referenciaData['tipo_tela_id'] ?? null)
            || empty($referenciaData['color_option_id'])
            || blank($referenciaData['referencia'] ?? null)
            || blank($referenciaData['gramaje'] ?? null);
    }

    /**
     * F1-A: color_option_id es obligatorio para datos nuevos. El fallback de
     * texto queda solo para comparar registros historicos que ya existen en BD
     * y no deberian participar en nuevas altas.
     */
    protected function referenciaKey(array $referenciaData): string
    {
        $colorOptionId = (int) ($referenciaData['color_option_id'] ?? 0);
        $colorClave = $colorOptionId
            ? 'opt:'.$colorOptionId
            : 'txt:'.strtolower(trim((string) ($referenciaData['color'] ?? '')));

        return implode('|', [
            (int) ($referenciaData['tipo_tela_id'] ?? 0),
            $colorClave,
            strtolower(trim((string) ($referenciaData['referencia'] ?? ''))),
            number_format((float) ($referenciaData['gramaje'] ?? 0), 3, '.', ''),
        ]);
    }
}
