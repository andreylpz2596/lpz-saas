<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * F4 - Payload V2 de creacion de Orden de Corte:
 *
 *   rollo_ids: [10, 11, 12]
 *   seleccion: [{attribute_family_id, color_option_id, eje_option_id, filtros?}, ...]
 *   cortador_id, observacion
 *
 * Aqui solo se valida ESTRUCTURA. No hay product_id ni cantidad_proyectada: un
 * `detalles`/`product_id` que llegue del navegador simplemente no se lee (no
 * esta en las reglas, asi que validated() no lo devuelve). La autoridad de
 * dominio (rollos, colores permitidos, resolucion de simples) es
 * OrdenCorteService::crearDesdeSeleccion().
 */
class StoreOrdenCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'rollo_ids' => collect($this->input('rollo_ids', []))->filter()->unique()->values()->all(),
            'seleccion' => collect($this->input('seleccion', []))
                ->filter(fn ($fila) => is_array($fila) && array_filter($fila))
                ->values()
                ->all(),
        ]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.ordenes_corte.create')
            && CantidadCortadaGate::userCanGestionarSolicitud();
    }

    public function rules(): array
    {
        return array_merge([
            'cortador_id' => ['required', 'integer', 'exists:admins,id'],
            'observacion' => ['nullable', 'string'],
        ], SeleccionOrdenCorteRequest::reglasSeleccion(seleccionRequerida: true));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $cortadorId = (int) $this->input('cortador_id');

            if ($cortadorId && ! CortadorResolver::isCortador($cortadorId)) {
                $validator->errors()->add('cortador_id', 'El cortador seleccionado no tiene el rol cortador.');
            }
        });
    }
}
