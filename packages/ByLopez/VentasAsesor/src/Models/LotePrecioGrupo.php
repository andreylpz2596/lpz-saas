<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException as Invalido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una confirmacion de «Precios por grupo» (Doc. 32). Auditoria, nunca fuente de precios. Solo puede cambiar el estado de
 * la reindexacion (que corre despues del commit); el resto es inmutable y no se borra.
 */
class LotePrecioGrupo extends Model
{
    public const INDICE_PENDIENTE = 'pendiente';

    public const INDICE_ACTUALIZADO = 'actualizado';

    public const INDICE_ERROR = 'error';

    protected $table = 'bylopez_va_precio_grupo_lotes';

    protected $fillable = [
        'lote', 'token', 'customer_group_id', 'grupo_nombre_snapshot', 'operacion', 'parametros', 'alcance', 'filtros',
        'variantes', 'creadas', 'actualizadas', 'quitadas', 'sin_cambio', 'indice_estado', 'indice_error',
        'actor_admin_id', 'actor_nombre_snapshot', 'motivo',
    ];

    protected $casts = [
        'customer_group_id' => 'integer',
        'parametros' => 'array',
        'filtros' => 'array',
        'variantes' => 'integer',
        'creadas' => 'integer',
        'actualizadas' => 'integer',
        'quitadas' => 'integer',
        'sin_cambio' => 'integer',
        'actor_admin_id' => 'integer',
    ];

    public function cambios(): HasMany
    {
        return $this->hasMany(CambioPrecioGrupo::class, 'lote_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $lote) {
            $permitidos = ['indice_estado', 'indice_error', 'updated_at'];

            if (array_diff(array_keys($lote->getDirty()), $permitidos) !== []) {
                throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de precios por grupo es de solo adicion.');
            }
        });

        static::deleting(fn () => throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de precios por grupo no se borra.'));
    }
}
