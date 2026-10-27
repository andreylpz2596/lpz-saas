<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Enums\TipoReglaComision;
use ByLopez\VentasAsesor\Exceptions\ReglaComisionInvalidaException as Invalida;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de comision con vigencia (COM-03).
 *
 * Invariantes que impone el modelo (alcance honesto: eventos de Eloquent; SQL directo los salta):
 *  - Los campos de CALCULO (codigo, tipo, base, porcentaje, alcance, prioridad, vigente_desde) no cambian nunca:
 *    cambiar el porcentaje es crear otra regla. Asi una regla que ya se uso no reescribe historia.
 *  - `vigente_hasta` solo se fija una vez (NULL → fecha) para cerrar la vigencia.
 *  - `activo` solo pasa a false en una regla que todavia NO rige (cancelar una programacion futura).
 *  - No se borra.
 */
class ReglaComision extends Model
{
    public const CAMPOS_DE_CALCULO = ['codigo', 'tipo', 'base', 'porcentaje', 'alcance', 'prioridad', 'vigente_desde'];

    protected $table = 'bylopez_va_comision_reglas';

    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'base', 'porcentaje', 'alcance', 'prioridad',
        'vigente_desde', 'vigente_hasta', 'activo', 'creado_por_admin_id', 'mecanismo', 'motivo',
    ];

    protected $casts = [
        'tipo' => TipoReglaComision::class,
        'porcentaje' => 'decimal:4',
        'prioridad' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
        'activo' => 'boolean',
        'creado_por_admin_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $regla) {
            foreach (self::CAMPOS_DE_CALCULO as $campo) {
                if ($regla->isDirty($campo)) {
                    throw new Invalida(Invalida::CAMPO_INMUTABLE, "El campo «{$campo}» de una regla de comision no se modifica: cree una regla nueva.");
                }
            }

            if ($regla->isDirty('vigente_hasta') && $regla->getOriginal('vigente_hasta') !== null) {
                throw new Invalida(Invalida::CAMPO_INMUTABLE, 'La vigencia de esta regla ya estaba cerrada.');
            }

            if ($regla->isDirty('activo') && ($regla->activo || $regla->vigente_desde->lte(now()))) {
                throw new Invalida(Invalida::CANCELACION_INVALIDA, 'Solo se cancela una regla que todavia no rige; una vigente se cierra con otra regla.');
            }
        });

        static::deleting(function () {
            throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'Una regla de comision no se borra.');
        });
    }

    /** Reglas activas del alcance que rigen en la fecha dada: [desde, hasta). */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha, string $alcance = TipoReglaComision::ALCANCE_GLOBAL): Builder
    {
        return $consulta->where('alcance', $alcance)
            ->where('activo', true)
            ->where('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>', $fecha));
    }

    /** Fraccion para multiplicar (20.0000 → 0.2). */
    public function fraccion(): float
    {
        return (float) $this->porcentaje / 100;
    }
}
