<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma. `total_esperado` es el total que el asesor vio en EstadoNuevaVenta
 * (TOTALES_CAMBIARON si no coincide, D-55 lease y token los resuelve
 * ConfirmarVentaService). La propiedad comercial (empresa/asesor) no se expone por HTTP
 * todavia (Fase 02A: "no la expone por HTTP").
 */
class ConfirmarNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total_esperado' => ['required', 'numeric', 'min:0'],
        ];
    }
}
