<?php

namespace ByLopez\VentasAsesor\Http\Requests\Cartera;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo la FORMA de la solicitud de abono multipedido. Suma, saldos, alcance, medio y referencia los valida
 * RegistrarAbonoClienteService bajo bloqueo: el backend es la autoridad y la interfaz solo refleja las reglas.
 */
class RegistrarAbonoClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'medio_cobro' => ['required', 'string', 'max:40'],
            'plataforma' => ['nullable', 'string', 'max:30'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'distribucion' => ['required', 'array', 'min:1', 'max:200'],
            'distribucion.*.pedido_id' => ['required', 'integer', 'min:1'],
            'distribucion.*.monto' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }
}
