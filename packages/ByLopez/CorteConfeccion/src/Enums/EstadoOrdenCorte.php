<?php

namespace ByLopez\CorteConfeccion\Enums;

enum EstadoOrdenCorte: string
{
    case PendienteEntrega = 'pendiente_entrega';
    case EntregadoACortador = 'entregado_a_cortador';
    case EnCorte = 'en_corte';
    case ReportadoPorCortador = 'reportado_por_cortador';
    case RecibidoBodega = 'recibido_bodega';
    case Cerrado = 'cerrado';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::PendienteEntrega => 'Pendiente de entrega',
            self::EntregadoACortador => 'Entregado al cortador',
            self::EnCorte => 'En corte',
            self::ReportadoPorCortador => 'Reportado por cortador',
            self::RecibidoBodega => 'Recibido en bodega',
            self::Cerrado => 'Cerrado',
            self::Anulado => 'Anulado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendienteEntrega => 'label-pending',
            self::EntregadoACortador => 'label-active',
            self::EnCorte => 'label-processing',
            self::ReportadoPorCortador => 'label-processing',
            self::RecibidoBodega => 'label-active',
            self::Cerrado => 'label-closed',
            self::Anulado => 'label-canceled',
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
