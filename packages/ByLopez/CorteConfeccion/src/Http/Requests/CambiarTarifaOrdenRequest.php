<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pacta o cambia la tarifa de UNA combinacion tela + referencia dentro de una orden. 'taller' = orden de confeccion,
 * 'cortador' = orden de corte (default de la ruta). El motivo es obligatorio: queda en el historial del prestador.
 */
class CambiarTarifaOrdenRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_tela_id' => 'tipo de tela',
            'attribute_family_id' => 'referencia',
            'valor_unitario' => 'tarifa',
            'motivo' => 'motivo',
        ];
    }
}
