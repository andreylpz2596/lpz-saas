<?php

namespace ByLopez\CorteConfeccion\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * F4: unica regla de compatibilidad de rollos para una Orden de Corte.
 *
 * Los rollos son compatibles si comparten EXACTAMENTE el mismo tipo_tela_id
 * (atributo Bagisto tipo_tela). Ya NO importan el color, el gramaje, la
 * referencia, la compra ni el proveedor: una orden puede mezclar colores. Las
 * comprobaciones legitimas se conservan: todo rollo debe tener tipo_tela_id y
 * color_option_id estructurados (los colores de la orden salen SOLO de ellos).
 *
 * La disponibilidad/reserva (estado, ordenes abiertas) la valida
 * OrdenCorteService::validarRollosDisponibles().
 */
final class CompatibilidadRollosCorte
{
    /**
     * @return array{tipo_tela_id: int, colores: array<int, string>} colores: color_option_id => etiqueta
     */
    public static function validar(Collection $rollos): array
    {
        if ($rollos->isEmpty()) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollo-no-disponible')]);
        }

        foreach ($rollos as $rollo) {
            if (! $rollo->tipo_tela_id) {
                throw ValidationException::withMessages([
                    'rollo_ids' => trans('corteconfeccion::app.errors.rollo-sin-tipo-tela', ['codigo' => $rollo->codigo]),
                ]);
            }

            if (! $rollo->color_option_id) {
                throw ValidationException::withMessages([
                    'rollo_ids' => trans('corteconfeccion::app.errors.rollo-sin-color-vinculado', ['codigo' => $rollo->codigo]),
                ]);
            }
        }

        if ($rollos->pluck('tipo_tela_id')->map(fn ($id) => (int) $id)->unique()->count() > 1) {
            throw ValidationException::withMessages(['rollo_ids' => trans('corteconfeccion::app.errors.rollos-incompatibles')]);
        }

        $colores = [];

        foreach ($rollos as $rollo) {
            $colores[(int) $rollo->color_option_id] ??= (string) $rollo->color;
        }

        return [
            'tipo_tela_id' => (int) $rollos->first()->tipo_tela_id,
            'colores' => $colores,
        ];
    }
}
