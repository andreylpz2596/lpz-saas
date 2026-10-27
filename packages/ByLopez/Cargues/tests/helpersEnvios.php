<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\ResumenService;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/*
| Ayudantes de las pruebas del tipo «99 Envíos — Envíos completos» (solo instancia AISLADA, ver CarguesTestCase).
| El procedimiento real (sp_cg_envios_completos_v2) corre en la base principal de pruebas (`lpz_saas_testing`, conexion
| `mysql`), donde viven las tablas de Logistica. Pedidos, envios y guias son SINTETICOS y se borran al terminar.
*/

const CE_PROCEDIMIENTO = 'sp_cg_envios_completos_v2';
const CE_TABLA = 'bylopez_cgi_envios_completos';
const CE_PREFIJO = 'CGTEST-';

/** Encabezados REALES de la exportacion «Envíos Completos» (10 §2): 28 columnas con sus gemelas. */
const CE_ENCABEZADOS = [
    'codigo_sucursal', 'valor_servicio', 'fecha_envio', 'numero_de_guia', 'transportadora', 'Producto', 'nombre_destinatario',
    'direccion_destinatario', 'telefono_destinatario', 'ciudad_destino', 'valor_comercial', 'estado_del_envio', 'nombre_sucursal',
    'fecha_actualizacion', 'aplica_contrapago', 'seguro', 'valor_seguro_99', 'codigo', 'numero_guia', 'producto', 'destinatario',
    'direccion', 'telefono', 'ciudad', 'valor', 'estado', 'transportadora_nombre', 'Aplica Contrapago',
];

function ce_ruta(string $archivo): string
{
    return dirname(__DIR__).'/src/Database/Procedures/'.$archivo;
}

/** Instala el procedimiento VERSIONADO tal como esta en el paquete (sin DELIMITER). */
function ce_instalar_sp(): void
{
    $sql = file_get_contents(ce_ruta(CE_PROCEDIMIENTO.'.sql'));
    $sql = str_replace('END$$', 'END', preg_replace('/^DELIMITER.*$/m', '', $sql));
    $cuerpo = trim(preg_split('/^DROP PROCEDURE IF EXISTS '.CE_PROCEDIMIENTO.';/m', $sql)[1]);

    DB::unprepared('DROP PROCEDURE IF EXISTS '.CE_PROCEDIMIENTO);
    DB::unprepared($cuerpo);
}

function ce_map(): array
{
    return json_decode(file_get_contents(ce_ruta(CE_PROCEDIMIENTO.'.map.json')), true);
}

function ce_mapeo(string $codigo = '1', string $transportadora = 'INTER_RAPIDISIMO', bool $activo = true): void
{
    DB::table('bylopez_lg_proveedor_transportadoras')->updateOrInsert(
        ['proveedor' => '99envios', 'codigo_externo' => $codigo],
        ['nombre_externo' => 'sintética', 'transportadora_codigo' => $transportadora, 'activo' => $activo, 'created_at' => now(), 'updated_at' => now()],
    );
}

/** Pedido + envio + guia SINTETICOS. Devuelve el id de la guia. */
function ce_guia(string $numero, string $transportadora = 'INTER_RAPIDISIMO', float $recaudo = 100000.0, string $origen = '99envios'): int
{
    $orden = DB::table('orders')->insertGetId(['increment_id' => CE_PREFIJO.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $envio = DB::table('shipments')->insertGetId(['order_id' => $orden, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('bylopez_lg_guias')->insertGetId([
        'order_id' => $orden, 'shipment_id' => $envio, 'transportadora_codigo' => $transportadora, 'numero_guia' => $numero,
        'origen_guia' => $origen, 'generada_por' => 'operaria', 'valor_flete' => 18000, 'flete_pagador' => 'bylopez',
        'recaudo_esperado' => $recaudo, 'recaudo_estado' => $recaudo > 0 ? 'pendiente_liquidar' : 'no_aplica',
        'actor_admin_id' => 1, 'actor_name_snapshot' => 'Prueba', 'idempotency_key' => CE_PREFIJO.uniqid('', true), 'huella' => str_repeat('0', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Borra TODO lo sintetico de estas pruebas en la base principal (en orden de llaves foraneas). */
function ce_limpiar(): void
{
    $guias = DB::table('bylopez_lg_guias as g')->join('orders as o', 'o.id', '=', 'g.order_id')->where('o.increment_id', 'like', CE_PREFIJO.'%')->pluck('g.id');

    DB::table('bylopez_lg_guia_estado_proveedor_eventos')->whereIn('guia_id', $guias)->delete();
    DB::table('bylopez_lg_guia_estados_proveedor')->whereIn('guia_id', $guias)->delete();
    DB::table('bylopez_lg_guias')->whereIn('id', $guias)->delete();
    $ordenes = DB::table('orders')->where('increment_id', 'like', CE_PREFIJO.'%')->pluck('id');
    DB::table('shipments')->whereIn('order_id', $ordenes)->delete();
    DB::table('orders')->whereIn('id', $ordenes)->delete();
    DB::table('bylopez_lg_proveedor_transportadoras')->where('proveedor', '99envios')->delete();
    DB::statement('DROP TABLE IF EXISTS `'.CE_TABLA.'`');
    DB::unprepared('DROP PROCEDURE IF EXISTS '.CE_PROCEDIMIENTO);
}

/**
 * Fila del archivo con la ESTRUCTURA real y datos sinteticos. Claves: g (guia), tr (codigo), fe, fa, est, vc, vs, seg,
 * tseg, cp ('1' o ''), cpt ('Si'/'No').
 */
function ce_fila(array $o): array
{
    $o += ['tr' => '1', 'fe' => '2026-09-20 10:00:00', 'fa' => '2026-09-22 15:30:00', 'est' => 'Entregada', 'vc' => '100000', 'vs' => '23426.22',
        'seg' => '3337', 'tseg' => 'Seguro 99', 'cp' => '1'];
    $o['cpt'] ??= $o['cp'] === '1' ? 'Si' : 'No';
    $nombreTr = $o['nom'] ?? ['1' => 'interrapidisimo', '4' => 'coordinadora'][$o['tr']] ?? 'otra';

    return [
        '668099', $o['vs'], $o['fe'], $o['g'], $o['tr'], 'CAMISETAS', 'Destinatario Sintético', 'Calle 1 # 2-3', '3000000000', 'CIUDAD SINTÉTICA',
        $o['vc'], $o['est'], 'By Lopez', $o['fa'], $o['cp'], $o['tseg'], $o['seg'],
        '668099', $o['g'], 'CAMISETAS', 'Destinatario Sintético', 'Calle 1 # 2-3', '3000000000', 'CIUDAD SINTÉTICA', $o['vc'], $o['est'], $nombreTr, $o['cpt'],
    ];
}

function ce_csv(array $filas): UploadedFile
{
    $h = fopen('php://temp', 'r+');
    fputcsv($h, CE_ENCABEZADOS, ';', '"', '');

    foreach ($filas as $f) {
        fputcsv($h, ce_fila($f), ';', '"', '');
    }

    rewind($h);

    return UploadedFile::fake()->createWithContent('envios_completos_sintetico.csv', stream_get_contents($h));
}

/** Tipo activo con el procedimiento y el map VERSIONADOS. */
function ce_tipo(string $politica = 'procesar_validas'): array
{
    ce_instalar_sp();
    $admin = cg_admin();
    $tipos = app(TiposService::class);
    $tipo = $tipos->crear($admin, [
        'nombre' => '99 Envíos — Envíos completos (prueba)', 'descripcion' => 'Prueba sintética',
        'conexion' => 'mysql', 'procedimiento' => CE_PROCEDIMIENTO, 'accion' => 'actualizar_estado',
        'politica_errores' => $politica, 'limites' => null, 'map' => ce_map(),
    ]);
    $v = $tipo->versiones()->first();
    $revision = $tipos->confirmarContrato($admin, $v);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());

    return [$tipo->fresh(), $v, $admin, $revision];
}

/** Sube el archivo y valida con la sugerencia + la columna de contrapago elegida (AB «Aplica Contrapago» por defecto). */
function ce_validar($tipo, $admin, array $filas, int $columnaContrapago = 27, ?int $columnaTransportadora = null): Carga
{
    $servicio = app(CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], ce_csv($filas));
    $rel = (array) $carga->encabezados['sugerencias'];
    $rel['aplica_contrapago'] = $columnaContrapago;

    if ($columnaTransportadora !== null) {
        $rel['transportadora'] = $columnaTransportadora; // p. ej. 26 = «transportadora_nombre» (exportaciones sin la columna de código)
    }

    return $servicio->validar($admin, $carga, ['relaciones' => $rel])->fresh();
}

function ce_ejecutar(Carga $carga, $admin): Carga
{
    $r = app(ResumenService::class)->calcular($carga);

    return app(CargasService::class)->ejecutar($admin, $carga, $r['confirmar_rechazadas'] ? ['confirmar_rechazadas' => $r['confirmar_rechazadas']] : [])->fresh();
}

/** CHECKSUM de las tablas que el procedimiento NO debe tocar (A12, F1-R5). */
function ce_intocables(): array
{
    $r = [];

    foreach (['orders', 'shipments', 'bylopez_lg_guias', 'bylopez_lg_entregas', 'bylopez_lg_retornos', 'bylopez_lg_validaciones_entrega',
        'bylopez_lg_recepciones_devolucion', 'bylopez_va_pagos', 'bylopez_va_aplicaciones_pago', 'bylopez_va_pagos_eventos', 'bylopez_va_comision_reglas'] as $t) {
        $r[$t] = (string) DB::selectOne("CHECKSUM TABLE `{$t}`")->Checksum;
    }

    return $r;
}

/** Codigo de rechazo de una fila (por guia) en esta carga. */
function ce_codigos(Carga $carga): array
{
    return DB::table('bylopez_cg_carga_incidencias as i')->join('bylopez_cg_carga_datos as d', function ($j) {
        $j->on('d.carga_id', '=', 'i.carga_id')->on('d.fila_origen', '=', 'i.fila_origen');
    })->where('i.carga_id', $carga->id)->orderBy('i.fila_origen')->get(['i.fila_origen', 'i.codigo', 'i.severidad', 'i.etapa', 'd.estado_final'])
        ->map(fn ($r) => $r->fila_origen.':'.$r->codigo.':'.$r->severidad.':'.$r->etapa)->all();
}
