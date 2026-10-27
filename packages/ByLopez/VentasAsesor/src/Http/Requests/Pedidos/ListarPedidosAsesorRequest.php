<?php

namespace ByLopez\VentasAsesor\Http\Requests\Pedidos;

use ByLopez\VentasAsesor\Services\Pedidos\ConsultaPedidosAsesorService as Consulta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Sales\Models\Order;

/**
 * Filtros de "Mis ventas": solo estructura. La autorizacion y el ALCANCE (propios / todos)
 * los decide el controlador con AutorizacionVentasAsesor; un parametro `asesor` nunca amplia
 * lo que un asesor con view_own puede ver (se ignora).
 */
class ListarPedidosAsesorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'estado' => ['nullable', Rule::in([
                Order::STATUS_PENDING, Order::STATUS_PENDING_PAYMENT, Order::STATUS_PROCESSING,
                Order::STATUS_COMPLETED, Order::STATUS_CANCELED, Order::STATUS_CLOSED, Order::STATUS_FRAUD,
            ])],
            'entrega' => ['nullable', 'string', 'max:191'],
            'asesor' => ['nullable', 'string', 'max:20', 'regex:/^(\d+|'.implode('|', Consulta::FILTROS_PROPIEDAD).')$/'],
            'resumen' => ['nullable', Rule::in(Consulta::RESUMENES)],
            'pedido' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: ?string, desde: ?string, hasta: ?string, estado: ?string, entrega: ?string, asesor: ?string, resumen: ?string} */
    public function filtros(): array
    {
        $valor = fn (string $k) => ($v = trim((string) $this->input($k, ''))) === '' ? null : $v;

        return [
            'q' => $valor('q'),
            'desde' => $valor('desde'),
            'hasta' => $valor('hasta'),
            'estado' => $valor('estado'),
            'entrega' => $valor('entrega'),
            'asesor' => $valor('asesor'),
            'resumen' => $valor('resumen'),
        ];
    }
}
