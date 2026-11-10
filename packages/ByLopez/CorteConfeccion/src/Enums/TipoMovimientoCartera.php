<?php

namespace ByLopez\CorteConfeccion\Enums;

enum TipoMovimientoCartera: string
{
    case Abono = 'abono';
    case Prestamo = 'prestamo';
    case Devolucion = 'devolucion';
    case SaldoInicialTrabajo = 'saldo_inicial_trabajo';
    case SaldoInicialAnticipo = 'saldo_inicial_anticipo';
    case SaldoInicialPrestamo = 'saldo_inicial_prestamo';

    public function label(): string
    {
        return match ($this) {
            self::Abono => 'Abono por trabajo',
            self::Prestamo => 'Préstamo',
            self::Devolucion => 'Devolución de préstamo',
            self::SaldoInicialTrabajo => 'Saldo inicial · deuda previa por trabajo',
            self::SaldoInicialAnticipo => 'Saldo inicial · anticipo previo',
            self::SaldoInicialPrestamo => 'Saldo inicial · préstamo anterior',
        };
    }

    public function codigoPrefijo(): string
    {
        return match ($this) {
            self::Abono => 'AB',
            self::Prestamo => 'PR',
            self::Devolucion => 'DV',
            default => 'SI',
        };
    }

    /** Movimientos que son dinero entregado por trabajo (y pueden aplicarse a ordenes). */
    public function esDineroPorTrabajo(): bool
    {
        return in_array($this, [self::Abono, self::SaldoInicialAnticipo], true);
    }

    public function esPrestamo(): bool
    {
        return in_array($this, [self::Prestamo, self::SaldoInicialPrestamo], true);
    }

    public function esSaldoInicial(): bool
    {
        return in_array($this, [self::SaldoInicialTrabajo, self::SaldoInicialAnticipo, self::SaldoInicialPrestamo], true);
    }
}
