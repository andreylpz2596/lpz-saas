<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Support\Esquema;
use ByLopez\Cargues\Support\PalabrasReservadas;

/**
 * Validacion ESTRICTA del map (07 §5.6, §4.4). Lista cerrada de claves y de tipos: nada del JSON llega a la DDL como
 * texto libre. Calcula los limites de InnoDB por su cuenta (en MariaDB 10.4 un UNIQUE largo no da error 1071 [V]).
 * No habla con la BD: solo valida forma. Devuelve una lista de errores {codigo, ruta, mensaje}; vacia = valido.
 */
class MapValidator
{
    public const CLAVES_MAP = ['tabla', 'acciones', 'campos', 'llaves', 'duplicados', 'transaccion', 'reanudable', 'tablas_negocio', 'extension', 'derivados'];

    public const CLAVES_CAMPO = ['nombre', 'etiqueta', 'tipo', 'longitud', 'precision', 'escala', 'nulo', 'obligatorio', 'sinonimos', 'formato', 'vacio'];

    public const DUPLICADOS = ['rechazar_todas', 'primera', 'ultima'];

    public const TRANSACCIONES = ['motor', 'procedimiento'];

    public const VACIO_PROCEDIMIENTO = ['rechazar', 'nulo', 'ignorar'];

    public function validar(array $map, string $modo = 'procedimiento'): array
    {
        $e = [];
        $err = function (string $codigo, string $ruta, string $mensaje) use (&$e) {
            $e[] = ['codigo' => $codigo, 'ruta' => $ruta, 'mensaje' => $mensaje];
        };

        if ($modo !== 'procedimiento') {
            $err('MODO_NO_DISPONIBLE', 'modo', 'Solo el modo procedimiento está disponible en la Fase 1; el modo tabla llega en la Fase 2.');

            return $e;
        }

        foreach (array_diff(array_keys($map), self::CLAVES_MAP) as $k) {
            $err('MAP_CLAVE_DESCONOCIDA', (string) $k, "La clave «{$k}» no existe en el map. No se admiten valores por defecto, columnas generadas, restricciones ni SQL.");
        }

        $this->tabla($map, $err);
        $acciones = $this->acciones($map, $err);
        $campos = $this->campos($map, $acciones, $err);
        $campos = $this->extension($map, $campos, $acciones, $err);
        $this->llaves($map, $campos, $err);
        $this->filaYClaves($map, $campos, $err);

        if (isset($map['duplicados']) && ! in_array($map['duplicados'], self::DUPLICADOS, true)) {
            $err('DUPLICADOS_INVALIDO', 'duplicados', 'Use: '.implode(', ', self::DUPLICADOS).'.');
        }

        if (! isset($map['transaccion']) || ! in_array($map['transaccion'], self::TRANSACCIONES, true)) {
            $err('TRANSACCION_INVALIDA', 'transaccion', 'Declare el modelo transaccional del procedimiento: '.implode(' | ', self::TRANSACCIONES).'.');
        }

        if (isset($map['reanudable']) && ! is_bool($map['reanudable'])) {
            $err('REANUDABLE_INVALIDO', 'reanudable', 'Debe ser verdadero o falso.');
        }

        $this->tablasNegocio($map, $err);

        return $e;
    }

    /** Map con sus valores por defecto aplicados (solo si ya es valido). */
    public function normalizar(array $map): array
    {
        $map['duplicados'] ??= 'rechazar_todas';
        $map['reanudable'] ??= false;
        $map['llaves'] = ($map['llaves'] ?? []) + ['primaria' => null, 'unicas' => [], 'indices' => []];
        $map['llaves']['primaria'] = $map['llaves']['primaria'] ?: null;
        $map['llaves']['unicas'] = array_values($map['llaves']['unicas'] ?? []);
        $map['llaves']['indices'] = array_values($map['llaves']['indices'] ?? []);
        $map['campos'] = array_map(function (array $c) {
            $c = Esquema::normalizarCampo($c);
            $c['vacio'] ??= Esquema::esObligatorioEnAlguna($c) ? 'rechazar' : 'nulo';

            return $c;
        }, $map['campos'] ?? []);
        $map['tablas_negocio'] = array_values($map['tablas_negocio'] ?? []);

        if (isset($map['derivados'])) {
            $map['derivados'] = array_map(fn (array $c) => Esquema::normalizarCampo($c + ['nulo' => true]) + ['vacio' => 'nulo'], $map['derivados']);
        }

        return $map;
    }

    /**
     * Extension de tipo (opcional): `extension` nombra una clase registrada en `cargues.extensiones` y `derivados` son columnas
     * extra de la intermedia que ELLA calcula (no se relacionan con columnas del archivo). Devuelve campos + derivados para
     * las comprobaciones de llaves y de tamano.
     *
     * @return array<string, array>
     */
    private function extension(array $map, array $campos, array $acciones, callable $err): array
    {
        $ext = $map['extension'] ?? null;
        $der = $map['derivados'] ?? null;

        if ($ext === null && $der === null) {
            return $campos;
        }

        if (! is_string($ext) || ! array_key_exists($ext, (array) config('cargues.extensiones', []))) {
            $err('EXTENSION_DESCONOCIDA', 'extension', 'La extensión debe estar registrada en cargues.extensiones (código de la aplicación, nunca texto del map).');
        }

        if ($der === null) {
            return $campos;
        }

        if (! is_array($der) || ! array_is_list($der) || $der === []) {
            $err('DERIVADOS_INVALIDOS', 'derivados', 'Debe ser una lista de campos.');

            return $campos;
        }

        foreach ($der as $i => $campo) {
            $ruta = "derivados.{$i}";

            if (! is_array($campo)) {
                $err('CAMPO_INVALIDO', $ruta, 'Cada derivado debe ser un objeto.');

                continue;
            }

            foreach (array_diff(array_keys($campo), ['nombre', 'tipo', 'longitud', 'precision', 'escala', 'etiqueta']) as $k) {
                $err('CAMPO_CLAVE_DESCONOCIDA', "{$ruta}.{$k}", "Un derivado solo admite nombre, tipo, longitud, precisión, escala y etiqueta («{$k}» no).");
            }

            $nombre = $campo['nombre'] ?? null;

            if (! is_string($nombre) || ! preg_match('/^[a-z][a-z0-9_]{0,50}$/', $nombre) || str_starts_with($nombre, 'cg_') || PalabrasReservadas::contiene($nombre)) {
                $err('CAMPO_NOMBRE_INVALIDO', "{$ruta}.nombre", 'Nombre inválido, reservado o con prefijo cg_.');

                continue;
            }

            if (isset($campos[$nombre])) {
                $err('CAMPO_DUPLICADO', "{$ruta}.nombre", "El nombre «{$nombre}» ya existe entre los campos o los derivados.");

                continue;
            }

            $this->campo($campo + ['nulo' => true], $ruta, $acciones, $err);
            $campos[$nombre] = Esquema::normalizarCampo($campo + ['nulo' => true]);
        }

        return $campos;
    }

    private function tabla(array $map, callable $err): void
    {
        if (! isset($map['tabla']) || ! is_string($map['tabla']) || ! preg_match('/^bylopez_cgi_[a-z0-9_]{1,40}$/', $map['tabla'])) {
            $err('MAP_TABLA_INVALIDA', 'tabla', 'El nombre de la intermedia debe cumplir ^bylopez_cgi_[a-z0-9_]{1,40}$ (el prefijo garantiza que solo se toquen tablas del módulo).');
        }
    }

    /** @return string[] */
    private function acciones(array $map, callable $err): array
    {
        $a = $map['acciones'] ?? null;

        if (! is_array($a) || $a === [] || ! array_is_list($a)) {
            $err('ACCIONES_INVALIDAS', 'acciones', 'Declare al menos una acción (lista de códigos).');

            return [];
        }

        foreach ($a as $accion) {
            if (! is_string($accion) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $accion)) {
                $err('ACCIONES_INVALIDAS', 'acciones', 'Cada acción debe cumplir ^[a-z][a-z0-9_]{0,39}$.');
            }
        }

        if (count(array_unique($a)) !== count($a)) {
            $err('ACCIONES_INVALIDAS', 'acciones', 'Hay acciones repetidas.');
        }

        return array_values(array_filter($a, 'is_string'));
    }

    /** @return array<string, array> campos por nombre */
    private function campos(array $map, array $acciones, callable $err): array
    {
        $lista = $map['campos'] ?? null;
        $max = (int) config('cargues.limites.max_columnas', 150);

        if (! is_array($lista) || $lista === [] || ! array_is_list($lista)) {
            $err('MAP_CAMPOS_VACIOS', 'campos', 'Declare al menos un campo.');

            return [];
        }

        if (count($lista) > $max) {
            $err('MAP_CAMPOS_EXCESO', 'campos', "Máximo {$max} campos.");
        }

        $porNombre = [];

        foreach ($lista as $i => $campo) {
            $ruta = "campos.{$i}";

            if (! is_array($campo)) {
                $err('CAMPO_INVALIDO', $ruta, 'Cada campo debe ser un objeto.');

                continue;
            }

            foreach (array_diff(array_keys($campo), self::CLAVES_CAMPO) as $k) {
                $err('CAMPO_CLAVE_DESCONOCIDA', "{$ruta}.{$k}", "La clave «{$k}» no existe en un campo (no hay defaults, generadas, CHECK ni SQL).");
            }

            $nombre = $campo['nombre'] ?? null;

            if (! is_string($nombre) || ! preg_match('/^[a-z][a-z0-9_]{0,50}$/', $nombre)) {
                $err('CAMPO_NOMBRE_INVALIDO', "{$ruta}.nombre", 'El nombre debe cumplir ^[a-z][a-z0-9_]{0,50}$.');

                continue;
            }

            if (str_starts_with($nombre, 'cg_')) {
                $err('CAMPO_RESERVADO', "{$ruta}.nombre", "El prefijo «cg_» está reservado para el motor ({$nombre}).");
            } elseif (PalabrasReservadas::contiene($nombre)) {
                $err('CAMPO_RESERVADO', "{$ruta}.nombre", "«{$nombre}» es una palabra reservada de MySQL/MariaDB.");
            }

            if (isset($porNombre[$nombre])) {
                $err('CAMPO_DUPLICADO', "{$ruta}.nombre", "El campo «{$nombre}» está repetido.");

                continue;
            }

            $this->campo($campo, $ruta, $acciones, $err);
            $porNombre[$nombre] = Esquema::normalizarCampo($campo);
        }

        return $porNombre;
    }

    private function campo(array $c, string $ruta, array $acciones, callable $err): void
    {
        $tipo = $c['tipo'] ?? null;

        if (! in_array($tipo, Esquema::TIPOS, true)) {
            $err('CAMPO_TIPO_INVALIDO', "{$ruta}.tipo", 'Tipos permitidos: '.implode(', ', Esquema::TIPOS).'.');

            return;
        }

        $int = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v));

        if ($tipo === 'texto' && (! $int($c['longitud'] ?? null) || $c['longitud'] < 1 || $c['longitud'] > 1000)) {
            $err('CAMPO_LONGITUD', "{$ruta}.longitud", 'texto: longitud entre 1 y 1000.');
        }

        if ($tipo === 'identificador' && (! $int($c['longitud'] ?? null) || $c['longitud'] < 1 || $c['longitud'] > 191)) {
            $err('CAMPO_LONGITUD', "{$ruta}.longitud", 'identificador: longitud entre 1 y 191.');
        }

        if ($tipo === 'decimal') {
            $p = $c['precision'] ?? null;
            $s = $c['escala'] ?? null;

            if (! $int($p) || ! $int($s) || $p < 1 || $p > 38 || $s > 10 || $s > $p) {
                $err('DECIMAL_RANGO', "{$ruta}.precision", 'decimal: precisión 1–38, escala 0–10 y escala ≤ precisión.');
            }
        }

        if (isset($c['nulo']) && ! is_bool($c['nulo'])) {
            $err('CAMPO_NULO_INVALIDO', "{$ruta}.nulo", 'Debe ser verdadero o falso.');
        }

        $o = $c['obligatorio'] ?? false;

        if (! is_bool($o) && ! (is_array($o) && array_is_list($o) && $o === array_values(array_intersect($o, $acciones)))) {
            $err('OBLIGATORIO_INVALIDO', "{$ruta}.obligatorio", 'Debe ser true, false o una lista de acciones declaradas en `acciones`.');
        }

        if (($c['nulo'] ?? true) === false && $o !== true && $o !== false) {
            $err('OBLIGATORIO_CONTRADICTORIO', "{$ruta}.obligatorio", 'Un campo `nulo: false` es siempre obligatorio. Si debe serlo solo en algunas acciones, declare `nulo: true`.');
        }

        if (($c['nulo'] ?? true) === false && $o === false && array_key_exists('obligatorio', $c)) {
            $err('OBLIGATORIO_CONTRADICTORIO', "{$ruta}.obligatorio", '`nulo: false` contradice `obligatorio: false`.');
        }

        $vacio = $c['vacio'] ?? null;

        if ($vacio !== null && ! in_array($vacio, self::VACIO_PROCEDIMIENTO, true)) {
            $err('VACIO_INVALIDO', "{$ruta}.vacio", 'Reglas válidas en modo procedimiento: '.implode(', ', self::VACIO_PROCEDIMIENTO).'. («default» no existe: la intermedia no tiene valores por defecto.)');
        } elseif ($vacio === 'nulo' && ($c['nulo'] ?? true) === false) {
            $err('VACIO_INVALIDO', "{$ruta}.vacio", '«nulo» exige un campo `nulo: true`.');
        } elseif ($vacio !== null && $vacio !== 'rechazar' && Esquema::esObligatorioEnAlguna($c)) {
            $err('OBLIGATORIO_VACIO_REGLA', "{$ruta}.vacio", 'Un campo obligatorio solo admite la regla «rechazar».');
        }

        foreach (['sinonimos'] as $lista) {
            if (isset($c[$lista]) && (! is_array($c[$lista]) || count($c[$lista]) > 10 || array_filter($c[$lista], fn ($x) => ! is_string($x) || mb_strlen($x) > 100))) {
                $err('CAMPO_SINONIMOS', "{$ruta}.{$lista}", 'Lista de hasta 10 textos de 100 caracteres.');
            }
        }

        if (isset($c['etiqueta']) && (! is_string($c['etiqueta']) || mb_strlen($c['etiqueta']) > 100 || trim($c['etiqueta']) === '')) {
            $err('CAMPO_ETIQUETA', "{$ruta}.etiqueta", 'Texto de 1 a 100 caracteres.');
        }

        $this->formato($c, $tipo, $ruta, $err);
    }

    private function formato(array $c, string $tipo, string $ruta, callable $err): void
    {
        if (! isset($c['formato'])) {
            return;
        }

        $f = $c['formato'];

        if (! is_array($f)) {
            $err('FORMATO_INVALIDO', "{$ruta}.formato", 'Debe ser un objeto.');

            return;
        }

        $permitidas = match ($tipo) {
            'decimal' => ['decimal', 'miles'],
            'fecha', 'fecha_hora' => ['formatos'],
            'booleano' => ['verdadero', 'falso'],
            default => [],
        };

        foreach (array_diff(array_keys($f), $permitidas) as $k) {
            $err('FORMATO_INVALIDO', "{$ruta}.formato.{$k}", "El tipo {$tipo} no admite «{$k}».");
        }

        if ($tipo === 'decimal') {
            foreach (['decimal', 'miles'] as $k) {
                if (isset($f[$k]) && (! is_string($f[$k]) || ! in_array($f[$k], ['', '.', ',', ' ', "'"], true))) {
                    $err('FORMATO_INVALIDO', "{$ruta}.formato.{$k}", "«{$k}» admite . , espacio ' o vacío.");
                }
            }

            if (($f['decimal'] ?? ',') === ($f['miles'] ?? '.') && ($f['miles'] ?? '.') !== '') {
                $err('FORMATO_INVALIDO', "{$ruta}.formato", 'El separador decimal y el de miles no pueden ser el mismo.');
            }
        }

        if (in_array($tipo, ['fecha', 'fecha_hora'], true) && isset($f['formatos'])) {
            foreach ((array) $f['formatos'] as $fmt) {
                if (! is_string($fmt) || ! preg_match('/^[dmYyHhGgisjnaA\-\/\. :T]{2,30}$/', $fmt)) {
                    $err('FORMATO_INVALIDO', "{$ruta}.formato.formatos", 'Formatos de fecha de PHP con d m Y y H i s j n - / . : espacio T.');
                }
            }
        }

        if ($tipo === 'booleano') {
            foreach (['verdadero', 'falso'] as $k) {
                if (isset($f[$k]) && (! is_array($f[$k]) || array_filter($f[$k], fn ($x) => ! is_string($x)))) {
                    $err('FORMATO_INVALIDO', "{$ruta}.formato.{$k}", 'Lista de textos.');
                }
            }
        }
    }

    private function llaves(array $map, array $campos, callable $err): void
    {
        $ll = $map['llaves'] ?? [];

        if (! is_array($ll)) {
            $err('LLAVES_INVALIDAS', 'llaves', 'Debe ser un objeto con primaria, unicas e indices.');

            return;
        }

        foreach (array_diff(array_keys($ll), ['primaria', 'unicas', 'indices']) as $k) {
            $err('LLAVES_INVALIDAS', "llaves.{$k}", 'Solo primaria, unicas e indices.');
        }

        $grupos = [];

        if (! empty($ll['primaria'])) {
            $grupos[] = ['llaves.primaria', (array) $ll['primaria'], true, true];
        }

        foreach ((array) ($ll['unicas'] ?? []) as $i => $g) {
            $grupos[] = ["llaves.unicas.{$i}", (array) $g, true, false];
        }

        foreach ((array) ($ll['indices'] ?? []) as $i => $g) {
            $grupos[] = ["llaves.indices.{$i}", (array) $g, false, false];
        }

        foreach ($grupos as [$ruta, $lista, $unica, $primaria]) {
            if ($lista === [] || ! array_is_list($lista) || count($lista) > 8) {
                $err('LLAVE_INVALIDA', $ruta, 'Lista de 1 a 8 campos.');

                continue;
            }

            if (count(array_unique($lista)) !== count($lista)) {
                $err('LLAVE_INVALIDA', $ruta, 'Campo repetido dentro de la llave.');
            }

            foreach ($lista as $nombre) {
                if (! is_string($nombre) || ! isset($campos[$nombre])) {
                    $err('LLAVE_CAMPO_INEXISTENTE', $ruta, 'El campo «'.(is_string($nombre) ? $nombre : '?').'» no está declarado.');

                    continue;
                }

                $c = $campos[$nombre];
                $permitidos = $unica ? Esquema::TIPOS_LLAVE_UNICA : Esquema::TIPOS_INDEXABLES;

                if (! in_array($c['tipo'], $permitidos, true)) {
                    $err('LLAVE_TIPO_NO_PERMITIDO', $ruta, $unica
                        ? "«{$nombre}» ({$c['tipo']}) no puede formar una llave única: la BD comparara sin distinguir mayúsculas ni acentos y PHP no. Use `identificador`."
                        : "«{$nombre}» ({$c['tipo']}) no es indexable.");
                }

                if ($primaria && $c['nulo'] !== false) {
                    $err('LLAVE_PRIMARIA_NULABLE', $ruta, "«{$nombre}» forma parte de la llave primaria y debe ser `nulo: false`.");
                }
            }
        }
    }

    /** Limites de InnoDB calculados aqui (no se delega en la BD). */
    private function filaYClaves(array $map, array $campos, callable $err): void
    {
        $fila = Esquema::FILA_SISTEMA_BYTES;

        foreach ($campos as $c) {
            if (isset($c['tipo']) && in_array($c['tipo'], Esquema::TIPOS, true) && ($c['tipo'] !== 'texto' || isset($c['longitud'])) && ($c['tipo'] !== 'identificador' || isset($c['longitud'])) && ($c['tipo'] !== 'decimal' || isset($c['precision']))) {
                $fila += Esquema::bytesFila($c);
            }
        }

        if ($fila > Esquema::MAX_FILA_BYTES) {
            $err('FILA_SUPERA_LIMITE', 'campos', "La fila ocuparía {$fila} bytes en utf8mb4 (máximo ".Esquema::MAX_FILA_BYTES.').');
        }

        $ll = $map['llaves'] ?? [];
        $grupos = array_filter([
            'llaves.primaria' => ! empty($ll['primaria']) ? (array) $ll['primaria'] : null,
            ...array_combine(
                array_map(fn ($i) => "llaves.unicas.{$i}", array_keys((array) ($ll['unicas'] ?? []))),
                array_map(fn ($g) => (array) $g, (array) ($ll['unicas'] ?? [])),
            ),
            ...array_combine(
                array_map(fn ($i) => "llaves.indices.{$i}", array_keys((array) ($ll['indices'] ?? []))),
                array_map(fn ($g) => (array) $g, (array) ($ll['indices'] ?? [])),
            ),
        ]);

        foreach ($grupos as $ruta => $lista) {
            $bytes = 8; // cg_carga_id

            foreach ($lista as $nombre) {
                if (is_string($nombre) && isset($campos[$nombre]) && in_array($campos[$nombre]['tipo'] ?? null, Esquema::TIPOS_INDEXABLES, true) && ! (in_array($campos[$nombre]['tipo'], ['texto', 'identificador'], true) && ! isset($campos[$nombre]['longitud']))) {
                    $bytes += Esquema::bytesClave($campos[$nombre]);
                }
            }

            if ($bytes > Esquema::MAX_CLAVE_BYTES) {
                $err('INDICE_SUPERA_LIMITE', $ruta, "La clave ocuparía {$bytes} bytes (máximo ".Esquema::MAX_CLAVE_BYTES.' con utf8mb4). MariaDB 10.4 la aceptaría como HASH en silencio; el módulo la rechaza.');
            }
        }
    }

    private function tablasNegocio(array $map, callable $err): void
    {
        if (! array_key_exists('tablas_negocio', $map) || ! is_array($map['tablas_negocio']) || ! array_is_list($map['tablas_negocio'])) {
            $err('TABLAS_NEGOCIO_NO_DECLARADAS', 'tablas_negocio', 'Declare (aunque sea vacía) la lista de tablas de negocio que el procedimiento puede modificar. Se usa para revisar su contrato y vigilar VALIDAR.');

            return;
        }

        foreach ($map['tablas_negocio'] as $t) {
            if (! is_string($t) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $t)) {
                $err('TABLAS_NEGOCIO_INVALIDA', 'tablas_negocio', 'Nombres de tabla simples ([A-Za-z][A-Za-z0-9_]*).');
            }
        }
    }
}
