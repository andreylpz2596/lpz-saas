<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Misma autorizacion que agregar un rollo a la orden (permiso `operar` + rol
 * habilitado para gestionar la solicitud). El estado de la orden se valida en
 * OrdenCorteService::retirarRollo(), no aqui.
 */
class RetirarRolloOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.operar')
            && CantidadCortadaGate::userCanGestionarSolicitud();
    }

    public function rules(): array
    {
        return [];
    }
}
