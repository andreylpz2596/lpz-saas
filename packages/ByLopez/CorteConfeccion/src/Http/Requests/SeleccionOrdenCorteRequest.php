<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * F4: consultas de solo lectura de la pantalla de creacion (matriz de
 * opciones y previsualizacion). Misma autorizacion que crear la orden.
 * Solo estructura; el dominio lo valida SeleccionOrdenCorteService.
 */
class SeleccionOrdenCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'rollo_ids' => collect($this->input('rollo_ids', []))->filter()->unique()->values()->all(),
        ]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.create')
            && CantidadCortadaGate::userCanGestionarSolicitud();
    }

    public function rules(): array
    {
        return self::reglasSeleccion(seleccionRequerida: false);
    }

    /**
     * Reglas compartidas con StoreOrdenCorteRequest.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglasSeleccion(bool $seleccionRequerida): array
    {
        return [
            'rollo_ids' => ['required', 'array', 'min:1'],
            'rollo_ids.*' => ['required', 'integer', 'distinct', 'exists:bylopez_cc_rollos,id'],
            'seleccion' => $seleccionRequerida ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'seleccion.*.attribute_family_id' => ['required', 'integer', 'exists:attribute_families,id'],
            'seleccion.*.color_option_id' => ['required', 'integer', 'exists:attribute_options,id'],
            'seleccion.*.eje_option_id' => ['required', 'integer', 'exists:attribute_options,id'],
            // Preparado para lineas = familia + filtros estructurados (sin UI todavia).
            'seleccion.*.filtros' => ['nullable', 'array'],
            'seleccion.*.filtros.*' => ['integer'],
        ];
    }
}
