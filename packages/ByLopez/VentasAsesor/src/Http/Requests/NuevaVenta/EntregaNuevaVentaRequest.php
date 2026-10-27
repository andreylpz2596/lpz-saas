<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solo forma (03G-C): envio es obligatorio (D-30: la bodega solo es el envio, nunca la
 * facturacion), facturacion y metodo son opcionales. NuevaVentaService::entregaRapida
 * resuelve la direccion real/predeterminada, el metodo y el todo-o-nada.
 */
class EntregaNuevaVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipos = [OrigenDireccion::GUARDADA, OrigenDireccion::PUNTUAL, OrigenDireccion::IGUAL_FACTURACION, OrigenDireccion::PUNTO_RECOGIDA];

        return [
            'envio' => ['required', 'array'],
            'envio.tipo' => ['required', 'string', Rule::in($tipos)],
            'envio.address_id' => ['required_if:envio.tipo,'.OrigenDireccion::GUARDADA, 'integer', 'min:1'],
            'envio.inventory_source_id' => ['nullable', 'integer', 'min:1'],
            'envio.direccion' => ['required_if:envio.tipo,'.OrigenDireccion::PUNTUAL, 'array'],
            ...$this->reglasDireccion('envio.direccion'),

            'facturacion' => ['nullable', 'array'],
            'facturacion.tipo' => ['required_with:facturacion', 'string', Rule::in([OrigenDireccion::GUARDADA, OrigenDireccion::PUNTUAL])],
            'facturacion.address_id' => ['required_if:facturacion.tipo,'.OrigenDireccion::GUARDADA, 'integer', 'min:1'],
            'facturacion.direccion' => ['required_if:facturacion.tipo,'.OrigenDireccion::PUNTUAL, 'array'],
            ...$this->reglasDireccion('facturacion.direccion'),

            'metodo' => ['nullable', 'string', 'max:191'],
        ];
    }

    private function reglasDireccion(string $prefijo): array
    {
        return [
            "{$prefijo}.nombre" => ['required_with:'.$prefijo, 'string', 'max:191'],
            "{$prefijo}.apellido" => ['required_with:'.$prefijo, 'string', 'max:191'],
            "{$prefijo}.lineas" => ['required_with:'.$prefijo, 'array', 'min:1'],
            "{$prefijo}.lineas.*" => ['string', 'max:191'],
            // D-58: en Colombia el municipio es un CODIGO DIVIPOLA elegido del catalogo (el backend resuelve
            // ciudad y estado). `ciudad`/`departamento` solo se admiten para otros paises y se ignoran si hay codigo.
            "{$prefijo}.municipio_codigo" => [Rule::requiredIf(fn () => $this->has($prefijo) && strtoupper((string) $this->input("{$prefijo}.pais", 'CO')) === 'CO'), 'nullable', 'string', 'regex:/^\d{5}$/'],
            "{$prefijo}.ciudad" => ['nullable', 'string', 'max:191'],
            "{$prefijo}.departamento" => ['nullable', 'string', 'max:191'],
            "{$prefijo}.pais" => ['required_with:'.$prefijo, 'string', 'size:2'],
            "{$prefijo}.codigo_postal" => ['nullable', 'string', 'max:32'],
            "{$prefijo}.telefono" => ['required_with:'.$prefijo, 'string', 'max:32'],
            "{$prefijo}.email" => ['nullable', 'string', 'max:191'],
            "{$prefijo}.empresa" => ['nullable', 'string', 'max:191'],
        ];
    }

    public function envioComoOrigen(): OrigenDireccion
    {
        return $this->aOrigen((array) $this->input('envio'));
    }

    public function facturacionComoOrigen(): ?OrigenDireccion
    {
        $facturacion = $this->input('facturacion');

        return $facturacion === null ? null : $this->aOrigen((array) $facturacion);
    }

    public function messages(): array
    {
        return [
            'envio.direccion.municipio_codigo.required' => 'Selecciona una ciudad o municipio de la lista.',
            'facturacion.direccion.municipio_codigo.required' => 'Selecciona una ciudad o municipio de la lista.',
            'envio.direccion.municipio_codigo.regex' => 'Selecciona una ciudad o municipio de la lista.',
            'facturacion.direccion.municipio_codigo.regex' => 'Selecciona una ciudad o municipio de la lista.',
        ];
    }

    private function aOrigen(array $datos): OrigenDireccion
    {
        return match ($datos['tipo'] ?? null) {
            OrigenDireccion::GUARDADA => OrigenDireccion::guardada((int) $datos['address_id']),
            OrigenDireccion::PUNTUAL => OrigenDireccion::puntual($this->aDireccion((array) $datos['direccion'])),
            OrigenDireccion::IGUAL_FACTURACION => OrigenDireccion::igualFacturacion(),
            OrigenDireccion::PUNTO_RECOGIDA => OrigenDireccion::puntoRecogida(isset($datos['inventory_source_id']) ? (int) $datos['inventory_source_id'] : null),
            default => OrigenDireccion::puntoRecogida(),
        };
    }

    private function aDireccion(array $d): DireccionComercial
    {
        return new DireccionComercial(
            nombre: (string) $d['nombre'],
            apellido: (string) $d['apellido'],
            lineas: array_values((array) $d['lineas']),
            ciudad: (string) ($d['ciudad'] ?? ''),
            departamento: (string) ($d['departamento'] ?? ''),
            pais: (string) $d['pais'],
            codigoPostal: isset($d['codigo_postal']) ? (string) $d['codigo_postal'] : null,
            telefono: (string) $d['telefono'],
            email: isset($d['email']) ? (string) $d['email'] : null,
            empresa: isset($d['empresa']) ? (string) $d['empresa'] : null,
            municipioCodigo: isset($d['municipio_codigo']) && $d['municipio_codigo'] !== '' ? (string) $d['municipio_codigo'] : null,
        );
    }
}
