<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Support\Canonico;
use Illuminate\Database\Connection;

/**
 * Verificacion del procedimiento de un tipo (07 §8): firma exacta del contrato, definicion congelada con huella (incluye
 * el sql_mode de la rutina) y REVISION ESTATICA de su contrato. La revision estatica es una ayuda heuristica, NO una
 * prueba: no sustituye leer el procedimiento ni la vigilancia en ejecucion (ValidarProcedimientoService). Por eso la
 * activacion exige ademas una confirmacion humana ligada a la huella del procedimiento revisado.
 */
class ProcedimientoService
{
    /** Contrato de §8.1: [posicion => [nombre, modo, tipo, longitud]]. */
    public const FIRMA = [
        1 => ['p_carga_id', 'IN', 'bigint', null],
        2 => ['p_accion', 'IN', 'varchar', 40],
        3 => ['p_modo', 'IN', 'varchar', 10],
        4 => ['p_resultado', 'OUT', 'varchar', 10],
        5 => ['p_mensaje', 'OUT', 'varchar', 500],
    ];

    /** @return array<int, array{codigo:string, ruta:string, mensaje:string}> */
    public function verificarFirma(Connection $c, string $nombre): array
    {
        $rutina = $c->selectOne(
            "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_NAME = ?",
            [$nombre],
        );

        if (! $rutina) {
            return [['codigo' => 'PROCEDIMIENTO_INEXISTENTE', 'ruta' => 'procedimiento', 'mensaje' => "El procedimiento «{$nombre}» no existe en la conexión del tipo."]];
        }

        $params = $c->select(
            'SELECT ORDINAL_POSITION, PARAMETER_MODE, PARAMETER_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.PARAMETERS
             WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME = ? AND ORDINAL_POSITION > 0 ORDER BY ORDINAL_POSITION',
            [$nombre],
        );

        $reales = [];

        foreach ($params as $p) {
            $reales[(int) $p->ORDINAL_POSITION] = [strtolower((string) $p->PARAMETER_NAME), strtoupper((string) $p->PARAMETER_MODE), strtolower((string) $p->DATA_TYPE), $p->CHARACTER_MAXIMUM_LENGTH === null ? null : (int) $p->CHARACTER_MAXIMUM_LENGTH];
        }

        if ($reales !== self::FIRMA) {
            return [['codigo' => 'PROCEDIMIENTO_FIRMA', 'ruta' => 'procedimiento', 'mensaje' => "«{$nombre}» no tiene la firma del contrato: (IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))."]];
        }

        return [];
    }

    /** @return array{definicion:string, sql_mode:string, huella:string}|null null si no se puede leer la definicion */
    public function congelar(Connection $c, string $nombre): ?array
    {
        $mostrar = $c->selectOne('SHOW CREATE PROCEDURE `'.str_replace('`', '', $nombre).'`');
        $definicion = $mostrar ? (array) $mostrar : [];
        $texto = $definicion['Create Procedure'] ?? null;

        if (! is_string($texto) || $texto === '') {
            return null;
        }

        $mode = (string) ($c->selectOne(
            "SELECT SQL_MODE AS m FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_NAME = ?",
            [$nombre],
        )->m ?? '');

        return ['definicion' => $texto, 'sql_mode' => $mode, 'huella' => hash('sha256', $texto."\n--sql_mode--\n".$mode)];
    }

    /**
     * Revision estatica del texto del procedimiento frente al map. Heuristica: quita comentarios y literales y busca
     * sentencias. Lo que no puede verificar lo marca como ADVERTENCIA, nunca como "correcto".
     *
     * @return array{hallazgos: array<int, array{nivel:string, codigo:string, mensaje:string}>, tablas_escritas: string[], huella: string, aviso: string}
     */
    public function revisarContrato(string $definicion, array $map): array
    {
        $texto = $this->limpiar($definicion);
        $h = [];
        $add = function (string $nivel, string $codigo, string $mensaje) use (&$h) {
            $h[] = compact('nivel', 'codigo', 'mensaje');
        };

        // 1. DDL (con commit implicito) salvo tablas TEMPORARY.
        if (preg_match('/\b(CREATE|ALTER|DROP|RENAME)\s+(?!TEMPORARY\b)(?:OR\s+REPLACE\s+)?(?:TABLE|DATABASE|SCHEMA|INDEX|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT|USER|SEQUENCE)\b/i', $texto)
            || preg_match('/\bTRUNCATE\b/i', $texto)) {
            $add('error', 'DDL_EN_PROCEDIMIENTO', 'Contiene DDL (CREATE/ALTER/DROP/RENAME/TRUNCATE): hace commit implícito y rompe el contrato.');
        }

        if (preg_match('/\b(LOAD\s+DATA|INTO\s+(OUTFILE|DUMPFILE))\b/i', $texto)) {
            $add('error', 'ARCHIVOS_EN_PROCEDIMIENTO', 'Lee o escribe archivos del servidor.');
        }

        // 2. Tablas que escribe frente a las declaradas.
        $escritas = $this->tablasEscritas($texto);
        $permitidas = array_map('strtolower', array_merge([(string) ($map['tabla'] ?? '')], (array) ($map['tablas_negocio'] ?? [])));

        foreach ($escritas as $t) {
            if (! in_array(strtolower($t), $permitidas, true)) {
                $add('error', 'ESCRITURA_NO_DECLARADA', "Escribe en «{$t}», que no es la intermedia ni está en `tablas_negocio`.");
            }
        }

        // 3. Modelo transaccional.
        $controlaTx = (bool) preg_match('/\b(COMMIT|ROLLBACK|START\s+TRANSACTION|SAVEPOINT)\b|\bBEGIN\s*;|\bSET\s+(SESSION\s+)?autocommit\b/i', $texto);

        if (($map['transaccion'] ?? null) === 'motor' && $controlaTx) {
            $add('error', 'TRANSACCION_EN_MODELO_MOTOR', 'El modelo «motor» prohíbe COMMIT/ROLLBACK/START TRANSACTION dentro del procedimiento: los controla PHP.');
        }

        if (($map['transaccion'] ?? null) === 'procedimiento' && ! preg_match('/\bCOMMIT\b/i', $texto)) {
            $add('advertencia', 'SIN_COMMIT_POR_LOTES', 'El modelo «procedimiento» debe confirmar por lotes (efectos y marcas en el mismo COMMIT), y no se encontró ningún COMMIT.');
        }

        // 4. Lo que no se puede revisar.
        if (preg_match('/\b(PREPARE|EXECUTE)\b/i', $texto)) {
            $add('advertencia', 'SQL_DINAMICO', 'Usa SQL dinámico (PREPARE/EXECUTE): no se pueden revisar las tablas que escribe.');
        }

        if (preg_match('/\bCALL\s+[`\w]+/i', $texto)) {
            $add('advertencia', 'CALL_ANIDADO', 'Llama a otros procedimientos: su contenido no se revisa aquí.');
        }

        if (! preg_match('/\bVALIDAR\b/i', $definicion)) {
            $add('advertencia', 'SIN_MODO_VALIDAR', 'No menciona el modo VALIDAR: no se puede comprobar que ese modo evite tocar tablas de negocio.');
        }

        // Solo el CUERPO (la firma siempre menciona p_carga_id).
        $cuerpo = preg_split('/\bBEGIN\b/i', $texto, 2)[1] ?? $texto;

        if (! preg_match('/\b(p_carga_id|cg_carga_id)\b/i', $cuerpo)) {
            $add('advertencia', 'NO_USA_CARGA_ID', 'No usa p_carga_id: debe leer solo su carga (WHERE cg_carga_id = p_carga_id).');
        }

        return [
            'hallazgos' => $h,
            'tablas_escritas' => array_values(array_unique($escritas)),
            'huella' => hash('sha256', $definicion),
            'aviso' => 'Revisión heurística: no prueba que el procedimiento cumpla el contrato. Léalo antes de confirmar; VALIDAR se vigila además en ejecución.',
        ];
    }

    public function huellaRevision(array $revision): string
    {
        return Canonico::huella($revision['hallazgos'] ?? []);
    }

    /** @return string[] */
    protected function tablasEscritas(string $t): array
    {
        $id = '(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?';
        $r = [];

        // Usos de la palabra UPDATE que no son una sentencia UPDATE.
        $t = preg_replace(['/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', '/\bFOR\s+UPDATE\b/i', '/\bON\s+UPDATE\b/i'], ' ', $t) ?? $t;

        $patrones = [
            '/\bINSERT\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?INTO\s+'.$id.'/i',
            '/\bREPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?INTO\s+'.$id.'/i',
            '/\bUPDATE\s+(?:LOW_PRIORITY\s+)?(?:IGNORE\s+)?'.$id.'/i',
            '/\bDELETE\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*FROM\s+'.$id.'/i',
            '/\bDELETE\s+`?[A-Za-z0-9_]+`?\s+FROM\s+'.$id.'/i',
        ];

        foreach ($patrones as $p) {
            if (preg_match_all($p, $t, $m)) {
                array_push($r, ...$m[1]);
            }
        }

        // UPDATE a JOIN b ... : tambien las tablas unidas se consideran escritas si se asignan (no se distingue): se
        // avisa solo por la primera. Las tablas temporales (tmp_/temp_) no cuentan.
        return array_values(array_filter($r, fn ($n) => ! preg_match('/^(tmp|temp)_/i', $n) && strtolower($n) !== 'set'));
    }

    /** Quita comentarios y literales para que no confundan a las expresiones regulares. */
    protected function limpiar(string $sql): string
    {
        $sql = preg_replace('~/\*.*?\*/~s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? $sql;

        return preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '""', $sql) ?? $sql;
    }
}
