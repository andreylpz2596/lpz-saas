<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoReasignacion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\Reasignacion;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Cierre de validacion (2026-10-31): H11 (anulacion por rol), custodia fisica de la reasignacion en transito y
| permiso `reasignar` de BODEGA (boton y endpoint).
|--------------------------------------------------------------------------
*/

function cv_orden_activa(): array
{
    $admin = qa_admin('Admin Cierre '.uniqid());
    $p = Product::factory()->simple()->create();
    $corte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($admin);
    $taller = qa_taller();
    $orden = app(OrdenConfeccionService::class)->create(['orden_corte_id' => $corte->id, 'taller_id' => $taller->id, 'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 40]]]);

    return compact('admin', 'p', 'corte', 'taller', 'orden');
}

function cv_foto(array $e): array
{
    return [
        'estado' => $e['orden']->fresh()->estado,
        'cantidades' => $e['orden']->fresh()->detalles->map(fn ($d) => [(int) $d->cantidad_entregada, (int) $d->cantidad_reasignada_salida])->all(),
        'disponible' => app(DisponibilidadConfeccionService::class)->disponiblePara($e['corte']->fresh(), $e['p']->id),
    ];
}

test('H11 el cortador no anula una orden de confeccion ajena por POST directo, ni con ACL operar ni con permission_type=all', function () {
    $e = cv_orden_activa();
    $antes = cv_foto($e);
    expect($antes['estado'])->toBe(EstadoOrdenConfeccion::Activa->value)->and($antes['disponible'])->toBe(60);

    $cortadores = [
        'cortador all' => qa_admin('CORTADOR', 'all'),
        'cortador operar' => qa_admin('Cortador', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.operar']),
    ];

    foreach ($cortadores as $nombre => $u) {
        $this->actingAs($u, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $e['orden']->id))->assertForbidden();
        expect(cv_foto($e))->toBe($antes, "{$nombre}: no debia cambiar nada");
    }
});

test('H11 un usuario autorizado si anula una orden activa y el disponible del corte se libera', function () {
    $e = cv_orden_activa();
    $bodega = qa_admin('Bodega Cierre', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.operar']);

    $this->actingAs($bodega, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $e['orden']->id))->assertRedirect();

    $despues = cv_foto($e);
    expect($despues['estado'])->toBe(EstadoOrdenConfeccion::Anulada->value)->and($despues['disponible'])->toBe(100);
});

test('H11 se conservan los bloqueos de anulacion con ENT reportado y con reasignacion vigente', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $bodega = qa_admin('Bodega Cierre', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.operar']);

    // Orden de Andrea con reporte (ENT): no se anula.
    app(OrdenConfeccionService::class)->reportarTaller($e['ordA']->fresh(), ['detalles' => [['id' => qa_detalle($e['ordA'], $e['M'])->id, 'cantidad' => 10]]]);
    $this->actingAs($bodega, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $e['ordA']->id));
    expect($e['ordA']->fresh()->estado)->not->toBe(EstadoOrdenConfeccion::Anulada->value);

    // Origen con reasignacion vigente: no se anula.
    $nueva = qa_escenario_maestro();
    Auth::guard('admin')->setUser($nueva['admin']);
    $r = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($nueva['ordJ'], $nueva['andrea'], [[qa_detalle($nueva['ordJ'], $nueva['M'])->id, 100]]));
    $this->actingAs($bodega, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $nueva['ordJ']->id));
    $this->actingAs($bodega, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.cancel', $r->orden_confeccion_destino_id));

    expect($nueva['ordJ']->fresh()->estado)->not->toBe(EstadoOrdenConfeccion::Anulada->value)
        ->and(OrdenConfeccion::find($r->orden_confeccion_destino_id)->estado)->not->toBe(EstadoOrdenConfeccion::Anulada->value);
});

test('custodia: 100 M en transito no son reportables por nadie y devolverlas exige constancia fisica, no basta pulsar revertir', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $bodega = qa_admin('Bodega Cierre', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reasignar']);

    $r = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]], [
        'situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito',
    ]));
    expect($r->estado)->toBe(EstadoReasignacion::EnTransito->value);

    $destino = $r->ordenDestino;
    $detalleDestino = qa_detalle($destino, $e['M']);

    // Nadie las reporta: el destino no puede (en transito) y el origen ya no las tiene (maximo 200).
    expect(fn () => app(OrdenConfeccionService::class)->reportarTaller($destino->fresh(), ['detalles' => [['id' => $detalleDestino->id, 'cantidad' => 1]]]))->toThrow(ValidationException::class)
        ->and(fn () => app(OrdenConfeccionService::class)->reportarTaller($e['ordJ']->fresh(), ['detalles' => [['id' => $d->id, 'cantidad' => 201]]]))->toThrow(ValidationException::class)
        ->and($d->fresh()->pendientePorReportar())->toBe(200)
        ->and($detalleDestino->fresh()->pendientePorReportar())->toBe(0);

    // Pulsar «cancelar traslado» (solo motivo) por HTTP o por servicio NO devuelve nada.
    $url = route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.revertir', $r->id);
    $this->actingAs($bodega, 'admin')->post($url, ['motivo' => 'me arrepenti'])->assertSessionHasErrors('devolucion_fisica_confirmada');
    $this->actingAs($bodega, 'admin')->post($url, ['motivo' => 'me arrepenti', 'devolucion_fisica_confirmada' => '1'])->assertSessionHasErrors('constancia_devolucion');
    expect(fn () => app(ReasignacionService::class)->revertir($r, 'solo motivo'))->toThrow(ValidationException::class);

    expect($r->fresh()->estado)->toBe(EstadoReasignacion::EnTransito->value)
        ->and($d->fresh()->pendientePorReportar())->toBe(200)
        ->and((int) $d->fresh()->cantidad_reasignada_salida)->toBe(100);

    // Con la constancia y la confirmacion fisica las prendas vuelven al saldo operativo de Jairo, una sola vez y auditadas.
    $this->actingAs($bodega, 'admin')->post($url, ['motivo' => 'me arrepenti', 'devolucion_fisica_confirmada' => '1', 'constancia_devolucion' => 'Devueltas por el conductor, remision 77'])->assertSessionHasNoErrors();

    $r = $r->fresh();
    expect($r->estado)->toBe(EstadoReasignacion::Cancelada->value)
        ->and($r->constancia_cierre)->toBe('Devueltas por el conductor, remision 77')
        ->and($r->cerrada_por)->toBe($bodega->id)
        ->and($d->fresh()->pendientePorReportar())->toBe(300);
});

test('custodia: sin salida fisica la reversion sigue sin exigir constancia (nada se movio del almacen)', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    $r = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]));
    $revertida = app(ReasignacionService::class)->revertir($r, 'Jairo si alcanza');

    expect($revertida->estado)->toBe(EstadoReasignacion::Revertida->value)->and($revertida->constancia_cierre)->toBeNull();
});

test('BODEGA: sin la ACL reasignar no hay boton ni endpoint; con la ACL ambos funcionan', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $base = ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.create', 'corte_confeccion.ordenes_confeccion.operar', 'corte_confeccion.ordenes_confeccion.recibir_bodega'];
    $sin = qa_admin('Bodega', 'custom', $base);
    $con = qa_admin('Bodega', 'custom', array_merge($base, ['corte_confeccion.ordenes_confeccion.reasignar']));
    $show = route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordJ']->id);
    $payload = fn () => qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 10]]);
    $antes = Reasignacion::count();

    $this->actingAs($sin, 'admin')->get($show)->assertOk()->assertDontSee('data-reasignar-abrir', false);

    foreach (['get' => route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordJ']->id), 'post' => route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordJ']->id)] as $metodo => $url) {
        $codigo = $this->actingAs($sin, 'admin')->{$metodo}($url, $metodo === 'post' ? $payload() : [])->status();
        expect($codigo)->toBeIn([401, 403]);
    }
    expect(Reasignacion::count())->toBe($antes);

    $this->actingAs($con, 'admin')->get($show)->assertOk()->assertSee('data-reasignar-abrir', false);
    $this->actingAs($con, 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordJ']->id))->assertOk();
    $this->actingAs($con, 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordJ']->id), $payload())->assertSuccessful();
    expect(Reasignacion::count())->toBe($antes + 1);
});
