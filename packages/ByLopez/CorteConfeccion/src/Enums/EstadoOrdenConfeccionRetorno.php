<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoOrdenConfeccionRetorno: string
{
    case PendienteBodega = 'pendiente_bodega';
    case RecibidaBodega = 'recibida_bodega';

    public function label(): string
    {
        return match ($this) {
            self::PendienteBodega => 'Pendiente de recepcion',
            self::RecibidaBodega => 'Recibida por bodega',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendienteBodega => 'label-info',
            self::RecibidaBodega => 'label-completed',
        };
    }

    public static function badgeHtml(?string $value): string
    {
        $estado = self::tryFrom((string) $value);

        if (! $estado) {
            return sprintf('<p class="label-info">%s</p>', e(str_replace('_', ' ', ucfirst((string) $value))));
        }

        return sprintf('<p class="%s">%s</p>', $estado->badgeClass(), e($estado->label()));
    }
}
