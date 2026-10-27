<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Models\Concerns\VersionConVigencia;
use Illuminate\Database\Eloquent\Model;

/**
 * Costo directo de venta ESTIMADO (empaque, flete asumido, cargo de cobro...), con su base de calculo explicita:
 *  - `por_unidad`: valor por prenda vendida;
 *  - `por_pedido`: valor fijo por pedido;
 *  - `porcentaje` + `base_porcentaje`: % sobre el precio neto de la mercancia, sobre el total cobrado o sobre el envio.
 * Filtros opcionales: referencia (familia), metodo de envio y metodo de pago (codigos de Bagisto). `fuente_real` dice
 * con que dato real se compara despues; la estimacion nunca reescribe la venta.
 */
class CostoDirecto extends Model
{
    use VersionConVigencia;

    public const CONCEPTOS = ['empaque', 'flete_asumido', 'cargo_cobro', 'transporte_venta', 'otro'];

    public const TIPOS = ['por_unidad', 'por_pedido', 'porcentaje'];

    public const BASES = ['precio_neto_mercancia', 'total_cobrado', 'valor_envio'];

    public const FUENTES_REALES = ['logistica_servicio', 'logistica_guia_flete', 'cargues_99envios', 'registro_manual', 'sin_fuente'];

    public const CAMPOS_DE_CALCULO = ['codigo', 'version', 'concepto', 'tipo_calculo', 'base_porcentaje', 'valor', 'familia_id', 'metodo_envio', 'metodo_pago', 'vigente_desde'];

    protected $table = 'bylopez_va_costos_directos';

    protected $fillable = [
        'codigo', 'version', 'concepto', 'nombre', 'tipo_calculo', 'base_porcentaje', 'valor', 'familia_id', 'metodo_envio',
        'metodo_pago', 'fuente_real', 'vigente_desde', 'vigente_hasta', 'activo', 'motivo', 'creado_por_admin_id',
        'creado_por_name_snapshot',
    ];

    protected $casts = [
        'version' => 'integer',
        'valor' => 'decimal:4',
        'familia_id' => 'integer',
        'vigente_desde' => 'datetime',
        'vigente_hasta' => 'datetime',
        'activo' => 'boolean',
    ];
}
