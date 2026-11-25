<?php

namespace ByLopez\VentasAsesor\Services\Venta\Dictado;

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado as Interprete;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

/**
 * Doc. 34 · Pedido por voz: cada linea que entendio InterpreteDictado se valida contra el catalogo REAL con el codigo unico
 * (Doc. 33): color → numero (bylopez_va_sku_colores) + genero (D/H/N) + linea (DZ/TP) + talla → SKU exacto de la variante
 * («110N-DZ-2»). Sin servicios externos. Solo lectura: no toca la venta; la pantalla agrega lo resuelto con el mismo
 * «aplicar tallas» de la matriz (precio, stock y permisos de siempre, revalidados al agregar).
 *
 * Estados por linea: `ok` (una variante exacta), `aviso` (resuelta, pero se asumio algo visible: el genero porque el color y la
 * talla solo existen en una linea, o la talla Única del crop top no dicha) y `error`, con `tipo_error`:
 *  - `interpretacion`: falta un dato, color ambiguo, numero dudoso o sobrante, cantidad invalida o contradictoria;
 *  - `catalogo`: esa combinacion no existe o el producto no esta activo (los atributos reconocidos se conservan).
 * La disponibilidad (`stock`, `gestiona_stock`) se informa para orientar; la pantalla la compara con lo que quedaria en la venta.
 */
class ResolutorDictado
{
    private const GENEROS = ['D' => 'Dama', 'H' => 'Caballero', 'N' => 'Niño'];

    /** Tallas que se pueden elegir al corregir una linea: codigo del SKU → etiqueta (en este orden). */
    public const TALLAS = ['S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL', 'U' => 'Única', '2' => '2-4', '6' => '6-8', '10' => '10-12', '14' => '14-16'];

    /**
     * @param  ?string  $tipoDefecto  tipo elegido por la asesora para las lineas donde no lo dijo (D, H o N); null = preguntar
     * @return list<array<string, mixed>>
     */
    public function interpretar(string $texto, ?string $tipoDefecto = null): array
    {
        $colores = $this->colores();
        $interprete = new Interprete(array_keys($colores), (array) config('ventasasesor.dictado.alias_colores', []));

        return array_map(fn (array $l) => $this->resolver($l, $colores, $tipoDefecto), $interprete->interpretar($texto));
    }

    /**
     * Corrige una linea a mano (selectores de la pantalla): mismos datos que entiende el dictado, ya elegidos, y la misma
     * validacion contra el catalogo. El tipo elegido cuenta como dicho (no se asume).
     *
     * @param  array{texto?: ?string, color: string, genero: ?string, linea: string, talla: string, cantidad: int}  $datos
     */
    public function corregir(array $datos): array
    {
        $talla = (string) $datos['talla'];

        return $this->resolver([
            'texto' => (string) ($datos['texto'] ?? ''),
            'color' => (string) $datos['color'],
            'candidatos' => [],
            'genero' => $datos['linea'] === 'TP' ? 'D' : ($datos['genero'] ?? null),
            'genero_explicito' => true,
            'linea' => (string) $datos['linea'],
            'talla' => $talla,
            'talla_etiqueta' => Interprete::etiquetaTalla($talla),
            'cantidad' => (int) $datos['cantidad'],
            'problemas' => [],
            'fuentes' => ['color' => null, 'genero' => null, 'talla' => 'corregida', 'cantidad' => null],
            'sin_asignar' => [],
            'opciones' => [],
            'detalle' => [],
            'corregida' => true,
        ], $this->colores(), null);
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

    /**
     * Lo que existe de verdad para los selectores: color → tipo (D, H, N, TP) → tallas (codigo del SKU), solo de productos
     * activos con el codigo unico. Sale del catalogo, no de una lista fija (Crop top M, Caballero XXL cuando existan).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function catalogo(): array
    {
        $porCodigo = array_flip($this->colores());
        $activos = DB::table('product_flat')
            ->where('channel', core()->getDefaultChannel()->code)
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())
            ->where('status', 1)
            ->pluck('product_id')->flip();

        $mapa = [];
        $variantes = DB::table('products')->whereNotNull('parent_id')->where('sku', 'like', '___%-%-%')->get(['sku', 'parent_id']);

        foreach ($variantes as $v) {
            if (! preg_match('/^(\d{3})([DHN])-(DZ|TP)-([A-Z0-9]+)$/', (string) $v->sku, $m) || ! isset($porCodigo[(int) $m[1]], $activos[$v->parent_id])) {
                continue;
            }
            $tipo = $m[3] === 'TP' ? 'TP' : $m[2];
            $mapa[$porCodigo[(int) $m[1]]][$tipo][$m[4]] = true;
        }

        $orden = array_flip(array_map('strval', array_keys(self::TALLAS)));

        foreach ($mapa as &$tipos) {
            foreach ($tipos as &$tallas) {
                $tallas = array_map('strval', array_keys($tallas));
                usort($tallas, fn ($a, $b) => ($orden[$a] ?? 99) <=> ($orden[$b] ?? 99));
            }
        }

        return $mapa;
    }

    /** @param  array<string, int>  $colores */
    private function resolver(array $l, array $colores, ?string $tipoDefecto): array
    {
        $fila = $l + ['estado' => 'error', 'tipo_error' => 'interpretacion', 'mensaje' => null, 'nota' => null, 'producto_id' => null,
            'variante_id' => null, 'sku' => null, 'nombre' => null, 'stock' => null, 'gestiona_stock' => null, 'tipo_por_contexto' => false];

        // E: el tipo elegido por la asesora solo completa lo que NO se dijo (nunca reemplaza un tipo dicho).
        if ($l['linea'] === 'DZ' && $l['genero'] === null && in_array($tipoDefecto, ['D', 'H', 'N'], true)) {
            $l['genero'] = $tipoDefecto;
            $fila['genero'] = $tipoDefecto;
            $fila['tipo_por_contexto'] = true;
        }

        if ($l['problemas'] !== []) {
            return ['mensaje' => $this->mensajeProblemas($l)] + $fila;
        }

        $codigo = $colores[$l['color']] ?? null;
        if ($codigo === null) {
            return ['mensaje' => 'El color '.$l['color'].' no tiene código en el catálogo.', 'tipo_error' => 'catalogo'] + $fila;
        }

        $generos = $l['linea'] === 'TP' ? ['D'] : ($l['genero'] !== null ? [$l['genero']] : ['D', 'H']);
        $skus = [];
        foreach ($generos as $g) {
            $skus[$g] = $codigo.$g.'-'.$l['linea'].'-'.$l['talla'];
        }

        $variantes = DB::table('products')->whereIn('sku', array_values($skus))->whereNotNull('parent_id')->get(['id', 'sku', 'parent_id']);

        if ($variantes->isEmpty()) {
            return ['mensaje' => $this->mensajeInexistente($l, $codigo, $generos), 'tipo_error' => 'catalogo'] + $fila;
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
            return ['mensaje' => 'El producto '.$v->sku.' no está activo para la venta.', 'sku' => $v->sku, 'tipo_error' => 'catalogo'] + $fila;
        }

        $generoAsumido = $l['linea'] !== 'TP' && $l['genero'] === null;
        $unicaAsumida = $l['linea'] === 'TP' && $l['talla'] === 'U' && ! preg_match('/unica|\bu\b/', (string) ($l['fuentes']['talla'] ?? ''))
            && empty($l['corregida']);
        $porLetra = in_array((string) ($l['fuentes']['talla'] ?? ''), ['ese', 'eme', 'ele'], true);
        $aviso = $generoAsumido ? 'Se tomó '.self::GENEROS[$genero].': es la única línea con ese color y talla.'
            : ($unicaAsumida ? 'Se tomó talla Única: no se dijo la talla del crop top.'
                : ($porLetra ? 'Se entendió «'.$l['fuentes']['talla'].'» como talla '.$l['talla_etiqueta'].': confírmalo.' : null));

        $variante = ProductProxy::modelClass()::query()->find((int) $v->id);

        return [
            'estado' => $aviso !== null ? 'aviso' : 'ok',
            'tipo_error' => null,
            'mensaje' => $aviso,
            'nota' => $fila['tipo_por_contexto'] ? 'Tipo '.self::GENEROS[$genero].': el elegido para lo que no se dijo.' : null,
            'genero' => $genero,
            'producto_id' => (int) $v->parent_id,
            'variante_id' => (int) $v->id,
            'sku' => $v->sku,
            'nombre' => (string) $padre->name,
            'stock' => $variante ? (int) $variante->getTypeInstance()->totalQuantity() : null,
            'gestiona_stock' => $variante ? (bool) $variante->manage_stock : null,
        ] + $fila;
    }

    private function mensajeProblemas(array $l): string
    {
        $partes = [];
        $detalle = $l['detalle'] ?? [];
        $comillas = fn (array $x) => '«'.implode(', ', $x).'»';

        foreach ($l['problemas'] as $problema) {
            $partes[] = match ($problema) {
                Interprete::FALTA_COLOR => 'No entendimos el color.',
                Interprete::COLOR_AMBIGUO => '¿Cuál color? '.implode(', ', $l['candidatos']).'.',
                Interprete::FALTA_TALLA => 'Falta la talla.',
                Interprete::TALLA_NO_RECONOCIDA => 'No entendimos la talla '.$comillas((array) ($detalle['talla_invalida'] ?? [])).'.',
                Interprete::TALLA_O_CANTIDAD => '¿'.$comillas((array) ($detalle['dudoso'] ?? [])).' es '.implode(' o ', array_map(fn ($o) => $this->leerOpcion($o), $l['opciones'])).'?',
                Interprete::FALTA_CANTIDAD => 'Falta la cantidad.',
                Interprete::CANTIDAD_INVALIDA => 'Cantidad inválida '.$comillas([(string) ($detalle['cantidad_invalida'] ?? '')]).': debe ser un número entero de 1 a 9999.',
                Interprete::CANTIDAD_CONTRADICTORIA => 'Hay dos cantidades '.$comillas((array) ($detalle['contradiccion'] ?? [])).': revisa cuál es.',
                Interprete::NUMEROS_SIN_ASIGNAR => 'No sé a qué corresponde '.$comillas($l['sin_asignar']).'.',
                default => $problema,
            };
        }

        return implode(' ', $partes);
    }

    /** «talla 6-8», «68 unidades» o «talla 6-8 y 24 unidades». */
    private function leerOpcion(array $o): string
    {
        $partes = [];
        if ($o['talla'] !== null) {
            $partes[] = 'talla '.$o['talla_etiqueta'];
        }
        if ($o['cantidad'] !== null) {
            $partes[] = $o['cantidad'].' unidades';
        }

        return implode(' y ', $partes);
    }

    private function mensajeInexistente(array $l, int $codigo, array $generos): string
    {
        $linea = fn (string $g) => $l['linea'] === 'TP' ? 'Crop top' : self::GENEROS[$g];
        $padres = DB::table('products')->whereNull('parent_id')
            ->whereIn('sku', array_map(fn ($g) => $codigo.$g.'-'.$l['linea'], $generos))->pluck('sku')->all();

        if ($padres !== []) {
            return 'La variante no existe: '.$l['color'].' '.implode(' / ', array_map($linea, $generos)).' no tiene talla '.$l['talla_etiqueta'].'.';
        }

        return 'Ese color no existe para '.implode(' ni ', array_map($linea, $generos)).': no hay '.$l['color'].'.';
    }
}
