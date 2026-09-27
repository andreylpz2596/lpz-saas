<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Direccion de facturacion o de envio del borrador (03D, salida), leida del CartAddress
 * de Bagisto. En la recogida (`origen = punto_recogida`) el lugar es la bodega y los
 * datos de persona son de quien recibe.
 */
final class DireccionBorrador
{
    public const ROL_FACTURACION = 'facturacion';

    public const ROL_ENVIO = 'envio';

    /** @param  list<string>  $lineas */
    public function __construct(
        public readonly string $rol,
        /** guardada | puntual | punto_recogida */
        public readonly string $origen,
        public readonly ?int $customerAddressId,
        public readonly ?int $inventorySourceId,
        public readonly string $nombre,
        public readonly string $apellido,
        public readonly ?string $empresa,
        public readonly array $lineas,
        public readonly string $ciudad,
        public readonly ?string $departamento,
        public readonly ?string $pais,
        public readonly ?string $codigoPostal,
        public readonly ?string $telefono,
        public readonly ?string $email,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
