<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrdenConfeccionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $detalles = collect($this->input('detalles', []))
            ->filter(fn ($detalle) => ! empty($detalle['cantidad_entregada']))
            ->values()
            ->all();

        $this->merge(['detalles' => $detalles]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.create');
    }

    public function rules(): array
    {
        return [
            'orden_corte_id' => ['required', 'integer', 'exists:bylopez_cc_ordenes_corte,id'],
            'taller_id' => ['required', 'integer', 'exists:bylopez_cc_talleres,id'],
            'fecha_entrega' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.product_id' => ['required', 'exists:products,id'],
            'detalles.*.talla' => ['nullable', 'string', 'max:50'],
            'detalles.*.cantidad_entregada' => ['required', 'integer', 'gt:0'],
        ];
    }
}
