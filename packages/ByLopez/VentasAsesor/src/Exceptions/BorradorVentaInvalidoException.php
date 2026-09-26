<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/** Operacion sobre un borrador de venta rechazada por una regla del dominio (03B). */
class BorradorVentaInvalidoException extends RuntimeException
{
    public const INEXISTENTE = 'borrador_inexistente';

    /** El estado no admite la operacion (confirmando, confirmado o descartado). */
    public const NO_EDITABLE = 'borrador_no_editable';

    /** Solo el propietario puede trabajarlo mientras dure su ventana de asignacion. */
    public const NO_ES_PROPIETARIO = 'no_es_propietario';

    /** Todavia dentro de la ventana de 1 dia habil del propietario. */
    public const AUN_ASIGNADO = 'aun_asignado';

    /** El actor no tiene permiso para operar borradores de venta. */
    public const SIN_PERMISO = 'sin_permiso';

    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    /** Referencia externa vacia tras recortar o de mas de 191 caracteres. */
    public const REFERENCIA_INVALIDA = 'referencia_externa_invalida';

    /** La referencia externa ya pertenece a un borrador de otro cliente. */
    public const REFERENCIA_EN_USO = 'referencia_externa_en_uso';

    /** El carrito del borrador ya no existe. */
    public const SIN_CARRITO = 'sin_carrito';

    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Operacion de borrador no valida: '.$codigo);
    }
}
