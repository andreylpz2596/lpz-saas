<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // G-11: forma solamente; el permiso por tipo lo valida ConfirmarVentaService.
            'tipo_operacion' => ['nullable', 'string', Rule::in(array_column(TipoOperacionPedido::cases(), 'value'))],
            // R2 «Vender para»: '' (automatico), 'empresa' o el admin_id de un asesor; el permiso lo valida VenderPara.
            'vender_para' => ['nullable', 'string', 'max:20'],
        ];
    }
}
