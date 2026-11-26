<?php

namespace ByLopez\VentasAsesor\Services\Venta\Dictado;

use ByLopez\VentasAsesor\Support\Dictado\InterpreteDictado as Interprete;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductProxy;

/**
 * Doc. 34 · Pedido por voz: cada linea que entendio InterpreteDictado se valida contra el catalogo REAL con el codigo unico
 * (Doc. 33): color → numero (bylopez_va_sku_colores) + genero (D/H/N) + linea (DZ/TP) + talla → SKU exacto de la variante
 * («110N-DZ-2»). Un SKU dictado se busca TAL COMO SE DIJO (no se reconstruye desde el nombre del color). Sin servicios externos.
 * Solo lectura: no toca la venta; agregar lo resuelto lo hace AgregarDictadoService con las reglas de siempre (precio, stock,
 * permisos), revalidando en el servidor.
 *
 * Estados por linea: `ok` (una variante exacta), `aviso` (resuelta, pero se asumio o se descarto algo visible) y `error`, con
 * `tipo_error`:
 *  - `interpretacion`: falta un dato o es ambiguo (color, talla, tipo, numero dudoso o sobrante, cantidad invalida o
 *    contradictoria, corrección sin reemplazo claro, SKU contradictorio); la pantalla ofrece opciones concretas;
 *  - `catalogo`: esa combinacion no existe o el producto no esta activo (los atributos reconocidos se conservan).
 * La disponibilidad (`stock`, `gestiona_stock`) se informa para orientar; nunca se usa para adivinar la variante.
 */
class ResolutorDictado
{
    private const GENEROS = ['D' => 'Dama', 'H' => 'Caballero', 'N' => 'Niño'];

    /** Tallas que se pueden elegir al corregir una linea: codigo del SKU → etiqueta (en este orden). */
    public const TALLAS = ['S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL', 'U' => 'Única', '2' => '2-4', '6' => '6-8', '10' => '10-12', '14' => '14-16'];

    /** Tipos que se pueden elegir como «Si no digo el tipo» (el crop top tambien: el servidor lo acepta). */
    public const TIPOS_DEFECTO = ['D', 'H', 'N', 'TP'];

    /** @var array<string, array<string, list<string>>>|null catalogo de la peticion (una lectura por interpretacion) */
    private ?array $catalogoCache = null;

    /**
     * @param  ?string  $tipoDefecto  tipo elegido por la asesora para las lineas donde no lo dijo (D, H, N o TP); null = preguntar
     * @return list<array<string, mixed>>
     */
    public function interpretar(string $texto, ?string $tipoDefecto = null): array
    {
        $colores = $this->colores();
        $interprete = new Interprete(array_keys($colores), (array) config('ventasasesor.dictado.alias_colores', []), array_flip($colores));

        return array_map(fn (array $l) => $this->resolver($l, $colores, $tipoDefecto), $interprete->interpretar($texto, $tipoDefecto));
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
            'genero_heredado' => false,
            'genero_por_talla' => false,
            'sku_dicho' => null,
            'talla_asumida' => false,
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
        $l += ['genero_heredado' => false, 'genero_por_talla' => false, 'sku_dicho' => null, 'talla_asumida' => false];
        $fila = $l + ['estado' => 'error', 'tipo_error' => 'interpretacion', 'mensaje' => null, 'nota' => null, 'producto_id' => null,
            'variante_id' => null, 'sku' => null, 'nombre' => null, 'stock' => null, 'gestiona_stock' => null, 'tipo_por_contexto' => false,
            'opciones_talla' => [], 'opciones_tipo' => []];

        // E: el tipo elegido por la asesora solo completa lo que NO se dijo (nunca reemplaza un tipo dicho ni resuelve una
        // duda de tipo: si «caballero» puede ser de esta prenda, la pregunta se queda).
        $dudaDeTipo = array_intersect($l['problemas'], [Interprete::TIPO_DUDOSO, Interprete::TIPO_CONTRADICTORIO]) !== [];
        if ($l['linea'] === 'DZ' && $l['genero'] === null && ! $dudaDeTipo && in_array($tipoDefecto, self::TIPOS_DEFECTO, true)) {
            $fila['tipo_por_contexto'] = true;
            if ($tipoDefecto === 'TP') {
                $l['linea'] = $fila['linea'] = 'TP';
                $l['genero'] = $fila['genero'] = 'D';
                // Crop top sin talla dicha: la decide el catalogo, igual que «crop top negro 2».
                if ($l['talla'] === null && $l['problemas'] === [Interprete::FALTA_TALLA]) {
                    $l['problemas'] = $fila['problemas'] = [];
                    $l['talla'] = 'U';
                    $l['talla_asumida'] = $fila['talla_asumida'] = true;
                }
            } else {
                $l['genero'] = $fila['genero'] = $tipoDefecto;
            }
        }

        $tipoLinea = $l['linea'] === 'TP' ? 'TP' : $l['genero'];

        if ($l['problemas'] !== []) {
            // Opciones concretas para lo que falta: tallas que existen para ese color y tipo, tipos que existen para el color.
            if ($l['color'] !== null && $tipoLinea !== null && array_intersect($l['problemas'], [Interprete::FALTA_TALLA, Interprete::TALLA_CONTRADICTORIA]) !== []) {
                $fila['opciones_talla'] = $this->catalogoActual()[$l['color']][$tipoLinea] ?? [];
            }
            if ($l['color'] !== null && $dudaDeTipo) {
                $fila['opciones_tipo'] = $this->tiposConTalla($l['color'], $l['talla']);
            }

            return ['mensaje' => $this->mensajeProblemas($l)] + $fila;
        }

        $codigo = $l['sku_dicho'] !== null ? (int) substr((string) $l['sku_dicho'], 0, 3) : ($colores[$l['color']] ?? null);
        if ($codigo === null) {
            return ['mensaje' => 'El color '.$l['color'].' no tiene código en el catálogo.', 'tipo_error' => 'catalogo'] + $fila;
        }

        $avisos = [];

        // Crop top sin talla dicha: nunca se asume Única si el color tambien tiene M.
        if ($l['linea'] === 'TP' && $l['talla_asumida']) {
            $tallasTp = $this->catalogoActual()[$l['color']]['TP'] ?? [];
            if ($tallasTp === []) {
                return ['mensaje' => 'No hay crop top '.$l['color'].' en el catálogo.', 'tipo_error' => 'catalogo'] + $fila;
            }
            if (count($tallasTp) > 1) {
                return ['mensaje' => 'El crop top '.$l['color'].' existe en '.implode(' y ', array_map(fn ($t) => self::TALLAS[$t] ?? $t, $tallasTp)).': elige la talla.',
                    'opciones_talla' => $tallasTp, 'talla' => null, 'talla_etiqueta' => null] + $fila;
            }
            $l['talla'] = $tallasTp[0];
            $l['talla_etiqueta'] = Interprete::etiquetaTalla($tallasTp[0]);
            $fila['talla'] = $l['talla'];
            $fila['talla_etiqueta'] = $l['talla_etiqueta'];
            $avisos[] = 'Se tomó talla '.$l['talla_etiqueta'].': es la única del crop top '.$l['color'].'.';
        }

        $generos = $l['linea'] === 'TP' ? ['D'] : ($l['genero'] !== null ? [$l['genero']] : ['D', 'H']);
        $skus = [];
        foreach ($generos as $g) {
            $skus[$g] = $codigo.$g.'-'.$l['linea'].'-'.$l['talla'];
        }
        // El SKU dicho completo se busca exacto (no se reemplaza por uno parecido).
        if ($l['sku_dicho'] !== null && substr_count((string) $l['sku_dicho'], '-') === 2) {
            $skus = [substr((string) $l['sku_dicho'], 3, 1) => (string) $l['sku_dicho']];
        }

        $variantes = DB::table('products')->whereIn('sku', array_values($skus))->whereNotNull('parent_id')->get(['id', 'sku', 'parent_id']);

        if ($variantes->isEmpty()) {
            $mensaje = $l['sku_dicho'] !== null && substr_count((string) $l['sku_dicho'], '-') === 2
                ? 'La referencia «'.$l['sku_dicho'].'» no existe en el catálogo.'
                : $this->mensajeInexistente($l, $codigo, $generos);

            return ['mensaje' => $mensaje, 'tipo_error' => 'catalogo',
                'opciones_talla' => $tipoLinea !== null ? ($this->catalogoActual()[$l['color']][$tipoLinea] ?? []) : []] + $fila;
        }

        if ($variantes->count() > 1) {
            return ['mensaje' => 'Di si es dama o caballero: '.$l['color'].' talla '.$l['talla_etiqueta'].' existe en las dos.',
                'opciones_tipo' => array_values(array_keys(array_filter($skus, fn ($s) => $variantes->contains('sku', $s))))] + $fila;
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

        if ($l['linea'] !== 'TP' && $l['genero'] === null) {
            $avisos[] = 'Se tomó '.self::GENEROS[$genero].': es la única línea con ese color y talla.';
        }
        if (! empty($l['detalle']['no_usadas'])) {
            $avisos[] = 'No usé: «'.implode('», «', $l['detalle']['no_usadas']).'». Revisa si falta algo.';
        }

        $notas = [];
        if ($fila['tipo_por_contexto']) {
            $notas[] = 'Tipo '.($l['linea'] === 'TP' ? 'Crop top' : self::GENEROS[$genero]).': el elegido para lo que no se dijo.';
        }
        if (! empty($l['detalle']['correcciones'])) {
            $notas[] = 'Corregido: '.implode('; ', $l['detalle']['correcciones']).'.';
        }

        $variante = ProductProxy::modelClass()::query()->find((int) $v->id);

        return [
            'estado' => $avisos !== [] ? 'aviso' : 'ok',
            'tipo_error' => null,
            'mensaje' => $avisos !== [] ? implode(' ', $avisos) : null,
            'nota' => $notas !== [] ? implode(' ', $notas) : null,
            'genero' => $genero,
            'producto_id' => (int) $v->parent_id,
            'variante_id' => (int) $v->id,
            'sku' => $v->sku,
            'nombre' => (string) $padre->name,
            'stock' => $variante ? (int) $variante->getTypeInstance()->totalQuantity() : null,
            'gestiona_stock' => $variante ? (bool) $variante->manage_stock : null,
        ] + $fila;
    }

    /** @return array<string, array<string, list<string>>> */
    private function catalogoActual(): array
    {
        return $this->catalogoCache ??= $this->catalogo();
    }

    /** Tipos (D, H, N, TP) que existen para un color (y la talla, si ya se sabe). */
    private function tiposConTalla(string $color, ?string $talla): array
    {
        $tipos = $this->catalogoActual()[$color] ?? [];

        return array_values(array_keys(array_filter($tipos, fn ($tallas) => $talla === null || in_array($talla, $tallas, true))));
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
                Interprete::TALLA_CONTRADICTORIA => 'Hay dos tallas '.$comillas((array) ($detalle['tallas_dobles'] ?? [])).': elige cuál es.',
                Interprete::TIPO_CONTRADICTORIO => 'Hay dos tipos '.$comillas((array) ($detalle['tipos_dobles'] ?? [])).': elige cuál es.',
                Interprete::TIPO_DUDOSO => '¿'.$comillas([(string) ($detalle['tipo_dudoso'] ?? '')]).' es de esta prenda o de la siguiente? Elige el tipo.',
                Interprete::CORRECCION_DUDOSA => 'Dijiste '.$comillas((array) ($detalle['correccion'] ?? [])).' pero no queda claro qué cambiar: revisa la línea.',
                Interprete::SKU_NO_RECONOCIDO => 'La referencia '.$comillas((array) ($detalle['skus_invalidos'] ?? [])).' no es del catálogo (código: color + D/H/N + DZ/TP + talla).',
                Interprete::SKU_CONTRADICTORIO => 'La referencia «'.($l['sku_dicho'] ?? '').'» no coincide con '.$comillas((array) ($detalle['sku_conflictos'] ?? [])).': revisa.',
                default => $problema,
            };
        }

        if (! empty($detalle['no_usadas'])) {
            $partes[] = 'No usé: '.$comillas($detalle['no_usadas']).'.';
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
