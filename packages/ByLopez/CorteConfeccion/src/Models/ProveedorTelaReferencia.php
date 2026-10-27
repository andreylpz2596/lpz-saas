<?php

namespace ByLopez\CorteConfeccion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Attribute\Models\AttributeOption;

class ProveedorTelaReferencia extends Model
{
    protected $table = 'bylopez_cc_proveedor_tela_referencias';

    protected $fillable = [
        'proveedor_tela_id',
        'tipo_tela_id',
        'color',
        'color_option_id',
        'referencia',
        'gramaje',
        'valor_kilo_referencia',
        'estado',
    ];

    protected $casts = [
        'valor_kilo_referencia' => 'decimal:2',
    ];

    public function proveedorTela(): BelongsTo
    {
        return $this->belongsTo(ProveedorTela::class, 'proveedor_tela_id');
    }

    public function tipoTela(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'tipo_tela_id');
    }

    /**
     * F1 (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §5.4): identidad real
     * del color, contra el atributo Bagisto `code = 'color'`. Nullable a
     * proposito -- puede no existir todavia una opcion real (ver "Verde
     * Botella" en el encargo de F1): `color` (texto) sigue siendo el
     * snapshot visual/historico, pero nunca la identidad para resolver
     * variantes en runtime.
     */
    public function colorOption(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'color_option_id');
    }

    public function rollos(): HasMany
    {
        return $this->hasMany(Rollo::class, 'proveedor_tela_referencia_id');
    }
}
