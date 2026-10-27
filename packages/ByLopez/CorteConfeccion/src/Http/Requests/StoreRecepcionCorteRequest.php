<?php

namespace ByLopez\CorteConfeccion\Http\Requests;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecepcionCorteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $detalles = collect($this->input('detalles', []))
            ->filter(fn ($detalle) => ! empty($detalle['orden_corte_detalle_id']) || ! empty($detalle['cantidad_recibida']) || ! empty($detalle['cantidad_defectuosa']))
            ->values()
            ->all();

        $this->merge(['detalles' => $detalles]);
    }

    public function authorize(): bool
    {
        return bouncer()->hasPermission('corte_confeccion.recepciones_corte')
            && CantidadCortadaGate::userCanConfirmarRecepcion();
    }

    public function rules(): array
    {
        return [
            'fecha_recepcion' => ['required', 'date'],
            // F3: UNA sola merma total no reutilizable de toda la orden (kg,
            // hasta 3 decimales como los pesos de rollos). El limite superior
            // (peso de tela asignado a la orden) se valida en withValidator().
            'merma_total' => ['required', 'numeric', 'gte:0', 'decimal:0,3'],
            // Los pesos historicos ya no se aceptan: descartarlos en silencio
            // ocultaria tela que el usuario cree que vuelve al inventario.
            'peso_sobrante_usable' => ['prohibited'],
            'peso_retasos' => ['prohibited'],
            'peso_desperdicio' => ['prohibited'],
            'observacion' => ['nullable', 'string'],
            'recibido_por' => ['required', 'string', 'max:255'],
            'detalles' => ['required', 'array', 'min:1'],
            // Identidad real de la fila: cada una debe apuntar a un
            // OrdenCorteDetalle que pertenezca EXACTAMENTE a esta orden (no a
            // otra) y no puede repetirse dentro del mismo envio. Producto,
            // SKU, talla y cantidad reportada por el cortador se derivan de
            // este detalle en el Service -- nunca se leen del cliente (ver
            // AUD-02 §12.2: el bug original era comparar por posicion de
            // array en vez de por esta identidad).
            'detalles.*.orden_corte_detalle_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('bylopez_cc_ordenes_corte_detalles', 'id')
                    ->where(fn ($query) => $query->where('orden_corte_id', $this->route('ordenCorteId'))),
            ],
            'detalles.*.cantidad_recibida' => ['required', 'integer', 'gte:0'],
            'detalles.*.cantidad_defectuosa' => ['required', 'integer', 'gte:0'],
            'detalles.*.observacion' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        $legacy = trans('corteconfeccion::app.errors.recepcion-pesos-legacy');

        return [
            'merma_total.decimal' => trans('corteconfeccion::app.errors.merma-total-invalida'),
            'merma_total.gte' => trans('corteconfeccion::app.errors.merma-total-invalida'),
            'peso_sobrante_usable.prohibited' => $legacy,
            'peso_retasos.prohibited' => $legacy,
            'peso_desperdicio.prohibited' => $legacy,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Limite fisico: la merma no puede superar la tela asignada a la
            // orden (cada peso_inicial_usado es el peso completo del rollo).
            if (! $validator->errors()->has('merma_total') && is_numeric($this->input('merma_total'))) {
                $orden = OrdenCorte::query()->find($this->route('ordenCorteId'));

                if ($orden && round((float) $this->input('merma_total'), 3) > round($orden->pesoBase(), 3)) {
                    $validator->errors()->add('merma_total', trans('corteconfeccion::app.errors.merma-excede-peso-asignado'));
                }
            }

            // Reportado por el cortador, indexado por el ID real del detalle
            // (no por posicion): asi la fila 2 del POST se compara siempre
            // contra el detalle que dice ser, sin importar en que orden haya
            // llegado el formulario ni como se hayan agrupado visualmente.
            $cantidadesReportadasPorDetalleId = DB::table('bylopez_cc_ordenes_corte_detalles')
                ->where('orden_corte_id', $this->route('ordenCorteId'))
                ->pluck('cantidad_real_cortada', 'id');

            $puedeEditarCantidad = CantidadCortadaGate::userCanEdit();

            foreach ($this->input('detalles', []) as $index => $detalle) {
                $detalleOrdenId = (int) ($detalle['orden_corte_detalle_id'] ?? 0);

                // Si orden_corte_detalle_id no existe o no pertenece a esta
                // orden, la regla Rule::exists() de arriba ya lo rechazo --
                // aqui solo se evalua cuando si es valido.
                if (! $cantidadesReportadasPorDetalleId->has($detalleOrdenId)) {
                    continue;
                }

                $cantidadReportada = (int) $cantidadesReportadasPorDetalleId->get($detalleOrdenId);
                $cantidadRecibida = (int) ($detalle['cantidad_recibida'] ?? 0);

                if ($cantidadRecibida !== $cantidadReportada) {
                    if (! $puedeEditarCantidad) {
                        $validator->errors()->add("detalles.$index.cantidad_recibida", trans('corteconfeccion::app.errors.cantidad-cortada-no-autorizada'));
                    }

                    if (blank($detalle['observacion'] ?? null)) {
                        $validator->errors()->add("detalles.$index.observacion", trans('corteconfeccion::app.errors.observacion-requerida-diferencia'));
                    }
                }

                if ((int) ($detalle['cantidad_defectuosa'] ?? 0) > $cantidadRecibida) {
                    $validator->errors()->add("detalles.$index.cantidad_defectuosa", 'La cantidad defectuosa no puede superar la recibida.');
                }
            }
        });
    }
}
