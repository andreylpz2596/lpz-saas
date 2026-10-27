<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Estado de un Pago ByLopez (D-59 / P-04, Doc. 05 §42.4). Fuente UNICA de los cuatro valores: nada del
 * modulo debe repetir estos strings.
 *
 *   sin_revisar ──► confirmado ──► reversado   (reversado: capacidad futura, PAG-I)
 *        └────────► rechazado
 *
 * PAG-B solo define el catalogo y las reglas; ninguna transicion se ejecuta todavia (eso es de los servicios
 * de registrar / confirmar / rechazar, en bloques posteriores). No existe un estado «registrado»: registrar
 * y aplicar es una operacion atomica y el pago nace `sin_revisar`.
 */
enum EstadoPago: string
{
    /** Registrado y aplicado a pedidos, pendiente de revision. Ya baja el saldo comercial (P-01). */
    case SIN_REVISAR = 'sin_revisar';

    /** Revisado y validado por un usuario autorizado; es cuando nace la Transaction nativa. */
    case CONFIRMADO = 'confirmado';

    /** El ingreso nunca fue valido. Solo desde `sin_revisar`. Sus aplicaciones se anulan. */
    case RECHAZADO = 'rechazado';

    /** Pago ya confirmado que se deshace. Operacion excepcional; mecanica abierta (PAG-I). */
    case REVERSADO = 'reversado';

    /**
     * Estados a los que puede pasar desde este (transiciones aprobadas, P-04).
     *
     * @return list<self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::SIN_REVISAR => [self::CONFIRMADO, self::RECHAZADO],
            self::CONFIRMADO => [self::REVERSADO],
            self::RECHAZADO, self::REVERSADO => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), true);
    }

    /** Rechazado y reversado no tienen salida. */
    public function esFinal(): bool
    {
        return $this->transicionesPermitidas() === [];
    }

    /**
     * Las aplicaciones ACTIVAS de un pago en este estado disminuyen el saldo comercial. Un pago rechazado o
     * reversado ya no cuenta: sus aplicaciones deben estar anuladas (I-3).
     */
    public function cuentaParaSaldo(): bool
    {
        return $this === self::SIN_REVISAR || $this === self::CONFIRMADO;
    }

    /** Dinero verificado por un usuario autorizado (nace la Transaction nativa). */
    public function esVerificado(): bool
    {
        return $this === self::CONFIRMADO;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
