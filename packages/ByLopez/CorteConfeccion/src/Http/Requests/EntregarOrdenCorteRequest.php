<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EntregarOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar');
    }

    public function rules(): array
    {
        return [
            'peso_entregado' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
