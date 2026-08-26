<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StoreRecepcionCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $detalles = collect($this->input('detalles', []))
            ->filter(fn ($detalle) => ! empty($detalle['product_id']) || ! empty($detalle['talla']) || ! empty($detalle['cantidad_recibida']) || ! empty($detalle['cantidad_defectuosa']))
            ->values()
            ->all();

        $this->merge(['detalles' => $detalles]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.recepciones_corte');
    }

    public function rules(): array
    {
        return [
            'fecha_recepcion'                  => ['required', 'date'],
            'peso_sobrante_usable'             => ['required', 'numeric', 'gte:0'],
            'peso_retasos'                     => ['required', 'numeric', 'gte:0'],
            'peso_desperdicio'                 => ['required', 'numeric', 'gte:0'],
            'observacion'                      => ['nullable', 'string'],
            'recibido_por'                     => ['required', 'string', 'max:255'],
            'detalles'                         => ['required', 'array', 'min:1'],
            'detalles.*.product_id'            => ['required', 'exists:products,id'],
            'detalles.*.talla'                 => ['required', 'string', 'max:50'],
            'detalles.*.cantidad_recibida'     => ['required', 'integer', 'gte:0'],
            'detalles.*.cantidad_defectuosa'   => ['required', 'integer', 'gte:0'],
            'detalles.*.observacion'           => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! CantidadCortadaGate::userCanEdit()) {
                $cantidadesActuales = DB::table('bylopez_cc_ordenes_corte_detalles')
                    ->where('orden_corte_id', $this->route('ordenCorteId'))
                    ->orderBy('id')
                    ->pluck('cantidad_real_cortada')
                    ->values();

                foreach ($this->input('detalles', []) as $index => $detalle) {
                    $valorActual = (int) ($cantidadesActuales[$index] ?? 0);
                    $valorNuevo = (int) ($detalle['cantidad_recibida'] ?? 0);

                    if ($valorNuevo !== $valorActual) {
                        $validator->errors()->add("detalles.$index.cantidad_recibida", trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'));
                    }
                }
            }

            foreach ($this->input('detalles', []) as $index => $detalle) {
                if ((int) ($detalle['cantidad_defectuosa'] ?? 0) > (int) ($detalle['cantidad_recibida'] ?? 0)) {
                    $validator->errors()->add("detalles.$index.cantidad_defectuosa", 'La cantidad defectuosa no puede superar la recibida.');
                }
            }
        });
    }
}
