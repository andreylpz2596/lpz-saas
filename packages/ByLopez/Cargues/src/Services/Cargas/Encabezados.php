<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Services\Lectura\FilaLeida;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Encabezados del archivo para relacionar columnas. Las columnas se identifican por POSICION, no por nombre: dos
 * encabezados iguales o que solo difieren en mayusculas (p. ej. `Producto` y `producto`) son columnas distintas y se
 * muestran las dos; nunca se fusionan. Una sugerencia solo se ofrece cuando hay UNA coincidencia inequivoca.
 */
class Encabezados
{
    /**
     * @param  FilaLeida[]  $ejemplos
     * @return array{columnas: array<int, array{indice:int, letra:string, texto:string, ambiguo:bool, similar:bool, ejemplo:?string}>, ejemplos: array<int, array<int, ?string>>}
     */
    public function construir(?FilaLeida $encabezado, array $ejemplos, Normalizador $n): array
    {
        $ancho = count($encabezado?->valores ?? []);

        foreach ($ejemplos as $f) {
            $ancho = max($ancho, count($f->valores));
        }

        $textos = [];

        for ($i = 0; $i < $ancho; $i++) {
            $textos[$i] = $n->texto($encabezado?->valores[$i] ?? null) ?? '';
        }

        $exactos = array_count_values(array_filter($textos, fn ($t) => $t !== ''));
        $laxos = array_count_values(array_map(fn ($t) => $this->clave($t), array_filter($textos, fn ($t) => $t !== '')));

        $columnas = [];

        for ($i = 0; $i < $ancho; $i++) {
            $t = $textos[$i];
            $columnas[] = [
                'indice' => $i,
                'letra' => Coordinate::stringFromColumnIndex($i + 1),
                'texto' => $t,
                'ambiguo' => $t !== '' && ($exactos[$t] ?? 0) > 1,
                'similar' => $t !== '' && ($laxos[$this->clave($t)] ?? 0) > ($exactos[$t] ?? 0),
                'ejemplo' => $this->primerEjemplo($ejemplos, $i, $n),
            ];
        }

        return [
            'columnas' => $columnas,
            'ejemplos' => array_map(fn (FilaLeida $f) => array_map(fn ($i) => $n->texto($f->valores[$i] ?? null), range(0, max(0, $ancho - 1))), $ejemplos),
        ];
    }

    /**
     * Sugerencia campo => indice por coincidencia con nombre, etiqueta o sinonimos; solo si es unica (ni dos columnas
     * que casan ni encabezados equivalentes que se confundirian).
     *
     * @return array<string, int>
     */
    public function sugerir(array $columnas, array $campos): array
    {
        $sug = [];
        $usadas = [];

        foreach ($campos as $campo) {
            $claves = array_map([$this, 'clave'], array_merge([$campo['nombre'], $campo['etiqueta'] ?? $campo['nombre']], $campo['sinonimos'] ?? []));
            $candidatas = [];

            foreach ($columnas as $c) {
                if ($c['texto'] !== '' && in_array($this->clave($c['texto']), $claves, true)) {
                    $candidatas[] = $c;
                }
            }

            if (count($candidatas) === 1 && ! $candidatas[0]['ambiguo'] && ! $candidatas[0]['similar'] && ! in_array($candidatas[0]['indice'], $usadas, true)) {
                $sug[$campo['nombre']] = $candidatas[0]['indice'];
                $usadas[] = $candidatas[0]['indice'];
            }
        }

        return $sug;
    }

    /** Firma de los encabezados (para recuperar comparaciones guardadas y detectar un formato distinto). */
    public function firma(array $columnas): string
    {
        return hash('sha256', json_encode(array_column($columnas, 'texto'), JSON_UNESCAPED_UNICODE));
    }

    public function clave(string $t): string
    {
        $minuscula = mb_strtolower(trim($t));
        $descompuesta = \Normalizer::normalize($minuscula, \Normalizer::FORM_D);
        $sinTildes = preg_replace('/\p{Mn}+/u', '', $descompuesta === false ? $minuscula : $descompuesta) ?? $minuscula;

        return preg_replace('/[\s_\-]+/u', ' ', $sinTildes) ?? $sinTildes;
    }

    protected function primerEjemplo(array $ejemplos, int $i, Normalizador $n): ?string
    {
        foreach ($ejemplos as $f) {
            $t = $n->texto($f->valores[$i] ?? null);

            if ($t !== null && $t !== '') {
                return mb_substr($t, 0, 120);
            }
        }

        return null;
    }
}
