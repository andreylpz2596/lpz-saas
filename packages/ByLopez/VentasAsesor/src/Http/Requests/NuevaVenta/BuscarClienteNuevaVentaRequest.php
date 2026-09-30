<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo forma (03G-C): al menos un criterio y limites razonables. La logica de
 * telefono/email/nombre y sus conflictos la resuelve BuscarClienteComercialService.
 */
class BuscarClienteNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telefono' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:191'],
            'nombre' => ['nullable', 'string', 'max:191'],
            'channel_id' => ['nullable', 'integer', 'min:1'],
            'limite_candidatos' => ['nullable', 'integer', 'min:1', 'max:50'],
            'telefono_requerido_si_vacio' => ['sometimes'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('telefono') && ! $this->filled('email') && ! $this->filled('nombre')) {
                $validator->errors()->add('telefono', 'Se necesita al menos un criterio: telefono, email o nombre.');
            }
        });
    }

    public function aConsulta(): ConsultaClienteComercial
    {
        return new ConsultaClienteComercial(
            telefono: $this->string('telefono')->trim()->value() ?: null,
            email: $this->string('email')->trim()->value() ?: null,
            nombre: $this->string('nombre')->trim()->value() ?: null,
            channelId: $this->integer('channel_id') ?: null,
            limiteCandidatos: (int) $this->input('limite_candidatos', 20),
        );
    }
}
