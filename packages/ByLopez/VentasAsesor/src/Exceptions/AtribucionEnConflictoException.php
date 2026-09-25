<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Se intento registrar una atribucion para un pedido que ya la tiene, con un
 * contexto comercial DISTINTO.
 *
 * No se sobrescribe ni se devuelve exito silencioso: una reasignacion legitima se
 * hace con AsignacionAsesorService, que exige permiso, motivo e historial.
 *
 * El mensaje solo nombra los campos en conflicto, nunca sus valores: notas y
 * referencias externas pueden contener datos del cliente.
 */
class AtribucionEnConflictoException extends RuntimeException
{
    /** @param  list<string>  $camposEnConflicto */
    public function __construct(
        public readonly int $orderId,
        public readonly array $camposEnConflicto,
        public readonly ?int $atribucionId = null,
    ) {
        parent::__construct(sprintf(
            'El pedido %d ya tiene una atribucion comercial distinta (atribucion %s). Campos en conflicto: %s.',
            $orderId,
            $atribucionId === null ? 'desconocida' : (string) $atribucionId,
            implode(', ', $camposEnConflicto),
        ));
    }
}
