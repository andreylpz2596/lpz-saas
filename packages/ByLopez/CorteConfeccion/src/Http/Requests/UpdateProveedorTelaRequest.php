<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

class UpdateProveedorTelaRequest extends StoreProveedorTelaRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit');
    }
}
