<?php

namespace ByLopez\Logistica\Models\Concerns;

use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;

/**
 * Entrega y retorno son EVENTOS (bloque 4): no se editan ni se borran. La unica escritura posterior permitida es la
 * anulacion auditada `confirmado → anulado`, que solo toca los campos de anulacion. Fecha, cantidades, actor, fuente
 * y snapshots quedan como se registraron. Alcance honesto: son eventos de Eloquent; SQL directo los salta.
 */
trait EventoLogisticoInmutable
{
    /** Campos que la anulacion puede escribir (y nada mas). */
    private const CAMPOS_ANULACION = ['estado', 'motivo_anulacion', 'anulada_por_admin_id', 'anulada_por_name_snapshot', 'anulada_en', 'updated_at'];

    protected static function bootEventoLogisticoInmutable(): void
    {
        static::updating(function (self $evento) {
            $esAnulacion = $evento->getOriginal('estado') === Estado::CONFIRMADO
                && $evento->estado === Estado::ANULADO
                && array_diff(array_keys($evento->getDirty()), self::CAMPOS_ANULACION) === [];

            if (! $esAnulacion) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['id' => $evento->getKey()]);
            }
        });

        static::deleting(fn (self $evento) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['id' => $evento->getKey()]));
    }

    public function vigente(): bool
    {
        return $this->estado === Estado::CONFIRMADO;
    }
}
