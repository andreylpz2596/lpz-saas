<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Support\OrdenConfeccionGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportarOrdenConfeccionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $detalles = collect($this->input('detalles', []))
            ->filter(function ($detalle) {
                if (! is_array($detalle)) {
                    return true;
                }

                $cantidad = $detalle['cantidad'] ?? null;

                return ! (
                    $cantidad === null
                    || $cantidad === ''
                    || (is_numeric($cantidad) && (int) $cantidad === 0)
                );
            })
            ->all();

        $this->merge(['detalles' => $detalles]);
    }

    public function authorize(): bool
    {
        $orden = OrdenConfeccion::query()->with('taller')->find((int) $this->route('id'));

        return $orden && OrdenConfeccionGate::puedeReportarTaller($orden);
    }

    public function rules(): array
    {
        return [
            'fecha_reporte_taller' => ['nullable', 'date'],
            'observacion_taller' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.id' => ['required', 'integer', 'exists:bylopez_cc_ordenes_confeccion_detalles,id'],
            'detalles.*.cantidad' => ['required', 'integer', 'gt:0'],
            // Reparto por prioridad (P1): opcional; sin el, el servicio aplica el sugerido. El servicio valida enteros,
            // suma y saldo por nivel bajo bloqueo. `base` = saldo por nivel que vio la pantalla («u:a:n»).
            'detalles.*.urgente' => ['nullable'],
            'detalles.*.alta' => ['nullable'],
            'detalles.*.normal' => ['nullable'],
            'detalles.*.base' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $orden = OrdenConfeccion::query()->with('detalles')->find((int) $this->route('id'));

            if (! $orden) {
                return;
            }

            $detallesOrden = $orden->detalles->keyBy('id');
            foreach ($this->input('detalles', []) as $index => $detalleInput) {
                $detalle = $detallesOrden->get((int) ($detalleInput['id'] ?? 0));

                if (! $detalle) {
                    $validator->errors()->add("detalles.{$index}.id", trans('corteconfeccion::app.errors.detalle-no-pertenece-orden'));

                    continue;
                }

                $reportada = (int) ($detalleInput['cantidad'] ?? 0);

                if ($reportada > $detalle->cantidad_vigente) {
                    $validator->errors()->add("detalles.{$index}.cantidad", trans('corteconfeccion::app.errors.cantidad-retorno-excede-entregada'));
                }
            }
        });
    }
}
