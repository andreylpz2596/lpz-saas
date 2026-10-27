<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Foundation\Http\FormRequest;

class EntregarOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orden = OrdenCorte::query()->find((int) $this->route('id'));

        return $orden && CortadorAccess::puedeOperarOrden($orden);
    }

    public function rules(): array
    {
        // El peso entregado ya no lo escribe el usuario: OrdenCorteService::entregar()
        // lo calcula automaticamente a partir de los rollos reservados.
        return [];
    }
}
