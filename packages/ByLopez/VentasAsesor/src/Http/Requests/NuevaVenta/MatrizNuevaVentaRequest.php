<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma: cliente (precio RT-03) obligatorio; borrador opcional (senal de ventas simultaneas por lote). */
class MatrizNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'borrador_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
