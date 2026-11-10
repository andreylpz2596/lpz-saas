<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Enums\SituacionFisicaReasignacion;
use ByLopez\CorteConfeccion\Support\ReasignacionGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReasignarPrendasRequest extends FormRequest
{
    /**
     * Solo se descartan las lineas SIN cantidad (campo vacio). Un cero o un negativo se conserva a proposito: el
     * servicio los rechaza completos en vez de ignorarlos en silencio.
     */
    protected function prepareForValidation(): void
    {
        $lineas = collect($this->input('lineas', []))
            ->filter(fn ($linea) => is_array($linea) && ($linea['cantidad'] ?? '') !== '' && ($linea['cantidad'] ?? null) !== null)
            ->values()
            ->all();

        $this->merge(['lineas' => $lineas]);
    }

    public function authorize(): bool
    {
        return ReasignacionGate::puedeReasignar();
    }

    public function rules(): array
    {
        return [
            'taller_destino_id' => ['required', 'integer', 'exists:bylopez_cc_talleres,id'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'motivo' => ['required', 'string', 'max:2000'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'situacion_fisica' => ['required', Rule::enum(SituacionFisicaReasignacion::class)],
            'confirmacion_fisica' => ['nullable', 'boolean'],
            'traslado' => ['nullable', Rule::in(['completado', 'en_transito'])],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.detalle_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required'],
            'lineas.*.cantidad_en_proceso' => ['nullable'],
            // Reparto por prioridad de lo que se traslada (P1); el servicio lo valida bajo bloqueo.
            'lineas.*.urgente' => ['nullable'],
            'lineas.*.alta' => ['nullable'],
            'lineas.*.normal' => ['nullable'],
            'lineas.*.base' => ['nullable', 'string', 'max:40'],
        ];
    }
}
