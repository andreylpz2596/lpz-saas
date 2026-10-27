<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma: varias combinaciones linea x color en una consulta; cliente (precio RT-03) obligatorio. */
class MatricesNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productos' => ['required', 'array', 'min:1', 'max:50'],
            'productos.*' => ['integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'borrador_id' => ['nullable', 'integer', 'min:1'],
            'solo_vendibles' => ['nullable', 'boolean'],
        ];
    }
}
