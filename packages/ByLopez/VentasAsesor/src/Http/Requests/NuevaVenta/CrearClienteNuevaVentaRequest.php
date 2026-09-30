<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (03G-C): tipos y longitudes. RT-04 (grupo explicito, normalizacion del
 * telefono, email opcional y duplicados) los resuelve CrearClienteComercialService.
 */
class CrearClienteNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'apellido' => ['required', 'string', 'max:191'],
            'telefono' => ['required', 'string', 'max:32'],
            'customer_group_id' => ['nullable', 'integer', 'min:1'],
            'email' => ['nullable', 'string', 'max:191'],
            'channel_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function aDatos(): DatosClienteComercial
    {
        return new DatosClienteComercial(
            nombre: $this->string('nombre')->trim()->value(),
            apellido: $this->string('apellido')->trim()->value(),
            telefono: $this->string('telefono')->trim()->value(),
            customerGroupId: $this->integer('customer_group_id') ?: null,
            email: $this->string('email')->trim()->value() ?: null,
            channelId: $this->integer('channel_id') ?: null,
        );
    }
}
