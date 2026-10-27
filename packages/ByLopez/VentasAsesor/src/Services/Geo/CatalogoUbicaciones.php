<?php

namespace ByLopez\VentasAsesor\Services\Geo;

use ByLopez\VentasAsesor\DataObjects\UbicacionResuelta;
use ByLopez\VentasAsesor\Support\Geo\NombresGeograficos;
use Illuminate\Support\Facades\DB;

/**
 * Lectura del catalogo local DIVIPOLA (D-58). Reutilizable (Clientes, Logistica, API): solo
 * lee `bylopez_geo_*` y `country_states`; no consulta Internet ni escribe nada.
 *
 * - `buscar()`: autocomplete. Cada palabra escrita debe coincidir con el INICIO de una palabra del
 *   municipio o de su departamento (sin tildes ni mayusculas), asi «Pes» → Pesca, «Boy» → los
 *   municipios de Boyacá y «Boy Pe» → Boyacá — Pesca. Primero las coincidencias por municipio y
 *   despues las que solo coinciden por departamento. Solo municipios activos y un maximo de
 *   resultados: nunca se envia Colombia entera al navegador.
 * - `resolver()`: el BACKEND es la autoridad. Dado el codigo DIVIPOLA devuelve municipio,
 *   departamento y el `state` de Bagisto, o null si no existe o esta inactivo.
 */
class CatalogoUbicaciones
{
    public const MINIMO_CARACTERES = 2;

    public const LIMITE = 15;

    /**
     * @return list<array{municipio_codigo: string, municipio: string, departamento_codigo: string, departamento: string, etiqueta: string}>
     */
    public function buscar(string $texto, int $limite = self::LIMITE): array
    {
        $normalizado = NombresGeograficos::busqueda($texto);

        if (mb_strlen($normalizado) < self::MINIMO_CARACTERES) {
            return [];
        }

        // El texto normalizado solo contiene [a-z0-9 ]: no hay comodines de LIKE que escapar.
        $consulta = DB::table('bylopez_geo_municipios as m')
            ->join('bylopez_geo_departamentos as d', 'd.codigo_divipola', '=', 'm.departamento_codigo')
            ->where('m.activo', true);

        foreach (explode(' ', $normalizado) as $palabra) {
            $consulta->where(fn ($w) => $w
                ->where('m.nombre_busqueda', 'like', $palabra.'%')
                ->orWhere('m.nombre_busqueda', 'like', '% '.$palabra.'%')
                ->orWhere('d.nombre_busqueda', 'like', $palabra.'%')
                ->orWhere('d.nombre_busqueda', 'like', '% '.$palabra.'%'));
        }

        return $consulta
            ->select(['m.codigo_divipola as municipio_codigo', 'm.nombre_oficial as municipio', 'd.codigo_divipola as departamento_codigo', 'd.nombre_oficial as departamento'])
            ->selectRaw('CASE WHEN m.nombre_busqueda LIKE ? THEN 0 WHEN m.nombre_busqueda LIKE ? THEN 1 ELSE 2 END AS relevancia', [$normalizado.'%', '% '.$normalizado.'%'])
            ->orderBy('relevancia')
            ->orderBy('m.nombre_busqueda')
            ->limit(max(1, $limite))
            ->get()
            ->map(fn ($f) => [
                'municipio_codigo' => (string) $f->municipio_codigo,
                'municipio' => (string) $f->municipio,
                'departamento_codigo' => (string) $f->departamento_codigo,
                'departamento' => (string) $f->departamento,
                'etiqueta' => $f->departamento.' — '.$f->municipio,
            ])
            ->all();
    }

    public function resolver(string $municipioCodigo): ?UbicacionResuelta
    {
        // Cadena de exactamente 5 digitos: nunca se interpreta como entero (perderia el cero inicial).
        if (preg_match('/^\d{5}$/', $municipioCodigo) !== 1) {
            return null;
        }

        $fila = DB::table('bylopez_geo_municipios as m')
            ->join('bylopez_geo_departamentos as d', 'd.codigo_divipola', '=', 'm.departamento_codigo')
            ->join('country_states as s', 's.id', '=', 'd.country_state_id')
            ->where('m.codigo_divipola', $municipioCodigo)
            ->where('m.activo', true)
            ->where('s.country_code', 'CO')
            ->first(['m.codigo_divipola as municipio_codigo', 'm.nombre_oficial as municipio', 'd.codigo_divipola as departamento_codigo', 'd.nombre_oficial as departamento', 's.code as state_code']);

        return $fila === null ? null : new UbicacionResuelta(
            municipioCodigo: (string) $fila->municipio_codigo,
            municipio: (string) $fila->municipio,
            departamentoCodigo: (string) $fila->departamento_codigo,
            departamento: (string) $fila->departamento,
            stateCode: (string) $fila->state_code,
        );
    }
}
