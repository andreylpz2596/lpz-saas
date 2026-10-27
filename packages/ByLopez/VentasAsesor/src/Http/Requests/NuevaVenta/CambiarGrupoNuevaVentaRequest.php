<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (GRP-01). El permiso, el motivo obligatorio, el grupo valido y la propiedad de la venta los exige
 * GrupoVentaService, con codigos estables; el permiso tambien lo exige el ACL de la ruta.
 */
class CambiarGrupoNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grupo_id' => ['required', 'integer', 'min:1'],
            'motivo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
