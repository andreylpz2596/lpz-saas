<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrdenCorteRolloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')
            && CantidadCortadaGate::userCanGestionarSolicitud();
    }

    public function rules(): array
    {
        return [
            'rollo_id' => ['required', 'integer', 'exists:bylopez_cc_rollos,id'],
        ];
    }
}
