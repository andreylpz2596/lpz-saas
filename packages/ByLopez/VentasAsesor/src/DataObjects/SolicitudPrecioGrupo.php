<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Una edicion de «Precios por grupo» ya validada (Doc. 32). La arma EdicionPreciosGrupoService::solicitud().
 *
 * Operaciones:
 *  - fijo        establece un precio FIJO (`fixed`) en el escalon `qty` (crea o reemplaza esa fila).
 *  - porcentaje  establece un DESCUENTO % sobre el precio base (`discount`) en el escalon `qty`.
 *  - ajustar     sube o baja UNA VEZ los precios FIJOS existentes de ese escalon (por importe o por %); no crea filas,
 *                no toca descuentos % y no convierte un fijo en porcentaje.
 *  - completar   crea la fila del escalon SOLO donde no existe (fijo o %); lo existente queda igual.
 *  - quitar      borra la fila de ese escalon.
 * Nunca se toca otro grupo, otro escalon ni la fila de «todos los grupos».
 */
final class SolicitudPrecioGrupo
{
    public const FIJO = 'fijo';

    public const PORCENTAJE = 'porcentaje';

    public const AJUSTAR = 'ajustar';

    public const COMPLETAR = 'completar';

    public const QUITAR = 'quitar';

    public const OPERACIONES = [self::FIJO, self::PORCENTAJE, self::AJUSTAR, self::COMPLETAR, self::QUITAR];

    public const AJUSTE_IMPORTE = 'importe';

    public const AJUSTE_PORCENTAJE = 'porcentaje';

    public const REDONDEOS = [1, 50, 100];

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $filtros
     */
    public function __construct(
        public readonly int $grupoId,
        public readonly string $operacion,
        public readonly int $qty,
        public readonly ?string $tipo,
        public readonly ?string $valor,
        public readonly ?string $modoAjuste,
        public readonly int $redondeo,
        public readonly array $ids,
        public readonly string $alcance,
        public readonly array $filtros,
        public readonly ?string $motivo,
    ) {}

    /** @return array<string, mixed> lo que define la operacion (va al token y a la bitacora) */
    public function parametros(): array
    {
        return [
            'operacion' => $this->operacion, 'qty' => $this->qty, 'tipo' => $this->tipo, 'valor' => $this->valor,
            'modo_ajuste' => $this->modoAjuste, 'redondeo' => $this->redondeo,
        ];
    }

    /** @return array<string, mixed> para reenviar en el formulario de confirmacion */
    public function comoFormulario(): array
    {
        return [
            'grupo' => $this->grupoId, 'operacion' => $this->operacion, 'qty' => $this->qty, 'tipo' => $this->tipo,
            'valor' => $this->valor, 'modo_ajuste' => $this->modoAjuste, 'redondeo' => $this->redondeo,
            'alcance' => $this->alcance, 'motivo' => $this->motivo,
        ];
    }
}
