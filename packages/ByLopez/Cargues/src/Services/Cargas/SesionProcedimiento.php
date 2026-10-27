<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use PDO;
use Throwable;

/**
 * Operaciones sobre la SESION DEDICADA (PDO sin reconexion) en la que corre el procedimiento, comunes a VALIDAR y
 * EJECUTAR (07 §8.4): nombre y verificacion del bloqueo, CALL con la firma del contrato, huella de los datos de entrada
 * de la intermedia y tope de tiempo de sentencia.
 */
class SesionProcedimiento
{
    /** `cg:<esquema>:<carga>`; esquemas largos se acortan con un hash para no pasar de 64 caracteres. */
    public function nombreBloqueo(string $esquema, int $cargaId): string
    {
        return 'cg:'.(strlen($esquema) > 40 ? substr(sha1($esquema), 0, 12) : $esquema).':'.$cargaId;
    }

    /** `GET_LOCK(nombre, 0)`: no espera. */
    public function tomarBloqueo(PDO $pdo, string $nombre): bool
    {
        return (int) $pdo->query('SELECT GET_LOCK('.$pdo->quote($nombre).', 0)')->fetchColumn() === 1;
    }

    public function liberarBloqueo(PDO $pdo, string $nombre): void
    {
        try {
            $pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($nombre).')')->fetchAll();
        } catch (Throwable) {
        }
    }

    /** Antes de leer los OUT o confirmar, la sesion debe seguir siendo la que tiene el bloqueo. */
    public function exigirBloqueo(PDO $pdo, string $nombre): void
    {
        $dueno = $pdo->query('SELECT IS_USED_LOCK('.$pdo->quote($nombre).') = CONNECTION_ID()')->fetchColumn();

        if ((int) $dueno !== 1) {
            throw new OperacionInvalidaException('BLOQUEO_PERDIDO', 'La sesión perdió el bloqueo de la carga; no se confirma nada.');
        }
    }

    /** @return array{resultado:string, mensaje:?string} */
    public function llamar(PDO $pdo, string $procedimiento, Carga $carga, string $modo): array
    {
        $st = $pdo->prepare('CALL `'.str_replace('`', '', $procedimiento).'`(?, ?, ?, @cg_resultado, @cg_mensaje)');
        $st->execute([(int) $carga->id, (string) $carga->accion, $modo]);

        // Un procedimiento puede devolver conjuntos de resultados (SELECT): se vacian todos antes de leer los OUT.
        while ($st->nextRowset()) {
        }

        $st->closeCursor();

        $r = $pdo->query('SELECT @cg_resultado AS r, @cg_mensaje AS m')->fetch(PDO::FETCH_ASSOC);

        return ['resultado' => (string) ($r['r'] ?? ''), 'mensaje' => $r['m'] === null ? null : (string) $r['m']];
    }

    /**
     * Huella de los datos de ENTRADA de la carga en la intermedia (misma que LlenadoIntermediaService), leida por la
     * sesion dedicada: ve sus propios cambios aun sin confirmar. Las marcas `cg_*` no entran.
     */
    public function huellaEntrada(PDO $pdo, array $map, int $cargaId): string
    {
        $partes = ['cg_fila_origen', 'cg_carga_dato_id', 'cg_duplicado', "IFNULL(cg_ausentes,'~')"];

        foreach (\ByLopez\Cargues\Support\Esquema::columnas($map) as $campo) {
            $partes[] = "IFNULL(CAST(`{$campo['nombre']}` AS CHAR),'~')";
        }

        $concat = "CONCAT_WS('\x1F', ".implode(', ', $partes).')';
        $r = $pdo->query("SELECT COUNT(*) AS n, IFNULL(BIT_XOR(CRC32({$concat})), 0) AS a, IFNULL(SUM(CRC32(CONCAT({$concat}, 'x'))), 0) AS b FROM `{$map['tabla']}` WHERE cg_carga_id = ".(int) $cargaId)->fetch(PDO::FETCH_ASSOC);

        return hash('sha256', $r['n'].'|'.$r['a'].'|'.$r['b']);
    }

    /** Un procedimiento desbocado no debe quedar corriendo para siempre en el servidor (solo MariaDB lo soporta). */
    public function topeDeTiempo(PDO $pdo): void
    {
        try {
            $pdo->exec('SET SESSION max_statement_time = '.max(1, (int) config('cargues.cola.timeout', 900) - 60));
        } catch (Throwable) {
        }
    }

    /** ¿La excepcion es una perdida de la conexion (el resultado en el servidor queda INCIERTO)? */
    public function esConexionPerdida(Throwable $e): bool
    {
        $codigo = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;

        return in_array($codigo, [2006, 2013], true) || preg_match('/gone away|Lost connection|server has gone|Error while sending/i', $e->getMessage()) === 1;
    }
}
