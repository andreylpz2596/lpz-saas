<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (03G-D): tipos y longitudes. Las reglas de la direccion guardada (obligatorios,
 * telefono E.164, email opcional, codigo postal segun la configuracion de Bagisto) las aplica
 * GuardarDireccionClienteComercialService y vuelven como `datos_cliente_invalidos` con el
 * campo que falla. El cliente NO viene en la peticion: es el del borrador.
 *
 * D-58: el municipio llega como CODIGO DIVIPOLA elegido en el autocomplete y es obligatorio:
 * escribir «Pes» sin seleccionar no es una ubicacion. La ciudad, el estado y el pais los resuelve
 * el backend desde el catalogo; aqui no se aceptan.
 */
class AgregarDireccionNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['nullable', 'string', 'max:191'],
            'apellido' => ['nullable', 'string', 'max:191'],
            'lineas' => ['nullable', 'array', 'max:3'],
            'lineas.*' => ['nullable', 'string', 'max:191'],
            'municipio_codigo' => ['required', 'string', 'regex:/^\d{5}$/'],
            'codigo_postal' => ['nullable', 'string', 'max:32'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:191'],
            'predeterminada' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'municipio_codigo.required' => 'Selecciona una ciudad o municipio de la lista.',
            'municipio_codigo.regex' => 'Selecciona una ciudad o municipio de la lista.',
        ];
    }

    public function aDireccion(): DireccionComercial
    {
        return new DireccionComercial(
            nombre: $this->string('nombre')->trim()->value(),
            apellido: $this->string('apellido')->trim()->value(),
            lineas: array_values(array_map('strval', (array) $this->input('lineas', []))),
            ciudad: '',
            departamento: '',
            pais: 'CO',
            codigoPostal: $this->string('codigo_postal')->trim()->value() ?: null,
            telefono: $this->string('telefono')->trim()->value(),
            email: $this->string('email')->trim()->value() ?: null,
            predeterminada: $this->boolean('predeterminada'),
            municipioCodigo: $this->string('municipio_codigo')->trim()->value(),
        );
    }
}
