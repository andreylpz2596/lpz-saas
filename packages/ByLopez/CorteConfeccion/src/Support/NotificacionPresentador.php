<?php

namespace ByLopez\CorteConfeccion\Support;

use ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion;
use ByLopez\CorteConfeccion\Models\Notificacion;
use Illuminate\Support\Carbon;

/** Texto de un aviso del modulo para la campana y el listado de notificaciones (una sola definicion). */
class NotificacionPresentador
{
    /** @return array<string, mixed> */
    public static function presentar(Notificacion $notificacion): array
    {
        $datos = (array) $notificacion->datos;
        $cambios = collect($datos['cambios'] ?? []);
        $primero = $cambios->first() ?? [];
        $ordenes = $cambios->pluck('orden_codigo')->unique();

        $variante = trim(implode(' ', array_filter([
            $primero['nombre'] ?? $primero['sku'] ?? null,
            $primero['color'] ?? null,
            isset($primero['talla']) && $primero['talla'] !== '' ? 'T'.$primero['talla'] : null,
        ])));

        $texto = $primero
            ? sprintf(
                '%d u. %s: %s → %s',
                (int) $primero['cantidad'],
                $variante,
                PrioridadOrdenConfeccion::tryFrom((string) $primero['desde'])?->label() ?? $primero['desde'],
                PrioridadOrdenConfeccion::tryFrom((string) $primero['hacia'])?->label() ?? $primero['hacia'],
            )
            : '';

        if ($cambios->count() > 1) {
            $texto .= sprintf(' y %d cambio%s más', $cambios->count() - 1, $cambios->count() > 2 ? 's' : '');
        }

        $fecha = isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : $notificacion->created_at;

        return [
            'id' => $notificacion->id,
            'titulo' => 'Cambio de prioridad · '.$ordenes->implode(', '),
            'texto' => $texto,
            'autor' => $datos['usuario'] ?? null,
            'nota' => $datos['nota'] ?? null,
            'fecha' => $fecha?->format('d-m-Y H:i'),
            'leida' => $notificacion->leida_en !== null,
            'url' => route('admin.corte_confeccion.notificaciones.abrir', $notificacion->id),
            'hacia' => $primero['hacia'] ?? null,
        ];
    }
}
