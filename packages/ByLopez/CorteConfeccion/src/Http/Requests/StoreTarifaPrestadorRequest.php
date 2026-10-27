<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Agrega una referencia con su tarifa, o una nueva vigencia de una referencia existente, en la ficha de un taller o
 * cortador. El tipo ('taller' | 'cortador') llega como default de la ruta, no del cliente. Que la referencia pertenezca a
 * esa tela y que la vigencia sea posterior a la ultima se valida en TarifaPrestadorService.
 */
class StoreTarifaPrestadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return TarifaPrestadorGate::puedeGestionar((string) $this->route('tipo'));
    }

    public function rules(): array
    {
        return [
            'tipo_tela_id' => ['required', 'integer', 'exists:attribute_options,id'],
            'attribute_family_id' => ['required', 'integer', 'exists:attribute_families,id'],
            'valor_unitario' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_tela_id' => 'tipo de tela',
            'attribute_family_id' => 'referencia del producto',
            'valor_unitario' => 'tarifa',
            'vigente_desde' => 'inicio de vigencia',
            'observacion' => 'observacion',
        ];
    }
}
