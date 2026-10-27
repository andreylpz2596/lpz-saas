<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrdenCorteDetalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')
            && CantidadCortadaGate::userCanGestionarSolicitud();
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:products,id'],
            'genero_linea' => ['nullable', 'string', 'max:255'],
            'talla' => ['nullable', 'string', 'max:50'],
            'cantidad_proyectada' => ['required', 'integer', 'gt:0'],
            'observacion' => ['nullable', 'string'],
        ];
    }
}
