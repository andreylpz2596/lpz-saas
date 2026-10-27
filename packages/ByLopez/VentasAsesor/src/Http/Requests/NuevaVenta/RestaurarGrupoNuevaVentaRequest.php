<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/** Solo forma (GRP-01): el motivo obligatorio lo exige GrupoVentaService. */
class RestaurarGrupoNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
