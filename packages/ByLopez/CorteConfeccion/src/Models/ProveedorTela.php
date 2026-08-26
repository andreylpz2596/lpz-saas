<?php

namespace ByLopez\CorteConfeccion\Models;

use ByLopez\CorteConfeccion\Contracts\ProveedorTela as ProveedorTelaContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Attribute\Models\AttributeOption;

class ProveedorTela extends Model implements ProveedorTelaContract
{
    protected $table = 'bylopez_cc_proveedores_tela';

    protected $fillable = [
        'nombre',
        'nit',
        'telefono',
        'contacto',
        'direccion',
        'tipo',
        'estado',
        'observacion',
    ];

    public function comprasTela(): HasMany
    {
        return $this->hasMany(CompraTela::class, 'proveedor_id');
    }

    public function tiposTela(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'bylopez_cc_proveedor_tela_tipos',
            'proveedor_tela_id',
            'tipo_tela_id'
        )->withTimestamps();
    }

    public function referenciasTela(): HasMany
    {
        return $this->hasMany(ProveedorTelaReferencia::class, 'proveedor_tela_id');
    }
}
