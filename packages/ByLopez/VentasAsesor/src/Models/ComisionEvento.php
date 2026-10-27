<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/** Historial append-only de una linea de comision. */
class ComisionEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_va_comision_eventos';

    protected $fillable = ['linea_id', 'tipo', 'detalle', 'actor_admin_id', 'actor_name_snapshot'];

    protected $casts = ['linea_id' => 'integer', 'detalle' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalida(Invalida::CAMPO_INMUTABLE, 'El historial no se modifica.'));
        static::deleting(fn () => throw new Invalida(Invalida::BORRADO_PROHIBIDO, 'El historial no se borra.'));
    }
}
