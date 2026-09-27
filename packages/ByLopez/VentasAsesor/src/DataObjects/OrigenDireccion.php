<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * De donde sale una direccion del borrador (03D, entrada).
 *
 * - guardada: una CustomerAddress del cliente del carrito (se verifica la pertenencia).
 * - puntual: solo para esta venta; NO se guarda en el cliente.
 * - igual_facturacion (solo envio): Bagisto copia la facturacion (use_for_shipping).
 * - punto_recogida (solo envio): una bodega real (fuente de inventario activa); null =
 *   la unica activa del canal.
 */
final class OrigenDireccion
{
    public const GUARDADA = 'guardada';

    public const PUNTUAL = 'puntual';

    public const IGUAL_FACTURACION = 'igual_facturacion';

    public const PUNTO_RECOGIDA = 'punto_recogida';

    private function __construct(
        public readonly string $tipo,
        public readonly ?int $addressId = null,
        public readonly ?DireccionComercial $direccion = null,
        public readonly ?int $inventorySourceId = null,
    ) {}

    public static function guardada(int $addressId): self
    {
        return new self(self::GUARDADA, addressId: $addressId);
    }

    public static function puntual(DireccionComercial $direccion): self
    {
        return new self(self::PUNTUAL, direccion: $direccion);
    }

    public static function igualFacturacion(): self
    {
        return new self(self::IGUAL_FACTURACION);
    }

    public static function puntoRecogida(?int $inventorySourceId = null): self
    {
        return new self(self::PUNTO_RECOGIDA, inventorySourceId: $inventorySourceId);
    }
}
