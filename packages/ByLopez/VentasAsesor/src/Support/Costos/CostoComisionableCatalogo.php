<?php

namespace ByLopez\VentasAsesor\Support\Costos;

use Illuminate\Support\Facades\DB;

/**
 * Lectura del COSTO COMISIONABLE vigente en el catalogo (D-COST-02): atributo `cost` de Bagisto, EAV en
 * `product_attribute_values.float_value`. Hoy es global (`value_per_channel = 0`, `value_per_locale = 0`); si algun dia
 * fuera por canal, se prefiere el valor del canal por defecto sobre el global. Un valor <= 0 o NULL = sin costo (Bagisto
 * guarda un precio vacio como NULL; un 0 significa «no cargado»).
 */
class CostoComisionableCatalogo
{
    public const ATRIBUTO = 'cost';

    public function atributo(): ?object
    {
        return DB::table('attributes')->where('code', self::ATRIBUTO)->first(['id', 'value_per_channel', 'value_per_locale']);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, float> solo productos con costo > 0
     */
    public function costosDe(array $productIds): array
    {
        $atributo = $this->atributo();

        if ($atributo === null || $productIds === []) {
            return [];
        }

        $canal = core()->getDefaultChannelCode();
        $costos = [];

        $filas = DB::table('product_attribute_values')
            ->where('attribute_id', $atributo->id)
            ->whereIn('product_id', $productIds)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $canal))
            ->get(['product_id', 'channel', 'float_value']);

        foreach ($filas->sortBy(fn (object $f) => $f->channel === null ? 0 : 1) as $fila) {
            $valor = $fila->float_value === null ? null : (float) $fila->float_value;

            if ($valor !== null && $valor > 0) {
                $costos[(int) $fila->product_id] = $valor;
            } else {
                unset($costos[(int) $fila->product_id]);
            }
        }

        return $costos;
    }
}
