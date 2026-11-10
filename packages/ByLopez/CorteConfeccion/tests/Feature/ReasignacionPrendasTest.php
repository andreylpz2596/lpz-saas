<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoReasignacion;
use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenTarifa;
use ByLopez\CorteConfeccion\Models\Reasignacion;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Plan de validacion, seccion 6.6: matriz R01-R23 de reasignacion de prendas.
| Escenario: 1.000 aptas (S 300 / M 400 / L 300), Jairo 700 y Andrea 300.
|--------------------------------------------------------------------------
*/

/** Denegado por ACL de ruta (401 del middleware de Bagisto) o por el gate/FormRequest (403). */
function rea_denegado(TestResponse $respuesta): void
{
    expect($respuesta->status())->toBeIn([401, 403]);
}

function rea(): ReasignacionService
{
    return app(ReasignacionService::class);
}

function rea_reportar(OrdenConfeccion $orden, array $cantidadesPorProducto): void
{
    app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), [
        'detalles' => collect($cantidadesPorProducto)->map(fn ($c, $productId) => [
            'id' => $orden->detalles()->where('product_id', $productId)->value('id'),
            'cantidad' => $c,
        ])->values()->all(),
    ]);
}

test('R01 100 M sin trabajo previo: Jairo 600, Andrea 400, total 1.000 y stock sin cambios', function () {
    $e = qa_escenario_maestro();
    $productos = [$e['S'], $e['M'], $e['L']];
    foreach ($productos as $p) {
        qa_sembrar_stock($p, 50);
    }
    $movimientosAntes = InventarioMovimiento::count();
    $stockAntes = array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos));

    expect(qa_total_taller($e['corte'], $e['jairo'], $productos))->toBe(700)
        ->and(qa_total_taller($e['corte'], $e['andrea'], $productos))->toBe(300);

    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['M'])->id, 100]]));

    expect($r->estado)->toBe(EstadoReasignacion::Aplicada->value)
        ->and($r->totalUnidades())->toBe(100)
        ->and(qa_total_taller($e['corte'], $e['jairo'], $productos))->toBe(600)
        ->and(qa_total_taller($e['corte'], $e['andrea'], $productos))->toBe(400)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(200)
        ->and(qa_vigente_taller($e['corte'], $e['andrea'], $e['M']))->toBe(200);

    // Andrea conserva su orden de 300 y suma otra orden de 100: ninguna se fusiona ni se reabre.
    expect(OrdenConfeccion::where('taller_id', $e['andrea']->id)->where('orden_corte_id', $e['corte']->id)->count())->toBe(2)
        ->and((int) $e['ordA']->fresh()->detalles->sum('cantidad_entregada'))->toBe(300)
        ->and((int) $e['ordJ']->fresh()->detalles->sum('cantidad_entregada'))->toBe(700);

    foreach ($productos as $p) {
        expect(qa_conservacion($e['corte'], $p))->toBe((int) DB::table('bylopez_cc_recepciones_corte_detalles')->where('product_id', $p->id)->sum('cantidad_recibida'));
    }

    expect(InventarioMovimiento::count())->toBe($movimientosAntes)
        ->and(array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos)))->toBe($stockAntes);
});

test('R02 mover toda una talla elegible deja esa variante en cero en el origen y no toca las demas', function () {
    $e = qa_escenario_maestro();

    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['S'])->id, 200]]));

    expect(qa_vigente_taller($e['corte'], $e['jairo'], $e['S']))->toBe(0)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(300)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['L']))->toBe(200)
        ->and(qa_vigente_taller($e['corte'], $e['andrea'], $e['S']))->toBe(300)
        ->and($e['ordJ']->fresh()->estado)->toBe(EstadoOrdenConfeccion::Activa->value);
});

test('R03 mover todo el saldo de una orden la deja en estado explicito «reasignada» con el historial intacto', function () {
    $e = qa_escenario_maestro();
    $lineas = $e['ordJ']->detalles->map(fn ($d) => [$d->id, (int) $d->cantidad_entregada])->all();

    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], $lineas));

    $origen = $e['ordJ']->fresh()->load('detalles');
    expect($origen->estado)->toBe(EstadoOrdenConfeccion::Reasignada->value)
        ->and($origen->detalles->sum(fn ($d) => $d->cantidad_vigente))->toBe(0)
        ->and((int) $origen->detalles->sum('cantidad_entregada'))->toBe(700)
        ->and($r->lineas)->toHaveCount(3)
        ->and($r->ordenDestino->detalles->sum('cantidad_entregada'))->toBe(700);

    // No es anulada: las entregas historicas NO vuelven al pool.
    expect(app(DisponibilidadConfeccionService::class)->resumenPorOrdenCorte($e['corte']->fresh())['total_pendiente'])->toBe(0);
});

test('R04 si Jairo ya reporto 250 de sus 300 M (aunque bodega no lo reciba) el maximo es 50', function () {
    $e = qa_escenario_maestro();
    rea_reportar($e['ordJ'], [$e['M']->id => 250]);
    $detalle = qa_detalle($e['ordJ'], $e['M']);

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, 51]])))->toThrow(ValidationException::class);

    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, 50]]));

    expect(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(250)
        ->and($e['ordJ']->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value)
        ->and(qa_detalle($e['ordJ'], $e['M'])->fresh()->pendientePorReportar())->toBe(0);
});

test('R05 prendas en proceso sin reportar no son saldo libre: se declaran, se excluyen y exigen verificacion fisica', function () {
    $e = qa_escenario_maestro();
    $detalle = qa_detalle($e['ordJ'], $e['M']); // 300 pendientes, 250 en proceso fisicamente

    // 100 + 250 en proceso > 300 pendientes.
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, 100, 250]])))->toThrow(ValidationException::class);

    // Ya entregadas al taller: sin la confirmacion fisica explicita se rechaza.
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, 50, 250]], ['situacion_fisica' => 'entregada_verificada'])))->toThrow(ValidationException::class);

    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, 50, 250]], ['situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true]));

    expect($r->lineas->first()->cantidad_en_proceso_declarada)->toBe(250)
        ->and($r->situacion_fisica)->toBe('entregada_verificada')
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(250);
});

test('R06 101 con elegible 100, cero, negativo y decimal se rechazan completos sin cambiar nada', function () {
    $e = qa_escenario_maestro();
    rea_reportar($e['ordJ'], [$e['M']->id => 200]); // elegible 100
    $detalle = qa_detalle($e['ordJ'], $e['M']);
    $antes = OrdenConfeccion::count();

    foreach ([101, 0, -5, '1.5', 1.5, 'abc', '', null] as $malo) {
        expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalle->id, $malo]])))
            ->toThrow(ValidationException::class);
    }

    expect(OrdenConfeccion::count())->toBe($antes)
        ->and(Reasignacion::count())->toBe(0)
        ->and((int) $detalle->fresh()->cantidad_reasignada_salida)->toBe(0);
});

test('R07 detalle ajeno, de otro corte o configurable se rechaza completo', function () {
    $e = qa_escenario_maestro();
    $detalleAndrea = qa_detalle($e['ordA'], $e['M']);

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalleAndrea->id, 10]])))->toThrow(ValidationException::class);
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[999999999, 10]])))->toThrow(ValidationException::class);

    $detalleJairo = qa_detalle($e['ordJ'], $e['M']);
    DB::table('products')->where('id', $e['M']->id)->update(['type' => 'configurable']);

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$detalleJairo->id, 10]])))->toThrow(ValidationException::class);

    expect(Reasignacion::count())->toBe(0);
});

test('R08 mismo taller, destino inactivo o inexistente se rechazan', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $inactivo = qa_taller(['estado' => 'inactivo']);

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['jairo'], [[$d->id, 10]])))->toThrow(ValidationException::class);
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $inactivo, [[$d->id, 10]])))->toThrow(ValidationException::class);
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 10]], ['taller_destino_id' => 987654321])))->toThrow(ValidationException::class);

    expect(Reasignacion::count())->toBe(0);
});

test('R09 el destino usa SU tarifa (o Sin tarifa) y nunca copia la del origen; el importe cambia visiblemente', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    $combinaciones = app(OrdenTarifaService::class)->combinaciones($e['ordJ']->fresh())['lineas'];
    $tipoTela = $combinaciones[0]['tipo_tela_id'];
    $familia = $combinaciones[0]['attribute_family_id'];

    // Jairo cobra 1.450 en su orden; Andrea no tiene tarifa para la combinacion.
    app(OrdenTarifaService::class)->pactar($e['ordJ']->fresh(), $tipoTela, $familia, 1450, 'Pacto QA', $e['admin']->id);

    $r1 = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 10]]));
    $filaDestino = OrdenTarifa::where('orden_confeccion_id', $r1->orden_confeccion_destino_id)->first();
    expect($filaDestino)->not->toBeNull()
        ->and($filaDestino->tarifa_unitaria)->toBeNull(); // Sin tarifa, no la de Jairo

    // Ahora Andrea cobra 1.600.
    DB::table('bylopez_cc_tarifas_prestador')->insert(['taller_id' => $e['andrea']->id, 'tipo_tela_id' => $tipoTela, 'attribute_family_id' => $familia, 'valor_unitario' => 1600, 'vigente_desde' => now()->subDay()->toDateString()]);

    $r2 = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 20]]));
    $resumenDestino = app(OrdenTarifaService::class)->resumen($r2->ordenDestino->fresh());
    expect((float) $resumenDestino['lineas'][0]['tarifa'])->toBe(1600.0)
        ->and($resumenDestino['lineas'][0]['cantidad'])->toBe(20)
        ->and($resumenDestino['total_referencial'])->toBe(32000.0);

    // El origen conserva su tarifa y refleja la cantidad VIGENTE (700 - 30 = 670).
    $resumenOrigen = app(OrdenTarifaService::class)->resumen($e['ordJ']->fresh());
    expect((float) $resumenOrigen['lineas'][0]['tarifa'])->toBe(1450.0)
        ->and($resumenOrigen['lineas'][0]['cantidad'])->toBe(670);
});

test('R10 varias lineas y una invalida: ningun movimiento parcial', function () {
    $e = qa_escenario_maestro();
    $s = qa_detalle($e['ordJ'], $e['S']);
    $m = qa_detalle($e['ordJ'], $e['M']);
    $antesOrdenes = OrdenConfeccion::count();

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$s->id, 50], [$m->id, 301]])))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::count())->toBe($antesOrdenes)
        ->and((int) $s->fresh()->cantidad_reasignada_salida)->toBe(0)
        ->and(Reasignacion::count())->toBe(0);
});

test('R11 doble envio con la misma llave: una sola reasignacion, una sola orden destino', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $payload = qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]);
    $antes = OrdenConfeccion::count();

    $a = rea()->reasignar($payload);
    $b = rea()->reasignar($payload);

    expect($b->id)->toBe($a->id)
        ->and(Reasignacion::count())->toBe(1)
        ->and(OrdenConfeccion::count())->toBe($antes + 1)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(200);
});

test('R11 por HTTP: reintentar el mismo POST no duplica y responde igual', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $payload = ['taller_destino_id' => $e['andrea']->id, 'idempotency_key' => 'http-'.uniqid(), 'motivo' => 'Retraso', 'situacion_fisica' => 'sin_entrega_fisica', 'lineas' => [['detalle_id' => $d->id, 'cantidad' => 100]]];
    $url = route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordJ']->id);

    $r1 = $this->actingAs($e['admin'], 'admin')->postJson($url, $payload)->assertOk();
    $r2 = $this->actingAs($e['admin'], 'admin')->postJson($url, $payload)->assertOk();

    expect($r2->json('reasignacion.id'))->toBe($r1->json('reasignacion.id'))
        ->and(Reasignacion::count())->toBe(1);
});

test('R12 misma llave con contenido distinto: conflicto y la operacion original no cambia', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $payload = qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]);
    $original = rea()->reasignar($payload);

    $otra = $payload;
    $otra['lineas'][0]['cantidad'] = 40;

    expect(fn () => rea()->reasignar($otra))->toThrow(ReasignacionConflictoException::class);

    $fresca = $original->fresh()->load('lineas');
    expect(Reasignacion::count())->toBe(1)
        ->and($fresca->lineas->first()->cantidad)->toBe(100)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(200);

    // Por HTTP el conflicto es 409.
    $this->actingAs($e['admin'], 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordJ']->id), [
        'taller_destino_id' => $e['andrea']->id, 'idempotency_key' => $payload['idempotency_key'], 'motivo' => $payload['motivo'],
        'situacion_fisica' => 'sin_entrega_fisica', 'lineas' => [['detalle_id' => $d->id, 'cantidad' => 40]],
    ])->assertStatus(409);
});

test('R13 reasignaciones sucesivas sobre el mismo saldo nunca superan lo elegible', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $tercero = qa_taller();

    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 200]]));
    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $tercero, [[$d->id, 101]])))->toThrow(ValidationException::class);
    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $tercero, [[$d->id, 100]]));

    expect(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(0)
        ->and(qa_conservacion($e['corte'], $e['M']))->toBe(400);
});

test('R14 reasignar frente a reportar, recibir, cancelar y distribuir mantiene consistencia', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]));

    // Reportar: ya solo quedan 200 vigentes.
    expect(fn () => rea_reportar($e['ordJ'], [$e['M']->id => 201]))->toThrow(ValidationException::class);
    rea_reportar($e['ordJ'], [$e['M']->id => 200]);

    // Recibir en bodega: cuadra y no altera responsabilidades.
    $retorno = $e['ordJ']->fresh()->retornos()->first();
    app(OrdenConfeccionService::class)->recibirBodega($retorno, ['detalles' => $retorno->detalles->map(fn ($x) => ['id' => $x->id, 'cantidad_recibida' => $x->cantidad_reportada, 'cantidad_defectuosa' => 0, 'cantidad_faltante' => 0])->all()]);

    // Cancelar: bloqueado por tener reasignacion vigente y por tener retornos.
    expect(fn () => app(OrdenConfeccionService::class)->cancel($e['ordJ']->fresh()))->toThrow(ValidationException::class);
    expect(fn () => app(OrdenConfeccionService::class)->cancel(OrdenConfeccion::where('reasignacion_origen_id', '!=', null)->firstOrFail()))->toThrow(ValidationException::class);

    // Distribuir: el pool global sigue en cero (la reasignacion mueve, no libera).
    expect(fn () => app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $e['corte']->id,
        'asignaciones' => [['taller_id' => $e['andrea']->id, 'detalles' => [['product_id' => $e['M']->id, 'cantidad' => 1]]]],
    ]))->toThrow(ValidationException::class);

    expect(qa_conservacion($e['corte'], $e['M']))->toBe(400);
});

test('R15 traslado fisico pendiente: las unidades no estan disponibles a la vez en origen y destino', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]], [
        'situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito',
    ]));

    expect($r->estado)->toBe(EstadoReasignacion::EnTransito->value);

    $destino = $r->ordenDestino;
    $detalleDestino = qa_detalle($destino, $e['M']);

    // Origen ya no las tiene; destino las tiene contablemente pero NO puede reportarlas todavia.
    expect(qa_detalle($e['ordJ'], $e['M'])->fresh()->pendientePorReportar())->toBe(200)
        ->and($detalleDestino->fresh()->pendientePorReportar())->toBe(0)
        ->and(fn () => rea_reportar($destino, [$e['M']->id => 1]))->toThrow(ValidationException::class);

    // Un ENT del origen no puede incluirlas.
    expect(fn () => rea_reportar($e['ordJ'], [$e['M']->id => 201]))->toThrow(ValidationException::class);

    rea()->confirmarRecepcion($r);

    expect($r->fresh()->estado)->toBe(EstadoReasignacion::Aplicada->value)
        ->and($detalleDestino->fresh()->pendientePorReportar())->toBe(100);
    rea_reportar($destino, [$e['M']->id => 100]);
    expect(qa_conservacion($e['corte'], $e['M']))->toBe(400);

    // Confirmar dos veces no vuelve a mover nada.
    expect(fn () => rea()->confirmarRecepcion($r))->toThrow(ValidationException::class);
});

test('R16 cancelacion antes de la recepcion y reversion posterior son compensaciones unicas y auditadas', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $productos = [$e['S'], $e['M'], $e['L']];

    $enTransito = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]], ['situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito']));
    $cancelada = rea()->revertir($enTransito, 'Error de digitacion', 'Devueltas por el conductor, remision 55', true);

    expect($cancelada->estado)->toBe(EstadoReasignacion::Cancelada->value)
        ->and($cancelada->motivo_cierre)->toBe('Error de digitacion')
        ->and($cancelada->constancia_cierre)->toBe('Devueltas por el conductor, remision 55')
        ->and($cancelada->cerrada_por)->not->toBeNull()
        ->and(qa_total_taller($e['corte'], $e['jairo'], $productos))->toBe(700)
        ->and(qa_total_taller($e['corte'], $e['andrea'], $productos))->toBe(300);

    expect(fn () => rea()->revertir($enTransito, 'otra vez'))->toThrow(ValidationException::class);

    $aplicada = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]));
    $revertida = rea()->revertir($aplicada, 'Jairo si alcanza');

    expect($revertida->estado)->toBe(EstadoReasignacion::Revertida->value)
        ->and(qa_total_taller($e['corte'], $e['jairo'], $productos))->toBe(700)
        ->and(qa_total_taller($e['corte'], $e['andrea'], $productos))->toBe(300)
        ->and($aplicada->fresh()->ordenDestino->estado)->toBe(EstadoOrdenConfeccion::Reasignada->value)
        ->and(Reasignacion::count())->toBe(2); // el historial no se borra
});

test('R17 reversion tras el reporte del destino queda bloqueada sin borrar ENT ni ingresos', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]]));
    rea_reportar($r->ordenDestino, [$e['M']->id => 30]);

    expect(fn () => rea()->revertir($r, 'ups'))->toThrow(ValidationException::class);

    expect($r->ordenDestino->fresh()->retornos()->count())->toBe(1)
        ->and($r->fresh()->estado)->toBe(EstadoReasignacion::Aplicada->value)
        ->and(qa_vigente_taller($e['corte'], $e['andrea'], $e['M']))->toBe(200);
});

test('R18 origen anulado/cerrado y peticiones directas sin permiso quedan bloqueadas', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);

    // Origen anulado.
    $anulada = $e['ordJ']->fresh();
    $anulada->update(['estado' => 'anulada']);
    expect(fn () => rea()->reasignar(qa_payload_reasignar($anulada, $e['andrea'], [[$d->id, 10]])))->toThrow(ValidationException::class);
    $anulada->update(['estado' => 'cerrada']);
    expect(fn () => rea()->reasignar(qa_payload_reasignar($anulada, $e['andrea'], [[$d->id, 10]])))->toThrow(ValidationException::class);
    $anulada->update(['estado' => 'activa']);

    $payload = ['taller_destino_id' => $e['andrea']->id, 'idempotency_key' => 'perm-'.uniqid(), 'motivo' => 'x', 'situacion_fisica' => 'sin_entrega_fisica', 'lineas' => [['detalle_id' => $d->id, 'cantidad' => 10]]];
    $url = route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordJ']->id);

    // Taller y cortador, aun con permission_type = all o con la ACL asignada por error.
    rea_denegado($this->actingAs($e['jairo']->usuario ?? qa_admin('TALLER CONFECCION', 'all'), 'admin')->postJson($url, $payload));
    rea_denegado($this->actingAs(qa_admin('CORTADOR', 'all'), 'admin')->postJson($url, $payload));
    rea_denegado($this->actingAs(qa_admin('CORTADOR', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.reasignar']), 'admin')->postJson($url, $payload));
    rea_denegado($this->actingAs(qa_admin('TALLER CONFECCION', 'custom', ['corte_confeccion.ordenes_confeccion.reasignar']), 'admin')->postJson($url, $payload));
    // Rol sin la ACL.
    rea_denegado($this->actingAs(qa_admin('Sin ACL', 'custom', ['corte_confeccion']), 'admin')->postJson($url, $payload));
    // Contexto, recibir y revertir tambien.
    rea_denegado($this->actingAs(qa_admin('Sin ACL 2', 'custom', ['corte_confeccion']), 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordJ']->id)));

    // Bodega con la ACL si puede.
    $bodega = qa_admin('Bodega', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.reasignar']);
    $ok = $this->actingAs($bodega, 'admin')->postJson($url, $payload)->assertOk();
    $reasignacionId = $ok->json('reasignacion.id');

    rea_denegado($this->actingAs(qa_admin('TALLER CONFECCION', 'all'), 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.revertir', $reasignacionId), ['motivo' => 'x']));
    rea_denegado($this->actingAs(qa_admin('CORTADOR', 'all'), 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.recibir', $reasignacionId)));

    // Revertir cuando el origen ya cerro: bloqueado.
    $origen = OrdenConfeccion::findOrFail($e['ordJ']->id);
    $origen->update(['estado' => 'cerrada']);
    expect(fn () => rea()->revertir(Reasignacion::findOrFail($reasignacionId), 'tarde'))->toThrow(ValidationException::class);
});

test('R19 con el corte padre ya cerrado la reasignacion es posible y no lo reabre', function () {
    $e = qa_escenario_maestro();
    expect($e['corte']->estado)->toBe(EstadoOrdenCorte::Cerrado->value);

    rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['M'])->id, 100]]));

    expect($e['corte']->fresh()->estado)->toBe(EstadoOrdenCorte::Cerrado->value);
});

test('R20 un fallo entre el descuento del origen y la creacion del destino revierte todo', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordJ'], $e['M']);
    $ordenesAntes = OrdenConfeccion::count();

    Event::listen(ReasignacionService::EVENTO_DESCONTADO, function () {
        throw new RuntimeException('fallo inducido');
    });

    expect(fn () => rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 100]])))->toThrow(RuntimeException::class);

    expect((int) $d->fresh()->cantidad_reasignada_salida)->toBe(0)
        ->and(Reasignacion::count())->toBe(0)
        ->and(OrdenConfeccion::count())->toBe($ordenesAntes)
        ->and(DB::table('bylopez_cc_reasignacion_lineas')->count())->toBe(0)
        ->and($e['ordJ']->fresh()->estado)->toBe(EstadoOrdenConfeccion::Activa->value);
});

test('R21 Andrea a un tercer taller: trazabilidad encadenada, conservacion y tarifa propia; la cadena se revierte en orden', function () {
    $e = qa_escenario_maestro();
    $tercero = qa_taller();
    $productos = [$e['S'], $e['M'], $e['L']];

    $r1 = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['M'])->id, 100]]));
    $r2 = rea()->reasignar(qa_payload_reasignar($r1->ordenDestino, $tercero, [[qa_detalle($r1->ordenDestino, $e['M'])->id, 40]]));

    expect($r2->taller_origen_id)->toBe($e['andrea']->id)
        ->and($r2->taller_destino_id)->toBe($tercero->id)
        ->and(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(200)
        ->and(qa_vigente_taller($e['corte'], $e['andrea'], $e['M']))->toBe(160)
        ->and(qa_vigente_taller($e['corte'], $tercero, $e['M']))->toBe(40)
        ->and(qa_conservacion($e['corte'], $e['M']))->toBe(400)
        ->and(qa_total_taller($e['corte'], $e['jairo'], $productos) + qa_total_taller($e['corte'], $e['andrea'], $productos) + qa_total_taller($e['corte'], $tercero, $productos))->toBe(1000);

    // r1 no se puede revertir mientras Andrea ya cedio 40 de esas 100 al tercero.
    expect(fn () => rea()->revertir($r1, 'x'))->toThrow(ValidationException::class);

    rea()->revertir($r2, 'Se devuelve al taller de Andrea');
    rea()->revertir($r1, 'Se devuelve a Jairo');

    expect(qa_vigente_taller($e['corte'], $e['jairo'], $e['M']))->toBe(300)
        ->and(qa_vigente_taller($e['corte'], $e['andrea'], $e['M']))->toBe(100)
        ->and(qa_vigente_taller($e['corte'], $tercero, $e['M']))->toBe(0);
});

test('R22 tablas, fichas y progresos muestran el mismo saldo neto tras recargar', function () {
    $e = qa_escenario_maestro();
    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['M'])->id, 100]]));

    // DataGrid de ordenes de confeccion
    $grid = $this->actingAs($e['admin'], 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.index'), ['X-Requested-With' => 'XMLHttpRequest']);
    $grid->assertOk();
    $filas = collect($grid->json('records'))->keyBy('id');
    expect((int) $filas[$e['ordJ']->id]['total_entregado'])->toBe(600)
        ->and((int) $filas[$r->orden_confeccion_destino_id]['total_entregado'])->toBe(100)
        ->and((int) $filas[$e['ordA']->id]['total_entregado'])->toBe(300);

    // Ficha de la orden origen y destino, corte y taller
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordJ']->id))->assertOk()->assertSee('Reasignaciones');

    // El layout admin no publica meta csrf-token: el modal debe llevar su propio token (hallazgo visual: sin el, el POST real daba 419).
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordJ']->id))->assertSee('data-csrf="', false)->assertSee('id="reasignar-modal"', false);
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $r->orden_confeccion_destino_id))->assertOk();
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $e['corte']->id))->assertOk();
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.talleres.show', $e['andrea']->id))->assertOk();

    // Nueva sesion: el mismo saldo desde la base.
    $nuevo = OrdenConfeccion::with('detalles')->find($e['ordJ']->id);
    expect($nuevo->detalles->sum(fn ($d) => $d->cantidad_vigente))->toBe(600);

    // Contexto del modal: elegible por linea coincide con el calculo central.
    $ctx = $this->actingAs($e['admin'], 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordJ']->id))->assertOk();
    $m = collect($ctx->json('lineas'))->firstWhere('product_id', $e['M']->id);
    expect($m['original'])->toBe(300)->and($m['cedida'])->toBe(100)->and($m['vigente'])->toBe(200)->and($m['elegible'])->toBe(200);
});

test('R23 recorrido maestro: 985 buenas + 8 defectuosas + 7 faltantes, stock +985 y cierre correcto', function () {
    $e = qa_escenario_maestro();
    $productos = [$e['S'], $e['M'], $e['L']];
    foreach ($productos as $p) {
        qa_sembrar_stock($p, 10);
    }
    $stockAntes = array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos));

    $r = rea()->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[qa_detalle($e['ordJ'], $e['M'])->id, 100]]));
    $ordR = $r->ordenDestino;

    // [orden, producto] => [buenas, defectuosas, faltantes]; cada linea suma lo reportado.
    $resultados = [
        [$e['ordJ'], $e['S'], 195, 3, 2], [$e['ordJ'], $e['M'], 198, 1, 1], [$e['ordJ'], $e['L'], 197, 1, 2],
        [$e['ordA'], $e['S'], 99, 1, 0], [$e['ordA'], $e['M'], 98, 1, 1], [$e['ordA'], $e['L'], 99, 0, 1],
        [$ordR, $e['M'], 99, 1, 0],
    ];

    foreach ([$e['ordJ'], $e['ordA'], $ordR] as $orden) {
        $cantidades = $orden->fresh()->detalles->mapWithKeys(fn ($d) => [$d->product_id => $d->cantidad_vigente])->all();
        rea_reportar($orden, $cantidades);
    }

    $totales = ['buenas' => 0, 'defectuosas' => 0, 'faltantes' => 0];
    foreach ([$e['ordJ'], $e['ordA'], $ordR] as $orden) {
        foreach ($orden->fresh()->retornos as $retorno) {
            $detalles = $retorno->detalles->map(function ($rd) use ($orden, $resultados, &$totales) {
                $fila = collect($resultados)->first(fn ($x) => $x[0]->id === $orden->id && $x[1]->id === $rd->product_id);
                $totales['buenas'] += $fila[2];
                $totales['defectuosas'] += $fila[3];
                $totales['faltantes'] += $fila[4];

                return ['id' => $rd->id, 'cantidad_recibida' => $fila[2], 'cantidad_defectuosa' => $fila[3], 'cantidad_faltante' => $fila[4], 'observacion_bodega' => 'QA diferencia'];
            })->all();

            app(OrdenConfeccionService::class)->recibirBodega($retorno, ['detalles' => $detalles]);
        }
    }

    expect($totales)->toBe(['buenas' => 985, 'defectuosas' => 8, 'faltantes' => 7])
        ->and(array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos)) - $stockAntes)->toBe(985)
        ->and(qa_stock_fisico($e['S']))->toBe(10 + 294)
        ->and(qa_stock_fisico($e['M']))->toBe(10 + 395)
        ->and(qa_stock_fisico($e['L']))->toBe(10 + 296);

    foreach ([$e['ordJ'], $e['ordA'], $ordR] as $orden) {
        expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);
    }

    // Faltantes y defectuosas NO regresan al saldo por confeccionar.
    foreach ($productos as $p) {
        expect(app(DisponibilidadConfeccionService::class)->disponiblePara(OrdenCorte::findOrFail($e['corte']->id), $p->id))->toBe(0);
    }
});

test('R23-control recorrido integro sin reasignacion: mismos totales y stock +985', function () {
    $e = qa_escenario_maestro();
    $productos = [$e['S'], $e['M'], $e['L']];
    foreach ($productos as $p) {
        qa_sembrar_stock($p, 10);
    }
    $stockAntes = array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos));

    // Jairo 300 M, Andrea 100 M (sin mover); mismas buenas por variante (S 294, M 395, L 296 = 985).
    $res = [
        [$e['ordJ'], $e['S'], 195, 3, 2], [$e['ordJ'], $e['M'], 296, 2, 2], [$e['ordJ'], $e['L'], 197, 1, 2],
        [$e['ordA'], $e['S'], 99, 1, 0], [$e['ordA'], $e['M'], 99, 0, 1], [$e['ordA'], $e['L'], 99, 0, 1],
    ];

    foreach ([$e['ordJ'], $e['ordA']] as $orden) {
        rea_reportar($orden, $orden->fresh()->detalles->mapWithKeys(fn ($d) => [$d->product_id => $d->cantidad_vigente])->all());

        foreach ($orden->fresh()->retornos as $retorno) {
            app(OrdenConfeccionService::class)->recibirBodega($retorno, ['detalles' => $retorno->detalles->map(function ($rd) use ($orden, $res) {
                $fila = collect($res)->first(fn ($x) => $x[0]->id === $orden->id && $x[1]->id === $rd->product_id);

                return ['id' => $rd->id, 'cantidad_recibida' => $fila[2], 'cantidad_defectuosa' => $fila[3], 'cantidad_faltante' => $fila[4], 'observacion_bodega' => 'QA'];
            })->all()]);
        }
    }

    expect(array_sum(array_map(fn ($p) => qa_stock_fisico($p), $productos)) - $stockAntes)->toBe(985)
        ->and($e['ordJ']->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value)
        ->and($e['ordA']->fresh()->estado)->toBe(EstadoOrdenConfeccion::Cerrada->value);
});
