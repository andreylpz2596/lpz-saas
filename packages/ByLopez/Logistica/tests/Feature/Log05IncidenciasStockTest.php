<?php

use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\Logistica\Contracts\AjustaInventario;
use ByLopez\Logistica\Enums\EstadoIncidenciaStock;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\IncidenciaStock;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Stock\IncidenciasStockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersCom.php';

uses(TestCase::class);

/*
| Excepcion de despacho «stock del sistema insuficiente, pero la prenda esta en bodega»: BODEGA solicita, el Administrador
| regulariza (sube el stock al conteo fisico) o rechaza, y despues el despacho es el normal. Solo lpz_saas_testing.
*/

beforeEach(fn () => Storage::fake('private'));

function log5_bodega()
{
    return f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.stock', 'logistica.stock.solicitar']));
}

/** Administrador (rol `all`) autenticado en el guard `admin`: la compuerta del ajuste mira al usuario autenticado. */
function log5_admin()
{
    $admin = f03b_admin('all');
    Auth::guard('admin')->setUser($admin);

    return $admin;
}

function log5_stock(array $e): int
{
    return (int) DB::table('product_inventories')->where('product_id', $e['item']->product_id)->where('inventory_source_id', 1)->sum('qty');
}

function log5_ctx(array $e, $actor): array
{
    return app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $actor);
}

function log5_solicitar(array $e, $actor, int $conteo, array $extra = []): IncidenciaStock
{
    return app(IncidenciasStockService::class)->solicitar(
        $actor, (int) $e['pedido']->id, [$e['item']->id => $conteo], $extra['nota'] ?? 'Las prendas estaban en el estante B', $extra['clave'] ?? 'inc-'.Str::uuid(),
    );
}

test('STK-1 stock 0: BODEGA ve maximo 0 (input bloqueado), hay faltante y solo puede SOLICITAR', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $ctx = log5_ctx($e, log5_bodega());

    expect($ctx['unidades']['lineas'][0])->toMatchArray(['pendientes' => 3, 'disponibles' => 0, 'maximo' => 0])
        ->and($ctx['stock'])->toMatchArray(['hay_faltante' => true, 'puede_solicitar' => true, 'puede_regularizar' => false, 'abierta' => null])
        ->and($ctx['stock']['lineas'][0])->toMatchArray(['necesaria' => 3, 'stock_sistema' => 0]);

    // El Administrador no «solicita»: regulariza directamente.
    $admin = log5_ctx($e, log5_admin())['stock'];

    expect($admin['puede_regularizar'])->toBeTrue()->and($admin['puede_solicitar'])->toBeFalse();
});

test('STK-2 solicitar no cambia inventario ni crea Shipment; una abierta por pedido; idempotente', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $bodega = log5_bodega();
    $envios = DB::table('shipments')->count();

    $a = log5_solicitar($e, $bodega, 3, ['clave' => 'inc-fija-1']);

    expect($a->estado)->toBe(EstadoIncidenciaStock::SOLICITADA)
        ->and((int) $a->abierta)->toBe(1)
        ->and($a->lineas)->toHaveCount(1)
        ->and($a->lineas[0]->only(['cantidad_necesaria', 'stock_sistema_solicitud', 'conteo_declarado']))->toBe(['cantidad_necesaria' => 3, 'stock_sistema_solicitud' => 0, 'conteo_declarado' => 3])
        ->and(log5_stock($e))->toBe(0)
        ->and(DB::table('shipments')->count())->toBe($envios)
        // El drawer sigue bloqueado y muestra la solicitud pendiente.
        ->and(log5_ctx($e, $bodega)['unidades']['lineas'][0]['maximo'])->toBe(0)
        ->and(log5_ctx($e, $bodega)['stock']['abierta']['id'])->toBe((int) $a->id)
        ->and(log5_ctx($e, $bodega)['stock']['puede_solicitar'])->toBeFalse();

    // Reintento exacto: la misma solicitud. Otra clave: ya hay una abierta. Misma clave con otro contenido: conflicto.
    expect(log5_solicitar($e, $bodega, 3, ['clave' => 'inc-fija-1'])->id)->toBe($a->id)
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, 3)))->toBe(Invalida::INCIDENCIA_ABIERTA)
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, 4, ['clave' => 'inc-fija-1'])))->toBe(Invalida::IDEMPOTENCIA_CONFLICTO)
        ->and(IncidenciaStock::query()->where('order_id', $e['pedido']->id)->count())->toBe(1);
});

test('STK-3 validaciones de la solicitud', function () {
    $e = log2_pedido(3, ['stock' => 1]);
    $bodega = log5_bodega();

    expect(log2_error(fn () => log5_solicitar($e, $bodega, 3, ['nota' => '  '])))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, 1)))->toBe(Invalida::CONTEO_NO_SUPERA_STOCK)   // no supera el sistema
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, -2)))->toBe(Invalida::CONTEO_INVALIDO)
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, 2)))->toBeNull();   // 2 > 1: valido

    // Sin faltante (stock alcanza): no hay incidencia que abrir.
    $suficiente = log2_pedido(3, ['stock' => 10]);

    expect(log2_error(fn () => log5_solicitar($suficiente, $bodega, 20)))->toBe(Invalida::SIN_FALTANTE)
        ->and(log5_ctx($suficiente, $bodega)['stock']['hay_faltante'])->toBeFalse()
        // Linea que no es del pedido, y un actor sin permiso (asesor).
        ->and(log2_error(fn () => app(IncidenciasStockService::class)->solicitar($bodega, (int) log2_pedido(3, ['stock' => 0])['pedido']->id, [99999999 => 5], 'x', 'inc-'.Str::uuid())))->toBe(Invalida::LINEA_NO_DEL_PEDIDO)
        ->and(log2_error(fn () => log5_solicitar(log2_pedido(3, ['stock' => 0]), log2_asesor(), 3)))->toBe(Invalida::SIN_PERMISO);
});

test('STK-4 BODEGA no puede regularizar ni rechazar (servicio) y la compuerta de inventario tambien lo niega', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $bodega = log5_bodega();
    $inc = log5_solicitar($e, $bodega, 3);
    $servicio = app(IncidenciasStockService::class);
    $linea = $inc->lineas[0];

    expect(log2_error(fn () => $servicio->regularizar($bodega, $inc->id, [$linea->id => 3], 'motivo', true)))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->rechazar($bodega, $inc->id, 'motivo')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->regularizarDirecto($bodega, (int) $e['pedido']->id, [$e['item']->id => 3], 'motivo', true, 'inc-'.Str::uuid())))->toBe(Invalida::SIN_PERMISO)
        ->and(log5_stock($e))->toBe(0);

    // Aunque algo llamara al adaptador con BODEGA autenticada, el ajuste manual de CorteConfeccion lo rechaza.
    Auth::guard('admin')->setUser($bodega);

    expect(log2_error(fn () => app(AjustaInventario::class)->elevarAConteo($bodega, (int) $e['item']->product_id, 3, 'x', 'inc-'.Str::uuid())))->toBe(Invalida::AJUSTE_NO_AUTORIZADO)
        // Y nunca se suplanta: el actor debe ser el usuario autenticado.
        ->and(log2_error(fn () => app(AjustaInventario::class)->elevarAConteo(log5_admin(), (int) $e['item']->product_id, 3, 'x', 'inc-'.Str::uuid())))->toBeNull()
        ->and(log5_stock($e))->toBe(3);
});

test('STK-5 el Administrador regulariza: stock exacto, libro auditado, el drawer recalcula y el despacho es el normal', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $bodega = log5_bodega();
    $inc = log5_solicitar($e, $bodega, 3);
    $admin = log5_admin();

    $r = app(IncidenciasStockService::class)->regularizar($admin, $inc->id, [$inc->lineas[0]->id => 3], 'Conteo físico en estante B', true);
    $l = $r->lineas[0];
    $mov = InventarioMovimiento::query()->find($l->movimiento_id);

    expect($r->estado)->toBe(EstadoIncidenciaStock::REGULARIZADA)
        ->and($r->abierta)->toBeNull()
        ->and($r->resuelta_por_admin_id)->toBe((int) $admin->id)
        ->and($r->motivo_resolucion)->toBe('Conteo físico en estante B')
        ->and($l->only(['stock_sistema_resolucion', 'conteo_confirmado', 'diferencia']))->toBe(['stock_sistema_resolucion' => 0, 'conteo_confirmado' => 3, 'diferencia' => 3])
        ->and(log5_stock($e))->toBe(3)
        // Libro de inventario: anterior, movimiento, nueva, usuario y motivo.
        ->and($mov)->not->toBeNull()
        ->and([(int) $mov->cantidad_anterior, (int) $mov->cantidad_movimiento, (int) $mov->cantidad_nueva, (int) $mov->admin_id])->toBe([0, 3, 3, (int) $admin->id])
        ->and($mov->observacion)->toContain('Conteo físico en estante B')->toContain('#'.$e['pedido']->increment_id);

    // El drawer recalcula: ya hay maximo y se despacha por el camino normal; no queda inventario negativo.
    $ctx = log5_ctx($e, $bodega);

    expect($ctx['unidades']['lineas'][0]['maximo'])->toBe(3)
        ->and($ctx['stock']['hay_faltante'])->toBeFalse()
        ->and($ctx['stock']['ultima']['estado'])->toBe('regularizada');

    app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 3]]));

    expect(log5_stock($e))->toBe(0)
        ->and(DB::table('product_inventories')->where('qty', '<', 0)->count())->toBe(0)
        ->and(log2_error(fn () => app(IncidenciasStockService::class)->regularizar($admin, $inc->id, [$inc->lineas[0]->id => 5], 'otra vez', true)))->toBe(Invalida::INCIDENCIA_YA_RESUELTA);
});

test('STK-6 toda regularizacion exige conteo, motivo y confirmacion; solo SUBE el stock', function () {
    $e = log2_pedido(5, ['stock' => 1]);
    $inc = log5_solicitar($e, log5_bodega(), 4);
    $admin = log5_admin();
    $servicio = app(IncidenciasStockService::class);
    $linea = $inc->lineas[0]->id;

    expect(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$linea => 4], 'motivo', false)))->toBe(Invalida::CONFIRMACION_REQUERIDA)
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$linea => 4], '   ', true)))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [], 'motivo', true)))->toBe(Invalida::CONTEO_INVALIDO)
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$linea => 'abc'], 'motivo', true)))->toBe(Invalida::CONTEO_INVALIDO)
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$linea => 0], 'motivo', true)))->toBe(Invalida::CONTEO_MENOR_AL_SISTEMA)   // 0 < 1: bajar no se permite
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$linea => 1], 'motivo', true)))->toBe(Invalida::SIN_DIFERENCIA)           // igual al sistema
        ->and(log5_stock($e))->toBe(1)
        ->and(IncidenciaStock::query()->find($inc->id)->estado)->toBe(EstadoIncidenciaStock::SOLICITADA)
        ->and(InventarioMovimiento::query()->where('product_id', $e['item']->product_id)->count())->toBe(0);
});

test('STK-7 si el stock cambio entre la solicitud y la resolucion, el resultado es EXACTAMENTE el conteo confirmado', function () {
    $e = log2_pedido(5, ['stock' => 0]);
    $inc = log5_solicitar($e, log5_bodega(), 4);
    $admin = log5_admin();

    // Mientras tanto alguien ingreso 1 unidad: el sistema ya muestra 1.
    DB::table('product_inventories')->where('product_id', $e['item']->product_id)->update(['qty' => 1]);

    $r = app(IncidenciasStockService::class)->regularizar($admin, $inc->id, [$inc->lineas[0]->id => 4], 'Conteo del día', true);

    expect(log5_stock($e))->toBe(4)
        ->and($r->lineas[0]->only(['stock_sistema_resolucion', 'diferencia']))->toBe(['stock_sistema_resolucion' => 1, 'diferencia' => 3]);
});

test('STK-8 rechazar no cambia nada, deja el pedido pendiente y permite una nueva solicitud', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $bodega = log5_bodega();
    $inc = log5_solicitar($e, $bodega, 3);
    $admin = log5_admin();
    $servicio = app(IncidenciasStockService::class);

    expect(log2_error(fn () => $servicio->rechazar($admin, $inc->id, '')))->toBe(Invalida::MOTIVO_REQUERIDO);

    $r = $servicio->rechazar($admin, $inc->id, 'En bodega no están las prendas');

    expect($r->estado)->toBe(EstadoIncidenciaStock::RECHAZADA)
        ->and($r->abierta)->toBeNull()
        ->and($r->resuelta_por_name_snapshot)->toBe($admin->name)
        ->and(log5_stock($e))->toBe(0)
        ->and(InventarioMovimiento::query()->where('product_id', $e['item']->product_id)->count())->toBe(0)
        ->and(log5_ctx($e, $bodega)['unidades']['lineas'][0]['maximo'])->toBe(0)
        ->and(log5_ctx($e, $bodega)['stock']['ultima'])->toMatchArray(['estado' => 'rechazada', 'motivo' => 'En bodega no están las prendas'])
        ->and(log2_error(fn () => $servicio->rechazar($admin, $inc->id, 'otra vez')))->toBe(Invalida::INCIDENCIA_YA_RESUELTA)
        ->and(log2_error(fn () => $servicio->regularizar($admin, $inc->id, [$inc->lineas[0]->id => 3], 'x', true)))->toBe(Invalida::INCIDENCIA_YA_RESUELTA)
        ->and(log2_error(fn () => log5_solicitar($e, $bodega, 3)))->toBeNull();   // puede volver a solicitar tras el rechazo
});

test('STK-9 el Administrador solicita y resuelve en UNA operacion, con la misma auditoria, e idempotente', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $admin = log5_admin();
    $servicio = app(IncidenciasStockService::class);
    $clave = 'inc-dir-'.Str::uuid();

    $r = $servicio->regularizarDirecto($admin, (int) $e['pedido']->id, [$e['item']->id => 3], 'Conteo físico directo', true, $clave);

    expect($r->estado)->toBe(EstadoIncidenciaStock::REGULARIZADA)
        ->and($r->solicitante_admin_id)->toBe((int) $admin->id)
        ->and($r->resuelta_por_admin_id)->toBe((int) $admin->id)
        ->and(log5_stock($e))->toBe(3)
        ->and(InventarioMovimiento::query()->where('product_id', $e['item']->product_id)->count())->toBe(1);

    // Reintento de la misma operacion: no vuelve a ajustar.
    expect($servicio->regularizarDirecto($admin, (int) $e['pedido']->id, [$e['item']->id => 3], 'Conteo físico directo', true, $clave)->id)->toBe($r->id)
        ->and(log5_stock($e))->toBe(3)
        ->and(InventarioMovimiento::query()->where('product_id', $e['item']->product_id)->count())->toBe(1);

    // Con una solicitud de BODEGA abierta, la operacion directa resuelve ESA solicitud (no crea una segunda).
    $f = log2_pedido(2, ['stock' => 0]);
    $abierta = log5_solicitar($f, log5_bodega(), 2);
    $dir = $servicio->regularizarDirecto($admin, (int) $f['pedido']->id, [$f['item']->id => 2], 'Verificado', true, 'inc-dir-'.Str::uuid());

    expect($dir->id)->toBe($abierta->id)->and($dir->solicitante_admin_id)->not->toBe($dir->resuelta_por_admin_id)
        ->and(log5_stock($f))->toBe(2);
});

test('STK-10 si fisicamente hay MENOS de lo que el pedido necesita, se regulariza a lo que hay y el despacho es parcial', function () {
    $e = log2_pedido(5, ['stock' => 0]);
    $bodega = log5_bodega();
    $inc = log5_solicitar($e, $bodega, 2);

    app(IncidenciasStockService::class)->regularizar(log5_admin(), $inc->id, [$inc->lineas[0]->id => 2], 'Solo aparecieron 2', true);

    $l = log5_ctx($e, $bodega)['unidades']['lineas'][0];

    expect($l)->toMatchArray(['pendientes' => 5, 'disponibles' => 2, 'maximo' => 2])
        ->and(log5_ctx($e, $bodega)['stock']['hay_faltante'])->toBeTrue()   // aun falta stock para lo restante
        ->and(log2_error(fn () => app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 3]]))))->toBe(Invalida::STOCK_INSUFICIENTE);

    app(DespachoService::class)->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 2]]));

    expect(log5_stock($e))->toBe(0)->and(DB::table('product_inventories')->where('qty', '<', 0)->count())->toBe(0);
});

test('STK-11 configurable real (hija con qty_ordered = 0): se regulariza el stock de la VARIANTE que el Shipment descuenta', function () {
    Mail::fake();
    f03f_configurarBase();

    $cliente = f03b_cliente(f03b_grupo('STK11'));
    $configurable = f03b_configurable(8500.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, 20);

    $pedido = com_vender($cliente->id, f03b_admin(), $configurable, $variante, 3);
    DB::table('orders')->where('id', $pedido->id)->update(['shipping_method' => 'envio_nacional']);

    DB::table('product_inventories')->where('product_id', $variante->id)->update(['qty' => 0]);   // la venta ya se hizo; ahora el sistema dice 0

    $padre = DB::table('order_items')->where('order_id', $pedido->id)->whereNull('parent_id')->first();
    $bodega = log5_bodega();
    $servicio = app(IncidenciasStockService::class);

    $inc = $servicio->solicitar($bodega, (int) $pedido->id, [$padre->id => 3], 'En estante', 'inc-'.Str::uuid());

    // El SKU fisico de la incidencia es la variante, no el configurable.
    expect((int) $inc->lineas[0]->product_id)->toBe((int) $variante->id)
        ->and($inc->lineas[0]->sku_snapshot)->toBe($variante->sku);

    $servicio->regularizar(log5_admin(), $inc->id, [$inc->lineas[0]->id => 3], 'Conteo', true);

    expect((int) DB::table('product_inventories')->where('product_id', $variante->id)->sum('qty'))->toBe(3)
        ->and(log5_ctx(['pedido' => $pedido], $bodega)['unidades']['lineas'][0]['maximo'])->toBe(3);
});

test('STK-12 HTTP y ACL: BODEGA solicita, solo el Administrador regulariza/rechaza, el asesor no entra', function () {
    $e = log2_pedido(3, ['stock' => 0]);
    $pedido = (int) $e['pedido']->id;
    $bodega = log5_bodega();

    $r = $this->actingAs($bodega, 'admin')->postJson(route('admin.logistica.stock.solicitar', ['pedido' => $pedido]), [
        'idempotency_key' => 'inc-'.Str::uuid(), 'conteos' => [$e['item']->id => 3], 'nota' => 'Estaban en el estante B',
    ]);

    $r->assertOk()->assertJson(['ok' => true, 'estado' => 'solicitada']);
    $incidencia = (int) $r->json('incidencia_id');

    // BODEGA: ninguna ruta de resolucion (la ACL de Bagisto no la deja entrar; si entrara, el servicio responderia 403).
    foreach ([
        ['post', route('admin.logistica.stock.directa', ['pedido' => $pedido]), ['conteos' => [$e['item']->id => 3], 'motivo' => 'x', 'confirma_conteo_fisico' => 1, 'idempotency_key' => 'inc-'.Str::uuid()]],
        ['post', route('admin.logistica.incidencias.regularizar', ['incidencia' => $incidencia]), []],
        ['post', route('admin.logistica.incidencias.rechazar', ['incidencia' => $incidencia]), ['motivo' => 'x']],
    ] as [$metodo, $url, $datos]) {
        $respuesta = $this->actingAs($bodega, 'admin')->{$metodo.'Json'}($url, $datos);

        expect($respuesta->status())->toBeIn([401, 403]);
    }

    expect(log5_stock($e))->toBe(0);

    // Ver el detalle (JSON de revision) si; el asesor, no.
    $this->actingAs($bodega, 'admin')->getJson(route('admin.logistica.incidencias.detalle', ['incidencia' => $incidencia]))
        ->assertOk()->assertJsonPath('incidencia.puede_resolver', false);
    expect($this->actingAs(log2_asesor(), 'admin')->getJson(route('admin.logistica.incidencias.detalle', ['incidencia' => $incidencia]))->status())->toBeIn([401, 403]);

    // El Administrador revisa (con stock en vivo) y regulariza por HTTP.
    $admin = log5_admin();
    $detalle = $this->actingAs($admin, 'admin')->getJson(route('admin.logistica.incidencias.detalle', ['incidencia' => $incidencia]));

    $detalle->assertOk()->assertJsonPath('incidencia.puede_resolver', true)->assertJsonPath('incidencia.lineas.0.stock_vivo', 0);
    $lineaId = $detalle->json('incidencia.lineas.0.id');

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.incidencias.regularizar', ['incidencia' => $incidencia]), ['conteos' => [$lineaId => 3], 'motivo' => 'Verificado', 'confirma_conteo_fisico' => 0])
        ->assertStatus(422)->assertJson(['codigo' => Invalida::CONFIRMACION_REQUERIDA]);

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.incidencias.regularizar', ['incidencia' => $incidencia]), ['conteos' => [$lineaId => 3], 'motivo' => 'Verificado', 'confirma_conteo_fisico' => 1])
        ->assertOk()->assertJson(['ok' => true, 'estado' => 'regularizada']);

    expect(log5_stock($e))->toBe(3);

    // La bandeja: pestaña de incidencias visible para quien las ve; la bandeja normal sigue igual.
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.despachos.index', ['vista' => 'incidencias', 'estado' => 'todas']))
        ->assertOk()->assertSee('data-incidencias', false)->assertSee('#'.$e['pedido']->increment_id);
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.despachos.index'))->assertOk()->assertSee('data-pestana="incidencias"', false);
});

test('STK-13 el adaptador es la unica puerta: sin conteo mayor no hay movimiento y la clave es idempotente', function () {
    $e = log2_pedido(3, ['stock' => 4]);
    $admin = log5_admin();
    $ajuste = app(AjustaInventario::class);
    $id = (int) $e['item']->product_id;

    expect(log2_error(fn () => $ajuste->elevarAConteo($admin, $id, 4, 'x', 'adp-'.Str::uuid())))->toBe(Invalida::SIN_DIFERENCIA)
        ->and(log2_error(fn () => $ajuste->elevarAConteo($admin, $id, 2, 'x', 'adp-'.Str::uuid())))->toBe(Invalida::CONTEO_MENOR_AL_SISTEMA)
        ->and(log5_stock($e))->toBe(4);

    $primero = $ajuste->elevarAConteo($admin, $id, 7, 'Conteo', 'adp-fija');
    $segundo = $ajuste->elevarAConteo($admin, $id, 7, 'Conteo', 'adp-fija');

    expect($primero)->toMatchArray(['anterior' => 4, 'nueva' => 7, 'diferencia' => 3])
        ->and($segundo['movimiento_id'])->toBe($primero['movimiento_id'])
        ->and(log5_stock($e))->toBe(7)
        ->and($ajuste->stockActual($id))->toBe(7)
        ->and($ajuste->ultimosMovimientos($id))->toHaveCount(1);
});
