<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\ContextoEntregaService;
use ByLopez\Logistica\Services\Entregas\ReporteProveedorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../../VentasAsesor/tests/helpersFase03b.php';

uses(TestCase::class);

/*
| Estado REPORTADO por el proveedor intermediario (99 Envios) en Logistica › Entregas. Solo LECTURA y solo informativo: no es
| entrega registrada ni validada por ByLopez ni un pago. Datos SINTETICOS (prefijo LGRP-), solo lpz_saas_testing.
*/

const RP_PREFIJO = 'LGRP-';

afterEach(function () {
    $ordenes = DB::table('orders')->where('increment_id', 'like', RP_PREFIJO.'%')->pluck('id');
    $guias = DB::table('bylopez_lg_guias')->whereIn('order_id', $ordenes)->pluck('id');
    DB::table('bylopez_lg_guia_estado_proveedor_eventos')->whereIn('guia_id', $guias)->delete();
    DB::table('bylopez_lg_guia_estados_proveedor')->whereIn('guia_id', $guias)->delete();
    DB::table('bylopez_lg_guias')->whereIn('id', $guias)->delete();
    DB::table('shipments')->whereIn('order_id', $ordenes)->delete();
    DB::table('orders')->whereIn('id', $ordenes)->delete();
});

/** Pedido + envio + guia sinteticos. Devuelve [envio, guia]. */
function rp_envio(string $numero, string $origen = '99envios'): array
{
    $orden = DB::table('orders')->insertGetId(['increment_id' => RP_PREFIJO.uniqid(), 'customer_first_name' => 'PRUEBA', 'customer_last_name' => 'SINTÉTICA', 'created_at' => now(), 'updated_at' => now()]);
    $envio = DB::table('shipments')->insertGetId(['order_id' => $orden, 'carrier_code' => 'INTER_RAPIDISIMO', 'track_number' => $numero, 'total_qty' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $guia = DB::table('bylopez_lg_guias')->insertGetId([
        'order_id' => $orden, 'shipment_id' => $envio, 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'numero_guia' => $numero,
        'origen_guia' => $origen, 'generada_por' => 'operaria', 'valor_flete' => 18000, 'flete_pagador' => 'bylopez',
        'recaudo_esperado' => 51000, 'recaudo_estado' => 'pendiente_liquidar', 'actor_admin_id' => 1, 'actor_name_snapshot' => 'Prueba',
        'idempotency_key' => RP_PREFIJO.uniqid('', true), 'huella' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$envio, $guia];
}

function rp_reporte(int $guia, string $numero, string $estado, string $conciliacion = 'coincide', ?string $reportado = '51000.00'): void
{
    DB::table('bylopez_lg_guia_estados_proveedor')->insert([
        'guia_id' => $guia, 'proveedor' => '99envios', 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'numero_guia' => $numero, 'estado_proveedor' => $estado,
        'fecha_estado_proveedor' => '2026-10-02 15:01:38', 'fecha_envio_proveedor' => '2026-09-29 17:08:18', 'valor_comercial_reportado' => 76000,
        'valor_servicio_reportado' => '24821.54', 'valor_seguro_reportado' => 3396, 'seguro_reportado' => 'Seguro 99', 'aplica_contrapago_reportado' => 1,
        'recaudo_reportado' => $reportado, 'recaudo_interno' => 51000, 'recaudo_diferencia' => $reportado === null ? null : $reportado - 51000,
        'conciliacion_recaudo' => $conciliacion, 'carga_id' => 6, 'fila_origen' => 23, 'reportes' => 1, 'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00',
    ]);
    DB::table('bylopez_lg_guia_estado_proveedor_eventos')->insert([
        'guia_id' => $guia, 'proveedor' => '99envios', 'estado_proveedor' => $estado, 'fecha_estado_proveedor' => '2026-10-02 15:01:38', 'fecha_envio_proveedor' => '2026-09-29 17:08:18',
        'estado_anterior' => null, 'recaudo_reportado' => $reportado, 'recaudo_interno' => 51000, 'recaudo_diferencia' => $reportado === null ? null : $reportado - 51000, 'conciliacion_recaudo' => $conciliacion, 'carga_id' => 6, 'fila_origen' => 23, 'created_at' => '2026-10-18 10:00:00',
    ]);
}

test('un reporte aplicado deja la guía «cruzada»; sin reporte NO lo está y el listado lo distingue de las guías que no son de 99 Envíos', function () {
    [$e1, $g1] = rp_envio('LGRP0001');
    [$e2] = rp_envio('LGRP0002');
    [$e3] = rp_envio('LGRP0003', 'manual');
    rp_reporte($g1, 'LGRP0001', 'Entregada');

    $r = app(ReporteProveedorService::class)->resumenPorEnvios([$e1, $e2, $e3]);

    expect($r[$e1])->toMatchArray(['cruzada' => true, 'esperada' => true, 'estado' => 'Entregada', 'discrepancia' => false])
        ->and($r[$e2])->toMatchArray(['cruzada' => false, 'esperada' => true])        // 99 Envios, aun sin reporte → «Sin reporte de 99 Envíos»
        ->and($r[$e3])->toMatchArray(['cruzada' => false, 'esperada' => false]);       // otra guia: el listado no dice nada
});

test('la discrepancia de recaudo se avisa en el resumen y el detalle separa estado, fecha del estado, último cruce, recaudos y cargos', function () {
    [$e, $g] = rp_envio('LGRP0010');
    rp_reporte($g, 'LGRP0010', 'Reclame en oficina', 'difiere', '76000.00');

    expect(app(ReporteProveedorService::class)->resumenPorEnvios([$e])[$e]['discrepancia'])->toBeTrue();

    $d = app(ReporteProveedorService::class)->detalleDeEnvio($e);

    expect($d)->toMatchArray(['estado' => 'Reclame en oficina', 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'proveedor' => '99envios', 'carga_id' => 6, 'fila_origen' => 23])
        ->and($d['fecha_estado'])->toBe('2026-10-02 15:01:38')
        ->and($d['ultimo_cruce'])->toBe('2026-10-18 10:00:00')               // cuando se aplico en ByLopez ≠ fecha del estado
        ->and($d['recaudo']['reportado'])->toBe('76000.00')->and($d['recaudo']['interno'])->toBe('51000.00')->and($d['recaudo']['conciliacion'])->toBe('difiere')
        ->and($d['valor_servicio'])->toBe('24821.54')->and($d)->not->toHaveKey('flete')   // el valor del servicio no se llama «flete»
        ->and($d['historial'])->toHaveCount(1);
    expect(app(ReporteProveedorService::class)->detalleDeEnvio($e + 999999))->toBeNull();
});

test('el reporte NO cambia la entrega: sin eventos de Logística el envío sigue pendiente aunque 99 Envíos diga «Entregada»', function () {
    [$e, $g] = rp_envio('LGRP0020');
    rp_reporte($g, 'LGRP0020', 'Entregada');
    $antes = [DB::table('bylopez_lg_entregas')->count(), DB::table('bylopez_lg_validaciones_entrega')->count(), (string) DB::selectOne('CHECKSUM TABLE bylopez_lg_guias')->Checksum];

    $fila = app(ConsultaEntregaService::class)->listar(['estado' => 'todos', 'q' => 'LGRP0020'])->getCollection()->first();

    expect($fila->reporte_proveedor['cruzada'])->toBeTrue()
        ->and($fila->entregadas)->toEqual(0)->and($fila->estado_entrega)->toBe('pendiente')->and($fila->validacion)->not->toBe('validada')
        ->and([DB::table('bylopez_lg_entregas')->count(), DB::table('bylopez_lg_validaciones_entrega')->count(), (string) DB::selectOne('CHECKSUM TABLE bylopez_lg_guias')->Checksum])->toBe($antes);
});

test('el drawer (contexto) trae el reporte con la transportadora efectiva y el listado muestra el indicador solo con permiso de Entregas', function () {
    [$e, $g] = rp_envio('LGRP0030');
    rp_reporte($g, 'LGRP0030', 'Entregada', 'difiere', '76000.00');
    $admin = f03b_admin('custom', ['logistica', 'logistica.entregas']);

    $ctx = app(ContextoEntregaService::class)->paraEnvio($e, $admin);

    expect($ctx['reporte_proveedor']['estado'])->toBe('Entregada')->and($ctx['reporte_proveedor']['transportadora_nombre'])->toBeString()
        ->and($ctx['reporte_proveedor']['fecha_estado_texto'])->toBeString()->and($ctx['reporte_proveedor']['ultimo_cruce_texto'])->toBeString()
        ->and($ctx['estado_entrega'])->toBe('pendiente');

    $html = test()->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos', 'q' => 'LGRP0030']));
    $html->assertOk()->assertSee('data-reporte-proveedor="cruzada"', false)->assertSee('data-reporte-proveedor-aviso="discrepancia"', false)
        ->assertSee('data-logistica="reporte-proveedor"', false);

    // Sin la clave de Entregas no se ve nada (Cargues no se concede por ver Logística, y tampoco al reves).
    $sin = f03b_admin('custom', ['logistica']);
    expect(fn () => app(ContextoEntregaService::class)->paraEnvio($e, $sin))->toThrow(OperacionLogisticaInvalidaException::class);
});

test('sin reporte el drawer lo dice: reporte_proveedor es null y la vista muestra «Sin reporte de 99 Envíos»', function () {
    [$e] = rp_envio('LGRP0040');
    $admin = f03b_admin('custom', ['logistica', 'logistica.entregas']);

    expect(app(ContextoEntregaService::class)->paraEnvio($e, $admin)['reporte_proveedor'])->toBeNull();

    test()->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos', 'q' => 'LGRP0040']))
        ->assertOk()->assertSee('data-reporte-proveedor="sin-reporte"', false)->assertSee('Sin reporte de 99 Envíos');
});
