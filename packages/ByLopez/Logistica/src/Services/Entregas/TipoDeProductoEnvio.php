<?php

namespace ByLopez\Logistica\Services\Entregas;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Que tipo de producto lleva un envio», para el listado de Entregas (solo lectura, datos reales del catalogo).
 *
 * El tipo es la categoria de SEGUNDO nivel bajo la raiz del producto (la que agrupa la linea: «Camisetas Dama»), o la mas
 * cercana si el arbol es menos profundo. Si el producto no tiene categorias se usa el nombre de la linea del pedido. No hay
 * nombres de categoria en el codigo: todo sale de `categories` / `category_translations`.
 */
class TipoDeProductoEnvio
{
    /** Nivel del arbol (raiz = 0) que identifica el tipo de producto. */
    public const NIVEL_TIPO = 2;

    /**
     * @param  list<int>  $shipmentIds
     * @return array<int, array{principal: string, otros: int}> envio => tipo con mas unidades y cuantos tipos mas lleva
     */
    public function porEnvios(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $filas = DB::table('shipment_items as si')->join('order_items as oi', 'oi.id', '=', 'si.order_item_id')
            ->whereIn('si.shipment_id', $shipmentIds)
            ->get(['si.shipment_id', 'si.qty', 'oi.product_id', 'oi.name']);

        $tipos = $this->tiposPorProducto($filas->pluck('product_id')->filter()->unique()->map(fn ($id) => (int) $id)->all());
        $salida = [];

        foreach ($filas->groupBy('shipment_id') as $envio => $lineas) {
            $unidades = [];

            foreach ($lineas as $l) {
                $tipo = $tipos[(int) $l->product_id] ?? trim((string) $l->name);
                $unidades[$tipo] = ($unidades[$tipo] ?? 0) + (int) $l->qty;
            }

            arsort($unidades);
            $nombres = array_keys($unidades);

            $salida[(int) $envio] = ['principal' => (string) ($nombres[0] ?? ''), 'otros' => max(0, count($nombres) - 1)];
        }

        return $salida;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, string> producto => nombre del tipo (solo productos con categoria)
     */
    private function tiposPorProducto(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $categorias = DB::table('categories')->get(['id', 'parent_id'])->keyBy('id');
        $nombres = $this->nombres();
        $asignadas = DB::table('product_categories')->whereIn('product_id', $productIds)->orderBy('category_id')->get(['product_id', 'category_id']);
        $tipos = [];

        foreach ($asignadas->groupBy('product_id') as $producto => $filas) {
            $mejor = null;

            foreach ($filas as $f) {
                $cadena = $this->cadena((int) $f->category_id, $categorias);   // [raiz, nivel1, nivel2, ...]
                $elegida = $cadena[self::NIVEL_TIPO] ?? (count($cadena) > 1 ? end($cadena) : null);

                if ($elegida !== null && $mejor === null) {
                    $mejor = $elegida;
                }
            }

            if ($mejor !== null && isset($nombres[$mejor])) {
                $tipos[(int) $producto] = $nombres[$mejor];
            }
        }

        return $tipos;
    }

    /** Cadena de ancestros desde la raiz hasta la categoria (ids). */
    private function cadena(int $id, Collection $categorias): array
    {
        $cadena = [];

        for ($n = 0; $id !== 0 && isset($categorias[$id]) && $n < 20; $n++) {
            array_unshift($cadena, $id);
            $id = (int) ($categorias[$id]->parent_id ?? 0);
        }

        return $cadena;
    }

    /** @return array<int, string> */
    private function nombres(): array
    {
        $locale = app()->getLocale();

        return DB::table('category_translations')->orderByRaw('locale = ? desc', [$locale])->get(['category_id', 'name'])
            ->unique('category_id')
            ->mapWithKeys(fn ($f) => [(int) $f->category_id => mb_convert_case(mb_strtolower((string) $f->name), MB_CASE_TITLE)])->all();
    }
}
