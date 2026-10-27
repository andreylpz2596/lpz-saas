<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\ResumenService;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/*
| Ayudantes de las pruebas del tipo «Clientes históricos — 99 Envíos» (solo instancia AISLADA, ver CarguesTestCase). El procedimiento
| real (sp_cg_clientes_historicos_v1) y su extensión corren en la base principal de pruebas; clientes, guías y catálogo son SINTÉTICOS
| (prefijo de teléfono 30011100xx y guías CLTEST-…) y se borran al terminar.
*/

const CL_PROCEDIMIENTO = 'sp_cg_clientes_historicos_v2';
const CL_TABLA = 'bylopez_cgi_clientes_historicos';
const CL_PREFIJO_GUIA = 'CLTEST-';

/** Encabezados REALES de «Envíos completos» (primer bloque de la exportación de 28 columnas; las gemelas del segundo bloque no se usan). */
const CL_ENCABEZADOS = ['codigo_sucursal', 'valor_servicio', 'fecha_envio', 'numero_de_guia', 'transportadora', 'nombre_destinatario', 'direccion_destinatario', 'telefono_destinatario', 'ciudad_destino', 'valor_comercial', 'estado_del_envio', 'fecha_actualizacion'];

function cl_ruta(string $archivo): string
{
    return dirname(__DIR__).'/src/Database/Procedures/'.$archivo;
}

function cl_instalar_sp(): void
{
    $sql = file_get_contents(cl_ruta(CL_PROCEDIMIENTO.'.sql'));
    $sql = str_replace('END$$', 'END', preg_replace('/^DELIMITER.*$/m', '', $sql));
    $cuerpo = trim(preg_split('/^DROP PROCEDURE IF EXISTS '.CL_PROCEDIMIENTO.';/m', $sql)[1]);

    DB::unprepared('DROP PROCEDURE IF EXISTS '.CL_PROCEDIMIENTO);
    DB::unprepared($cuerpo);
}

function cl_map(): array
{
    return json_decode(file_get_contents(cl_ruta(CL_PROCEDIMIENTO.'.map.json')), true);
}

/** Catálogo geográfico SINTÉTICO mínimo: Medellín (único), dos «Albania» (ambiguo) y Santa Rosa de Lima. */
function cl_geo(): void
{
    // Como en desarrollo: el código postal NO es obligatorio (core_config); en la base de pruebas sin esa fila Bagisto lo exige.
    DB::table('core_config')->updateOrInsert(['code' => 'customer.address.requirements.postcode', 'channel_code' => 'default'], ['value' => '0', 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()]);
    core()->getConfigData('customer.address.requirements.postcode');
    cache()->flush();
    DB::table('country_states')->updateOrInsert(['country_code' => 'CO', 'code' => 'CLT1'], ['default_name' => 'CL Antioquia']);
    DB::table('country_states')->updateOrInsert(['country_code' => 'CO', 'code' => 'CLT2'], ['default_name' => 'CL Caquetá']);
    $s1 = DB::table('country_states')->where(['country_code' => 'CO', 'code' => 'CLT1'])->value('id');
    $s2 = DB::table('country_states')->where(['country_code' => 'CO', 'code' => 'CLT2'])->value('id');

    DB::table('bylopez_geo_departamentos')->insert([
        ['codigo_divipola' => '91', 'nombre_oficial' => 'CL Antioquia', 'nombre_busqueda' => 'cl antioquia', 'country_state_id' => $s1, 'created_at' => now(), 'updated_at' => now()],
        ['codigo_divipola' => '92', 'nombre_oficial' => 'CL Caquetá', 'nombre_busqueda' => 'cl caqueta', 'country_state_id' => $s2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    foreach ([['91001', 'Medellín', 'medellin', '91'], ['91002', 'Albania', 'albania', '91'], ['92001', 'Albania', 'albania', '92'], ['91003', 'Santa Rosa de Lima', 'santa rosa de lima', '91']] as [$c, $n, $b, $d]) {
        DB::table('bylopez_geo_municipios')->insert(['codigo_divipola' => $c, 'nombre_oficial' => $n, 'nombre_busqueda' => $b, 'departamento_codigo' => $d, 'tipo' => 'Municipio', 'activo' => 1,
            'fuente' => 'prueba', 'version_fuente' => 'x', 'checksum_fuente' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    }
}

/** Borra todo lo sintético de estas pruebas en la base principal (clientes con teléfono 30011100xx, sus direcciones, antecedentes y catálogo). */
function cl_limpiar(): void
{
    $ids = DB::table('customers')->where('phone', 'like', '+57300111%')->pluck('id');
    DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', 'like', CL_PREFIJO_GUIA.'%')->delete();
    DB::table('bylopez_va_cliente_direcciones_pendientes')->whereIn('customer_id', $ids)->delete();
    DB::table('bylopez_va_cliente_datos_pendientes')->whereIn('customer_id', $ids)->delete();
    DB::table('bylopez_lg_proveedor_transportadoras')->where('proveedor', '99envios')->delete();
    DB::table('addresses')->whereIn('customer_id', $ids)->delete();
    DB::table('bylopez_va_customer_phones')->whereIn('customer_id', $ids)->delete();
    DB::table('customers')->whereIn('id', $ids)->delete();
    DB::table('bylopez_geo_municipios')->whereIn('codigo_divipola', ['91001', '91002', '92001', '91003'])->delete();
    DB::table('bylopez_geo_departamentos')->whereIn('codigo_divipola', ['91', '92'])->delete();
    DB::table('country_states')->where('country_code', 'CO')->whereIn('code', ['CLT1', 'CLT2'])->delete();
    DB::table('core_config')->where('code', 'customer.address.requirements.postcode')->delete();
    DB::table('core_config')->where('code', 'like', '%customer_registration_confirmation_mail_to_admin%')->delete();
    DB::statement('DROP TABLE IF EXISTS `'.CL_TABLA.'`');
    DB::unprepared('DROP PROCEDURE IF EXISTS '.CL_PROCEDIMIENTO);
}

/** Fila con la ESTRUCTURA de «Envíos completos». Claves: g (guía sin prefijo), n (nombre), t (teléfono), d (dirección), c (ciudad), e (estado), fa (fecha del estado), tr (transportadora), fe (fecha de envío). */
function cl_fila(array $o): array
{
    $o += ['n' => 'Ana Maria Lopez Perez', 't' => '3001110001', 'd' => 'Calle 1 # 2-3', 'c' => 'MEDELLÍN', 'e' => 'Entregada', 'fa' => '2026-09-22 15:30:00', 'tr' => '', 'fe' => '2026-09-12 10:00:00'];

    return ['668099', '20000', $o['fe'], CL_PREFIJO_GUIA.$o['g'], $o['tr'], $o['n'], $o['d'], $o['t'], $o['c'], '100000', $o['e'], $o['fa']];
}

function cl_csv(array $filas): UploadedFile
{
    $h = fopen('php://temp', 'r+');
    fputcsv($h, CL_ENCABEZADOS, ';', '"', '');

    foreach ($filas as $f) {
        fputcsv($h, cl_fila($f), ';', '"', '');
    }

    rewind($h);

    return UploadedFile::fake()->createWithContent('cartera_sintetica.csv', stream_get_contents($h));
}

/** Tipo activo con el procedimiento, el map y la extensión VERSIONADOS. */
function cl_tipo(): array
{
    cl_instalar_sp();
    $admin = cg_admin();
    $tipos = app(TiposService::class);
    $tipo = $tipos->crear($admin, [
        'nombre' => 'Clientes históricos — 99 Envíos (prueba)', 'descripcion' => 'Prueba sintética', 'conexion' => 'mysql', 'procedimiento' => CL_PROCEDIMIENTO,
        'accion' => 'importar_historico', 'politica_errores' => 'procesar_validas', 'limites' => null, 'map' => cl_map(),
    ]);
    $v = $tipo->versiones()->first();
    $revision = $tipos->confirmarContrato($admin, $v);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());

    return [$tipo->fresh(), $v, $admin, $revision];
}

function cl_validar($tipo, $admin, array $filas): Carga
{
    $servicio = app(CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], cl_csv($filas));

    return $servicio->validar($admin, $carga, ['relaciones' => (array) $carga->encabezados['sugerencias']])->fresh();
}

function cl_ejecutar(Carga $carga, $admin): Carga
{
    $r = app(ResumenService::class)->calcular($carga);

    return app(CargasService::class)->ejecutar($admin, $carga, $r['confirmar_rechazadas'] ? ['confirmar_rechazadas' => $r['confirmar_rechazadas']] : [])->fresh();
}

/** Conteos de las tablas que la importación PUEDE escribir (para demostrar que VALIDAR y la repetición no cambian nada). */
function cl_conteos(): array
{
    return [
        'clientes' => DB::table('customers')->where('phone', 'like', '+57300111%')->count(),
        'telefonos' => DB::table('bylopez_va_customer_phones')->where('telefono_normalizado', 'like', '+57300111%')->count(),
        'direcciones' => DB::table('addresses as a')->join('customers as c', 'c.id', '=', 'a.customer_id')->where('c.phone', 'like', '+57300111%')->count(),
        'antecedentes' => DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', 'like', CL_PREFIJO_GUIA.'%')->count(),
        'clientes_total' => DB::table('customers')->count(),
    ];
}

/** Estado final por guía en esta carga: guía => estado:detalle. */
function cl_resultado(Carga $carga): array
{
    return DB::table(CL_TABLA)->where('cg_carga_id', $carga->id)->orderBy('cg_fila_origen')->get(['numero_de_guia', 'cg_estado', 'cg_detalle', 'cg_codigo'])
        ->mapWithKeys(fn ($r) => [str_replace(CL_PREFIJO_GUIA, '', $r->numero_de_guia) => ($r->cg_estado ?? 'null').':'.($r->cg_detalle ?? '-').($r->cg_codigo ? '/'.$r->cg_codigo : '')])->all();
}

/** Direcciones pendientes de completar de los clientes sintéticos (texto original y motivo). */
function cl_pendientes(): array
{
    return DB::table('bylopez_va_cliente_direcciones_pendientes as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->where('c.phone', 'like', '+57300111%')
        ->get(['p.direccion_original', 'p.ciudad_original', 'p.motivo', 'p.carga_id', 'p.fila_origen'])->map(fn ($r) => (array) $r)->all();
}

/** Aviso al administrador por cliente nuevo (la unica fuente de correos del evento customer.create.after). */
function cl_aviso_admin(bool $activo): void
{
    DB::table('core_config')->updateOrInsert(['code' => 'emails.general.notifications.emails.general.notifications.customer_registration_confirmation_mail_to_admin'],
        ['value' => $activo ? '1' : '0', 'channel_code' => null, 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()]);
    cache()->flush();
}
