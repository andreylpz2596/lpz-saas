<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\InventarioMovimientoGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AjusteManualInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return InventarioMovimientoGate::puedeAjustarManual();
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'tipo' => ['required', Rule::in(['entrada', 'salida'])],
            'cantidad' => ['required', 'integer', 'gt:0'],
            'motivo' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
