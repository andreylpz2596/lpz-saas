<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\TallerConfeccionAccess;

class UpdateTallerRequest extends StoreTallerRequest
{
    public function authorize(): bool
    {
        return ! TallerConfeccionAccess::esUsuarioTaller()
            && bouncer()->hasPermission('corte_confeccion.talleres.edit');
    }
}
