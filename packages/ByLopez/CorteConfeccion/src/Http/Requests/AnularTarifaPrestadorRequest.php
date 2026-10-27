<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\TarifaPrestadorGate;
use Illuminate\Foundation\Http\FormRequest;

class AnularTarifaPrestadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return TarifaPrestadorGate::puedeGestionar((string) $this->route('tipo'));
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['motivo' => 'motivo'];
    }
}
