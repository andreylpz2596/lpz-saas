<?php

namespace ByLopez\VentasAsesor\Services\Geo;

use ByLopez\VentasAsesor\Support\Geo\EquivalenciaDepartamentos;
use ByLopez\VentasAsesor\Support\Geo\NombresGeograficos;
use Illuminate\Support\Facades\DB;

/**
 * Importacion CONTROLADA del catalogo DIVIPOLA (D-58) desde el archivo oficial del DANE
 * (datos.gov.co, dataset gdxc-w37w, CSV). Nunca consulta Internet.
 *
 * Seguridad:
 *  - dry-run por defecto: `ejecutar(persistir: false)` calcula el mismo informe y NO escribe;
 *  - FALLA CERRADO: ante cualquier error (esquema distinto, codigos mal formados, departamento sin
 *    equivalencia, `country_state` inexistente o ambiguo) no se escribe nada, tampoco con
 *    persistir. Nunca se adivina una equivalencia dudosa;
 *  - idempotente: upsert por codigo DIVIPOLA; un archivo ya importado no produce cambios;
 *  - no borra: un municipio ausente de la fuente se INACTIVA y uno que reaparece se reactiva;
 *  - una sola transaccion.
 *
 * Esquema real verificado (2026-10-05): `Código Departamento` (2), `Nombre Departamento`,
 * `Código Municipio` (5), `Nombre Municipio`, `Tipo: Municipio / Isla / Área no municipalizada`,
 * `longitud`, `Latitud`. UTF-8, nombres en MAYUSCULAS, coordenadas con coma decimal (no se importan).
 */
class ImportadorDivipolaService
{
    public const FUENTE = 'DANE DIVIPOLA (datos.gov.co gdxc-w37w)';

    private const ENCABEZADO = ['codigo departamento', 'nombre departamento', 'codigo municipio', 'nombre municipio', 'tipo'];

    private const MAX_ERRORES = 25;

    /**
     * @return array{
     *     checksum: ?string, filas: int, errores: list<string>, advertencias: list<string>,
     *     departamentos: array{altas: list<string>, cambios: list<string>, sin_cambios: int},
     *     municipios: array{altas: int, cambios: list<string>, solo_metadatos: int, reactivados: list<string>, inactivados: list<string>, sin_cambios: int},
     *     persistido: bool
     * }
     */
    public function ejecutar(string $ruta, bool $persistir, ?string $corte = null): array
    {
        $informe = [
            'checksum' => null, 'filas' => 0, 'errores' => [], 'advertencias' => [],
            'departamentos' => ['altas' => [], 'cambios' => [], 'sin_cambios' => 0],
            'municipios' => ['altas' => 0, 'cambios' => [], 'solo_metadatos' => 0, 'reactivados' => [], 'inactivados' => [], 'sin_cambios' => 0],
            'persistido' => false,
        ];

        $contenido = is_file($ruta) ? file_get_contents($ruta) : false;

        if ($contenido === false || $contenido === '') {
            $informe['errores'][] = "No se pudo leer el archivo '{$ruta}' o esta vacio.";

            return $informe;
        }

        $informe['checksum'] = hash('sha256', $contenido);

        [$filas, $errores] = $this->leer($contenido);

        $informe['filas'] = count($filas);
        $informe['errores'] = $errores;

        if ($errores !== []) {
            return $informe;
        }

        $estados = $this->resolverEstados($filas, $informe);

        if ($informe['errores'] !== []) {
            return $informe;
        }

        $plan = $this->planificar($filas, $estados, $informe['checksum'], $corte, $informe);

        if ($persistir) {
            $this->escribir($plan);
            $informe['persistido'] = true;
        }

        return $informe;
    }

    /**
     * @return array{0: list<array{dep: string, dep_nombre: string, mun: string, mun_nombre: string, tipo: string}>, 1: list<string>}
     */
    private function leer(string $contenido): array
    {
        $contenido = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            return [[], ['El archivo no esta en UTF-8.']];
        }

        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $encabezado = fgetcsv($flujo, null, ',', '"', '') ?: [];

        foreach (self::ENCABEZADO as $i => $esperado) {
            $real = NombresGeograficos::busqueda((string) ($encabezado[$i] ?? ''));

            if ($real !== $esperado && ! ($esperado === 'tipo' && str_starts_with($real, 'tipo'))) {
                fclose($flujo);

                return [[], ['Encabezado inesperado en la columna '.($i + 1).": '".($encabezado[$i] ?? '')."' (se esperaba '{$esperado}'). La fuente cambio de esquema: revisar antes de importar."]];
            }
        }

        $filas = [];
        $errores = [];
        $vistos = [];
        $nombresDep = [];
        $linea = 1;

        while (($r = fgetcsv($flujo, null, ',', '"', '')) !== false) {
            $linea++;

            if ($r === [null]) {
                continue;
            }

            $dep = trim((string) ($r[0] ?? ''));
            $depNombre = trim((string) ($r[1] ?? ''));
            $mun = trim((string) ($r[2] ?? ''));
            $munNombre = trim((string) ($r[3] ?? ''));
            $tipo = trim((string) ($r[4] ?? ''));

            $problema = match (true) {
                preg_match('/^\d{2}$/', $dep) !== 1 => "codigo de departamento '{$dep}' invalido (se esperan 2 digitos)",
                preg_match('/^\d{5}$/', $mun) !== 1 => "codigo de municipio '{$mun}' invalido (se esperan 5 digitos)",
                ! str_starts_with($mun, $dep) => "el municipio {$mun} no pertenece al departamento {$dep}",
                $depNombre === '' || $munNombre === '' || $tipo === '' => 'nombre o tipo vacio',
                isset($vistos[$mun]) => "municipio {$mun} duplicado",
                isset($nombresDep[$dep]) && $nombresDep[$dep] !== $depNombre => "el departamento {$dep} aparece con dos nombres",
                default => null,
            };

            if ($problema !== null) {
                $errores[] = "Linea {$linea}: {$problema}.";

                if (count($errores) >= self::MAX_ERRORES) {
                    $errores[] = 'Hay mas errores; se omiten.';
                    break;
                }

                continue;
            }

            $vistos[$mun] = true;
            $nombresDep[$dep] = $depNombre;
            $filas[] = ['dep' => $dep, 'dep_nombre' => $depNombre, 'mun' => $mun, 'mun_nombre' => $munNombre, 'tipo' => $tipo];
        }

        fclose($flujo);

        if ($errores === [] && $filas === []) {
            $errores[] = 'El archivo no trae ningun municipio.';
        }

        return [$filas, $errores];
    }

    /**
     * Resuelve la equivalencia departamento DIVIPOLA → country_states ANTES de escribir.
     *
     * @return array<string, object> codigo DIVIPOLA del departamento => fila de country_states
     */
    private function resolverEstados(array $filas, array &$informe): array
    {
        $departamentos = [];

        foreach ($filas as $f) {
            $departamentos[$f['dep']] = $f['dep_nombre'];
        }

        $codigosEstado = [];

        foreach ($departamentos as $codigo => $nombre) {
            $estado = EquivalenciaDepartamentos::estadoDe($codigo);

            if ($estado === null) {
                $informe['errores'][] = "Departamento DIVIPOLA {$codigo} ({$nombre}) sin equivalencia con country_states de Bagisto.";

                continue;
            }

            $codigosEstado[$codigo] = $estado;
        }

        $porCodigo = DB::table('country_states')
            ->where('country_code', 'CO')->whereIn('code', array_values($codigosEstado))
            ->get()->groupBy('code');

        $resueltos = [];

        foreach ($codigosEstado as $codigo => $estado) {
            $filasEstado = $porCodigo->get($estado, collect());

            if ($filasEstado->count() !== 1) {
                $informe['errores'][] = $filasEstado->isEmpty()
                    ? "El estado de Bagisto '{$estado}' (departamento {$codigo} · {$departamentos[$codigo]}) no existe en country_states de CO"
                        .($estado === EquivalenciaDepartamentos::ESTADO_BOGOTA ? ': ejecute la migracion 2026_10_05_000010.' : '.')
                    : "El estado de Bagisto '{$estado}' esta duplicado en country_states de CO (ambiguo).";

                continue;
            }

            $fila = $filasEstado->first();
            $resueltos[$codigo] = $fila;

            if (NombresGeograficos::busqueda((string) $fila->default_name) !== NombresGeograficos::busqueda($departamentos[$codigo])) {
                $informe['advertencias'][] = "Departamento {$codigo}: DANE «{$departamentos[$codigo]}» ↔ Bagisto {$estado} «{$fila->default_name}» (equivalencia por codigo; los nombres difieren).";
            }
        }

        return $resueltos;
    }

    /** Calcula el diff contra la base y arma lo que `escribir()` aplicara. Llena el informe. */
    private function planificar(array $filas, array $estados, string $checksum, ?string $corte, array &$informe): array
    {
        $plan = ['dep_altas' => [], 'dep_cambios' => [], 'mun_altas' => [], 'mun_cambios' => [], 'inactivar' => []];

        $depsActuales = DB::table('bylopez_geo_departamentos')->get()->keyBy('codigo_divipola');
        $vistosDep = [];

        foreach ($filas as $f) {
            if (isset($vistosDep[$f['dep']])) {
                continue;
            }

            $vistosDep[$f['dep']] = true;

            $deseado = [
                'codigo_divipola' => $f['dep'],
                'nombre_oficial' => NombresGeograficos::presentacion($f['dep_nombre']),
                'nombre_busqueda' => NombresGeograficos::busqueda($f['dep_nombre']),
                'country_state_id' => (int) $estados[$f['dep']]->id,
            ];

            $actual = $depsActuales->get($f['dep']);

            if ($actual === null) {
                $plan['dep_altas'][] = $deseado;
                $informe['departamentos']['altas'][] = "{$f['dep']} {$deseado['nombre_oficial']} → {$estados[$f['dep']]->code}";
            } elseif ($actual->nombre_oficial !== $deseado['nombre_oficial'] || $actual->nombre_busqueda !== $deseado['nombre_busqueda'] || (int) $actual->country_state_id !== $deseado['country_state_id']) {
                $plan['dep_cambios'][] = $deseado;
                $informe['departamentos']['cambios'][] = "{$f['dep']} {$actual->nombre_oficial} → {$deseado['nombre_oficial']}";
            } else {
                $informe['departamentos']['sin_cambios']++;
            }
        }

        $munActuales = DB::table('bylopez_geo_municipios')->get()->keyBy('codigo_divipola');
        $codigosFuente = [];

        foreach ($filas as $f) {
            $codigosFuente[$f['mun']] = true;

            $deseado = [
                'codigo_divipola' => $f['mun'],
                'nombre_oficial' => NombresGeograficos::presentacion($f['mun_nombre']),
                'nombre_busqueda' => NombresGeograficos::busqueda($f['mun_nombre']),
                'departamento_codigo' => $f['dep'],
                'tipo' => $f['tipo'],
                'activo' => true,
                'fuente' => self::FUENTE,
                'version_fuente' => $corte,
                'checksum_fuente' => $checksum,
            ];

            $actual = $munActuales->get($f['mun']);

            if ($actual === null) {
                $plan['mun_altas'][] = $deseado;
                $informe['municipios']['altas']++;

                continue;
            }

            $descriptivo = ['nombre_oficial', 'nombre_busqueda', 'departamento_codigo', 'tipo'];
            $metadatos = ['fuente', 'version_fuente', 'checksum_fuente'];
            $cambioDescriptivo = collect($descriptivo)->contains(fn ($c) => (string) $actual->{$c} !== (string) $deseado[$c]);
            $cambioMetadatos = collect($metadatos)->contains(fn ($c) => (string) $actual->{$c} !== (string) $deseado[$c]);
            $reactiva = ! (bool) $actual->activo;

            if (! $cambioDescriptivo && ! $cambioMetadatos && ! $reactiva) {
                $informe['municipios']['sin_cambios']++;

                continue;
            }

            $plan['mun_cambios'][] = $deseado;

            if ($reactiva) {
                $informe['municipios']['reactivados'][] = "{$f['mun']} {$deseado['nombre_oficial']}";
            } elseif ($cambioDescriptivo) {
                $informe['municipios']['cambios'][] = "{$f['mun']} {$actual->nombre_oficial} → {$deseado['nombre_oficial']}";
            } else {
                $informe['municipios']['solo_metadatos']++;
            }
        }

        foreach ($munActuales as $codigo => $actual) {
            if ((bool) $actual->activo && ! isset($codigosFuente[$codigo])) {
                $plan['inactivar'][] = $codigo;
                $informe['municipios']['inactivados'][] = "{$codigo} {$actual->nombre_oficial}";
            }
        }

        return $plan;
    }

    private function escribir(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            $ahora = now();

            foreach ($plan['dep_altas'] as $fila) {
                DB::table('bylopez_geo_departamentos')->insert($fila + ['created_at' => $ahora, 'updated_at' => $ahora]);
            }

            foreach ($plan['dep_cambios'] as $fila) {
                DB::table('bylopez_geo_departamentos')->where('codigo_divipola', $fila['codigo_divipola'])
                    ->update(collect($fila)->except('codigo_divipola')->all() + ['updated_at' => $ahora]);
            }

            foreach (array_chunk($plan['mun_altas'], 200) as $lote) {
                DB::table('bylopez_geo_municipios')->insert(array_map(fn ($f) => $f + ['created_at' => $ahora, 'updated_at' => $ahora], $lote));
            }

            foreach ($plan['mun_cambios'] as $fila) {
                DB::table('bylopez_geo_municipios')->where('codigo_divipola', $fila['codigo_divipola'])
                    ->update(collect($fila)->except('codigo_divipola')->all() + ['updated_at' => $ahora]);
            }

            if ($plan['inactivar'] !== []) {
                DB::table('bylopez_geo_municipios')->whereIn('codigo_divipola', $plan['inactivar'])
                    ->update(['activo' => false, 'updated_at' => $ahora]);
            }
        });
    }
}
