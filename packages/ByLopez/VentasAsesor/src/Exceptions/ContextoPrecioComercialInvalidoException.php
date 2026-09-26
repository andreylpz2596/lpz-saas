<?php

namespace ByLopez\VentasAsesor\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Un contexto de precio comercial EXPLICITO que no es coherente (RT-03B).
 *
 * Fail closed: cuando alguien declara "este carrito es de este cliente" y los datos
 * no lo sostienen, no se precia con nadie. Nunca se cae al cliente de la sesion ni
 * al invitado, porque eso usaria en silencio el grupo de otra persona.
 */
class ContextoPrecioComercialInvalidoException extends RuntimeException
{
    public const CARRITO_INEXISTENTE = 'carrito_inexistente';

    public const CLIENTE_INEXISTENTE = 'cliente_inexistente';

    public const CARRITO_SIN_CLIENTE = 'carrito_sin_cliente';

    public const CLIENTE_NO_COINCIDE = 'cliente_no_coincide';

    public const IDENTIFICADOR_INVALIDO = 'identificador_invalido';

    public const CARRITO_DE_OTRO_CLIENTE = 'carrito_de_otro_cliente';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    /** Respuesta HTTP controlada: 409, con codigo estable y sin datos del cliente. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'La operacion comercial no tiene un contexto de precio coherente.',
            'codigo' => $this->codigo,
        ], 409);
    }
}
