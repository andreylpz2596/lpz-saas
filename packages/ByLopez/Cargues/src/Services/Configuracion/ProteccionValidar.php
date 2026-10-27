<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Models\Estructura;
use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * Proteccion PREVENTIVA de VALIDAR (07 §8.5): VALIDAR corre con una cuenta de BD RESTRINGIDA, de modo que una escritura
 * indebida falle en el servidor en vez de detectarse despues. Una cuenta "con SELECT" no basta, porque lo que cuenta son
 * los privilegios EFECTIVOS de quien ejecuta el cuerpo:
 *   - un procedimiento `SQL SECURITY DEFINER` (el valor por defecto) corre con los privilegios de su DEFINER (p. ej.
 *     root), no con los de la cuenta restringida; por eso solo se admite INVOKER, o un DEFINER que sea la propia cuenta;
 *   - cualquier otra rutina (procedimiento o funcion) que la cuenta pueda ejecutar y sea DEFINER ajeno es una via de
 *     escalada; por eso EXECUTE solo se admite sobre rutinas con nombre (nunca `db.*`) y cada una se comprueba igual;
 *   - los triggers y las llaves foraneas de la intermedia se ejecutan/cascadan sin pasar por los privilegios de la
 *     cuenta, asi que la intermedia no puede tenerlos;
 *   - roles, GRANT OPTION, FILE, ALL PRIVILEGES o cualquier privilegio de escritura fuera de las intermedias lo invalidan.
 *     La cuenta es UNA por conexion: con varios tipos activos tiene UPDATE sobre la intermedia de cada uno. Eso se
 *     admite solo para las intermedias REGISTRADAS (T3, estructura activa) de la conexion; nunca sobre otra tabla.
 *     Riesgo residual (docs/12 §3): un procedimiento con SQL dinamico podria marcar filas de la intermedia de OTRO tipo;
 *     los datos de entrada de ese tipo quedan vigilados por su huella de entrada.
 * Los privilegios se leen del propio servidor (`SHOW GRANTS` de la sesion que va a ejecutar), no de lo configurado.
 *
 * Limite: protege la sesion de VALIDAR. No decide si el procedimiento hace lo correcto en EJECUTAR (E2).
 */
class ProteccionValidar
{
    /** Privilegios de LECTURA o inocuos admitidos en cualquier objeto. */
    public const INOCUOS = ['USAGE', 'SELECT', 'SHOW VIEW'];

    public function __construct(protected ConexionTipo $conexiones) {}

    /** @return array{usuario:string, clave:string}|null */
    public function cuenta(string $conexion): ?array
    {
        $c = (array) config("cargues.validar.cuentas.{$conexion}", []);

        return ! empty($c['usuario']) ? ['usuario' => (string) $c['usuario'], 'clave' => (string) ($c['clave'] ?? '')] : null;
    }

    public function exigida(): bool
    {
        return (bool) config('cargues.validar.exigir_cuenta_restringida', true);
    }

    /**
     * Hallazgos de proteccion para un procedimiento y su intermedia. Con `$sesion` evalua ESA sesion (la que ejecutara
     * VALIDAR); sin ella abre una de corta vida. Con `$exigirConcesiones` exige ademas los permisos para trabajar.
     *
     * @return array<int, array{nivel:string, codigo:string, ruta:string, mensaje:string}>
     */
    public function verificar(Connection $admin, string $conexion, string $procedimiento, string $tabla, ?PDO $sesion = null, bool $exigirConcesiones = false): array
    {
        $cuenta = $this->cuenta($conexion);

        if (! $cuenta) {
            return [$this->h($this->exigida() ? 'error' : 'advertencia', 'VALIDAR_SIN_CUENTA_RESTRINGIDA',
                'No hay una cuenta de BD restringida para VALIDAR en la conexión «'.$conexion.'» (CARGUES_VALIDAR_DB_USERNAME). '
                .($this->exigida() ? 'Sin ella no se activa ni se valida: VALIDAR correría con los privilegios de la conexión del tipo.' : 'Solo se vigila después de ejecutar (modo no recomendado).'))];
        }

        $propia = $sesion === null;

        try {
            // Sin concesiones aun la cuenta no puede usar la base: se evalua igual (sin base) y la falta se avisa mas abajo.
            $sesion ??= $this->conexiones->pdoDedicada($conexion, 60, $cuenta, $exigirConcesiones);
        } catch (Throwable $e) {
            return [$this->h('error', 'VALIDAR_CUENTA_NO_CONECTA', 'La cuenta restringida de VALIDAR no pudo abrir sesión o usar la base: '.$this->corto($e->getMessage()).($exigirConcesiones ? ' (¿faltan permisos? ejecute `php artisan cargues:proteccion-validar --aplicar`)' : ''))];
        }

        try {
            return $this->evaluar($admin, $conexion, $sesion, $procedimiento, $tabla, $exigirConcesiones);
        } catch (Throwable $e) {
            return [$this->h('error', 'VALIDAR_PROTECCION_NO_VERIFICABLE', 'No se pudo verificar la protección de VALIDAR: '.$this->corto($e->getMessage()))];
        } finally {
            if ($propia) {
                $sesion = null;
            }
        }
    }

    /** @return array<int, array{nivel:string, codigo:string, ruta:string, mensaje:string}> */
    protected function evaluar(Connection $admin, string $conexion, PDO $sesion, string $procedimiento, string $tabla, bool $exigirConcesiones): array
    {
        $r = [];
        $esquema = (string) $admin->selectOne('SELECT DATABASE() AS d')->d;
        $info = $sesion->query('SELECT DATABASE() AS d, CURRENT_USER() AS u, CURRENT_ROLE() AS r')->fetch(PDO::FETCH_ASSOC);
        $yo = $this->normalizarCuenta((string) $info['u']);

        if ($info['d'] !== null && (string) $info['d'] !== $esquema) {
            return [$this->h('error', 'VALIDAR_CUENTA_OTRA_BD', 'La cuenta restringida no apunta a la misma base de datos que el tipo.')];
        }

        if ($yo === $this->normalizarCuenta((string) $admin->selectOne('SELECT CURRENT_USER() AS u')->u)) {
            return [$this->h('error', 'VALIDAR_CUENTA_ES_LA_DEL_TIPO', 'La cuenta de VALIDAR es la misma de la conexión del tipo: no restringe nada. Use una cuenta distinta.')];
        }

        if ($info['r'] !== null) {
            $r[] = $this->h('error', 'VALIDAR_CUENTA_CON_ROL', 'La sesión de VALIDAR tiene un rol activo; sus privilegios no se verifican aquí.');
        }

        $lineas = array_map('current', array_map('array_values', $sesion->query('SHOW GRANTS')->fetchAll(PDO::FETCH_ASSOC)));
        $intermedias = Estructura::where('conexion', $conexion)->where('estado', Estructura::ACTIVA)->pluck('nombre_tabla')->all();
        $g = $this->analizarGrants($lineas, $esquema, $tabla, $intermedias);

        foreach ($g['violaciones'] as $v) {
            $r[] = $this->h('error', 'VALIDAR_CUENTA_CON_ESCRITURA', "Privilegio no admitido en la cuenta de VALIDAR: {$v}.");
        }

        // Rutinas: la del tipo y todas las que la cuenta puede ejecutar. DEFINER ajeno = escalada de privilegios.
        $rutinas = array_unique(array_merge([$procedimiento], $g['rutinas']));
        $filas = $admin->select(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()',
        );

        foreach ($filas as $f) {
            if (! in_array($f->ROUTINE_NAME, $rutinas, true)) {
                continue;
            }

            $propiaDelTipo = $f->ROUTINE_NAME === $procedimiento;
            $definerEsLaCuenta = $this->normalizarCuenta((string) $f->DEFINER) === $yo;

            if (strtoupper((string) $f->SECURITY_TYPE) === 'DEFINER' && ! $definerEsLaCuenta) {
                $r[] = $this->h('error', $propiaDelTipo ? 'PROCEDIMIENTO_DEFINER' : 'RUTINA_EJECUTABLE_DEFINER',
                    ($propiaDelTipo ? "El procedimiento «{$f->ROUTINE_NAME}»" : "La rutina «{$f->ROUTINE_NAME}» (ejecutable por la cuenta de VALIDAR)")
                    ." es SQL SECURITY DEFINER con definidor {$f->DEFINER}: se ejecutaría con SUS privilegios y la cuenta restringida no limitaría nada. Recréelo con SQL SECURITY INVOKER.");
            }
        }

        if ($admin->selectOne('SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?', [$procedimiento])->n == 0) {
            $r[] = $this->h('error', 'PROCEDIMIENTO_INEXISTENTE', "El procedimiento «{$procedimiento}» no existe.");
        }

        // La intermedia no puede tener triggers ni llaves foraneas: escapan a los privilegios de la cuenta.
        $triggers = $admin->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$tabla]);
        $fks = $admin->select('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND (TABLE_NAME = ? OR REFERENCED_TABLE_NAME = ?)', [$tabla, $tabla]);

        if ($triggers) {
            $r[] = $this->h('error', 'INTERMEDIA_CON_TRIGGER', "La intermedia «{$tabla}» tiene triggers ({$triggers[0]->TRIGGER_NAME}): se ejecutan con privilegios de su definidor.");
        }

        if ($fks) {
            $r[] = $this->h('error', 'INTERMEDIA_CON_LLAVE_FORANEA', "La intermedia «{$tabla}» participa en llaves foráneas ({$fks[0]->CONSTRAINT_NAME}): sus acciones en cascada no pasan por los privilegios de la cuenta.");
        }

        if ($exigirConcesiones) {
            $falta = [];

            if (! $g['actualiza_intermedia']) {
                $falta[] = "GRANT SELECT, UPDATE ON `{$esquema}`.`{$tabla}`";
            }

            if (! in_array($procedimiento, $g['rutinas'], true)) {
                $falta[] = "GRANT EXECUTE ON PROCEDURE `{$esquema}`.`{$procedimiento}`";
            }

            if ($falta) {
                $r[] = $this->h('error', 'VALIDAR_FALTAN_PERMISOS', 'A la cuenta restringida le faltan permisos: '.implode('; ', $falta).'. Ejecute `php artisan cargues:proteccion-validar --aplicar` o pídalos al administrador de la BD.');
            }
        }

        return $r;
    }

    /**
     * Analiza las lineas de SHOW GRANTS. Funcion pura (se prueba sin servidor).
     *
     * @param  string[]  $lineas
     * @param  string[]  $intermedias  intermedias registradas de la conexion (otros tipos): UPDATE admitido sobre ellas
     * @return array{violaciones: string[], rutinas: string[], actualiza_intermedia: bool}
     */
    public function analizarGrants(array $lineas, string $esquema, string $tabla, array $intermedias = []): array
    {
        $violaciones = [];
        $rutinas = [];
        $actualiza = false;

        foreach ($lineas as $linea) {
            if (preg_match('/WITH\s+GRANT\s+OPTION/i', $linea)) {
                $violaciones[] = 'WITH GRANT OPTION';
            }

            if (! preg_match('/^GRANT\s+(.+?)\s+ON\s+(?:(PROCEDURE|FUNCTION|TABLE)\s+)?(\S+)\s+TO\s/is', $linea, $m)) {
                $violaciones[] = 'concesión no interpretable ('.substr($linea, 0, 60).')'; // p. ej. un rol o un PROXY

                continue;
            }

            [$db, $obj] = $this->objeto($m[3]);
            $clase = strtoupper($m[2] ?? '');
            $privs = array_map(fn ($p) => strtoupper(trim(preg_replace('/\(.*\)/s', '', $p))), $this->partir($m[1]));
            $esTabla = $clase !== 'PROCEDURE' && $clase !== 'FUNCTION' && $db === $esquema;
            $esIntermedia = $esTabla && $obj === $tabla;
            $esOtraIntermedia = $esTabla && $obj !== $tabla && in_array($obj, $intermedias, true);

            foreach ($privs as $p) {
                if ($clase === 'PROCEDURE' || $clase === 'FUNCTION') {
                    if ($p === 'EXECUTE' && $db === $esquema && $obj !== null && $obj !== '*') {
                        $rutinas[] = $obj;
                    } else {
                        $violaciones[] = "{$p} sobre {$clase} {$m[3]}";
                    }

                    continue;
                }

                if ($p === 'EXECUTE') {
                    $violaciones[] = "EXECUTE sobre {$m[3]} (solo se admite sobre rutinas con nombre)";
                } elseif ($esIntermedia && $p === 'UPDATE') {
                    $actualiza = true;
                } elseif ($esOtraIntermedia && $p === 'UPDATE') {
                    // intermedia registrada de otro tipo de la misma conexion (cuenta compartida)
                } elseif (! in_array($p, self::INOCUOS, true)) {
                    $violaciones[] = "{$p} sobre {$m[3]}";
                }
            }
        }

        return ['violaciones' => array_values(array_unique($violaciones)), 'rutinas' => array_values(array_unique($rutinas)), 'actualiza_intermedia' => $actualiza];
    }

    /**
     * Concede a la cuenta restringida lo minimo para VALIDAR de un tipo. Requiere GRANT OPTION en la conexion del tipo.
     *
     * @return array<int, array{sentencia:string, ok:bool, error:?string}>
     */
    public function conceder(Connection $admin, string $conexion, string $procedimiento, string $tabla, array $tablasNegocio, bool $aplicar = true): array
    {
        $cuenta = $this->cuenta($conexion);

        if (! $cuenta) {
            return [];
        }

        $sesion = $this->conexiones->pdoDedicada($conexion, 60, $cuenta, false);
        $quien = (string) $sesion->query('SELECT CURRENT_USER()')->fetchColumn();
        $sesion = null;
        [$u, $h] = $this->partirCuenta($quien);
        $to = $admin->getPdo()->quote($u).'@'.$admin->getPdo()->quote($h);
        $e = (string) $admin->selectOne('SELECT DATABASE() AS d')->d;
        $q = fn (string $n) => '`'.str_replace('`', '``', $n).'`';

        $sentencias = ["GRANT SELECT, UPDATE ON {$q($e)}.{$q($tabla)} TO {$to}", "GRANT EXECUTE ON PROCEDURE {$q($e)}.{$q($procedimiento)} TO {$to}"];

        foreach ($tablasNegocio as $t) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $t)) {
                $sentencias[] = "GRANT SELECT ON {$q($e)}.{$q($t)} TO {$to}";
            }
        }

        $out = [];

        foreach ($sentencias as $s) {
            try {
                if ($aplicar) {
                    $admin->unprepared($s);
                }

                $out[] = ['sentencia' => $s, 'ok' => $aplicar, 'error' => null];
            } catch (Throwable $ex) {
                $out[] = ['sentencia' => $s, 'ok' => false, 'error' => $this->corto($ex->getMessage())];
            }
        }

        return $out;
    }

    /** @return array{0:?string,1:?string} [base, objeto] con `*` como comodin */
    protected function objeto(string $o): array
    {
        if (! preg_match('/^(`(?:[^`]|``)+`|\*)\.(`(?:[^`]|``)+`|\*)$/', $o, $m)) {
            return [null, null];
        }

        $l = fn (string $x) => $x === '*' ? '*' : str_replace(['``', '\\_', '\\%'], ['`', '_', '%'], substr($x, 1, -1));

        return [$l($m[1]), $l($m[2])];
    }

    /** @return string[] */
    protected function partir(string $lista): array
    {
        $r = [];
        $n = 0;
        $actual = '';

        foreach (str_split($lista) as $ch) {
            if ($ch === '(') {
                $n++;
            } elseif ($ch === ')') {
                $n--;
            }

            if ($ch === ',' && $n === 0) {
                $r[] = $actual;
                $actual = '';
            } else {
                $actual .= $ch;
            }
        }

        return [...$r, $actual];
    }

    protected function normalizarCuenta(string $c): string
    {
        [$u, $h] = $this->partirCuenta($c);

        return $u.'@'.strtolower($h);
    }

    /** @return array{0:string,1:string} */
    protected function partirCuenta(string $c): array
    {
        $c = str_replace(['`', "'"], '', $c);
        $i = strrpos($c, '@');

        return $i === false ? [$c, ''] : [substr($c, 0, $i), substr($c, $i + 1)];
    }

    protected function corto(string $m): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $m) ?? $m, 0, 220);
    }

    protected function h(string $nivel, string $codigo, string $mensaje): array
    {
        return ['nivel' => $nivel, 'codigo' => $codigo, 'ruta' => 'procedimiento', 'mensaje' => $mensaje];
    }
}
