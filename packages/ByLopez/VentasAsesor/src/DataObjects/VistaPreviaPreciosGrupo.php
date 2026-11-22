<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Vista previa de una edicion de «Precios por grupo» (Doc. 32). No escribe nada. `token` = huella HMAC de la operacion
 * y del estado leido (reglas de la variante para el grupo y «todos los grupos», precio base y especial): si algo cambia
 * antes de confirmar, la confirmacion lo detecta y pide revisar.
 */
final class VistaPreviaPreciosGrupo
{
    public const CREAR = 'crear';

    public const ACTUALIZAR = 'actualizar';

    public const QUITAR = 'quitar';

    public const SIN_CAMBIO = 'sin_cambio';

    public const ERROR = 'error';

    /**
     * @param  array{id: int, nombre: string}  $grupo
     * @param  list<array<string, mixed>>  $filas
     * @param  array{crear: int, actualizar: int, quitar: int, sin_cambio: int, error: int}  $conteo
     */
    public function __construct(
        public readonly SolicitudPrecioGrupo $solicitud,
        public readonly array $grupo,
        public readonly array $filas,
        public readonly array $conteo,
        public readonly int $borradoresAbiertos,
        public readonly bool $conCostos,
        public readonly string $token,
    ) {}

    public function cambios(): int
    {
        return $this->conteo[self::CREAR] + $this->conteo[self::ACTUALIZAR] + $this->conteo[self::QUITAR];
    }

    public function confirmable(): bool
    {
        return $this->conteo[self::ERROR] === 0 && $this->cambios() > 0;
    }
}
