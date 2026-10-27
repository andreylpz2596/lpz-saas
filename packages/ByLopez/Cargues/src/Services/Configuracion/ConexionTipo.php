<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Conexion del tipo (A6): solo por NOMBRE, autorizada en `cargues.conexiones_autorizadas` y definida en
 * `config/database.php`; sin credenciales en la configuracion del tipo. Solo MySQL/MariaDB tienen motor habilitado.
 */
class ConexionTipo
{
    public const DRIVERS = ['mysql', 'mariadb'];

    public function autorizada(string $nombre): bool
    {
        return in_array($nombre, (array) config('cargues.conexiones_autorizadas', []), true)
            && is_array(config("database.connections.{$nombre}"))
            && in_array(config("database.connections.{$nombre}.driver"), self::DRIVERS, true);
    }

    public function errores(string $nombre): array
    {
        if (! is_array(config("database.connections.{$nombre}"))) {
            return [['codigo' => 'CONEXION_INEXISTENTE', 'ruta' => 'conexion', 'mensaje' => "La conexión «{$nombre}» no existe en config/database.php."]];
        }

        if (! in_array($nombre, (array) config('cargues.conexiones_autorizadas', []), true)) {
            return [['codigo' => 'CONEXION_NO_AUTORIZADA', 'ruta' => 'conexion', 'mensaje' => "La conexión «{$nombre}» no está en la lista autorizada del módulo."]];
        }

        if (! in_array(config("database.connections.{$nombre}.driver"), self::DRIVERS, true)) {
            return [['codigo' => 'CONEXION_SIN_MOTOR', 'ruta' => 'conexion', 'mensaje' => 'Solo MySQL/MariaDB tienen motor habilitado.']];
        }

        return [];
    }

    public function conexion(string $nombre): Connection
    {
        if (! $this->autorizada($nombre)) {
            throw new OperacionInvalidaException('CONEXION_NO_AUTORIZADA', "La conexión «{$nombre}» no está autorizada.");
        }

        return DB::connection($nombre);
    }

    public function esMariaDb(Connection $c): bool
    {
        return stripos((string) $c->selectOne('SELECT VERSION() AS v')->v, 'mariadb') !== false;
    }

    /** Intercalacion de COMPARACION EXACTA para los identificadores (08 §3.3). */
    public function colacionExacta(Connection $c): string
    {
        return $this->esMariaDb($c) ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
    }

    public function esquema(Connection $c): string
    {
        return (string) $c->selectOne('SELECT DATABASE() AS d')->d;
    }

    /**
     * PDO DEDICADA para el ejecutor (07 §8.4): sin conexion persistente y SIN reconexion automatica. Si se perdiera, una
     * reconexion silenciosa crearia una sesion sin el bloqueo con nombre. Hereda el sql_mode de la conexion de Laravel.
     * Con `$cuenta` usa ESAS credenciales (la cuenta restringida de VALIDAR) contra el mismo servidor y base.
     */
    public function pdoDedicada(string $nombre, ?int $waitTimeout = null, ?array $cuenta = null, bool $usarBase = true): PDO
    {
        $conexion = $this->conexion($nombre);
        $cfg = (array) config("database.connections.{$nombre}");

        $dsn = ! empty($cfg['unix_socket'])
            ? 'mysql:unix_socket='.$cfg['unix_socket'].($cuenta ? '' : ';dbname='.$cfg['database'])
            : 'mysql:host='.$cfg['host'].';port='.($cfg['port'] ?? 3306).($cuenta ? '' : ';dbname='.$cfg['database']);

        $pdo = new PDO($dsn, $cuenta['usuario'] ?? $cfg['username'] ?? null, $cuenta['clave'] ?? $cfg['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        // La cuenta restringida conecta SIN base (sin ningun privilegio sobre ella el servidor negaria la conexion con
        // error 1044) y la selecciona despues: asi el motivo real queda claro y la verificacion puede evaluarla igualmente.
        if ($cuenta && $usarBase) {
            $pdo->exec('USE `'.str_replace('`', '', (string) $cfg['database']).'`');
        }

        $charset = $cfg['charset'] ?? 'utf8mb4';
        $pdo->exec("SET NAMES '{$charset}'");
        $pdo->exec('SET SESSION wait_timeout = '.(int) ($waitTimeout ?? config('cargues.ejecutor.wait_timeout', 300)));
        $mode = (string) $conexion->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $pdo->exec('SET SESSION sql_mode = '.$pdo->quote($mode));

        return $pdo;
    }
}
