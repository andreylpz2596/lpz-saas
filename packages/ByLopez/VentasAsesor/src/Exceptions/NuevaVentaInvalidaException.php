<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Rechazos propios de la operacion "Nueva venta rapida" (03G-B). Los rechazos que ya
 * tienen un codigo en otro servicio (cliente, borrador, variante, linea, entrega, pago,
 * confirmacion) NO se repiten aqui: pasan tal cual y 03G-C los mapea todos.
 *
 * `LOTE_RECHAZADO` envuelve el fallo de UNA talla del lote (todo o nada): trae la variante
 * que fallo, el codigo de la causa y la excepcion original.
 */
class NuevaVentaInvalidaException extends RuntimeException
{
    public const CANAL_NO_PERMITIDO = 'canal_no_permitido';

    public const ACCESO_RAPIDO_INEXISTENTE = 'acceso_rapido_inexistente';

    public const ACCESO_RAPIDO_SIN_RESOLVER = 'acceso_rapido_sin_resolver';

    public const LOTE_VACIO = 'lote_vacio';

    public const LOTE_RECHAZADO = 'lote_rechazado';

    /** El cliente no tiene direccion predeterminada ni se indico una: hace falta una real (D-30). */
    public const DIRECCION_FACTURACION_REQUERIDA = 'direccion_facturacion_requerida';

    public function __construct(
        public readonly string $codigo,
        public readonly ?int $varianteId = null,
        public readonly ?string $causa = null,
        ?Throwable $previa = null,
    ) {
        parent::__construct('Nueva venta no valida: '.$codigo.($causa ? ' ('.$causa.')' : ''), 0, $previa);
    }
}
