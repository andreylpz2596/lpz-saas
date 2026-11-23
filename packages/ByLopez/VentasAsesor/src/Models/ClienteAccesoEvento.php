<?php

namespace ByLopez\VentasAsesor\Models;

use Illuminate\Database\Eloquent\Model;

/** Auditoria del acceso de clientes: append-only (sin updated_at, sin borrado). */
class ClienteAccesoEvento extends Model
{
    public $timestamps = false;

    protected $table = 'bylopez_va_clientes_acceso_eventos';

    protected $fillable = ['acceso_id', 'customer_id', 'admin_id', 'accion', 'actor_admin_id', 'motivo', 'detalle', 'created_at'];

    protected $casts = ['detalle' => 'array', 'created_at' => 'datetime'];
}
