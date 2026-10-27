<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Support\OrdenConfeccionGate;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecibirOrdenConfeccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return OrdenConfeccionGate::puedeRecibirBodega();
    }

    /** Toast de Bagisto (flash 'error') ademas del detalle en el formulario: el usuario ve por que no se guardo. */
    protected function failedValidation(ValidatorContract $validator): void
    {
        session()->flash('error', 'No se pudo guardar la recepcion: '.str_replace(['"', "\n"], ["'", ' '], (string) $validator->errors()->first()));

        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        return [
            'fecha_recepcion_bodega' => ['nullable', 'date'],
            'observacion_bodega' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.id' => ['required', 'integer', 'exists:bylopez_cc_ordenes_confeccion_retorno_detalles,id'],
            'detalles.*.cantidad_recibida' => ['required', 'integer', 'min:0'],
            'detalles.*.cantidad_defectuosa' => ['required', 'integer', 'min:0'],
            'detalles.*.cantidad_faltante' => ['required', 'integer', 'min:0'],
            'detalles.*.observacion_bodega' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $retorno = OrdenConfeccionRetorno::query()->with('detalles')->find((int) $this->route('retornoId'));

            if (! $retorno) {
                return;
            }

            $detallesRetorno = $retorno->detalles->keyBy('id');
            $idsRecibidos = collect($this->input('detalles', []))->pluck('id')->map(fn ($id) => (int) $id);

            if ($idsRecibidos->unique()->count() !== $detallesRetorno->count()) {
                $validator->errors()->add('detalles', trans('corteconfeccion::app.errors.detalles-incompletos'));
            }

            foreach ($this->input('detalles', []) as $index => $detalleInput) {
                $detalle = $detallesRetorno->get((int) ($detalleInput['id'] ?? 0));

                if (! $detalle) {
                    $validator->errors()->add("detalles.{$index}.id", trans('corteconfeccion::app.errors.detalle-no-pertenece-orden'));

                    continue;
                }

                $recibida = (int) ($detalleInput['cantidad_recibida'] ?? 0);
                $defectuosa = (int) ($detalleInput['cantidad_defectuosa'] ?? 0);
                $faltante = (int) ($detalleInput['cantidad_faltante'] ?? 0);
                $reportada = (int) $detalle->cantidad_reportada;

                if ($recibida + $defectuosa + $faltante !== $reportada) {
                    $validator->errors()->add("detalles.{$index}.cantidad_recibida", trans('corteconfeccion::app.errors.cantidades-recepcion-no-cuadran'));
                }

                if (($defectuosa > 0 || $faltante > 0) && blank($detalleInput['observacion_bodega'] ?? null)) {
                    $validator->errors()->add("detalles.{$index}.observacion_bodega", trans('corteconfeccion::app.errors.observacion-requerida-faltante-bodega'));
                }
            }
        });
    }
}
