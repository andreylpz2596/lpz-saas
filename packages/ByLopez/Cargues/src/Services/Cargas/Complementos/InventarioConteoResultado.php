<?php

namespace ByLopez\Cargues\Services\Cargas\Complementos;

use ByLopez\Cargues\Contracts\ComplementaResultado;
use ByLopez\Cargues\Models\Carga;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complemento del tipo «Inventario — Actualizar cantidades»: SOLO LEE la intermedia. Por fila muestra el producto del sistema (el
 * que se cruzo por ID/SKU), el stock actual, la cantidad del archivo y la diferencia (lo que subira o bajara).
 */
class InventarioConteoResultado implements ComplementaResultado
{
    public function complementar(Carga $carga, array $filas): array
    {
        if ($filas === [] || ! Schema::hasTable('bylopez_cgi_inventario_conteo')) {
            return [];
        }

        $registros = DB::table('bylopez_cgi_inventario_conteo')->where('cg_carga_id', $carga->id)->whereIn('cg_fila_origen', array_keys($filas))->get()->keyBy('cg_fila_origen');
        $salida = [];

        foreach ($registros as $n => $r) {
            if ($r->producto_id === null) {
                continue;
            }

            $pares = [['etiqueta' => 'Producto del sistema', 'valor' => '#'.$r->producto_id.' · '.$r->producto_sku.($r->producto_nombre ? ' · '.$r->producto_nombre : '')]];

            if ($r->stock_actual !== null) {
                $d = (int) $r->diferencia;
                $pares[] = ['etiqueta' => 'Cantidad', 'valor' => 'Sistema '.$r->stock_actual.' → Archivo '.$r->cantidad.' ('.($d > 0 ? '+' : '').$d.')'];
            }

            $salida[(int) $n] = $pares;
        }

        return $salida;
    }
}
