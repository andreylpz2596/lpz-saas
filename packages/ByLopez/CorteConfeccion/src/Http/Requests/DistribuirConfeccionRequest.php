<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Support\DistribucionConfeccionGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DistribuirConfeccionRequest extends FormRequest
{
    /**
     * Filtra, antes de validar, las filas con
     * cantidad <= 0, y las asignaciones que terminen sin ningun detalle
     * valido. Las cantidades sin distribuir NUNCA deben convertirse en
     * filas persistidas (docs/confeccion/02_MODELO_DISTRIBUCION.md §5): el
     * filtro es explicito (comparacion numerica) en vez de depender de
     * empty(), a diferencia del StoreOrdenConfeccionRequest original.
     */
    protected function prepareForValidation(): void
    {
        $asignaciones = collect($this->input('asignaciones', []))
            ->map(function ($asignacion) {
                $detalles = collect($asignacion['detalles'] ?? [])
                    ->filter(fn ($detalle) => (int) ($detalle['cantidad'] ?? 0) > 0)
                    ->values()
                    ->all();

                return array_merge($asignacion, ['detalles' => $detalles]);
            })
            ->filter(fn ($asignacion) => ! empty($asignacion['detalles']))
            ->values()
            ->all();

        $this->merge(['asignaciones' => $asignaciones]);
    }

    public function authorize(): bool
    {
        // Fase SEC-01: DistribucionConfeccionGate ya incluye la verificacion
        // ACL estandar (bouncer()->hasPermission()) mas la lista de bloqueo
        // explicita por nombre de rol (Taller Confeccion/Cortador), que no
        // depende de `permission_type='all'`.
        return DistribucionConfeccionGate::puedeDistribuir();
    }

    public function rules(): array
    {
        return [
            'orden_corte_id' => ['required', 'integer', 'exists:bylopez_cc_ordenes_corte,id'],

            'asignaciones' => ['required', 'array', 'min:1'],
            // "distinct" compara este valor contra sus hermanos del mismo
            // array (todas las asignaciones.*.taller_id): una operacion de
            // distribucion nunca puede generar mas de una OrdenConfeccion
            // para el mismo Taller (arquitectura aprobada en 02_MODELO_DISTRIBUCION.md §1).
            'asignaciones.*.taller_id' => ['required', 'integer', 'exists:bylopez_cc_talleres,id', 'distinct'],
            'asignaciones.*.fecha_entrega' => ['nullable', 'date'],
            'asignaciones.*.observacion' => ['nullable', 'string'],

            'asignaciones.*.detalles' => ['required', 'array', 'min:1'],
            'asignaciones.*.detalles.*.product_id' => ['required', 'exists:products,id'],
            'asignaciones.*.detalles.*.cantidad' => ['required', 'integer', 'gt:0'],
            'asignaciones.*.detalles.*.prioridad' => ['nullable', Rule::enum(PrioridadOrdenConfeccion::class)],
        ];
    }
}
