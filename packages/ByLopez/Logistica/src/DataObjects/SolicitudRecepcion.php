<?php

namespace ByLopez\Logistica\DataObjects;

/**
 * Solicitud de RECEPCION FISICA de una devolucion en bodega.
 *
 * - `lineas`: retorno_item_id => ['recibidas' => n, 'aptas' => n, 'novedad' => n, 'motivo' => ?texto]. Debe traer TODAS las
 *   lineas de la devolucion (la recepcion es de la devolucion completa; lo que no llego va como faltante).
 * - `idempotencyKey`: la misma clave = la misma operacion (doble clic, reintento, timeout).
 */
final class SolicitudRecepcion
{
    /** @param  array<int|string, array<string, mixed>>  $lineas */
    public function __construct(
        public readonly int $retornoId,
        public readonly string $fecha,
        public readonly array $lineas,
        public readonly string $idempotencyKey,
        public readonly ?string $observacion = null,
    ) {}
}
