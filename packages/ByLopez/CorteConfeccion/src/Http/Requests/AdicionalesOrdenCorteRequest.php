<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * F5: consultas de solo lectura (matriz y previsualizacion) de los adicionales
 * del cortador. Misma autorizacion que reportar el resultado.
 */
class AdicionalesOrdenCorteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orden = OrdenCorte::query()->find((int) $this->route('id'));

        return $orden && CortadorAccess::puedeOperarOrden($orden);
    }

    public function rules(): array
    {
        return ReportarResultadoCorteRequest::reglasAdicionales(cantidadRequerida: false);
    }
}
