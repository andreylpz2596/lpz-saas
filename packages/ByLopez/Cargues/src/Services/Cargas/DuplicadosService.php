<?php

namespace ByLopez\Cargues\Services\Cargas;

/**
 * Deteccion de filas que violarian una llave primaria o unica de la intermedia (07 §5.5), segun `map.duplicados`.
 * Es la logica validada en `08` §3.2 (12 filas: rechazar_todas 3,5,7,8,10 · primera 5,8,10 · ultima 3,5,7).
 *
 * Solo se comparan llaves de tipos cuya comparacion en la BD coincide con la de PHP (identificador exacto, enteros,
 * decimales, fechas): el validador del map no admite `texto` en llaves unicas. Un valor NULL no colisiona.
 * La restriccion real de la BD sigue siendo la garantia final (LlenadoIntermediaService).
 */
class DuplicadosService
{
    /**
     * @param  array<int, array<string, mixed>>  $filas  fila_origen => valores normalizados (solo filas aceptadas)
     * @param  array{primaria: ?array, unicas: array}  $llaves
     * @return array{0: int[], 1: array<int, array{motivo: string, campo: string}>} aceptadas y rechazadas con su motivo
     */
    public function detectar(array $filas, array $llaves, string $politica): array
    {
        $grupos = array_merge(! empty($llaves['primaria']) ? [$llaves['primaria']] : [], $llaves['unicas'] ?? []);
        $rechazo = [];

        if ($grupos === []) {
            $aceptadas = array_keys($filas);
            sort($aceptadas);

            return [$aceptadas, []];
        }

        if ($politica === 'rechazar_todas') {
            foreach ($grupos as $g) {
                $porValor = [];

                foreach ($filas as $n => $f) {
                    $vals = array_map(fn ($c) => $f[$c] ?? null, $g);

                    if (in_array(null, $vals, true)) {
                        continue; // NULL no viola UNIQUE en MySQL/MariaDB
                    }

                    $porValor[json_encode($vals, JSON_UNESCAPED_UNICODE)][] = $n;
                }

                foreach ($porValor as $json => $ns) {
                    if (count($ns) > 1) {
                        foreach ($ns as $n) {
                            $rechazo[$n][] = ['campo' => implode('+', $g), 'motivo' => 'llave duplicada en el archivo ('.implode('+', $g).'='.implode('|', json_decode($json)).'; filas '.implode(',', $ns).')'];
                        }
                    }
                }
            }
        } else {
            $orden = array_keys($filas);
            sort($orden);

            if ($politica === 'ultima') {
                $orden = array_reverse($orden);
            }

            $tomados = [];

            foreach ($orden as $n) {
                foreach ($grupos as $gi => $g) {
                    $vals = array_map(fn ($c) => $filas[$n][$c] ?? null, $g);

                    if (in_array(null, $vals, true)) {
                        continue;
                    }

                    $k = $gi.':'.json_encode($vals, JSON_UNESCAPED_UNICODE);

                    if (isset($tomados[$k])) {
                        $rechazo[$n][] = ['campo' => implode('+', $g), 'motivo' => 'llave duplicada en el archivo ('.implode('+', $g).'='.implode('|', $vals)."; conservada la fila {$tomados[$k]})"];
                    }
                }

                if (! isset($rechazo[$n])) {
                    foreach ($grupos as $gi => $g) {
                        $vals = array_map(fn ($c) => $filas[$n][$c] ?? null, $g);

                        if (! in_array(null, $vals, true)) {
                            $tomados[$gi.':'.json_encode($vals, JSON_UNESCAPED_UNICODE)] = $n;
                        }
                    }
                }
            }
        }

        $aceptadas = array_values(array_diff(array_keys($filas), array_keys($rechazo)));
        sort($aceptadas);

        $salida = [];

        foreach ($rechazo as $n => $motivos) {
            $salida[$n] = ['campo' => implode(' / ', array_unique(array_column($motivos, 'campo'))), 'motivo' => implode(' / ', array_column($motivos, 'motivo'))];
        }

        return [$aceptadas, $salida];
    }
}
