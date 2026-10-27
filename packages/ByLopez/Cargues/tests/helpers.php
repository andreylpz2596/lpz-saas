<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\Admin;

/*
| Ayudantes de las pruebas de Cargues (solo instancia aislada: ver CarguesTestCase). Datos 100 % sinteticos.
*/

const CG_TABLA = 'bylopez_cgi_envios_prueba';

/** Conexion del tipo: otra base (`cg_tipo_test`) de la MISMA instancia aislada; es otra conexion PDO. */
function cg_conn(): Connection
{
    return DB::connection('cargues_tipo_prueba');
}

/** Map estandar de las pruebas: 5 campos, primaria, una unica y un indice (F1-C1). */
function cg_mapa(array $sobre = []): array
{
    return array_replace_recursive([
        'tabla' => CG_TABLA,
        'acciones' => ['actualizar_estado'],
        'campos' => [
            ['nombre' => 'guia', 'etiqueta' => 'Guía', 'tipo' => 'identificador', 'longitud' => 20, 'nulo' => false, 'sinonimos' => ['Número de guía', 'numero_de_guia']],
            ['nombre' => 'telefono', 'etiqueta' => 'Teléfono', 'tipo' => 'identificador', 'longitud' => 20, 'nulo' => true, 'vacio' => 'nulo'],
            ['nombre' => 'estado', 'etiqueta' => 'Estado', 'tipo' => 'texto', 'longitud' => 40, 'nulo' => false],
            ['nombre' => 'valor', 'etiqueta' => 'Valor', 'tipo' => 'decimal', 'precision' => 14, 'escala' => 2, 'nulo' => true, 'vacio' => 'nulo', 'formato' => ['decimal' => ',', 'miles' => '.']],
            ['nombre' => 'fecha_estado', 'etiqueta' => 'Fecha del estado', 'tipo' => 'fecha_hora', 'nulo' => true, 'vacio' => 'nulo', 'formato' => ['formatos' => ['d/m/Y H:i']]],
        ],
        'llaves' => ['primaria' => ['guia'], 'unicas' => [['telefono']], 'indices' => [['estado']]],
        'duplicados' => 'rechazar_todas',
        'transaccion' => 'motor',
        'reanudable' => false,
        'tablas_negocio' => ['cg_negocio_a'],
    ], $sobre);
}

/** Roles: 1 = Administrador, 2 = otro rol con `permission_type = all`, 3 = rol personalizado. */
function cg_roles(): void
{
    foreach ([[1, 'Administrador', 'all', null], [2, 'Otro rol todo', 'all', null], [3, 'Rol personalizado', 'custom', json_encode(['dashboard'])]] as [$id, $n, $t, $p]) {
        DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $n, 'description' => $n, 'permission_type' => $t, 'permissions' => $p, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function cg_admin(int $rol = 1): Admin
{
    cg_roles();

    return Admin::factory()->create(['role_id' => $rol, 'email' => 'cg'.uniqid().'@prueba.test']);
}

/** Limpieza total: tablas del modulo, intermedias, procedimientos y tablas de negocio sinteticas, y archivos subidos. */
function cg_limpiar(): void
{
    foreach (DB::table('bylopez_cg_cargas')->pluck('archivo_ruta') as $ruta) {
        Storage::disk(config('cargues.disco'))->delete($ruta);
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['bylopez_cg_carga_incidencias', 'bylopez_cg_carga_datos', 'bylopez_cg_cargas', 'bylopez_cg_comparaciones', 'bylopez_cg_tipo_versiones', 'bylopez_cg_estructuras', 'bylopez_cg_tipos'] as $t) {
        DB::table($t)->delete();
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    $c = cg_conn();

    foreach ($c->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'bylopez\\_cgi\\_%' OR TABLE_NAME LIKE 'cg\\_negocio\\_%')") as $t) {
        $c->statement('DROP TABLE `'.$t->TABLE_NAME.'`');
    }

    foreach ($c->select("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'sp\\_cg\\_%'") as $r) {
        $c->unprepared('DROP PROCEDURE `'.$r->ROUTINE_NAME.'`');
    }
}

/** Tablas de negocio sinteticas de la conexion del tipo (las que un VALIDAR no debe tocar). */
function cg_negocio(): void
{
    $c = cg_conn();

    foreach (['cg_negocio_a', 'cg_negocio_b'] as $t) {
        $c->statement("CREATE TABLE IF NOT EXISTS `{$t}` (id INT AUTO_INCREMENT PRIMARY KEY, guia VARCHAR(40) NOT NULL, estado VARCHAR(40) NOT NULL) ENGINE=InnoDB");
    }

    $c->table('cg_negocio_a')->insert([['guia' => 'N-1', 'estado' => 'x'], ['guia' => 'N-2', 'estado' => 'y']]);
}

/**
 * Instala un procedimiento SINTETICO con la firma del contrato. `$validar` es el SQL que corre en modo VALIDAR;
 * `$cabeza` se ejecuta antes (declaraciones) y por defecto el procedimiento responde OK.
 */
function cg_sp(string $nombre, string $validar, string $resultado = "'OK'"): void
{
    $c = cg_conn();
    $c->unprepared("DROP PROCEDURE IF EXISTS `{$nombre}`");
    $c->unprepared(<<<SQL
        CREATE PROCEDURE `{$nombre}`(IN p_carga_id BIGINT, IN p_accion VARCHAR(40), IN p_modo VARCHAR(10), OUT p_resultado VARCHAR(10), OUT p_mensaje VARCHAR(500))
        BEGIN
            SET p_resultado = {$resultado};
            SET p_mensaje = NULL;
            IF p_modo = 'VALIDAR' THEN
                {$validar}
            ELSE
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EJECUTAR se habilita en E2';
            END IF;
        END
        SQL);
}

/** Procedimiento correcto: en VALIDAR rechaza las filas sin telefono (solo marca la intermedia). */
function cg_sp_ok(string $nombre = 'sp_cg_prueba', string $tabla = CG_TABLA): void
{
    cg_sp($nombre, "UPDATE {$tabla} SET cg_estado = 'rechazada', cg_codigo = 'SIN_TELEFONO', cg_mensaje = 'La fila no trae teléfono' WHERE cg_carga_id = p_carga_id AND telefono IS NULL;
        IF ROW_COUNT() > 0 THEN SET p_resultado = 'PARCIAL'; END IF;");
}

/** Procedimiento que no rechaza nada (para probar solo las reglas del motor). */
function cg_sp_nulo(string $nombre = 'sp_cg_prueba'): void
{
    cg_sp($nombre, 'SET p_mensaje = NULL;');
}

/** Tipo en borrador con la configuracion estandar. */
function cg_tipo(array $o = []): array
{
    $admin = $o['admin'] ?? cg_admin();
    $servicio = app(TiposService::class);
    $tipo = $servicio->crear($admin, [
        'nombre' => $o['nombre'] ?? 'Tipo '.uniqid(),
        'descripcion' => $o['descripcion'] ?? 'Tipo de prueba',
        'conexion' => 'cargues_tipo_prueba',
        'procedimiento' => $o['procedimiento'] ?? 'sp_cg_prueba',
        'accion' => 'actualizar_estado',
        'politica_errores' => array_key_exists('politica', $o) ? $o['politica'] : 'bloquear_todo',
        'limites' => $o['limites'] ?? null,
        'map' => $o['map'] ?? cg_mapa(),
    ]);

    return [$tipo, $tipo->versiones()->first(), $admin];
}

/** Tipo activo y listo para cargar. */
function cg_tipo_activo(array $o = []): array
{
    cg_negocio();

    if (! ($o['sin_sp'] ?? false)) {
        cg_sp_ok($o['procedimiento'] ?? 'sp_cg_prueba', $o['map']['tabla'] ?? CG_TABLA);
    }

    [$tipo, $v, $admin] = cg_tipo($o);
    app(TiposService::class)->confirmarContrato($admin, $v);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());

    return [$tipo->fresh(), $v, $admin];
}

function cg_csv(string $contenido, string $nombre = 'envios.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

/** CSV UTF-8 con BOM, `;`, a partir de filas (la primera es el encabezado). */
function cg_csv_filas(array $filas, string $nombre = 'envios.csv'): UploadedFile
{
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");

    foreach ($filas as $f) {
        fputcsv($h, $f, ';', '"', '');
    }

    rewind($h);

    return cg_csv(stream_get_contents($h), $nombre);
}

const CG_ENCABEZADOS = ['Número de guía', 'Teléfono', 'Estado', 'Valor', 'Fecha del estado'];

/** Filas sinteticas validas (N filas). */
function cg_filas(int $n, int $desde = 1): array
{
    $f = [CG_ENCABEZADOS];

    for ($i = $desde; $i < $desde + $n; $i++) {
        $f[] = ['G'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), '300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'ENTREGADO', '1.234,50', '01/10/2026 10:30'];
    }

    return $f;
}

/** Crea la carga desde un CSV y la deja con la sugerencia de relaciones aplicada. */
function cg_carga(Tipo $tipo, Admin $admin, array $filas, ?array $relaciones = null): Carga
{
    $servicio = app(CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], cg_csv_filas($filas));

    cg_relaciones_guardadas($carga, $relaciones);

    return $carga->fresh();
}

/** Las relaciones sugeridas (por nombre/sinonimos) o las dadas. */
function cg_relaciones_guardadas(Carga $carga, ?array $dadas = null): array
{
    return $dadas ?? ($carga->encabezados['sugerencias'] ?? []);
}

/** Valida la carga con la sugerencia (o las relaciones dadas) y espera el resultado (cola sync). */
function cg_validar(Carga $carga, Admin $admin, ?array $relaciones = null, array $extra = []): Carga
{
    return app(CargasService::class)->validar($admin, $carga, ['relaciones' => $relaciones ?? ($carga->encabezados['sugerencias'] ?? [])] + $extra)->fresh();
}

function cg_version(Tipo $tipo): TipoVersion
{
    return $tipo->fresh()->versionVigente;
}

/** Extrae y decodifica el JSON que una pantalla entrega a Vue (`window.__x = {...};`). */
function cg_config_de_pagina(string $html, string $variable = 'window.__cargues'): array
{
    if (! preg_match('/'.preg_quote($variable, '/').' = (\{.*?\});\s*<\/script>/s', $html, $m)) {
        return [];
    }

    return json_decode($m[1], true) ?? [];
}
