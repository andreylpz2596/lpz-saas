<?php

namespace ByLopez\VentasAsesor\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo la FORMA de la solicitud. Las reglas de negocio (monto contra saldo, medio activo, referencia segun su
 * politica, fecha, alcance) las valida RegistrarPagoService con el saldo recalculado bajo bloqueo: el backend es
 * la autoridad y la interfaz solo las refleja.
 */
class RegistrarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Pago combinado: la solicitud trae `partes` en vez de monto/medio/referencia unicos. */
    public function esCombinado(): bool
    {
        return $this->has('partes');
    }

    public function rules(): array
    {
        if ($this->esCombinado()) {
            return [
                'partes' => ['required', 'array', 'min:2', 'max:6'],
                'partes.*.monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
                'partes.*.plataforma' => ['required', 'string', 'max:30'],
                'partes.*.comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
                'partes.*.referencia' => ['nullable', 'string', 'max:100'],
                'fecha_pago' => ['required', 'date_format:Y-m-d'],
                'observacion' => ['nullable', 'string', 'max:1000'],
                'idempotency_key' => ['required', 'string', 'min:16', 'max:90'],
            ];
        }

        return [
            'monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'medio_cobro' => ['required', 'string', 'max:40'],
            'plataforma' => ['nullable', 'string', 'max:30'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ];
    }
}
