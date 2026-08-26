<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\CompraTela as CompraTelaContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompraTela extends Model implements CompraTelaContract
{
    protected $table = 'bylopez_cc_compras_tela';

    protected $fillable = [
        'codigo',
        'proveedor_id',
        'proveedor_nombre',
        'numero_factura',
        'fecha_compra',
        'subtotal',
        'descuento',
        'impuesto',
        'total_factura',
        'cantidad_rollos',
        'peso_total',
        'estado',
        'observacion',
    ];

    protected $casts = [
        'fecha_compra'    => 'date',
        'subtotal'        => 'decimal:2',
        'descuento'       => 'decimal:2',
        'impuesto'        => 'decimal:2',
        'total_factura'   => 'decimal:2',
        'cantidad_rollos' => 'integer',
        'peso_total'      => 'decimal:3',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorTela::class, 'proveedor_id');
    }

    public function rollos(): HasMany
    {
        return $this->hasMany(Rollo::class, 'compra_tela_id');
    }
}
