<?php

namespace ByLopez\VentasAsesor\Http\Requests\Cartera;

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Services\Cartera\BandejaPagosService as Bandeja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parametros GET de la pantalla Cartera / Pagos: solo estructura. El alcance lo decide el servicio con el actor; un
 * parametro `registrador` nunca amplia lo que un actor con view_own puede ver (se ignora).
 */
class ListarPagosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(EstadoPago::valores())],
            'medio' => ['nullable', 'string', 'max:40'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'registrador' => ['nullable', 'integer', 'min:1'],
            'resumen' => ['nullable', Rule::in(Bandeja::RESUMENES)],
            'pago' => ['nullable', 'integer', 'min:1'],
            'cliente' => ['nullable', 'integer', 'min:1'],
            'abono' => ['nullable', 'integer', 'min:1'],
            'ok' => ['nullable', Rule::in(['confirmado', 'rechazado'])],
            'pago_ok' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: ?string, estado: ?string, medio: ?string, desde: ?string, hasta: ?string, registrador: ?string, resumen: ?string} */
    public function filtros(): array
    {
        $valor = fn (string $k) => ($v = trim((string) $this->input($k, ''))) === '' ? null : $v;

        return [
            'q' => $valor('q'),
            'estado' => $valor('estado'),
            'medio' => $valor('medio'),
            'desde' => $valor('desde'),
            'hasta' => $valor('hasta'),
            'registrador' => $valor('registrador'),
            'resumen' => $valor('resumen'),
        ];
    }
}
