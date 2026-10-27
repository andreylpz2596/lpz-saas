<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Attribute\Models\Attribute;

class StoreRolloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.rollos.create');
    }

    public function rules(): array
    {
        $colorAttributeId = Attribute::query()->where('code', 'color')->value('id');

        return [
            'codigo' => ['required', 'string', 'max:255', 'unique:bylopez_cc_rollos,codigo'],
            'proveedor' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            // F1 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §5.4): nunca
            // un attribute_options.id de otro atributo -- resuelto por
            // codigo, nunca por ID hardcodeado (difieren entre ambientes).
            'color_option_id' => [
                'required',
                'integer',
                Rule::exists('attribute_options', 'id')->where(fn ($query) => $query->where('attribute_id', $colorAttributeId ?: 0)),
            ],
            'tipo_tela' => ['required', 'string', 'max:255'],
            'peso_inicial' => ['required', 'numeric', 'gt:0'],
            'peso_disponible' => ['nullable', 'numeric', 'gte:0', 'lte:peso_inicial'],
            'costo_total' => ['required', 'numeric', 'gte:0'],
            'fecha_compra' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string'],
        ];
    }
}
