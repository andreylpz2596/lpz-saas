<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;

/**
 * Doc. 33 (VentasAsesor) — En el cargue de camisetas el CÓDIGO lo pone el patrón, no el archivo: «115D-DZ» para el producto base y
 * «115D-DZ-M» para la variante (número del color + público, línea por la tela, talla). Las columnas `codigo_base` y `sku_variante` del
 * archivo solo agrupan las filas; si el grupo se puede calcular, se reemplazan por el código del patrón antes de analizar, aplicar
 * y mostrar el resultado, así el cargue es repetible (el mismo archivo da el mismo código) y encuentra los productos ya creados.
 *
 * - Un grupo se reescribe solo si TODAS sus filas tienen público, color y talla reconocidos y un único color (configurable =
 *   línea × color). Si no, conserva los códigos del archivo y el análisis explica lo que falte.
 * - `asignar` = false (vista previa): un color sin número usa uno provisional, sin escribir nada.
 * - Desactivado con `ventasasesor.sku.automatico` = false.
 */
class SkuCamisetas
{
    public function __construct(protected CatalogoCamisetas $catalogo, protected ?CodigoSkuService $codigos = null)
    {
        $this->codigos ??= class_exists(CodigoSkuService::class) ? new CodigoSkuService : null;
    }

    public static function activo(): bool
    {
        return class_exists(CodigoSkuService::class) && (bool) config('ventasasesor.sku.automatico', true);
    }

    /**
     * @param  iterable<array{id:int|string, v:array<string,mixed>}>  $filas
     * @return array<int|string, array{codigo_base:string, sku_variante:string}> id => códigos del patrón (solo grupos calculables)
     */
    public function mapa(iterable $filas, bool $asignar): array
    {
        if (! self::activo() || $this->codigos === null) {
            return [];
        }

        $cfg = (array) config('cargues.productos_camisetas');
        $attr = (array) $cfg['atributos'];
        $grupos = [];

        foreach ($filas as $f) {
            $base = CatalogoCamisetas::clave((string) ($f['v']['codigo_base'] ?? ''));

            if ($base !== '') {
                $grupos[$base][] = $f;
            }
        }

        $salida = [];

        foreach ($grupos as $g) {
            $publico = null;
            $tela = null;

            foreach ($g as $f) {
                $publico ??= trim((string) ($f['v']['publico'] ?? '')) ?: null;
                $tela ??= trim((string) ($f['v']['tipo_tela'] ?? '')) ?: null;
            }

            $codigoPublico = CatalogoCamisetas::clave($publico);
            $codigoPublico = $cfg['sinonimos_publico'][$codigoPublico] ?? $codigoPublico;
            $p = $cfg['publicos'][$codigoPublico] ?? null;
            $familia = $p ? $this->catalogo->familia((string) $p['familia']) : null;

            if ($familia === null) {
                continue;
            }

            $tela ??= $cfg['por_defecto']['tipo_tela'] ?? null;
            $telaId = $tela === null ? null : $this->catalogo->opcion($attr['tipo_tela'], $tela)[0];
            $ejeTalla = (string) ($p['atributo_talla'] ?? $attr['talla']);
            $colores = [];
            $tallas = [];

            foreach ($g as $f) {
                $cid = $this->catalogo->opcion($attr['color'], trim((string) ($f['v']['color'] ?? '')))[0];
                $tid = $this->catalogo->opcion($ejeTalla, trim((string) ($f['v']['talla'] ?? '')))[0];
                $codigoTalla = $tid === null ? null : $this->codigos->talla((int) $tid);

                if ($cid === null || $codigoTalla === null) {
                    continue 2;
                }

                $colores[(int) $cid] = true;
                $tallas[$f['id']] = $codigoTalla;
            }

            if (count($colores) !== 1) {
                continue;
            }

            $base = $this->codigos->base((int) $familia['id'], (int) array_key_first($colores), $telaId === null ? null : (int) $telaId, $asignar);

            if ($base === null) {
                continue;
            }

            foreach ($g as $f) {
                $salida[$f['id']] = ['codigo_base' => $base, 'sku_variante' => $base.'-'.$tallas[$f['id']]];
            }
        }

        return $salida;
    }

    /**
     * Las mismas filas con `codigo_base` / `sku_variante` del patrón (guarda los del archivo en `*_archivo`).
     *
     * @param  list<array{id:int, v:array<string,mixed>}>  $filas
     */
    public function reescribir(array $filas, bool $asignar): array
    {
        $mapa = $this->mapa($filas, $asignar);

        foreach ($filas as &$f) {
            if (isset($mapa[$f['id']])) {
                $f['v']['codigo_base_archivo'] = $f['v']['codigo_base'] ?? null;
                $f['v']['sku_variante_archivo'] = $f['v']['sku_variante'] ?? null;
                $f['v'] = array_replace($f['v'], $mapa[$f['id']]);
            }
        }

        return $filas;
    }
}
