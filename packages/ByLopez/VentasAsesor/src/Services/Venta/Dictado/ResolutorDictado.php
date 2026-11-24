<?php

namespace ByLopez\VentasAsesor\Services\Venta\Dictado;

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado as Interprete;
use Illuminate\Support\Facades\DB;

/**
 * Doc. 34 · Pedido por voz: cada linea que entendio InterpreteDictado se valida contra el catalogo REAL con el codigo unico
 * (Doc. 33): color → numero (bylopez_va_sku_colores) + genero (D/H/N) + linea (DZ/TP) + talla → SKU exacto de la variante
 * («110N-DZ-2»). Sin servicios externos. Solo lectura: no toca la venta; la pantalla agrega lo resuelto con el mismo
 * «aplicar tallas» de la matriz (precio, stock y permisos de siempre).
 *
 * Estados por linea: `ok` (una variante exacta), `aviso` (resuelta, pero se asumio algo: p. ej. el genero porque el color solo
 * existe en una linea) y `error` (falta un dato, color ambiguo, o esa combinacion no existe en el catalogo).
 */
class ResolutorDictado
{
    private const GENEROS = ['D' => 'Dama', 'H' => 'Caballero', 'N' => 'Niño'];

    /**
     * @return list<array<string, mixed>>
     */
    public function interpretar(string $texto): array
    {
        $colores = $this->colores();
        $interprete = new Interprete(array_keys($colores), (array) config('ventasasesor.dictado.alias_colores', []));

        return array_map(fn (array $l) => $this->resolver($l, $colores), $interprete->interpretar($texto));
    }

    /** @return array<string, int> nombre de color del catalogo → numero del codigo unico */
    public function colores(): array
    {
        return DB::table('bylopez_va_sku_colores as c')
            ->leftJoin('attribute_options as o', 'o.id', '=', 'c.attribute_option_id')
            ->orderBy('c.codigo')
            ->get(['c.codigo', DB::raw('COALESCE(o.admin_name, c.color_snapshot) as nombre')])
            ->filter(fn ($f) => trim((string) $f->nombre) !== '')
            ->mapWithKeys(fn ($f) => [trim((string) $f->nombre) => (int) $f->codigo])
            ->all();
    }

    /** @param  array<string, int>  $colores */
    private function resolver(array $l, array $colores): array
    {
        $fila = $l + ['estado' => 'error', 'mensaje' => null, 'producto_id' => null, 'variante_id' => null, 'sku' => null, 'nombre' => null];

        if ($l['problemas'] !== []) {
            return ['mensaje' => $this->mensajeProblemas($l)] + $fila;
        }

        $codigo = $colores[$l['color']] ?? null;
        if ($codigo === null) {
            return ['mensaje' => 'El color '.$l['color'].' no tiene código en el catálogo.'] + $fila;
        }

        $generos = $l['linea'] === 'TP' ? ['D'] : ($l['genero'] !== null ? [$l['genero']] : ['D', 'H']);
        $skus = [];
        foreach ($generos as $g) {
            $skus[$g] = $codigo.$g.'-'.$l['linea'].'-'.$l['talla'];
        }

        $variantes = DB::table('products')->whereIn('sku', array_values($skus))->whereNotNull('parent_id')->get(['id', 'sku', 'parent_id']);

        if ($variantes->isEmpty()) {
            return ['mensaje' => $this->mensajeInexistente($l, $codigo, $generos)] + $fila;
        }

        if ($variantes->count() > 1) {
            return ['mensaje' => 'Di si es dama o caballero: '.$l['color'].' talla '.$l['talla_etiqueta'].' existe en las dos.'] + $fila;
        }

        $v = $variantes->first();
        $genero = (string) array_search($v->sku, $skus, true);
        $padre = DB::table('product_flat')->where('product_id', $v->parent_id)
            ->where('channel', core()->getDefaultChannel()->code)
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())
            ->first(['name', 'status']);

        if ($padre === null || ! (int) $padre->status) {
            return ['mensaje' => 'El producto '.$v->sku.' no está activo para la venta.', 'sku' => $v->sku] + $fila;
        }

        $asumido = $l['linea'] !== 'TP' && $l['genero'] === null;

        return [
            'estado' => $asumido ? 'aviso' : 'ok',
            'mensaje' => $asumido ? 'Se tomó '.self::GENEROS[$genero].': es la única línea con ese color y talla.' : null,
            'genero' => $genero,
            'producto_id' => (int) $v->parent_id,
            'variante_id' => (int) $v->id,
            'sku' => $v->sku,
            'nombre' => (string) $padre->name,
        ] + $fila;
    }

    private function mensajeProblemas(array $l): string
    {
        $partes = [];

        foreach ($l['problemas'] as $problema) {
            $partes[] = match ($problema) {
                Interprete::FALTA_COLOR => 'No entendí el color.',
                Interprete::COLOR_AMBIGUO => '¿Cuál color? '.implode(', ', $l['candidatos']).'.',
                Interprete::FALTA_TALLA => 'Falta la talla.',
                Interprete::FALTA_CANTIDAD => 'Falta la cantidad.',
                Interprete::CANTIDAD_INVALIDA => 'La cantidad debe estar entre 1 y 9999.',
                default => $problema,
            };
        }

        return implode(' ', $partes);
    }

    private function mensajeInexistente(array $l, int $codigo, array $generos): string
    {
        $linea = fn (string $g) => $l['linea'] === 'TP' ? 'Top' : self::GENEROS[$g];
        $padres = DB::table('products')->whereNull('parent_id')
            ->whereIn('sku', array_map(fn ($g) => $codigo.$g.'-'.$l['linea'], $generos))->pluck('sku')->all();

        if ($padres !== []) {
            return $l['color'].' '.implode(' / ', array_map($linea, $generos)).' no tiene talla '.$l['talla_etiqueta'].'.';
        }

        return 'No hay '.$l['color'].' en '.implode(' ni ', array_map($linea, $generos)).'.';
    }
}
