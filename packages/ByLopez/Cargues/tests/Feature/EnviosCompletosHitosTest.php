<?php

use ByLopez\Cargues\Extensiones\EnviosCompletos\EnviosCompletosHitosExtension as Hitos;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\Complementos\EnviosCompletosResultado;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use ByLopez\Cargues\Tests\CarguesTestCase;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersEnvios.php';

uses(CarguesTestCase::class);

/*
| «99 Envíos — Envíos completos» VERSIÓN 3 (docs/16 §15): hitos logísticos automáticos. Pedidos, envíos y guías SINTÉTICOS en la
| instancia AISLADA. Entregada → entrega registrada + validada; Devolución ratificada → retorno pendiente de recepción (sin stock);
| contradicciones → incidencia; reportes antiguos → sin hito; repetir o recuperar no duplica nada.
*/

const EH_SP = 'sp_cg_envios_completos_v3';
const EH_CUENTA = 'cg_validar';

beforeEach(function () {
    cg_limpiar();
    eh_limpiar();
    $c = cg_conn();
    $c->unprepared('DROP USER IF EXISTS `'.EH_CUENTA.'`@`127.0.0.1`');
    $c->unprepared('CREATE USER `'.EH_CUENTA."`@`127.0.0.1` IDENTIFIED BY 'cg_validar_pw'");
    config([
        'cargues.cola.conexion' => 'sync',
        'cargues.conexiones_autorizadas' => ['cargues_tipo_prueba', 'mysql'],
        'cargues.validar.exigir_cuenta_restringida' => true,
        'cargues.validar.conceder_al_activar' => true,
        'cargues.validar.cuentas.mysql' => ['usuario' => EH_CUENTA, 'clave' => 'cg_validar_pw'],
    ]);
    ce_mapeo('1', 'INTER_RAPIDISIMO');
});

afterEach(function () {
    cg_limpiar();
    eh_limpiar();
    cg_conn()->unprepared('DROP USER IF EXISTS `'.EH_CUENTA.'`@`127.0.0.1`');
});

function eh_limpiar(): void
{
    $ordenes = DB::table('orders')->where('increment_id', 'like', CE_PREFIJO.'%')->pluck('id');
    $envios = DB::table('shipments')->whereIn('order_id', $ordenes)->pluck('id');
    DB::table('bylopez_lg_entrega_items')->whereIn('entrega_id', DB::table('bylopez_lg_entregas')->whereIn('shipment_id', $envios)->pluck('id'))->delete();
    DB::table('bylopez_lg_entregas')->whereIn('shipment_id', $envios)->delete();
    DB::table('bylopez_lg_validaciones_entrega')->whereIn('shipment_id', $envios)->delete();
    DB::table('bylopez_lg_retorno_items')->whereIn('retorno_id', DB::table('bylopez_lg_retornos')->whereIn('shipment_id', $envios)->pluck('id'))->delete();
    DB::table('bylopez_lg_retornos')->whereIn('shipment_id', $envios)->delete();
    DB::table('bylopez_va_comision_reevaluaciones')->whereIn('order_id', $ordenes)->delete();
    DB::table('shipment_items')->whereIn('shipment_id', $envios)->delete();
    DB::table('order_items')->whereIn('order_id', $ordenes)->delete();
    ce_limpiar();
    DB::unprepared('DROP PROCEDURE IF EXISTS '.EH_SP);
}

/** Tipo ACTIVO con el procedimiento y el map de la versión 3 VERSIONADOS (con la extensión de hitos). */
function eh_tipo(): array
{
    $sql = file_get_contents(ce_ruta(EH_SP.'.sql'));
    $sql = str_replace('END$$', 'END', preg_replace('/^DELIMITER.*$/m', '', $sql));
    DB::unprepared('DROP PROCEDURE IF EXISTS '.EH_SP);
    DB::unprepared(trim(preg_split('/^DROP PROCEDURE IF EXISTS '.EH_SP.';/m', $sql)[1]));

    $admin = cg_admin();
    $tipos = app(TiposService::class);
    $tipo = $tipos->crear($admin, [
        'nombre' => '99 Envíos — Envíos completos v3 (prueba)', 'descripcion' => 'Prueba sintética de hitos',
        'conexion' => 'mysql', 'procedimiento' => EH_SP, 'accion' => 'actualizar_estado',
        'politica_errores' => 'procesar_validas', 'limites' => null, 'map' => json_decode(file_get_contents(ce_ruta(EH_SP.'.map.json')), true),
    ]);
    $v = $tipo->versiones()->first();
    $tipos->confirmarContrato($admin, $v);
    app(ActivarTipoService::class)->activar($admin, $v->fresh());

    return [$tipo->fresh(), $admin];
}

/** Pedido + envío DESPACHADO hace 2 días con 2 líneas (2 + 1 unidades) + guía 99 Envíos/INTER_RAPIDISIMO. */
function eh_envio(string $numero): array
{
    $guia = ce_guia($numero);
    $g = DB::table('bylopez_lg_guias')->find($guia);
    $hace = now()->subDays(2);
    DB::table('orders')->where('id', $g->order_id)->update(['status' => 'processing', 'created_at' => $hace->copy()->subHour()]);
    DB::table('shipments')->where('id', $g->shipment_id)->update(['carrier_code' => 'INTER_RAPIDISIMO', 'track_number' => $numero, 'total_qty' => 3, 'created_at' => $hace, 'updated_at' => $hace]);

    foreach ([2, 1] as $i => $qty) {
        $linea = DB::table('order_items')->insertGetId(['order_id' => $g->order_id, 'sku' => CE_PREFIJO.'SKU'.$i, 'type' => 'simple', 'name' => 'Prenda sintética '.$i, 'qty_ordered' => $qty, 'qty_shipped' => $qty, 'created_at' => $hace, 'updated_at' => $hace]);
        DB::table('shipment_items')->insert(['shipment_id' => $g->shipment_id, 'order_item_id' => $linea, 'sku' => CE_PREFIJO.'SKU'.$i, 'name' => 'Prenda sintética '.$i, 'qty' => $qty, 'created_at' => $hace, 'updated_at' => $hace]);
    }

    return ['guia' => $guia, 'pedido' => (int) $g->order_id, 'envio' => (int) $g->shipment_id];
}

/** Fecha del proveedor de hoy (anterior a ahora, posterior al despacho): ninguna regla de fecha se flexibiliza. */
function eh_hoy(int $minutosAtras = 30): string
{
    return now()->subMinutes($minutosAtras)->format('Y-m-d H:i:s');
}

function eh_cargar($tipo, $admin, array $filas): Carga
{
    return ce_ejecutar(ce_validar($tipo, $admin, $filas), $admin)->fresh();
}

/** guía => "estado:detalle/códigos" de cada fila de la carga (marca final de la intermedia). */
function eh_res(Carga $carga): array
{
    return DB::table(CE_TABLA)->where('cg_carga_id', $carga->id)->orderBy('cg_fila_origen')->get()
        ->mapWithKeys(fn ($f) => [$f->numero_de_guia => $f->cg_estado.':'.($f->cg_detalle ?? '-').($f->cg_codigo ? '/'.$f->cg_codigo : '')])->all();
}

function eh_hitos(array $e): array
{
    $vig = fn ($t) => DB::table($t)->where('shipment_id', $e['envio'])->where('estado', 'confirmado')->count();

    return ['entregas' => $vig('bylopez_lg_entregas'), 'validaciones' => $vig('bylopez_lg_validaciones_entrega'), 'retornos' => $vig('bylopez_lg_retornos'),
        'recepciones' => DB::table('bylopez_lg_recepciones_devolucion')->where('shipment_id', $e['envio'])->count()];
}

/** Lo que NUNCA debe tocar un hito: stock, pedidos, envíos, guías, pagos, Cartera, comisiones y cuentas de transportadora. */
function eh_intocables(): array
{
    $r = [];

    foreach (['product_inventories', 'orders', 'order_items', 'shipments', 'shipment_items', 'bylopez_lg_guias', 'invoices', 'bylopez_va_pagos', 'bylopez_va_aplicaciones_pago',
        'bylopez_va_pagos_eventos', 'bylopez_va_comision_lineas', 'bylopez_va_umbral_movimientos', 'bylopez_lg_cuenta_movimientos', 'bylopez_lg_recepciones_devolucion'] as $t) {
        $r[$t] = (string) DB::selectOne("CHECKSUM TABLE `{$t}`")->Checksum;
    }

    return $r;
}

test('Entregada: registra la entrega de las unidades pendientes de ESE envío y la valida, con la evidencia del proveedor; no toca stock, pagos ni comisiones; repetir no duplica', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0001');
    $otro = eh_envio('EH0002');                                       // otro envío del archivo con un estado SIN hito: solo estado
    $intocables = eh_intocables();
    $fa = eh_hoy();

    $carga = eh_cargar($tipo, $admin, [['g' => 'EH0001', 'fa' => $fa, 'est' => 'Entregada'], ['g' => 'EH0002', 'fa' => $fa, 'est' => 'Reclame en oficina']]);

    expect($carga->estado)->toBe('completada')
        ->and(eh_res($carga))->toBe(['EH0001' => 'aplicada:entrega_registrada_y_validada', 'EH0002' => 'aplicada:insertada'])
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0])
        ->and(eh_hitos($otro))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 0, 'recepciones' => 0])
        ->and(eh_intocables())->toBe($intocables);

    $entrega = DB::table('bylopez_lg_entregas')->where('shipment_id', $a['envio'])->first();
    expect($entrega->fuente)->toBe('reporte_proveedor')
        ->and($entrega->fecha_entrega)->toBe(substr($fa, 0, 10))
        ->and($entrega->idempotency_key)->toBe(Hitos::claveEntrega($a['envio']))
        ->and($entrega->evidencia_referencia)->toContain('«Entregada» del '.$fa)->toContain('cargue #'.$carga->id)->toContain('fila 2')
        ->and((int) DB::table('bylopez_lg_entrega_items')->where('entrega_id', $entrega->id)->sum('cantidad'))->toBe(3)
        ->and(DB::table('bylopez_lg_validaciones_entrega')->where('shipment_id', $a['envio'])->value('observacion'))->toStartWith('Validada automáticamente: 99 Envíos «Entregada»');

    // Logística: sale de «Pendientes», queda en «Entregados» y en el historial, validada.
    $consulta = app(ConsultaEntregaService::class);
    $ids = fn (string $estado) => collect($consulta->listar(['estado' => $estado])->items())->pluck('shipment_id')->map(fn ($i) => (int) $i)->all();
    expect($ids('pendientes'))->not->toContain($a['envio'])->toContain($otro['envio'])
        ->and($ids('entregados'))->toContain($a['envio'])
        ->and($consulta->envio($a['envio'])['validacion'])->toBe('confirmado');

    // El resultado muestra el hito.
    $hito = collect(app(EnviosCompletosResultado::class)->complementar($carga, [2 => ['valores' => ['transportadora' => '1', 'numero_de_guia' => 'EH0001'], 'estado' => 'aplicada']])[2])->firstWhere('etiqueta', trans('cargues::app.res.res_hito'));
    expect($hito['valor'])->toContain('entrega registrada #'.$entrega->id)->toContain('validada #');

    // Repetir el MISMO archivo: sin cambio y ningún efecto nuevo (ni en Logística ni en el estado reportado).
    $antes = [eh_hitos($a), DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count(), eh_intocables()];
    $repetida = eh_cargar($tipo, $admin, [['g' => 'EH0001', 'fa' => $fa, 'est' => 'Entregada'], ['g' => 'EH0002', 'fa' => $fa, 'est' => 'Reclame en oficina']]);
    expect(eh_res($repetida))->toBe(['EH0001' => 'sin_cambio:hito_ya_registrado', 'EH0002' => 'sin_cambio:ya_registrado'])
        ->and([eh_hitos($a), DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count(), eh_intocables()])->toBe($antes);
});

test('Validación fallida: la entrega queda REGISTRADA y el resultado lo dice (parcial); al repetir el archivo («sin cambio» del reporte) se completa SOLO la validación', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0101');
    $fa = eh_hoy();

    // La validación falla por una regla de Logística (simulada en el servicio real).
    app()->bind(ValidarEntregaService::class, fn ($app) => new class($app->make(\ByLopez\Logistica\Support\AutorizacionLogistica::class), $app->make(ConsultaEntregaService::class)) extends ValidarEntregaService
    {
        public function validar($actor, int $shipmentId, string $idempotencyKey, ?string $observacion = null): array
        {
            throw new Invalida(Invalida::VALIDACION_CON_PENDIENTES);
        }
    });

    $carga = eh_cargar($tipo, $admin, [['g' => 'EH0101', 'fa' => $fa]]);
    $fila = DB::table(CE_TABLA)->where('cg_carga_id', $carga->id)->first();

    expect($carga->estado)->toBe('completada')
        ->and(eh_res($carga))->toBe(['EH0101' => 'aplicada:entrega_registrada_validacion_pendiente/VALIDACION_PENDIENTE'])
        ->and($fila->cg_mensaje)->toContain('La entrega quedó registrada, pero la validación no se pudo completar')->toContain('vuelva a cargar el archivo')
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 0, 'retornos' => 0, 'recepciones' => 0])
        ->and(ce_codigos($carga))->toBe(['2:VALIDACION_PENDIENTE:advertencia:ejecucion']);

    $hito = collect(app(EnviosCompletosResultado::class)->complementar($carga, [2 => ['valores' => ['transportadora' => '1', 'numero_de_guia' => 'EH0101'], 'estado' => 'aplicada']])[2])->firstWhere('etiqueta', trans('cargues::app.res.res_hito'));
    expect($hito['valor'])->toContain('validación PENDIENTE');

    // Se resuelve la causa y se vuelve a cargar el MISMO archivo: el reporte es «ya registrado», pero la validación pendiente se completa.
    app()->offsetUnset(ValidarEntregaService::class);
    $segunda = eh_cargar($tipo, $admin, [['g' => 'EH0101', 'fa' => $fa]]);

    expect(eh_res($segunda))->toBe(['EH0101' => 'aplicada:validacion_completada'])
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0])
        ->and(DB::table('bylopez_lg_guia_estado_proveedor_eventos')->where('guia_id', $a['guia'])->count())->toBe(1);

    // Tercera vez: nada pendiente.
    expect(eh_res(eh_cargar($tipo, $admin, [['g' => 'EH0101', 'fa' => $fa]])))->toBe(['EH0101' => 'sin_cambio:hito_ya_registrado'])
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0]);
});

test('Recuperación: si la aplicación se interrumpe (error inesperado), la carga queda reanudable; «Ejecutar de nuevo» procesa solo lo pendiente sin duplicar estado ni hitos', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0201');
    $b = eh_envio('EH0202');
    $fa = eh_hoy();
    $falla = $b['envio'];

    app()->bind(ValidarEntregaService::class, fn ($app) => new class($app->make(\ByLopez\Logistica\Support\AutorizacionLogistica::class), $app->make(ConsultaEntregaService::class), $falla) extends ValidarEntregaService
    {
        public function __construct($autorizacion, $consulta, private int $falla)
        {
            parent::__construct($autorizacion, $consulta);
        }

        public function validar($actor, int $shipmentId, string $idempotencyKey, ?string $observacion = null): array
        {
            if ($shipmentId === $this->falla) {
                throw new RuntimeException('Conexión perdida simulada');
            }

            return parent::validar($actor, $shipmentId, $idempotencyKey, $observacion);
        }
    });

    $carga = eh_cargar($tipo, $admin, [['g' => 'EH0201', 'fa' => $fa], ['g' => 'EH0202', 'fa' => $fa]]);

    expect($carga->estado)->toBe('fallida_ejecucion')
        ->and($carga->mensaje)->toContain('APLICACION_INTERRUMPIDA')
        ->and(eh_res($carga))->toBe(['EH0201' => 'aplicada:entrega_registrada_y_validada', 'EH0202' => ':insertada'])
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0])
        ->and(eh_hitos($b))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 0, 'recepciones' => 0])   // la fila se revirtió entera
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->whereIn('guia_id', [$a['guia'], $b['guia']])->count())->toBe(2);  // el estado ya quedó

    app()->offsetUnset(ValidarEntregaService::class);
    $carga = ce_ejecutar($carga, $admin)->fresh();

    expect($carga->estado)->toBe('completada')
        ->and(eh_res($carga))->toBe(['EH0201' => 'aplicada:entrega_registrada_y_validada', 'EH0202' => 'aplicada:entrega_registrada_y_validada'])
        ->and(eh_hitos($a))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0])
        ->and(eh_hitos($b))->toBe(['entregas' => 1, 'validaciones' => 1, 'retornos' => 0, 'recepciones' => 0])
        ->and(DB::table('bylopez_lg_guia_estado_proveedor_eventos')->whereIn('guia_id', [$a['guia'], $b['guia']])->count())->toBe(2);
});

test('Devolución ratificada: retorno reportado de las unidades pendientes, PENDIENTE de recepción física; sin reingreso de stock; repetir no duplica', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0301');
    $intocables = eh_intocables();
    $porRecibir = app(ConsultaEntregaService::class)->indicadores()['devoluciones_por_recibir'];
    $fa = eh_hoy();

    $carga = eh_cargar($tipo, $admin, [['g' => 'EH0301', 'fa' => $fa, 'est' => 'Devolución ratificada']]);
    $retorno = DB::table('bylopez_lg_retornos')->where('shipment_id', $a['envio'])->first();

    expect(eh_res($carga))->toBe(['EH0301' => 'aplicada:retorno_registrado_pendiente_recepcion'])
        ->and(eh_hitos($a))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 1, 'recepciones' => 0])
        ->and($retorno->fuente)->toBe('reporte_proveedor')
        ->and($retorno->motivo)->toBe('Devolución ratificada (99 Envíos)')
        ->and($retorno->fecha_retorno)->toBe(substr($fa, 0, 10))
        ->and((int) DB::table('bylopez_lg_retorno_items')->where('retorno_id', $retorno->id)->sum('cantidad'))->toBe(3)
        ->and(eh_intocables())->toBe($intocables)                                                   // stock y recepciones intactos
        ->and(app(ConsultaEntregaService::class)->indicadores()['devoluciones_por_recibir'])->toBe($porRecibir + 1);

    $consulta = app(ConsultaEntregaService::class);
    expect(collect($consulta->listar(['estado' => 'pendientes'])->items())->pluck('shipment_id')->map(fn ($i) => (int) $i)->all())->not->toContain($a['envio'])
        ->and(collect($consulta->listar(['estado' => 'retornados'])->items())->pluck('shipment_id')->map(fn ($i) => (int) $i)->all())->toContain($a['envio']);

    $hito = collect(app(EnviosCompletosResultado::class)->complementar($carga, [2 => ['valores' => ['transportadora' => '1', 'numero_de_guia' => 'EH0301'], 'estado' => 'aplicada']])[2])->firstWhere('etiqueta', trans('cargues::app.res.res_hito'));
    expect($hito['valor'])->toContain('devolución reportada #'.$retorno->id)->toContain('pendiente de recepción física');

    expect(eh_res(eh_cargar($tipo, $admin, [['g' => 'EH0301', 'fa' => $fa, 'est' => 'Devolución ratificada']])))->toBe(['EH0301' => 'sin_cambio:hito_ya_registrado'])
        ->and(eh_hitos($a))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 1, 'recepciones' => 0])
        ->and(eh_intocables())->toBe($intocables);
});

test('Contradicciones y reportes antiguos: incidencia sin revertir nada; un reporte anterior no retrocede ni genera hitos; un hito anulado a mano no se vuelve a registrar', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0401');
    $b = eh_envio('EH0402');
    $t1 = eh_hoy(90);
    $t2 = eh_hoy(60);
    $t3 = eh_hoy(30);

    // A: Entregada (t2) → entregada y validada. B: Devolución ratificada (t2) → retorno.
    eh_cargar($tipo, $admin, [['g' => 'EH0401', 'fa' => $t2], ['g' => 'EH0402', 'fa' => $t2, 'est' => 'Devolución ratificada']]);
    $hitos = [eh_hitos($a), eh_hitos($b)];

    // Reporte ANTERIOR (t1) con el estado contrario: omitido por antiguo, sin hitos ni cambio de estado.
    $antigua = eh_cargar($tipo, $admin, [['g' => 'EH0401', 'fa' => $t1, 'est' => 'Devolución ratificada'], ['g' => 'EH0402', 'fa' => $t1, 'est' => 'Entregada']]);
    expect(eh_res($antigua))->toBe(['EH0401' => 'omitida_antigua:fecha_anterior_a_la_registrada', 'EH0402' => 'omitida_antigua:fecha_anterior_a_la_registrada'])
        ->and([eh_hitos($a), eh_hitos($b)])->toBe($hitos)
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $a['guia'])->value('estado_proveedor'))->toBe('Entregada');

    // Reporte POSTERIOR (t3) contradictorio: el estado reportado se actualiza (es lo que dice el proveedor), pero el hito NO se
    // registra ni se revierte el existente: incidencia CONTRADICCION_PROVEEDOR.
    $nueva = eh_cargar($tipo, $admin, [['g' => 'EH0401', 'fa' => $t3, 'est' => 'Devolución ratificada'], ['g' => 'EH0402', 'fa' => $t3, 'est' => 'Entregada']]);
    expect(eh_res($nueva))->toBe(['EH0401' => 'aplicada:contradiccion/CONTRADICCION_PROVEEDOR', 'EH0402' => 'aplicada:contradiccion/CONTRADICCION_PROVEEDOR'])
        ->and([eh_hitos($a), eh_hitos($b)])->toBe($hitos)
        ->and(ce_codigos($nueva))->toBe(['2:CONTRADICCION_PROVEEDOR:advertencia:ejecucion', '3:CONTRADICCION_PROVEEDOR:advertencia:ejecucion']);

    // Hito anulado a mano en Logística (corrección): repetir el reporte NO lo vuelve a registrar.
    $c = eh_envio('EH0403');
    eh_cargar($tipo, $admin, [['g' => 'EH0403', 'fa' => $t2, 'est' => 'Devolución ratificada']]);
    app(AnularEventoLogisticoService::class)->anularRetorno($admin, (int) DB::table('bylopez_lg_retornos')->where('shipment_id', $c['envio'])->value('id'), 'Corrección de prueba');
    $repetida = eh_cargar($tipo, $admin, [['g' => 'EH0403', 'fa' => $t2, 'est' => 'Devolución ratificada']]);
    expect(eh_res($repetida))->toBe(['EH0403' => 'sin_cambio:retorno_anulado_en_logistica/HITO_ANULADO_EN_LOGISTICA'])
        ->and(eh_hitos($c))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 0, 'recepciones' => 0]);
});

test('Fecha del proveedor anterior al despacho: la regla de Logística NO se flexibiliza; el estado se registra y la entrega queda como incidencia', function () {
    [$tipo, $admin] = eh_tipo();
    $a = eh_envio('EH0501');                                            // despachado hace 2 días

    $carga = eh_cargar($tipo, $admin, [['g' => 'EH0501', 'fa' => now()->subDays(5)->format('Y-m-d H:i:s')]]);
    $fila = DB::table(CE_TABLA)->where('cg_carga_id', $carga->id)->first();

    expect(eh_res($carga))->toBe(['EH0501' => 'aplicada:entrega_no_registrada/ENTREGA_NO_REGISTRADA'])
        ->and($fila->cg_mensaje)->toContain('fecha_anterior_al_despacho')
        ->and(eh_hitos($a))->toBe(['entregas' => 0, 'validaciones' => 0, 'retornos' => 0, 'recepciones' => 0])
        ->and(DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $a['guia'])->value('estado_proveedor'))->toBe('Entregada');
});
