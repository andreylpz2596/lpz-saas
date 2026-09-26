<?php

namespace ByLopez\VentasAsesor\DataObjects;

use DateTimeInterface;

/**
 * Lectura del borrador para la UI futura (03B). Cliente y totales salen del carrito de
 * Bagisto; aqui solo se presentan.
 *
 * `avisos` (P-09 y grupo): cliente_inactivo, cliente_suspendido, grupo_no_apto. El
 * borrador se puede trabajar igual; `clienteConfirmable` dice si la futura confirmacion
 * lo permitiria.
 */
final class VistaBorradorVenta
{
    public const AVISO_CLIENTE_INACTIVO = 'cliente_inactivo';

    public const AVISO_CLIENTE_SUSPENDIDO = 'cliente_suspendido';

    public const AVISO_GRUPO_NO_APTO = 'grupo_no_apto';

    /**
     * @param  array<string, mixed>|null  $cliente
     * @param  array<string, float|int>|null  $totales
     * @param  list<string>  $avisos
     */
    public function __construct(
        public readonly int $id,
        public readonly string $estado,
        public readonly ?int $cartId,
        public readonly ?array $cliente,
        public readonly ?array $totales,
        public readonly int $propietarioAdminId,
        public readonly int $creadoPorAdminId,
        public readonly ?DateTimeInterface $asignadoHasta,
        public readonly bool $reclamable,
        public readonly string $canalVenta,
        public readonly string $mecanismoCreacion,
        public readonly ?string $externalReference,
        public readonly ?int $orderId,
        public readonly bool $clienteConfirmable,
        public readonly array $avisos,
    ) {}
}
