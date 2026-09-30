<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (03G-C): lote no vacio, variante_id entero y cantidad entera >= 0 como
 * texto o numero. I-5, D-39, stock y el todo-o-nada los resuelve NuevaVentaService::
 * aplicarTallas (objetivosValidados + LOTE_RECHAZADO).
 */
class AplicarTallasNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
