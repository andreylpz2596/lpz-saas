<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma (03G-C): canal_permitido lo valida NuevaVentaService::canalPermitido. */
class IniciarNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'canal' => ['required', 'string', 'max:32'],
            'referencia_externa' => ['nullable', 'string', 'max:191'],
        ];
    }
}
