<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRolloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.rollos.create');
    }

    public function rules(): array
    {
        return [
            'codigo'          => ['required', 'string', 'max:255', 'unique:bylopez_cc_rollos,codigo'],
            'proveedor'       => ['required', 'string', 'max:255'],
            'color'           => ['required', 'string', 'max:255'],
            'tipo_tela'       => ['required', 'string', 'max:255'],
            'peso_inicial'    => ['required', 'numeric', 'gt:0'],
            'peso_disponible' => ['nullable', 'numeric', 'gte:0', 'lte:peso_inicial'],
            'costo_total'     => ['required', 'numeric', 'gte:0'],
            'fecha_compra'    => ['nullable', 'date'],
            'observacion'     => ['nullable', 'string'],
        ];
    }
}
