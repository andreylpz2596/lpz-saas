<?php

use ByLopez\Logistica\Contracts\ReingresaInventario;
use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\RecibirDevolucionService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Support\Inventario\ReingresoViaCorteConfeccion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Repositories\ShipmentRepository;
use Webkul\User\Contracts\Admin;

require_once __DIR__.'/../../../VentasAsesor/tests/helpersRt04b1a.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersFase03b.php';

uses(TestCase::class);

/*
| Recepcion fisica de devoluciones + reingreso de las unidades APTAS al inventario (Entregas · Fase B/C).
| Devolucion REGISTRADA ≠ RECIBIDA en bodega ≠ reingreso. Solo lpz_saas_testing.
*/

const RD_PERMISOS = ['logistica', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.recibir_devolucion'];

/**
 * Pedido real despachado por ShipmentRepository desde `$fuente` (descuenta el stock de esa bodega), con `$qty` unidades,
 * y una DEVOLUCION registrada de `$devuelve` unidades. Devuelve ids y el producto fisico.
 */
function rd_envio(int $qty = 10, ?int $devuelve = null, int $fuente = 1, int $stockInicial = 50): array
{
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $item->update(['qty_ordered' => $qty, 'qty_shipped' => 0]);

    rd_gestion((int) $item->product_id, true);
    DB::table('product_inventories')->updateOrInsert(['product_id' => $item->product_id, 'inventory_source_id' => $fuente, 'vendor_id' => 0], ['qty' => $stockInicial]);

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => 'BYLOPEZ', 'track_number' => 'GUIA-'.uniqid(), 'source' => $fuente,
        'items' => [$item->id => [$fuente => $qty]],
    ]]);

    $si = (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id');
    $e = ['pedido' => $pedido, 'item' => $item, 'envio' => $envio->fresh(), 'si' => $si, 'producto' => (int) $item->product_id, 'fuente' => $fuente, 'retorno' => null, 'retorno_item' => null];

    if ($devuelve !== null) {
        $r = app(RegistrarRetornoTransportadoraService::class)->registrar(rd_bodega(), new SolicitudEventoEnvio(
            shipmentId: (int) $envio->id, orderId: (int) $pedido->id, fecha: now()->toDateString(), items: [$si => $devuelve],
            fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'rd-ret-'.Str::uuid(), motivo: 'Cliente ausente',
        ));
        $e['retorno'] = $r->registro;
        $e['retorno_item'] = (int) $r->registro->items->sole()->id;
    }

    return $e;
}

/** `manage_stock` es un atributo EAV del producto: el Shipment solo descuenta stock cuando es verdadero. */
function rd_gestion(int $productId, bool $gestiona): void
{
    $atributo = (int) DB::table('attributes')->where('code', 'manage_stock')->value('id');
    $clave = ['product_id' => $productId, 'attribute_id' => $atributo, 'channel' => 'default'];

    // La fabrica ya crea la fila (con unique_id NULL): se actualiza esa, no se duplica.
    if (DB::table('product_attribute_values')->where($clave)->exists()) {
        DB::table('product_attribute_values')->where($clave)->update(['boolean_value' => $gestiona ? 1 : 0]);

        return;
    }

    DB::table('product_attribute_values')->insert($clave + ['unique_id' => 'default|'.$productId.'|'.$atributo, 'locale' => null, 'boolean_value' => $gestiona ? 1 : 0]);
}

function rd_bodega(array $permisos = RD_PERMISOS)
{
    return f03b_admin('custom', $permisos);
}

function rd_stock(int $producto, int $fuente = 1): int
{
    return (int) DB::table('product_inventories')->where('product_id', $producto)->where('inventory_source_id', $fuente)->where('vendor_id', 0)->value('qty');
}

function rd_recepcion(array $e, array $linea, array $extra = []): SolicitudRecepcion
{
    return new SolicitudRecepcion(...$extra + [
        'retornoId' => (int) $e['retorno']->id,
        'fecha' => now()->toDateString(),
        'lineas' => [$e['retorno_item'] => $linea],
        'idempotencyKey' => 'rd-rec-'.Str::uuid(),
    ]);
}

function rd_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

test('RD-1 una devolucion REGISTRADA no suma inventario y queda pendiente de recibir', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);

    expect(rd_stock($e['producto']))->toBe($antes)
        ->and($antes)->toBe(40) // el despacho descontó 10 de 50
        ->and(RecepcionDevolucion::query()->count())->toBe(0)
        ->and(app(ConsultaEntregaService::class)->envio((int) $e['envio']->id)['estado_devolucion'])->toBe('pendiente_recibir')
        ->and(app(ConsultaEntregaService::class)->indicadores()['devoluciones_por_recibir'])->toBe(1);
});

test('RD-2 recepcion 10/10/10: el inventario sube +10 en la bodega del despacho y deja el movimiento reingreso_devolucion', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);
    $bodega = rd_bodega();

    $r = app(RecibirDevolucionService::class)->recibir($bodega, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0]));
    $item = $r->recepcion->items->sole();
    $mov = DB::table('bylopez_cc_inventario_movimientos')->where('id', $item->movimiento_inventario_id)->first();

    expect($r->yaExistia)->toBeFalse()
        ->and(rd_stock($e['producto']))->toBe($antes + 10)
        ->and($r->recepcion->inventory_source_id)->toBe(1)
        ->and($item->product_id)->toBe($e['producto'])
        ->and($item->reingreso_estado)->toBe('reingresado')
        ->and($item->faltantes)->toBe(0)
        ->and($mov->tipo_movimiento)->toBe('reingreso_devolucion')
        ->and((int) $mov->inventory_source_id)->toBe(1)
        ->and((int) $mov->cantidad_anterior)->toBe($antes)
        ->and((int) $mov->cantidad_nueva)->toBe($antes + 10)
        ->and($mov->origen_tipo)->toBe('recepcion_devolucion')
        ->and((int) $mov->origen_id)->toBe((int) $r->recepcion->id)
        ->and((int) $mov->admin_id)->toBe((int) $bodega->id)
        ->and($mov->idempotency_key)->toBe('reingreso:recepcion:'.$r->recepcion->id.':retorno_item:'.$e['retorno_item'])
        ->and(app(ConsultaEntregaService::class)->envio((int) $e['envio']->id)['estado_devolucion'])->toBe('reintegrada');
});

test('RD-3 recepcion 10/10 con 8 aptas y 2 con novedad: solo las 8 aptas suben el inventario', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);

    $r = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 10, 'aptas' => 8, 'novedad' => 2, 'motivo' => '2 prendas manchadas']));

    expect(rd_stock($e['producto']))->toBe($antes + 8)
        ->and($r->recepcion->items->sole()->novedad)->toBe(2)
        ->and(app(ConsultaEntregaService::class)->envio((int) $e['envio']->id)['estado_devolucion'])->toBe('reintegrada_parcial');
});

test('RD-4 recepcion con faltantes: recibidas 8 de 10, aptas 8 → +8 y 2 faltantes con motivo', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);

    $r = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 8, 'aptas' => 8, 'novedad' => 0, 'motivo' => 'Llegaron 8 de 10']));

    expect(rd_stock($e['producto']))->toBe($antes + 8)
        ->and($r->recepcion->items->sole()->faltantes)->toBe(2);

    // Sin ninguna apta: se recibe y se documenta, pero el inventario NO se toca.
    $otro = rd_envio(4, 4);
    $stock = rd_stock($otro['producto']);
    $x = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($otro, ['recibidas' => 4, 'aptas' => 0, 'novedad' => 4, 'motivo' => 'Todas dañadas']));

    expect(rd_stock($otro['producto']))->toBe($stock)
        ->and($x->recepcion->items->sole()->reingreso_estado)->toBe('sin_aptas')
        ->and($x->recepcion->items->sole()->movimiento_inventario_id)->toBeNull();
});

test('RD-5 validaciones de backend: cantidades incoherentes, exceso, motivo faltante y lineas incompletas se rechazan sin tocar stock', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);
    $s = app(RecibirDevolucionService::class);
    $b = rd_bodega();

    expect(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 8, 'novedad' => 1, 'motivo' => 'x']))))->toBe(Invalida::RECEPCION_CANTIDADES_INVALIDAS)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 11, 'aptas' => 11, 'novedad' => 0]))))->toBe(Invalida::RECEPCION_CANTIDADES_INVALIDAS)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 9, 'novedad' => 1]))))->toBe(Invalida::RECEPCION_MOTIVO_REQUERIDO)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 8, 'aptas' => 8, 'novedad' => 0]))))->toBe(Invalida::RECEPCION_MOTIVO_REQUERIDO)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => -1, 'aptas' => 0, 'novedad' => 0]))))->toBe(Invalida::RECEPCION_CANTIDADES_INVALIDAS)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 'abc', 'aptas' => 0, 'novedad' => 0]))))->toBe(Invalida::RECEPCION_CANTIDADES_INVALIDAS)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0], ['lineas' => [99999 => ['recibidas' => 1, 'aptas' => 1, 'novedad' => 0]]]))))->toBe(Invalida::RECEPCION_LINEAS_INCOMPLETAS)
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0], ['fecha' => now()->addDay()->toDateString()]))))->toBe(Invalida::FECHA_INVALIDA)
        ->and(rd_stock($e['producto']))->toBe($antes)
        ->and(RecepcionDevolucion::query()->count())->toBe(0);
});

test('RD-6 idempotencia: doble clic / reintento no duplica; otra solicitud con la misma clave o una segunda recepcion se rechazan', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);
    $s = app(RecibirDevolucionService::class);
    $b = rd_bodega();
    $peticion = rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0]);

    $primera = $s->recibir($b, $peticion);
    $reintento = $s->recibir($b, $peticion);

    expect($reintento->yaExistia)->toBeTrue()
        ->and($reintento->recepcion->id)->toBe($primera->recepcion->id)
        ->and(rd_stock($e['producto']))->toBe($antes + 10)
        ->and(DB::table('bylopez_cc_inventario_movimientos')->where('tipo_movimiento', 'reingreso_devolucion')->where('origen_id', $primera->recepcion->id)->count())->toBe(1)
        // Misma clave con OTRA solicitud: conflicto, jamas reinterpretar.
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 5, 'aptas' => 5, 'novedad' => 0, 'motivo' => 'x'], ['idempotencyKey' => $peticion->idempotencyKey]))))->toBe(Invalida::IDEMPOTENCIA_CONFLICTO)
        // Otra clave sobre la misma devolucion (otra pestaña / otro usuario): ya fue recibida.
        ->and(rd_error(fn () => $s->recibir($b, rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0]))))->toBe(Invalida::RECEPCION_YA_REGISTRADA)
        ->and(rd_stock($e['producto']))->toBe($antes + 10);

    // La base es la ultima barrera: ni siquiera SQL directo puede crear una segunda recepcion VIGENTE de la misma devolucion.
    expect(fn () => DB::table('bylopez_lg_recepciones_devolucion')->insert([
        'retorno_id' => $e['retorno']->id, 'shipment_id' => $e['envio']->id, 'order_id' => $e['pedido']->id, 'inventory_source_id' => 1,
        'fecha_recepcion' => now()->toDateString(), 'actor_admin_id' => $b->id, 'actor_name_snapshot' => 'x', 'estado' => 'confirmado',
        'idempotency_key' => 'sql-directo-'.Str::uuid(), 'huella' => str_repeat('a', 64),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('RD-7 todo o nada: si el reingreso de una linea falla, la recepcion completa se revierte y el stock no cambia', function () {
    $e = rd_envio(10, 10);
    $antes = rd_stock($e['producto']);

    app()->bind(ReingresaInventario::class, fn () => new class implements ReingresaInventario
    {
        public function reingresar(Admin $actor, int $productId, int $inventorySourceId, int $cantidad, string $motivo, string $origenTipo, int $origenId, string $clave): array
        {
            throw new Invalida(Invalida::REINGRESO_FALLIDO, ['causa' => 'falla simulada']);
        }
    });

    expect(rd_error(fn () => app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0]))))->toBe(Invalida::REINGRESO_FALLIDO)
        ->and(RecepcionDevolucion::query()->count())->toBe(0)
        ->and(DB::table('bylopez_lg_recepcion_devolucion_items')->count())->toBe(0)
        ->and(rd_stock($e['producto']))->toBe($antes);

    // Y la devolucion sigue pendiente: se puede recibir bien despues.
    app()->forgetInstance(ReingresaInventario::class);
    app()->bind(ReingresaInventario::class, ReingresoViaCorteConfeccion::class);
    app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 10, 'aptas' => 10, 'novedad' => 0]));

    expect(rd_stock($e['producto']))->toBe($antes + 10);
});

test('RD-8 bodega: el stock vuelve a la bodega REAL del Shipment; sin bodega determinable no se recibe ni se toca el stock', function () {
    // Segunda bodega real.
    $segunda = (int) DB::table('inventory_sources')->insertGetId([
        'code' => 'RDX'.Str::upper(Str::random(5)), 'name' => 'Bodega Cali', 'contact_name' => 'x', 'contact_email' => 'x@x.co', 'contact_number' => '1',
        'street' => 'Calle 1', 'city' => 'Cali', 'state' => 'VA', 'country' => 'CO', 'postcode' => '760001', 'priority' => 9, 'status' => 1,
    ]);
    $e = rd_envio(6, 6, $segunda, 20);
    $antes1 = rd_stock($e['producto'], 1);
    $antes2 = rd_stock($e['producto'], $segunda);

    $r = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 6, 'aptas' => 6, 'novedad' => 0]));

    expect($r->recepcion->inventory_source_id)->toBe($segunda)
        ->and(rd_stock($e['producto'], $segunda))->toBe($antes2 + 6)
        ->and(rd_stock($e['producto'], 1))->toBe($antes1);

    // Shipment historico sin bodega: se bloquea, queda como «requiere revision» y no se toca nada.
    $h = rd_envio(5, 5);
    DB::table('shipments')->where('id', $h['envio']->id)->update(['inventory_source_id' => null]);
    $stock = rd_stock($h['producto']);

    expect(rd_error(fn () => app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($h, ['recibidas' => 5, 'aptas' => 5, 'novedad' => 0]))))->toBe(Invalida::BODEGA_ORIGEN_INDETERMINADA)
        ->and(rd_stock($h['producto']))->toBe($stock)
        ->and(RecepcionDevolucion::query()->where('retorno_id', $h['retorno']->id)->count())->toBe(0)
        ->and(app(ConsultaEntregaService::class)->envio((int) $h['envio']->id)['estado_devolucion'])->toBe('requiere_revision');
});

test('RD-9 producto fisico: en una linea configurable el stock vuelve a la VARIANTE hija, no al padre; sin gestion de stock no hay movimiento', function () {
    $e = rd_envio(4, 4);
    $hija = (new ProductFaker)->getSimpleProductFactory()->create();
    rd_gestion((int) $hija->id, true);
    DB::table('product_inventories')->updateOrInsert(['product_id' => $hija->id, 'inventory_source_id' => 1, 'vendor_id' => 0], ['qty' => 30]);
    DB::table('order_items')->where('id', $e['item']->id)->update(['type' => 'configurable']);
    OrderItem::factory()->create(['order_id' => $e['pedido']->id, 'parent_id' => $e['item']->id, 'product_id' => $hija->id, 'sku' => $hija->sku, 'type' => 'simple', 'qty_ordered' => 0]);
    $padre = rd_stock($e['producto']);

    $r = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 4, 'aptas' => 4, 'novedad' => 0]));

    expect($r->recepcion->items->sole()->product_id)->toBe((int) $hija->id)
        ->and(rd_stock((int) $hija->id))->toBe(34)
        ->and(rd_stock($e['producto']))->toBe($padre);

    // Producto que no gestiona stock: el despacho tampoco lo descontó, asi que no se repone nada.
    $n = rd_envio(3, 3);
    rd_gestion($n['producto'], false);
    $antes = rd_stock($n['producto']);
    $x = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($n, ['recibidas' => 3, 'aptas' => 3, 'novedad' => 0]));

    expect($x->recepcion->items->sole()->reingreso_estado)->toBe('no_aplica')
        ->and(rd_stock($n['producto']))->toBe($antes);
});

test('RD-10 permisos: BODEGA con la clave recibe; sin la clave, el asesor o un usuario sin rol se rechazan', function () {
    $e = rd_envio(2, 2);
    $s = app(RecibirDevolucionService::class);
    $linea = ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0];

    expect(rd_error(fn () => $s->recibir(rd_bodega(['logistica', 'logistica.entregas', 'logistica.entregas.registrar']), rd_recepcion($e, $linea))))->toBe(Invalida::SIN_PERMISO)
        ->and(rd_error(fn () => $s->recibir(f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor']), rd_recepcion($e, $linea))))->toBe(Invalida::SIN_PERMISO)
        ->and(rd_error(fn () => $s->recibir(null, rd_recepcion($e, $linea))))->toBe(Invalida::SIN_PERMISO)
        ->and(RecepcionDevolucion::query()->count())->toBe(0)
        ->and($s->recibir(rd_bodega(), rd_recepcion($e, $linea))->yaExistia)->toBeFalse();
});

test('RD-11 anulaciones: una devolucion recibida no se anula; una recepcion con reingreso no se anula; sin reingreso si, y se puede recibir de nuevo', function () {
    $anular = app(AnularEventoLogisticoService::class);
    $admin = f03b_admin('all');

    // Devolucion sin recepcion: anulable (reglas de siempre).
    $libre = rd_envio(3, 3);
    expect($anular->anularRetorno($admin, (int) $libre['retorno']->id, 'Registrada por error')->estado)->toBe(Estado::ANULADO)
        ->and(rd_error(fn () => app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($libre, ['recibidas' => 3, 'aptas' => 3, 'novedad' => 0]))))->toBe(Invalida::RETORNO_ANULADO);

    // Con recepcion y reingreso: ni la devolucion ni la recepcion se anulan (no hay resta silenciosa de stock).
    $e = rd_envio(5, 5);
    $r = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 5, 'aptas' => 5, 'novedad' => 0]));
    $stock = rd_stock($e['producto']);

    expect(rd_error(fn () => $anular->anularRetorno($admin, (int) $e['retorno']->id, 'x')))->toBe(Invalida::RETORNO_CON_RECEPCION)
        ->and(rd_error(fn () => $anular->anularRecepcion($admin, (int) $r->recepcion->id, 'x')))->toBe(Invalida::RECEPCION_YA_REINGRESADA)
        ->and(rd_stock($e['producto']))->toBe($stock);

    // Recepcion SIN reingreso (todo con novedad): se puede anular y la devolucion vuelve a quedar pendiente.
    $n = rd_envio(2, 2);
    $x = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($n, ['recibidas' => 2, 'aptas' => 0, 'novedad' => 2, 'motivo' => 'Dañadas']));
    $anulada = $anular->anularRecepcion($admin, (int) $x->recepcion->id, 'Conteo equivocado');

    expect($anulada->estado)->toBe(Estado::ANULADO)
        ->and(app(ConsultaEntregaService::class)->envio((int) $n['envio']->id)['estado_devolucion'])->toBe('pendiente_recibir')
        ->and(rd_error(fn () => $anular->anularRecepcion(rd_bodega(), (int) $x->recepcion->id, 'x')))->toBe(Invalida::SIN_PERMISO);

    $otra = app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($n, ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0]));

    expect($otra->recepcion->id)->not->toBe($x->recepcion->id);
});

test('RD-12 drawer JSON: contexto con permisos, entrega y devolucion por el mismo servicio, recepcion por HTTP y paginas antiguas intactas', function () {
    $e = rd_envio(6);
    $bodega = rd_bodega();
    $admin = f03b_admin('all');
    $sinAcceso = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor']);
    $ctx = route('admin.logistica.entregas.drawer.contexto', $e['envio']->id);

    // Asesor: rechazado (ACL por ruta + servicio).
    $this->actingAs($sinAcceso, 'admin')->getJson($ctx)->assertStatus(401);

    // Bodega: contexto completo con tallas/filas y permisos.
    $json = $this->actingAs($bodega, 'admin')->getJson($ctx)->assertOk()->json('contexto');

    expect($json['resumen'])->toBe(['despachadas' => 6, 'entregadas' => 0, 'devueltas' => 0, 'pendientes' => 6])
        ->and($json['permisos'])->toBe(['registrar' => true, 'anular' => false, 'recibir' => true, 'validar' => false])
        ->and($json['productos'][0]['filas'][0]['shipment_item_id'])->toBe($e['si'])
        ->and($json['historial'][0]['tipo'])->toBe('despacho');

    // Entrega parcial por el drawer (RegistrarEntregaService) y la misma clave no duplica.
    $clave = 'drawer-'.Str::uuid();
    $cuerpo = ['order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => 2], 'idempotency_key' => $clave, 'carrier_code' => 'BYLOPEZ'];
    $this->actingAs($bodega, 'admin')->postJson(route('admin.logistica.entregas.drawer.entregar', $e['envio']->id), $cuerpo)
        ->assertOk()->assertJsonPath('contexto.resumen.entregadas', 2)->assertJsonPath('ya_existia', false);
    $this->actingAs($bodega, 'admin')->postJson(route('admin.logistica.entregas.drawer.entregar', $e['envio']->id), $cuerpo)
        ->assertOk()->assertJsonPath('contexto.resumen.entregadas', 2)->assertJsonPath('ya_existia', true);

    // Devolucion de 3: queda pendiente de recibir y NO toca el stock.
    $stock = rd_stock($e['producto']);
    $json = $this->actingAs($bodega, 'admin')->postJson(route('admin.logistica.entregas.drawer.devolver', $e['envio']->id), [
        'order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => 3], 'idempotency_key' => 'drawer-dev-'.Str::uuid(), 'motivo' => 'Direccion no encontrada',
    ])->assertOk()->assertJsonPath('contexto.estado_devolucion', 'pendiente_recibir')->json('contexto');

    expect(rd_stock($e['producto']))->toBe($stock)
        ->and($json['resumen']['devueltas'])->toBe(3);

    // Recibir por HTTP: +3 aptas al inventario; repetir la misma peticion no vuelve a sumar.
    $retorno = $json['devoluciones'][0];
    $recibir = ['fecha' => now()->toDateString(), 'idempotency_key' => 'drawer-rec-'.Str::uuid(), 'lineas' => [$retorno['lineas'][0]['retorno_item_id'] => ['recibidas' => 3, 'aptas' => 3, 'novedad' => 0]]];
    $url = route('admin.logistica.entregas.recepcion.store', $retorno['id']);

    $this->actingAs($bodega, 'admin')->postJson($url, $recibir)->assertOk()->assertJsonPath('contexto.estado_devolucion', 'reintegrada');
    $this->actingAs($bodega, 'admin')->postJson($url, $recibir)->assertOk()->assertJsonPath('ya_existia', true);

    expect(rd_stock($e['producto']))->toBe($stock + 3);

    // Sin permiso de recibir: 401 por ACL de ruta, sin tocar nada.
    $sinClave = rd_bodega(['logistica', 'logistica.entregas', 'logistica.entregas.registrar']);
    $this->actingAs($sinClave, 'admin')->postJson($url, $recibir + ['x' => 1])->assertStatus(401);

    // Paginas clasicas (respaldo): siguen respondiendo.
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index'))->assertOk();
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['envio' => $e['envio']->id]))->assertOk();
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.crear', $e['envio']->id))->assertOk();
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.retornos.crear', $e['envio']->id))->assertOk();
});

test('RD-13 bandeja: botones que abren el drawer, chip de devolucion por recibir y KPI; el drawer queda incluido una sola vez', function () {
    $e = rd_envio(6, 2);
    $admin = f03b_admin('all');

    $html = $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos', 'pedido' => $e['pedido']->id]))->assertOk()->getContent();
    $bandeja = preg_replace('#<script type="text/x-template".*?</script>#s', '', $html);

    expect(substr_count($html, 'app.component(\'v-logistica-entregas\''))->toBe(1)
        ->and(substr_count($html, '<v-logistica-entregas'))->toBe(1)
        // Los tres botones de la fila (escritorio y movil) llevan los datos del drawer y un respaldo a la pagina clasica.
        ->and($bandeja)->toContain('data-modo="ver"')
        ->and($bandeja)->toContain('data-modo="entregar"')
        ->and($bandeja)->toContain('data-modo="devolver"')
        ->and($bandeja)->toContain('href="'.route('admin.logistica.entregas.crear', $e['envio']->id).'"')
        ->and($bandeja)->toContain('href="'.route('admin.logistica.retornos.crear', $e['envio']->id).'"')
        // Devolucion pendiente de recibir, en lenguaje humano (no el codigo interno).
        ->and($bandeja)->toContain('data-devolucion="pendiente_recibir"')
        ->and($bandeja)->toContain('Devolución pendiente de recibir')
        ->and($bandeja)->toContain('data-kpi="devoluciones-por-recibir"')
        // Terminologia: «retorno» ya no se lee en la pantalla.
        ->and($bandeja)->not->toContain('Registrar retorno de transportadora')
        ->and($bandeja)->toContain('Devoluciones (30 días)');

    // Sin permiso de registrar (solo lectura): ve el envio pero no los botones de accion.
    $lector = f03b_admin('custom', ['logistica', 'logistica.entregas']);
    $soloLectura = preg_replace('#<script type="text/x-template".*?</script>#s', '', $this->actingAs($lector, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos', 'pedido' => $e['pedido']->id]))->assertOk()->getContent());

    expect($soloLectura)->toContain('data-modo="ver"')->and($soloLectura)->not->toContain('data-modo="entregar"')->and($soloLectura)->not->toContain('data-modo="devolver"');

    // Una vez recibida, deja de pedir accion de Bodega.
    app(RecibirDevolucionService::class)->recibir(rd_bodega(), rd_recepcion($e, ['recibidas' => 2, 'aptas' => 2, 'novedad' => 0]));
    $despues = preg_replace('#<script type="text/x-template".*?</script>#s', '', $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos', 'pedido' => $e['pedido']->id]))->getContent());

    expect($despues)->not->toContain('data-devolucion="pendiente_recibir"');
});

test('RD-14 la logica del drawer unico (modos, validaciones, recepcion con novedades, claves de idempotencia) pasa su prueba JS', function () {
    $salida = [];
    exec('node '.escapeshellarg(base_path('packages/ByLopez/Logistica/tests/js/componente_entregas.mjs')).' '
        .escapeshellarg(base_path('packages/ByLopez/Logistica/src/Resources/views/admin/entregas/componente.blade.php')).' 2>&1', $salida, $codigo);

    expect($codigo)->toBe(0, implode("\n", $salida))
        ->and(implode("\n", $salida))->toContain('JS-OK');
});
