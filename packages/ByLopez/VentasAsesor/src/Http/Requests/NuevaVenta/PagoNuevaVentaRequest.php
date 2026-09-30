<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma. La allowlist real de metodos la resuelve PagoBorradorService. */
class PagoNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'metodo' => ['required', 'string', 'max:191'],
        ];
    }
}
