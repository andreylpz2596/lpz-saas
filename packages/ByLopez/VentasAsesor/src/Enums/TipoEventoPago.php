<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Tipos de evento del historial append-only de un pago (`bylopez_va_pagos_eventos`, Doc. 05 §42.2).
 *
 * PAG-B solo define el catalogo: ningun servicio genera todavia estos eventos. Los cuatro primeros
 * acompanan cada transicion de `EstadoPago`; los dos ultimos son informativos y no cambian el estado del pago.
 */
enum TipoEventoPago: string
{
    case REGISTRADO = 'registrado';
    case CONFIRMADO = 'confirmado';
    case RECHAZADO = 'rechazado';
    case REVERSADO = 'reversado';

    /** PAG-G: un comprobante se adjunta; nunca confirma el pago por si solo. */
    case EVIDENCIA_AGREGADA = 'evidencia_agregada';

    /** Aviso (no bloqueo) de referencia repetida para el mismo medio de cobro (PAG-C). */
    case POSIBLE_DUPLICADO = 'posible_duplicado';

    /** Estado al que lleva el evento, o null si es solo informativo. */
    public function estadoResultante(): ?EstadoPago
    {
        return match ($this) {
            self::REGISTRADO => EstadoPago::SIN_REVISAR,
            self::CONFIRMADO => EstadoPago::CONFIRMADO,
            self::RECHAZADO => EstadoPago::RECHAZADO,
            self::REVERSADO => EstadoPago::REVERSADO,
            self::EVIDENCIA_AGREGADA, self::POSIBLE_DUPLICADO => null,
        };
    }

    /** Rechazar y reversar exigen un motivo (Doc. 05 §42.2). */
    public function exigeMotivo(): bool
    {
        return $this === self::RECHAZADO || $this === self::REVERSADO;
    }
}
