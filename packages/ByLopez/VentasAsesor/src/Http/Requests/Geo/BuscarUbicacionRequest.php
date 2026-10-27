<?php

namespace ByLopez\VentasAsesor\Http\Requests\Geo;

use ByLopez\VentasAsesor\Services\Geo\CatalogoUbicaciones;
use Illuminate\Foundation\Http\FormRequest;

/** Solo forma (D-58): texto de busqueda de 2 a 80 caracteres. La autorizacion es la ACL de la ruta. */
class BuscarUbicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:'.CatalogoUbicaciones::MINIMO_CARACTERES, 'max:80']];
    }

    public function texto(): string
    {
        return $this->string('q')->trim()->value();
    }
}
