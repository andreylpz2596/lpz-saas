<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.rollos.edit');
    }

    public function rules(): array
    {
        return [
            'codigo'          => ['required', 'string', 'max:255', Rule::unique('bylopez_cc_rollos', 'codigo')->ignore($this->route('id'))],
            'proveedor'       => ['required', 'string', 'max:255'],
            'color'           => ['required', 'string', 'max:255'],
            'tipo_tela'       => ['required', 'string', 'max:255'],
            'peso_inicial'    => ['required', 'numeric', 'gt:0'],
            'peso_disponible' => ['required', 'numeric', 'gte:0', 'lte:peso_inicial'],
            'costo_total'     => ['required', 'numeric', 'gte:0'],
            'fecha_compra'    => ['nullable', 'date'],
            'observacion'     => ['nullable', 'string'],
        ];
    }
}
