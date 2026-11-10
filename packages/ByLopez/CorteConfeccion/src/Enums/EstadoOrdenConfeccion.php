<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoOrdenConfeccion: string
{
    case Activa = 'activa';
    case EnConfeccion = 'en_confeccion';
    case TerminadaTaller = 'terminada_taller';
    case RecibidaBodega = 'recibida_bodega';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';
    /** Responsabilidad neta cero: todo su saldo se reasigno a otros talleres (el historial queda intacto). */
    case Reasignada = 'reasignada';

    public function label(): string
    {
        return match ($this) {
            self::Activa => 'Activa',
            self::EnConfeccion => 'En confeccion',
            self::TerminadaTaller => 'Terminada por taller',
            self::RecibidaBodega => 'Recibida en bodega',
            self::Cerrada => 'Cerrada',
            self::Anulada => 'Anulada',
            self::Reasignada => 'Reasignada',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Activa => 'label-active',
            self::EnConfeccion => 'label-info',
            self::TerminadaTaller => 'label-pending',
            self::RecibidaBodega => 'label-completed',
            self::Cerrada => 'label-closed',
            self::Anulada => 'label-canceled',
            self::Reasignada => 'label-closed',
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
