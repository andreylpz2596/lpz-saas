<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CortadorAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * F5: los adicionales del cortador se identifican de forma estructurada
 * (linea + color + eje), igual que la seleccion de F4. NO existe product_id:
 * si el navegador lo envia no esta en las reglas y validated() no lo devuelve.
 * La cantidad de un adicional debe ser > 0 (ya no se descartan filas en
 * silencio); el dominio lo valida OrdenCorteService::resolverAdicionales().
 */
class ReportarResultadoCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $resultados = collect($this->input('resultados', []))
            ->filter(fn ($fila) => isset($fila['detalle_id']))
            ->values()
            ->all();

        $this->merge([
            'resultados' => $resultados,
            'adicionales' => array_values((array) $this->input('adicionales', [])),
        ]);
    }

    public function authorize(): bool
    {
        $orden = OrdenCorte::query()->find((int) $this->route('id'));

        return $orden && CortadorAccess::puedeOperarOrden($orden);
    }

    public function rules(): array
    {
        return [
            'resultados' => ['nullable', 'array'],
            'resultados.*.detalle_id' => ['required', 'integer', 'exists:bylopez_cc_ordenes_corte_detalles,id'],
            'resultados.*.cantidad_real_cortada' => ['required', 'integer', 'gte:0'],
        ] + self::reglasAdicionales(cantidadRequerida: true);
    }

    /**
     * Reglas compartidas con los endpoints de lectura de adicionales.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglasAdicionales(bool $cantidadRequerida): array
    {
        return [
            'adicionales' => ['nullable', 'array'],
            'adicionales.*.attribute_family_id' => ['required', 'integer', 'exists:attribute_families,id'],
            'adicionales.*.color_option_id' => ['required', 'integer', 'exists:attribute_options,id'],
            'adicionales.*.eje_option_id' => ['required', 'integer', 'exists:attribute_options,id'],
            'adicionales.*.cantidad_real_cortada' => $cantidadRequerida ? ['required', 'integer', 'gt:0'] : ['nullable', 'integer', 'gt:0'],
            'adicionales.*.filtros' => ['nullable', 'array'],
            'adicionales.*.filtros.*' => ['integer'],
        ];
    }
}
