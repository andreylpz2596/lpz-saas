<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** Cambio o restauracion del grupo comercial TEMPORAL de una venta rechazado por una regla del dominio (GRP-01). */
class GrupoVentaInvalidoException extends RuntimeException
{
    /** El actor no tiene `ventas_asesor.pedidos.cambiar_grupo_temporal`. */
    public const SIN_PERMISO = 'sin_permiso_cambiar_grupo';

    public const MOTIVO_REQUERIDO = 'motivo_requerido';

    public const MOTIVO_DEMASIADO_LARGO = 'motivo_demasiado_largo';

    /** El grupo pedido ya es el grupo aplicado a la venta. */
    public const GRUPO_SIN_CAMBIO = 'grupo_sin_cambio';

    /** Restaurar sin un override vigente. */
    public const SIN_OVERRIDE = 'sin_override_vigente';

    /** El cliente del carrito no existe o no tiene un grupo habitual apto: no hay grupo original que conservar. */
    public const CLIENTE_SIN_GRUPO_HABITUAL = 'cliente_sin_grupo_habitual';

    public const MENSAJES = [
        self::SIN_PERMISO => 'No tienes permiso para cambiar el grupo de esta venta.',
        self::MOTIVO_REQUERIDO => 'Indica el motivo del cambio de grupo.',
        self::MOTIVO_DEMASIADO_LARGO => 'El motivo es demasiado largo (maximo 500 caracteres).',
        self::GRUPO_SIN_CAMBIO => 'Ese ya es el grupo aplicado a esta venta.',
        self::SIN_OVERRIDE => 'Esta venta ya usa el grupo habitual del cliente.',
        self::CLIENTE_SIN_GRUPO_HABITUAL => 'El cliente de esta venta no tiene un grupo habitual valido.',
    ];

    public function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAJES[$codigo] ?? 'Cambio de grupo no valido: '.$codigo);
    }
}
