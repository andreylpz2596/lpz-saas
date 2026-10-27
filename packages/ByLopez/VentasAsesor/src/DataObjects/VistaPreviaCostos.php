<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Vista previa OBLIGATORIA de una actualizacion masiva del costo comisionable (D-COST-05). No escribe nada.
 *
 * `token` firma (HMAC con la clave de la aplicacion) los ids, el costo nuevo y los costos ACTUALES vistos: si alguien
 * cambia un costo entre la vista previa y la confirmacion, el token deja de coincidir y hay que volver a previsualizar.
 */
final class VistaPreviaCostos
{
    /**
     * @param  list<int>  $ids
     * @param  list<array{id: int, sku: string, nombre: string, producto: ?string, actual: ?float, nuevo: float, diferencia: ?float}>  $filas
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $nuevoCosto,
        public readonly array $filas,
        public readonly int $conCambio,
        public readonly int $sinCambio,
        public readonly int $sinCostoActual,
        public readonly ?float $actualMinimo,
        public readonly ?float $actualMaximo,
        public readonly string $token,
    ) {}

    public function cantidad(): int
    {
        return count($this->ids);
    }
}
