<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CerrarOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar');
    }

    public function rules(): array
    {
        return [];
    }
}
