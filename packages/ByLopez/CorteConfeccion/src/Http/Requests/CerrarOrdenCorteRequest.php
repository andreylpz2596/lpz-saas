<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Foundation\Http\FormRequest;

class CerrarOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orden = OrdenCorte::query()->find((int) $this->route('id'));

        return $orden && CortadorAccess::puedeOperarOrden($orden);
    }

    public function rules(): array
    {
        return [];
    }
}
