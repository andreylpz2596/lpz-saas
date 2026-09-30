<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma: filtro de texto y limite de la consulta de colores de un acceso rapido. */
class ColoresNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:191'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
