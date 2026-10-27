<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\Rollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Attribute\Models\Attribute;

class UpdateRolloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.rollos.edit');
    }

    public function rules(): array
    {
        $colorAttributeId = Attribute::query()->where('code', 'color')->value('id');
        $colorRule = $this->colorBloqueado() ? 'nullable' : 'required';

        return [
            'codigo' => ['required', 'string', 'max:255', Rule::unique('bylopez_cc_rollos', 'codigo')->ignore($this->route('id'))],
            'proveedor' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'color_option_id' => [
                $colorRule,
                'integer',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $colorAttributeId ?: 0)),
            ],
            'tipo_tela' => ['required', 'string', 'max:255'],
            'peso_inicial' => ['required', 'numeric', 'gt:0'],
            'peso_disponible' => ['required', 'numeric', 'gte:0', 'lte:peso_inicial'],
            'costo_total' => ['required', 'numeric', 'gte:0'],
            'fecha_compra' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string'],
        ];
    }

    protected function colorBloqueado(): bool
    {
        return (bool) Rollo::query()->find($this->route('id'))?->colorBloqueado();
    }
}
