<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\Rollo as RolloContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Attribute\Models\AttributeOption;

class Rollo extends Model implements RolloContract
{
    protected $table = 'bylopez_cc_rollos';

    protected $fillable = [
        'compra_tela_id',
        'proveedor_tela_referencia_id',
        'proveedor_tela_id',
        'tipo_tela_id',
        'codigo',
        'proveedor',
        'color',
        'referencia',
        'tipo_tela',
        'gramaje',
        'peso_inicial',
        'peso_disponible',
        'valor_kilo',
        'costo_total',
        'fecha_compra',
        'estado',
        'observacion',
    ];

    protected $casts = [
        'fecha_compra'     => 'date',
        'peso_inicial'     => 'decimal:3',
        'peso_disponible'  => 'decimal:3',
        'valor_kilo'       => 'decimal:2',
        'costo_total'      => 'decimal:2',
    ];

    public function compraTela(): BelongsTo
    {
        return $this->belongsTo(CompraTela::class, 'compra_tela_id');
    }

    public function referenciaTela(): BelongsTo
    {
        return $this->belongsTo(ProveedorTelaReferencia::class, 'proveedor_tela_referencia_id');
    }

    public function tipoTela(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'tipo_tela_id');
    }

    public function ordenesCorte(): HasMany
    {
        return $this->hasMany(OrdenCorte::class, 'rollo_id');
    }

    public function ordenesCorteRollos(): HasMany
    {
        return $this->hasMany(OrdenCorteRollo::class, 'rollo_id');
    }
}
