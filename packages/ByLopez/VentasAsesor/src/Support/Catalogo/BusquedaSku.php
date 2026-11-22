<?php

namespace ByLopez\VentasAsesor\Support\Catalogo;

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;

/**
 * Doc. 33 — Búsqueda rápida por código único. Sin importar guiones, espacios ni mayúsculas:
 *
 *   «115d» / «115 d» / «115D-»  → productos 115D-…  (Verde Cali, Dama)
 *   «115dm» / «115 d m»          → variantes 115D-…-M
 *   «115ddzm» / «115D-DZ-M»      → 115D-DZ-M
 *   «115n6»                       → 115N-…-6 (niño 6-8)
 *
 * Además, «dama/mujer», «hombre/caballero», «niño/nino» se buscan como sinónimos en el nombre.
 */
final class BusquedaSku
{
    public const SINONIMOS = [
        'dama' => ['dama', 'mujer'], 'mujer' => ['mujer', 'dama'],
        'hombre' => ['hombre', 'caballero'], 'caballero' => ['caballero', 'hombre'],
        'nino' => ['niño', 'nino'], 'niño' => ['niño', 'nino'], 'nina' => ['niña', 'niño'],
    ];

    /**
     * @return array{producto:string, variante:?string}|null patrones LIKE (producto: «115D-%» o «115D-DZ%»; variante: «115D-%-M»)
     */
    public static function interpretar(string $texto): ?array
    {
        $c = mb_strtoupper((string) preg_replace('/[\s\-_.\/]+/u', '', trim($texto)));

        if (! preg_match('/^(\d{2,4})([DHN])([A-Z0-9]*)$/', $c, $m)) {
            return null;
        }

        $resto = $m[3];
        $linea = null;

        foreach (CodigoSkuService::lineas() as $l) {
            if ($l !== '' && str_starts_with($resto, $l)) {
                $linea = $l;
                $resto = substr($resto, strlen($l));
                break;
            }
        }

        // Repetición de base (115D-DZ2): el número pegado a la línea.
        $repeticion = '';

        if ($linea !== null && preg_match('/^(\d)(?=[A-Z])/', $resto, $r)) {
            $repeticion = $r[1];
            $resto = substr($resto, 1);
        }

        $base = $m[1].$m[2].'-'.($linea !== null ? $linea.$repeticion : '');

        return ['producto' => $base.'%', 'variante' => $resto === '' ? null : $base.'%-'.$resto];
    }

    /** Variantes de la palabra para buscar en el nombre (sinónimos de público). @return list<string> */
    public static function sinonimos(string $palabra): array
    {
        return self::SINONIMOS[mb_strtolower($palabra)] ?? [$palabra];
    }
}
