<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException as Invalido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una regla de precio por grupo creada, actualizada o quitada dentro de un lote (Doc. 32). SOLO DE ADICION. */
class CambioPrecioGrupo extends Model
{
    public const UPDATED_AT = null;

    public const CREAR = 'crear';

    public const ACTUALIZAR = 'actualizar';

    public const QUITAR = 'quitar';

    protected $table = 'bylopez_va_precio_grupo_cambios';

    protected $fillable = [
        'lote_id', 'product_id', 'sku_snapshot', 'customer_group_id', 'qty', 'accion', 'tipo_anterior', 'valor_anterior',
        'tipo_nuevo', 'valor_nuevo', 'precio_base_snapshot', 'precio_anterior', 'precio_resultante',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'customer_group_id' => 'integer',
        'qty' => 'integer',
        'valor_anterior' => 'decimal:4',
        'valor_nuevo' => 'decimal:4',
        'precio_base_snapshot' => 'decimal:4',
        'precio_anterior' => 'decimal:4',
        'precio_resultante' => 'decimal:4',
    ];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(LotePrecioGrupo::class, 'lote_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de precios por grupo es de solo adicion.'));
        static::deleting(fn () => throw new Invalido(Invalido::BITACORA_INMUTABLE, 'La bitacora de precios por grupo no se borra.'));
    }
}
