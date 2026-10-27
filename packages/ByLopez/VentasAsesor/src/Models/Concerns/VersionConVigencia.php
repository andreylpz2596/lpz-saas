<?php

namespace ByLopez\VentasAsesor\Models\Concerns;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Configuracion versionada con vigencia [vigente_desde, vigente_hasta) (mismo contrato que ReglaComision):
 *  - los campos de CALCULO (`CAMPOS_DE_CALCULO` del modelo) nunca cambian: cambiar un valor = version nueva;
 *  - `vigente_hasta` se fija una sola vez (cerrar la vigencia);
 *  - `activo` solo pasa a false en una version que todavia no rige (cancelar una programacion);
 *  - no se borra.
 * Alcance honesto: son eventos de Eloquent; SQL directo los salta. Solo escriben los servicios de configuracion.
 */
trait VersionConVigencia
{
    protected static function bootVersionConVigencia(): void
    {
        static::updating(function (self $m) {
            foreach (static::CAMPOS_DE_CALCULO as $campo) {
                if ($m->isDirty($campo)) {
                    throw new Invalida(Invalida::CAMPO_INMUTABLE, "El campo «{$campo}» no se modifica: cree una version nueva.");
                }
            }

            if ($m->isDirty('vigente_hasta') && $m->getOriginal('vigente_hasta') !== null) {
                throw new Invalida(Invalida::CAMPO_INMUTABLE, 'La vigencia de esta version ya estaba cerrada.');
            }

            if ($m->isDirty('activo') && ($m->activo || $m->vigente_desde->lte(now()))) {
                throw new Invalida(Invalida::ESTADO_INVALIDO, 'Solo se cancela una version que todavia no rige.');
            }
        });

        static::deleting(function () {
            throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'La configuracion versionada no se borra.');
        });
    }

    public function scopeVigentesEn(Builder $q, CarbonInterface $fecha): Builder
    {
        return $q->where($this->getTable().'.vigente_desde', '<=', $fecha)
            ->where(fn (Builder $w) => $w->whereNull($this->getTable().'.vigente_hasta')->orWhere($this->getTable().'.vigente_hasta', '>', $fecha))
            ->when($this->tieneActivo(), fn (Builder $w) => $w->where($this->getTable().'.activo', true));
    }

    public function vigenteEn(CarbonInterface $fecha): bool
    {
        return (! $this->tieneActivo() || $this->activo)
            && $this->vigente_desde->lte($fecha)
            && ($this->vigente_hasta === null || $this->vigente_hasta->gt($fecha));
    }

    protected function tieneActivo(): bool
    {
        return in_array('activo', $this->getFillable(), true);
    }
}
