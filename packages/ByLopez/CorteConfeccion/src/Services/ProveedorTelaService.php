<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Repositories\ProveedorTelaRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        return [
            'tipo_tela_id'          => $referenciaData['tipo_tela_id'],
            'color'                 => $referenciaData['color'],
            'referencia'            => $referenciaData['referencia'],
            'gramaje'               => $referenciaData['gramaje'],
            'valor_kilo_referencia' => ($referenciaData['valor_kilo_referencia'] ?? '') === '' ? null : $referenciaData['valor_kilo_referencia'],
            'estado'                => $referenciaData['estado'] ?? 'activo',
        ];
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
            ->get(['tipo_tela_id', 'color', 'referencia', 'gramaje'])
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
            || blank($referenciaData['color'] ?? null)
            || blank($referenciaData['referencia'] ?? null)
            || blank($referenciaData['gramaje'] ?? null);
    }

    protected function referenciaKey(array $referenciaData): string
    {
        return implode('|', [
            (int) ($referenciaData['tipo_tela_id'] ?? 0),
            strtolower(trim((string) ($referenciaData['color'] ?? ''))),
            strtolower(trim((string) ($referenciaData['referencia'] ?? ''))),
            number_format((float) ($referenciaData['gramaje'] ?? 0), 3, '.', ''),
        ]);
    }
}
