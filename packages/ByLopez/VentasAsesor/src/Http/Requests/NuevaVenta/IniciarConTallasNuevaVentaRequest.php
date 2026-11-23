<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (P7): la venta nace con la primera prenda. `referencia` es un identificador generado por la pantalla
 * (uno por intento de venta) que hace idempotente el reintento; el servidor lo liga al actor.
 */
class IniciarConTallasNuevaVentaRequest extends FormRequest
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
            'referencia' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'producto_id' => ['required', 'integer', 'min:1'],
            'cantidades' => ['required', 'array', 'min:1'],
            'cantidades.*' => ['required'],
        ];
    }

    /** @return array<int|string, mixed> variante_id => cantidad, tal como espera el dominio. */
    public function cantidadesPorVariante(): array
    {
        return (array) $this->input('cantidades', []);
    }
}
