<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\TelefonoCliente as TelefonoClienteContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Telefono indexado de un Customer de Bagisto (RT-04B1a).
 *
 * `principal` se guarda como 1 o NULL, nunca 0: asi la unica (customer_id,
 * principal) garantiza en la base un solo principal por cliente. El mutador
 * convierte cualquier valor falso en NULL.
 */
class TelefonoCliente extends Model implements TelefonoClienteContract
{
    protected $table = 'bylopez_va_customer_phones';

    protected $fillable = [
        'customer_id',
        'telefono_normalizado',
        'telefono_original',
        'principal',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'principal' => 'boolean',
    ];

    public function setPrincipalAttribute($valor): void
    {
        $this->attributes['principal'] = $valor ? 1 : null;
    }

    public function esPrincipal(): bool
    {
        return (bool) $this->principal;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(CustomerProxy::modelClass(), 'customer_id');
    }

    public function scopePrincipales(Builder $consulta): Builder
    {
        return $consulta->where('principal', 1);
    }
}
