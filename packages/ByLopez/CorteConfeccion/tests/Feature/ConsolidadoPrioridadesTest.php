<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Exceptions\ReasignacionConflictoException;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Models\Notificacion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\PrioridadCambio;
use ByLopez\CorteConfeccion\Models\PrioridadOperacion;
use ByLopez\CorteConfeccion\Services\ConsolidadoConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\PrioridadConfeccionService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Doc. 09: consolidado general, prioridad por cantidad y avisos en la campana de Bagisto.
| Escenario maestro: Jairo S200/M300/L200 y Andrea S100/M100/L100 (todo Normal al asignar).
|--------------------------------------------------------------------------
*/

function pc(): PrioridadConfeccionService
{
    return app(PrioridadConfeccionService::class);
}

function pc_cambio(OrdenConfeccionDetalle $d, int $cantidad, string $desde, string $hacia, array $over = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'nota' => 'Cliente mayorista pide primero',
        'cambios' => [['detalle_id' => $d->id, 'cantidad' => $cantidad, 'desde' => $desde, 'hacia' => $hacia]],
    ], $over);
}

function pc_reportar(OrdenConfeccion $orden, OrdenConfeccionDetalle $d, int $cantidad)
{
    return app(OrdenConfeccionService::class)->reportarTaller($orden->fresh(), ['detalles' => [['id' => $d->id, 'cantidad' => $cantidad]]]);
}

function pc_reparto(OrdenConfeccionDetalle $d): array
{
    return OrdenConfeccionDetalle::query()->findOrFail($d->id)->prioridadesPorConfeccionar();
}

function pc_consolidado(?array $alcance = null, array $filtros = []): array
{
    return app(ConsolidadoConfeccionService::class)->consultar($alcance, $filtros);
}

test('P01 la prioridad elegida al distribuir nace con TODA la cantidad (urgente y alta), Normal es el resto', function () {
    $e = qa_escenario_maestro();
    $X = Product::factory()->simple()->create();
    $corte = qa_orden_corte_recibida([['product' => $X, 'cantidad_recibida' => 90, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($e['admin']);

    $orden = app(OrdenConfeccionService::class)->distribuir(['orden_corte_id' => $corte->id, 'asignaciones' => [
        ['taller_id' => $e['jairo']->id, 'detalles' => [['product_id' => $X->id, 'cantidad' => 90, 'prioridad' => 'urgente']]],
    ]])->first();

    $d = $orden->detalles->first();

    expect([(int) $d->cantidad_urgente, (int) $d->cantidad_alta])->toBe([90, 0])
        ->and(pc_reparto($d))->toBe(['urgente' => 90, 'alta' => 0, 'normal' => 0])
        ->and(pc_reparto(qa_detalle($e['ordJ'], $e['M'])))->toBe(['urgente' => 0, 'alta' => 0, 'normal' => 300]);
});

test('P02 de 80 Normal se pasan 50 a Alta: quedan 50 Alta y 30 Normal, con historial y alerta al taller; nada mas cambia', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']);
    pc_reportar($e['ordA'], $d, 20); // quedan 80 por confeccionar

    $antes = [
        'entregada' => (int) $d->fresh()->cantidad_entregada,
        'movimientos' => InventarioMovimiento::count(),
        'tarifas' => DB::table('bylopez_cc_orden_tarifas')->count(),
        'cartera' => DB::table('bylopez_cc_cartera_movimientos')->count(),
    ];

    $r = pc()->cambiar(pc_cambio($d, 50, 'normal', 'alta'));

    expect(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 50, 'normal' => 30])
        ->and($r['repetida'])->toBeFalse()
        ->and($r['operacion']->notificacion_estado)->toBe(PrioridadOperacion::NOTIFICADO)
        ->and((int) $r['operacion']->usuario_id)->toBe((int) $e['admin']->id);

    $cambio = PrioridadCambio::query()->where('orden_confeccion_detalle_id', $d->id)->sole();
    expect([$cambio->cantidad, $cambio->prioridad_anterior, $cambio->prioridad_nueva])->toBe([50, 'normal', 'alta'])
        ->and($cambio->operacion->nota)->toBe('Cliente mayorista pide primero');

    $aviso = Notificacion::query()->where('prioridad_operacion_id', $r['operacion']->id)->sole();
    expect((int) $aviso->admin_id)->toBe((int) $e['andrea']->usuario_id)
        ->and($aviso->datos['cambios'][0])->toMatchArray(['cantidad' => 50, 'desde' => 'normal', 'hacia' => 'alta', 'orden_codigo' => $e['ordA']->codigo])
        ->and($aviso->datos['usuario'])->toBe($e['admin']->name);

    // Ni asignacion, ni inventario, ni tarifas, ni cartera.
    expect([
        'entregada' => (int) $d->fresh()->cantidad_entregada,
        'movimientos' => InventarioMovimiento::count(),
        'tarifas' => DB::table('bylopez_cc_orden_tarifas')->count(),
        'cartera' => DB::table('bylopez_cc_cartera_movimientos')->count(),
    ])->toBe($antes);
});

test('P03 cantidades invalidas, por encima del saldo o sin cambio real se rechazan sin historial ni alerta', function (mixed $cantidad, string $desde, string $hacia) {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']); // 100 Normal

    expect(fn () => pc()->cambiar(pc_cambio($d, 1, $desde, $hacia, ['cambios' => [['detalle_id' => $d->id, 'cantidad' => $cantidad, 'desde' => $desde, 'hacia' => $hacia]]])))
        ->toThrow(ValidationException::class);

    expect(PrioridadOperacion::count())->toBe(0)
        ->and(Notificacion::count())->toBe(0)
        ->and(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 0, 'normal' => 100]);
})->with([
    'decimal' => ['1.5', 'normal', 'alta'],
    'cero' => [0, 'normal', 'alta'],
    'negativo' => [-5, 'normal', 'alta'],
    'texto' => ['diez', 'normal', 'alta'],
    'excede el saldo' => [101, 'normal', 'alta'],
    'origen sin saldo' => [1, 'urgente', 'alta'],
    'misma prioridad' => [10, 'normal', 'normal'],
    'prioridad inexistente' => [10, 'normal', 'critica'],
]);

test('P04 doble clic / reintento con la misma llave: una sola operacion y una sola alerta; otra carga con la misma llave es conflicto', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']);
    $payload = pc_cambio($d, 30, 'normal', 'urgente');

    $primera = pc()->cambiar($payload);
    $segunda = pc()->cambiar($payload);

    expect($segunda['repetida'])->toBeTrue()
        ->and($segunda['operacion']->id)->toBe($primera['operacion']->id)
        ->and(PrioridadOperacion::count())->toBe(1)
        ->and(Notificacion::count())->toBe(1)
        ->and(pc_reparto($d))->toBe(['urgente' => 30, 'alta' => 0, 'normal' => 70]);

    $payload['cambios'][0]['cantidad'] = 31;
    expect(fn () => pc()->cambiar($payload))->toThrow(ReasignacionConflictoException::class);
    expect(Notificacion::count())->toBe(1);
});

test('P05 regla P1 al reportar: se sugiere Urgente -> Alta -> Normal, el taller puede ajustar y el reparto confirmado queda guardado', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']); // 100
    pc()->cambiar(pc_cambio($d, 20, 'normal', 'urgente'));
    pc()->cambiar(pc_cambio($d, 30, 'normal', 'alta')); // U20 A30 N50

    // Sin reparto: el sugerido «20 Urgentes + 20 Altas».
    $r1 = pc_reportar($e['ordA'], $d, 40);
    expect(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 10, 'normal' => 50])
        ->and([(int) $r1->detalles->first()->cantidad_urgente, (int) $r1->detalles->first()->cantidad_alta])->toBe([20, 20])
        ->and(PrioridadConfeccionService::repartoTexto(['urgente' => 20, 'alta' => 20, 'normal' => 0]))->toBe('20 Urgentes + 20 Altas');

    // El taller confecciono Normal, no la Alta: ajusta el reparto.
    $r2 = app(OrdenConfeccionService::class)->reportarTaller($e['ordA']->fresh(), ['detalles' => [[
        'id' => $d->id, 'cantidad' => 25, 'urgente' => 0, 'alta' => '0', 'normal' => '25', 'base' => '0:10:50',
    ]]]);
    expect(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 10, 'normal' => 25])
        ->and([(int) $r2->detalles->first()->cantidad_urgente, (int) $r2->detalles->first()->cantidad_alta])->toBe([0, 0]);

    $retornos = DB::table('bylopez_cc_ordenes_confeccion_retornos')->count();
    $rechazos = [
        'suma distinta' => ['cantidad' => 5, 'urgente' => 0, 'alta' => 1, 'normal' => 1],
        'excede un nivel' => ['cantidad' => 11, 'urgente' => 0, 'alta' => 11, 'normal' => 0],
        'negativo' => ['cantidad' => 5, 'urgente' => 0, 'alta' => -1, 'normal' => 6],
        'saldo cambio entretanto' => ['cantidad' => 5, 'base' => '0:30:50'],
    ];

    foreach ($rechazos as $caso => $entrada) {
        expect(fn () => app(OrdenConfeccionService::class)->reportarTaller($e['ordA']->fresh(), ['detalles' => [array_merge(['id' => $d->id], $entrada)]]))
            ->toThrow(ValidationException::class);
    }

    // Todo o nada: ningun rechazo dejo reporte ni movio prioridades.
    $fresco = $d->fresh();
    expect(DB::table('bylopez_cc_ordenes_confeccion_retornos')->count())->toBe($retornos)
        ->and(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 10, 'normal' => 25])
        ->and((int) $fresco->cantidad_urgente + (int) $fresco->cantidad_alta)->toBeLessThanOrEqual($fresco->saldoSinReportar());
});

test('P05b los reportes anteriores a P1 conservan reparto null y no se reinterpretan', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']);
    $retorno = pc_reportar($e['ordA'], $d, 10);
    DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('retorno_id', $retorno->id)->update(['cantidad_urgente' => null, 'cantidad_alta' => null]);

    pc()->cambiar(pc_cambio($d, 30, 'normal', 'urgente'));
    pc_reportar($e['ordA'], $d, 5);

    expect(DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('retorno_id', $retorno->id)->value('cantidad_urgente'))->toBeNull()
        ->and((int) DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles')->where('retorno_id', $retorno->id)->value('cantidad_reportada'))->toBe(10);

    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordA']->id))
        ->assertOk()->assertSee('Reparto por prioridad no registrado (reporte anterior)')->assertSee('5 Urgentes');
});

test('P06 reasignar traslada el reparto elegido (sugerido Urgente primero), el destino lo conserva y el reverso devuelve el REGISTRADO', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordJ'], $e['M']); // 300
    pc()->cambiar(pc_cambio($d, 100, 'normal', 'urgente')); // U100 N200

    // Sugerido: 100 Urgentes + 150 Normales.
    $ra = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 250]]));
    $destino = OrdenConfeccionDetalle::query()->findOrFail($ra->lineas->first()->detalle_destino_id);

    expect(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 0, 'normal' => 50])
        ->and(pc_reparto($destino))->toBe(['urgente' => 100, 'alta' => 0, 'normal' => 150])
        ->and([(int) $ra->lineas->first()->cantidad_urgente, (int) $ra->lineas->first()->cantidad_alta])->toBe([100, 0])
        ->and((int) OrdenConfeccionDetalle::query()->whereIn('id', [$d->id, $destino->id])->sum('cantidad_urgente'))->toBe(100);

    // Despues el administrador baja la urgencia del destino: el reverso NO usa eso, devuelve lo registrado.
    pc()->cambiar(pc_cambio($destino, 60, 'urgente', 'normal'));
    app(ReasignacionService::class)->revertir($ra, 'Jairo si alcanza');

    expect(pc_reparto($d))->toBe(['urgente' => 100, 'alta' => 0, 'normal' => 200])
        ->and((int) $destino->fresh()->cantidad_urgente)->toBe(0)
        ->and((int) $destino->fresh()->cantidad_alta)->toBe(0);
});

test('P06b reasignar con reparto elegido; reparto invalido o saldo cambiado se rechaza sin mover nada', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordJ'], $e['M']); // 300
    pc()->cambiar(pc_cambio($d, 100, 'normal', 'urgente'));
    pc()->cambiar(pc_cambio($d, 50, 'normal', 'alta')); // U100 A50 N150

    $antes = DB::table('bylopez_cc_reasignaciones')->count();
    $rechazos = [
        'suma distinta' => ['urgente' => 10, 'alta' => 0, 'normal' => 0],
        'excede alta' => ['urgente' => 0, 'alta' => 60, 'normal' => 0],
        'base vieja' => ['base' => '100:0:200'],
    ];

    foreach ($rechazos as $extra) {
        $payload = qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 60]]);
        $payload['lineas'][0] = array_merge($payload['lineas'][0], $extra);
        expect(fn () => app(ReasignacionService::class)->reasignar($payload))->toThrow(ValidationException::class);
    }

    expect(DB::table('bylopez_cc_reasignaciones')->count())->toBe($antes)
        ->and(pc_reparto($d))->toBe(['urgente' => 100, 'alta' => 50, 'normal' => 150]);

    // Elegido: 40 Altas + 20 Normales (no las urgentes).
    $payload = qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 60]]);
    $payload['lineas'][0] = array_merge($payload['lineas'][0], ['urgente' => '0', 'alta' => '40', 'normal' => '20', 'base' => '100:50:150']);
    $ra = app(ReasignacionService::class)->reasignar($payload);
    $destino = OrdenConfeccionDetalle::query()->findOrFail($ra->lineas->first()->detalle_destino_id);

    expect(pc_reparto($d))->toBe(['urgente' => 100, 'alta' => 10, 'normal' => 130])
        ->and(pc_reparto($destino))->toBe(['urgente' => 0, 'alta' => 40, 'normal' => 20]);

    // El mismo envio repetido (doble clic) devuelve la misma reasignacion.
    expect(app(ReasignacionService::class)->reasignar($payload)->id)->toBe($ra->id)
        ->and(pc_reparto($d))->toBe(['urgente' => 100, 'alta' => 10, 'normal' => 130]);
});

test('P07 en transito: el destino no lo cuenta por confeccionar ni como prioridad; el consolidado lo muestra aparte', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordJ'], $e['S']); // 200
    pc()->cambiar(pc_cambio($d, 200, 'normal', 'alta'));

    $ra = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordJ'], $e['andrea'], [[$d->id, 60]], [
        'situacion_fisica' => 'entregada_verificada', 'confirmacion_fisica' => true, 'traslado' => 'en_transito',
    ]));

    $andrea = pc_consolidado([$e['andrea']->id], ['producto' => $e['S']->id]);
    $jairo = pc_consolidado([$e['jairo']->id], ['producto' => $e['S']->id]);

    expect($andrea['totales'])->toMatchArray(['por_confeccionar' => 100, 'en_transito' => 60, 'alta' => 0])
        ->and($jairo['totales'])->toMatchArray(['por_confeccionar' => 140, 'alta' => 140, 'en_transito' => 0]);

    app(ReasignacionService::class)->confirmarRecepcion($ra);
    $andrea = pc_consolidado([$e['andrea']->id], ['producto' => $e['S']->id]);

    expect($andrea['totales'])->toMatchArray(['por_confeccionar' => 160, 'en_transito' => 0, 'alta' => 60]);
});

test('P08 consolidado: varias entregas no duplican; defectuosas resueltas no reaparecen; terminada sigue visible hasta que bodega reciba', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $dS = qa_detalle($e['ordA'], $e['S']);
    $dM = qa_detalle($e['ordA'], $e['M']);
    $dL = qa_detalle($e['ordA'], $e['L']);

    $r1 = pc_reportar($e['ordA'], $dS, 30);
    pc_reportar($e['ordA'], $dS, 20);
    app(OrdenConfeccionService::class)->recibirBodega($r1, ['detalles' => [[
        'id' => $r1->detalles->first()->id, 'cantidad_recibida' => 25, 'cantidad_defectuosa' => 3, 'cantidad_faltante' => 2, 'observacion_bodega' => 'manchas',
    ]]]);

    $c = pc_consolidado([$e['andrea']->id]);
    $s = $c['grupos']->firstWhere('product_id', $e['S']->id);

    // 100 - 50 reportadas = 50 por confeccionar; solo la 2.a entrega (20) sigue pendiente de bodega; defectos no vuelven.
    expect($s['por_confeccionar'])->toBe(50)
        ->and($s['pendiente_recibir'])->toBe(20)
        ->and($c['totales'])->toMatchArray(['por_confeccionar' => 250, 'pendiente_recibir' => 20, 'variantes' => 3, 'ordenes' => 1]);

    // Terminada por el taller: sigue en el consolidado mientras bodega no reciba.
    pc_reportar($e['ordA'], $dS, 50);
    pc_reportar($e['ordA'], $dM, 100);
    pc_reportar($e['ordA'], $dL, 100);
    expect($e['ordA']->fresh()->estado)->toBe('terminada_taller');

    $c = pc_consolidado([$e['andrea']->id]);
    expect($c['totales'])->toMatchArray(['por_confeccionar' => 0, 'pendiente_recibir' => 270, 'variantes' => 3]);
});

test('P09 los indicadores cubren TODO lo filtrado (no la pagina) y los filtros de talla, prioridad y texto se combinan', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    DB::table('bylopez_cc_ordenes_confeccion_detalles')->whereIn('id', [qa_detalle($e['ordJ'], $e['S'])->id, qa_detalle($e['ordA'], $e['S'])->id])->update(['talla' => '14']);
    DB::table('bylopez_cc_ordenes_confeccion_detalles')->whereIn('id', [qa_detalle($e['ordJ'], $e['M'])->id, qa_detalle($e['ordA'], $e['M'])->id])->update(['talla' => '16']);
    pc()->cambiar(pc_cambio(qa_detalle($e['ordJ'], $e['M']), 40, 'normal', 'urgente'));

    $alcance = [$e['jairo']->id, $e['andrea']->id];
    $todo = pc_consolidado($alcance);
    $tallas = pc_consolidado($alcance, ['tallas' => ['14', '16']]);
    $urgentes = pc_consolidado($alcance, ['tallas' => ['14', '16'], 'prioridad' => 'urgente']);
    $texto = pc_consolidado($alcance, ['q' => 'JAIRO', 'tallas' => ['16']]);

    expect($todo['totales'])->toMatchArray(['por_confeccionar' => 1000, 'variantes' => 6])
        ->and($tallas['totales'])->toMatchArray(['por_confeccionar' => 700, 'variantes' => 4, 'urgente' => 40])
        ->and($urgentes['totales'])->toMatchArray(['por_confeccionar' => 300, 'variantes' => 1, 'urgente' => 40])
        ->and($texto['totales'])->toMatchArray(['por_confeccionar' => 300, 'variantes' => 1]);
});

test('P10 un producto inactivo conserva sus pendientes historicos en el consolidado', function () {
    $e = qa_escenario_maestro();
    DB::table('product_flat')->where('product_id', $e['L']->id)->update(['status' => 0]);

    $l = pc_consolidado([$e['jairo']->id])['grupos']->firstWhere('product_id', $e['L']->id);

    expect($l)->not->toBeNull()->and($l['por_confeccionar'])->toBe(200);
});

test('P11 sin usuario vinculado: el cambio queda registrado, nadie es «notificado» y el administrador lo sabe', function () {
    $e = qa_escenario_maestro();
    $e['andrea']->update(['usuario_id' => null]);

    $respuesta = $this->actingAs($e['admin'], 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.prioridad'), pc_cambio(qa_detalle($e['ordA'], $e['M']), 10, 'normal', 'alta'));

    $respuesta->assertOk()->assertJson(['notificado' => false]);
    expect($respuesta->json('message'))->toContain('nadie recibió la alerta')
        ->and(PrioridadOperacion::sole()->notificacion_estado)->toBe(PrioridadOperacion::SIN_DESTINATARIO)
        ->and(Notificacion::count())->toBe(0);
});

test('P12 aislamiento por VINCULO: un rol con otro nombre vinculado a un taller solo ve el suyo y nunca cambia prioridades', function () {
    $e = qa_escenario_maestro();
    $satelite = qa_admin('Satélite Norte '.uniqid(), 'all');
    $e['andrea']->update(['usuario_id' => $satelite->id]);

    $pagina = $this->actingAs($satelite, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'));
    $pagina->assertOk()->assertSee($e['ordA']->codigo)->assertDontSee($e['ordJ']->codigo);

    // Ni filtrando por el otro taller.
    $this->actingAs($satelite, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado', ['taller' => $e['jairo']->id]))
        ->assertOk()->assertDontSee($e['ordJ']->codigo);

    $this->actingAs($satelite, 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.prioridad'), pc_cambio(qa_detalle($e['ordA'], $e['M']), 10, 'normal', 'alta'))
        ->assertForbidden();

    // La ficha y el listado de ordenes tambien quedan aislados por el vinculo.
    $this->actingAs($satelite, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordJ']->id))->assertForbidden();

    expect(PrioridadOperacion::count())->toBe(0);
});

test('P13 rol de prestador sin vinculo no ve nada; personal sin la llave de prioridad no puede cambiarla', function () {
    $e = qa_escenario_maestro();
    $huerfano = qa_admin('TALLER CONFECCION', 'all');
    $sinLlave = qa_admin('Coordinadora '.uniqid(), 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion']);

    $this->actingAs($huerfano, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))->assertForbidden();

    $this->actingAs($sinLlave, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))
        ->assertOk()->assertSee($e['ordJ']->codigo)->assertDontSee('data-p-abrir', false);

    $r = $this->actingAs($sinLlave, 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.prioridad'), pc_cambio(qa_detalle($e['ordA'], $e['M']), 10, 'normal', 'alta'));
    expect($r->status())->toBeIn([401, 403]);
    expect(PrioridadOperacion::count())->toBe(0);
});

test('P14 la alerta abre el consolidado en la variante y orden; solo su destinatario la abre; leerla no cambia el trabajo', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    $d = qa_detalle($e['ordA'], $e['M']);
    $op = pc()->cambiar(pc_cambio($d, 50, 'normal', 'alta'))['operacion'];
    $aviso = Notificacion::query()->where('prioridad_operacion_id', $op->id)->sole();
    $tallerUser = $e['andrea']->usuario;

    // Otro usuario (aunque sea administrador) no abre ni lista avisos ajenos.
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.notificaciones.abrir', $aviso->id))->assertNotFound();
    expect($this->actingAs($e['admin'], 'admin')->getJson(route('admin.corte_confeccion.notificaciones.lista'))->json('no_leidas'))->toBe(0);

    $lista = $this->actingAs($tallerUser, 'admin')->getJson(route('admin.corte_confeccion.notificaciones.lista'));
    expect($lista->json('no_leidas'))->toBe(1)->and($lista->json('avisos.0.texto'))->toContain('50 u.')->toContain('Normal → Alta');

    $abrir = $this->actingAs($tallerUser, 'admin')->get(route('admin.corte_confeccion.notificaciones.abrir', $aviso->id));
    $abrir->assertRedirect(route('admin.corte_confeccion.ordenes_confeccion.consolidado', ['taller' => $e['andrea']->id, 'producto' => $e['M']->id, 'foco' => $d->id, 'aviso' => $aviso->id]));

    // El taller reporta 70 despues de la alerta: la pantalla explica lo actual sin repetir el pendiente antiguo.
    Auth::guard('admin')->setUser($e['admin']);
    pc_reportar($e['ordA'], $d, 70);

    $pagina = $this->actingAs($tallerUser, 'admin')->get($abrir->headers->get('Location'));
    $pagina->assertOk()
        ->assertSee('data-testid="aviso-contexto"', false)
        ->assertSee('data-foco="1"', false)
        ->assertSee('quedan 30 por confeccionar')
        ->assertDontSee('@endif', false);

    // Sin alerta abierta no aparece el recuadro ni texto de alerta suelto.
    $this->actingAs($tallerUser, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))
        ->assertOk()
        ->assertDontSee('data-testid="aviso-contexto"', false)
        ->assertDontSee('La alerta muestra el cambio')
        ->assertDontSee('@endif', false);

    // Con las prendas ya reportadas, la alerta explica el estado actual sin mostrar el pendiente antiguo.
    Auth::guard('admin')->setUser($e['admin']);
    pc_reportar($e['ordA'], $d, 30);
    $this->actingAs($tallerUser, 'admin')->get($abrir->headers->get('Location'))
        ->assertOk()
        ->assertSee('ya no tiene prendas por confeccionar')
        ->assertSee('El taller reportó 100 como terminadas; 100 siguen pendientes de recibir en bodega.')
        ->assertDontSee('@endif', false);

    expect($aviso->fresh()->leida_en)->not->toBeNull()
        ->and(pc_reparto($d))->toBe(['urgente' => 0, 'alta' => 0, 'normal' => 0]);
});

test('P15 la campana nativa recibe la extension y el listado nativo muestra los avisos del usuario', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    pc()->cambiar(pc_cambio(qa_detalle($e['ordA'], $e['M']), 5, 'normal', 'urgente', ['nota' => '{{ 7*7 }} <b>texto</b>']));
    $tallerUser = $e['andrea']->usuario;

    $html = $this->actingAs($tallerUser, 'admin')->get(route('admin.notification.index'))->assertOk();
    $html->assertSee('id="cc-avisos-campana"', false)
        ->assertSee('data-testid="avisos-corte-confeccion"', false)
        ->assertSee('5 u.')
        ->assertDontSee('<b>texto</b>', false); // escapado; v-pre evita que Vue evalue la nota

    // Usuario sin la llave del modulo: Bagisto queda intacto, sin la extension.
    $ventas = qa_admin('Ventas '.uniqid(), 'custom', ['dashboard']);
    $this->actingAs($ventas, 'admin')->get(route('admin.notification.index'))->assertOk()
        ->assertDontSee('id="cc-avisos-campana"', false)
        ->assertSee('v-notifications-template', false);
    expect(ConsolidadoConfeccionGate::puedeVer())->toBeFalse();
});
