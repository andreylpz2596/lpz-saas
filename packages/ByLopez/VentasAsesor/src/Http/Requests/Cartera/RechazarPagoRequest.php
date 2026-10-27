<?php

namespace ByLopez\VentasAsesor\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

/** Solo la forma. La categoria valida y el motivo obligatorio los comprueba RechazarPagoService. */
class RechazarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['required', 'string', 'max:40'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
