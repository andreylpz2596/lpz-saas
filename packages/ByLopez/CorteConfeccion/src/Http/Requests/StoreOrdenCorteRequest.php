<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrdenCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rolloIds = collect($this->input('rollo_ids', []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $detalles = collect($this->input('detalles', []))
            ->map(function ($detalle) {
                if (array_key_exists('cantidad_cortada', $detalle)) {
                    $detalle['cantidad_real_cortada'] = $detalle['cantidad_cortada'];
                }

                return $detalle;
            })
            ->filter(fn ($detalle) => ! empty($detalle['product_id'])
                || ! empty($detalle['genero_linea'])
                || ! empty($detalle['talla'])
                || ! empty($detalle['cantidad_proyectada'])
                || ! empty($detalle['cantidad_real_cortada']))
            ->values()
            ->all();

        $this->merge([
            'rollo_ids' => $rolloIds,
            'detalles'  => $detalles,
        ]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.create');
    }

    public function rules(): array
    {
        return [
            'rollo_ids'                       => ['required', 'array', 'min:1'],
            'rollo_ids.*'                     => ['required', 'integer', 'distinct', 'exists:bylopez_cc_rollos,id'],
            'cortador_id'                     => ['required', 'integer', 'exists:admins,id'],
            'fecha_entrega'                   => ['nullable', 'date'],
            'fecha_solicitud'                 => ['nullable', 'date'],
            'observacion'                     => ['nullable', 'string'],
            'peso_sobrante_total'             => ['nullable', 'numeric', 'gte:0'],
            'merma_total'                     => ['nullable', 'numeric', 'gte:0'],
            'detalles'                        => ['required', 'array', 'min:1'],
            'detalles.*.product_id'           => ['required', 'exists:products,id'],
            'detalles.*.variant_id'           => ['nullable', 'exists:products,id'],
            'detalles.*.genero_linea'         => ['nullable', 'string', 'max:255'],
            'detalles.*.talla'                => ['nullable', 'string', 'max:50'],
            'detalles.*.cantidad_proyectada'  => ['nullable', 'integer', 'gte:0'],
            'detalles.*.cantidad_cortada'      => ['nullable', 'integer', 'gte:0'],
            'detalles.*.cantidad_real_cortada' => ['nullable', 'integer', 'gte:0'],
            'detalles.*.observacion'          => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $cortadorId = (int) $this->input('cortador_id');

            if ($cortadorId && ! CortadorResolver::isCortador($cortadorId)) {
                $validator->errors()->add('cortador_id', 'El cortador seleccionado no tiene el rol cortador.');
            }

            if (CantidadCortadaGate::userCanEdit()) {
                return;
            }

            foreach ($this->input('detalles', []) as $index => $detalle) {
                $cantidad = $detalle['cantidad_cortada'] ?? $detalle['cantidad_real_cortada'] ?? null;

                if ($cantidad !== null && $cantidad !== '' && (int) $cantidad !== 0) {
                    $validator->errors()->add(
                        "detalles.$index.cantidad_cortada",
                        trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada')
                    );
                }
            }
        });
    }
}
