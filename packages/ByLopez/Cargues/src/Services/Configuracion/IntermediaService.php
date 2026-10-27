<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Support\Canonico;
use ByLopez\Cargues\Support\Esquema;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Intermedia dinamica (07 §5): la crea el motor a partir de un map YA validado, con el Schema Builder; jamas se
 * concatena SQL que venga del JSON. Todas las llaves llevan `cg_carga_id` al inicio, asi que dos cargas conviven en la
 * misma tabla sin mezclarse. La huella ignora etiquetas, sinonimos, formatos, reglas de vacio y politicas.
 */
class IntermediaService
{
    public function __construct(protected ConexionTipo $conexiones) {}

    /** Huella de ESTRUCTURA (tabla + campos + llaves). */
    public function huella(array $map): string
    {
        return Canonico::huella([
            'v' => 1,
            'tabla' => $map['tabla'],
            'campos' => array_map(fn (array $c) => array_filter([
                'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
                'longitud' => $c['longitud'] ?? null, 'precision' => $c['precision'] ?? null, 'escala' => $c['escala'] ?? null,
                'nulo' => (bool) ($c['nulo'] ?? true),
            ], fn ($v) => $v !== null), Esquema::columnas($map)),
            'llaves' => [
                'primaria' => $map['llaves']['primaria'] ?? null,
                'unicas' => array_values($map['llaves']['unicas'] ?? []),
                'indices' => array_values($map['llaves']['indices'] ?? []),
            ],
        ]);
    }

    /** Sentencias DDL (solo texto; para auditoria y para ejecutar). */
    public function ddl(Connection $c, array $map, ?string $tabla = null): array
    {
        $tabla ??= $map['tabla'];

        return array_merge($this->blueprintCrear($c, $map, $tabla)->toSql(), $this->blueprintLlaves($c, $map, $tabla)->toSql());
    }

    /**
     * Crea la intermedia. MySQL/MariaDB exigen que la columna AUTO_INCREMENT sea una llave EN el CREATE, y Laravel
     * emite los indices como sentencias aparte; por eso se crea con `cg_id` como llave primaria y, despues, se
     * ajustan las llaves (la primaria de negocio pasa a ser (cg_carga_id, campos) y `cg_id` queda UNIQUE). Si algun
     * paso falla se DESCARTA la tabla parcial: nunca queda una intermedia a medias.
     */
    public function crear(Connection $c, array $map, ?string $tabla = null): string
    {
        $tabla ??= $map['tabla'];
        $texto = implode(";\n", $this->ddl($c, $map, $tabla)).';';

        try {
            $this->blueprintCrear($c, $map, $tabla)->build();
            $this->blueprintLlaves($c, $map, $tabla)->build();
        } catch (\Throwable $e) {
            if ($this->existe($c, $tabla)) {
                $c->statement("DROP TABLE `{$tabla}`");
            }

            throw $e;
        }

        return $texto;
    }

    public function existe(Connection $c, string $tabla): bool
    {
        return (bool) $c->selectOne(
            'SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$tabla],
        );
    }

    /** Tablas del esquema que empiezan por un prefijo (para reconciliar `__nueva` / `__a<n>`). */
    public function tablasQueEmpiezanPor(Connection $c, string $prefijo): array
    {
        return array_map(fn ($r) => $r->TABLE_NAME, $c->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ESCAPE \'\\\\\'',
            [str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefijo).'%'],
        ));
    }

    /**
     * Compara la tabla REAL con la que produciria el map (columnas, tipos, nulabilidad, intercalacion de los
     * identificadores e indices). Devuelve las diferencias; vacio = coincide.
     *
     * @return string[]
     */
    public function verificar(Connection $c, array $map, ?string $tabla = null): array
    {
        $tabla ??= $map['tabla'];

        if (! $this->existe($c, $tabla)) {
            return ["La tabla {$tabla} no existe."];
        }

        $dif = [];
        $esperadas = $this->columnasEsperadas($c, $map);
        $reales = [];

        foreach ($c->getSchemaBuilder()->getColumns($tabla) as $col) {
            $reales[$col['name']] = $col;
        }

        foreach ($esperadas as $nombre => $e) {
            if (! isset($reales[$nombre])) {
                $dif[] = "Falta la columna {$nombre}.";

                continue;
            }

            $r = $reales[$nombre];

            if ($r['type_name'] !== $e['tipo']) {
                $dif[] = "La columna {$nombre} es {$r['type_name']} y debería ser {$e['tipo']}.";
            }

            if ((bool) $r['nullable'] !== $e['nulo']) {
                $dif[] = "La columna {$nombre} tiene otra nulabilidad.";
            }

            if (isset($e['colacion']) && ($r['collation'] ?? null) !== $e['colacion']) {
                $dif[] = "La columna {$nombre} tiene la intercalación ".($r['collation'] ?? '?')." y debería ser {$e['colacion']}.";
            }
        }

        foreach (array_diff(array_keys($reales), array_keys($esperadas)) as $extra) {
            $dif[] = "Sobra la columna {$extra}.";
        }

        $reales = [];

        foreach ($c->getSchemaBuilder()->getIndexes($tabla) as $i) {
            $reales[] = ['cols' => $i['columns'], 'unica' => (bool) $i['unique'], 'primaria' => (bool) $i['primary']];
        }

        foreach ($this->indicesEsperados($map) as $e) {
            $ok = false;

            foreach ($reales as $r) {
                if ($r['cols'] === $e['cols'] && $r['unica'] === $e['unica'] && $r['primaria'] === $e['primaria']) {
                    $ok = true;

                    break;
                }
            }

            if (! $ok) {
                $dif[] = 'Falta el índice '.($e['primaria'] ? 'primario' : ($e['unica'] ? 'único' : 'no único')).' ('.implode(', ', $e['cols']).').';
            }
        }

        return $dif;
    }

    /** Indices que debe tener la intermedia: [cols, unica, primaria]. */
    public function indicesEsperados(array $map): array
    {
        $ll = $map['llaves'] ?? [];
        $r = [];

        if (! empty($ll['primaria'])) {
            $r[] = ['cols' => array_merge(['cg_carga_id'], $ll['primaria']), 'unica' => true, 'primaria' => true];
            $r[] = ['cols' => ['cg_id'], 'unica' => true, 'primaria' => false];
        } else {
            $r[] = ['cols' => ['cg_id'], 'unica' => true, 'primaria' => true];
        }

        $r[] = ['cols' => ['cg_carga_id', 'cg_fila_origen'], 'unica' => true, 'primaria' => false];
        $r[] = ['cols' => ['cg_carga_id', 'cg_estado'], 'unica' => false, 'primaria' => false];

        foreach ($ll['unicas'] ?? [] as $g) {
            $r[] = ['cols' => array_merge(['cg_carga_id'], $g), 'unica' => true, 'primaria' => false];
        }

        foreach ($ll['indices'] ?? [] as $g) {
            $r[] = ['cols' => array_merge(['cg_carga_id'], $g), 'unica' => false, 'primaria' => false];
        }

        return $r;
    }

    /** Columnas esperadas: nombre => [tipo (type_name de Laravel), nulo, colacion?]. */
    protected function columnasEsperadas(Connection $c, array $map): array
    {
        $exacta = $this->conexiones->colacionExacta($c);
        $r = [
            'cg_id' => ['tipo' => 'bigint', 'nulo' => false],
            'cg_carga_id' => ['tipo' => 'bigint', 'nulo' => false],
            'cg_carga_dato_id' => ['tipo' => 'bigint', 'nulo' => false],
            'cg_fila_origen' => ['tipo' => 'int', 'nulo' => false],
            'cg_duplicado' => ['tipo' => 'tinyint', 'nulo' => false],
            'cg_ausentes' => ['tipo' => 'text', 'nulo' => true],
            'cg_estado' => ['tipo' => 'varchar', 'nulo' => true],
            'cg_detalle' => ['tipo' => 'varchar', 'nulo' => true],
            'cg_codigo' => ['tipo' => 'varchar', 'nulo' => true],
            'cg_mensaje' => ['tipo' => 'varchar', 'nulo' => true],
            'cg_procesado_en' => ['tipo' => 'datetime', 'nulo' => true],
        ];

        foreach (Esquema::columnas($map) as $campo) {
            $r[$campo['nombre']] = [
                'tipo' => ['texto' => 'varchar', 'identificador' => 'varchar', 'texto_largo' => 'text', 'entero' => 'bigint', 'decimal' => 'decimal',
                    'fecha' => 'date', 'fecha_hora' => 'datetime', 'booleano' => 'tinyint'][$campo['tipo']],
                'nulo' => (bool) ($campo['nulo'] ?? true),
            ] + ($campo['tipo'] === 'identificador' ? ['colacion' => $exacta] : []);
        }

        return $r;
    }

    /** CREATE TABLE: columnas y `cg_id` como llave primaria (ver `crear`). */
    protected function blueprintCrear(Connection $c, array $map, string $tabla): Blueprint
    {
        $exacta = $this->conexiones->colacionExacta($c);
        $c->getSchemaBuilder(); // inicializa la gramatica de esquema de la conexion (el Blueprint la necesita)
        $t = new Blueprint($c, $tabla);
        $t->create();
        $t->engine('InnoDB');
        $t->charset('utf8mb4');
        $t->collation('utf8mb4_unicode_ci');

        $t->unsignedBigInteger('cg_id')->autoIncrement();
        $t->unsignedBigInteger('cg_carga_id');
        $t->unsignedBigInteger('cg_carga_dato_id');
        $t->unsignedInteger('cg_fila_origen');
        $t->boolean('cg_duplicado')->default(false);
        $t->text('cg_ausentes')->nullable();

        foreach (Esquema::columnas($map) as $campo) {
            $col = match ($campo['tipo']) {
                'texto' => $t->string($campo['nombre'], (int) $campo['longitud']),
                'identificador' => $t->string($campo['nombre'], (int) $campo['longitud'])->collation($exacta),
                'texto_largo' => $t->text($campo['nombre']),
                'entero' => $t->bigInteger($campo['nombre']),
                'decimal' => $t->decimal($campo['nombre'], (int) $campo['precision'], (int) $campo['escala']),
                'fecha' => $t->date($campo['nombre']),
                'fecha_hora' => $t->dateTime($campo['nombre']),
                'booleano' => $t->boolean($campo['nombre']),
            };

            if ($campo['nulo'] ?? true) {
                $col->nullable();
            }
        }

        $t->string('cg_estado', 30)->nullable();
        $t->string('cg_detalle', 255)->nullable();
        $t->string('cg_codigo', 60)->nullable();
        $t->string('cg_mensaje', 500)->nullable();
        $t->dateTime('cg_procesado_en')->nullable();

        return $t;
    }

    /** ALTER TABLE: llaves e indices (todos con `cg_carga_id` al inicio). El orden importa: `cg_id` nunca queda sin llave. */
    protected function blueprintLlaves(Connection $c, array $map, string $tabla): Blueprint
    {
        $c->getSchemaBuilder();
        $ll = $map['llaves'] ?? [];
        $t = new Blueprint($c, $tabla);

        if (! empty($ll['primaria'])) {
            $t->unique('cg_id', 'cg_u_id');
            $t->dropPrimary();
            $t->primary(array_merge(['cg_carga_id'], $ll['primaria']));
        }

        $t->unique(['cg_carga_id', 'cg_fila_origen'], 'cg_u_fila');
        $t->index(['cg_carga_id', 'cg_estado'], 'cg_i_estado');

        foreach (array_values($ll['unicas'] ?? []) as $i => $g) {
            $t->unique(array_merge(['cg_carga_id'], $g), 'cg_u_'.($i + 1));
        }

        foreach (array_values($ll['indices'] ?? []) as $i => $g) {
            $t->index(array_merge(['cg_carga_id'], $g), 'cg_i_'.($i + 1));
        }

        return $t;
    }

    /** Filas de una carga en la intermedia (para pruebas y resumen). */
    public function contarFilas(Connection $c, string $tabla, int $cargaId): int
    {
        return (int) $c->table($tabla)->where('cg_carga_id', $cargaId)->count();
    }

    /** Borra por lotes las filas de UNA carga (nunca TRUNCATE: la tabla es compartida, 07 §5.7). */
    public function borrarCarga(Connection $c, string $tabla, int $cargaId, int $lote = 2000): int
    {
        $total = 0;

        do {
            $n = $c->delete("DELETE FROM `{$tabla}` WHERE cg_carga_id = ? LIMIT {$lote}", [$cargaId]);
            $total += $n;
        } while ($n === $lote);

        return $total;
    }
}
