<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Repositories\ShipmentRepository;

// Fixtures de pedido / admin reutilizados de VentasAsesor (solo datos de prueba, lpz_saas_testing).
require_once __DIR__.'/../../../VentasAsesor/tests/helpersRt04b1a.php';
require_once __DIR__.'/../../../VentasAsesor/tests/helpersFase03b.php';

uses(TestCase::class);

/*
| LOG-01 — entrega canonica, retorno de transportadora, idempotencia, anulacion y permisos. Solo lpz_saas_testing.
*/

/** Pedido real de Bagisto con UN item de `$qty` unidades, despachado completo por ShipmentRepository (con sus listeners). */
function log_envio(int $qty = 5, string $transportadora = 'BYLOPEZ', ?string $metodo = null): array
{
    Mail::fake();
    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();
    $item->update(['qty_ordered' => $qty, 'qty_shipped' => 0]);

    if ($metodo !== null) {
        $pedido->update(['shipping_method' => $metodo]);
    }

    $envio = app(ShipmentRepository::class)->create(['order_id' => $pedido->id, 'shipment' => [
        'carrier_title' => $transportadora, 'track_number' => 'GUIA-'.uniqid(), 'source' => 1,
        'items' => [$item->id => [1 => $qty]],
    ]]);

    return ['pedido' => $pedido, 'envio' => $envio->fresh(), 'si' => (int) DB::table('shipment_items')->where('shipment_id', $envio->id)->value('id')];
}

function log_solicitud(array $e, array $items, array $extra = []): SolicitudEventoEnvio
{
    return new SolicitudEventoEnvio(...$extra + [
        'shipmentId' => (int) $e['envio']->id,
        'orderId' => (int) $e['pedido']->id,
        'fecha' => now()->toDateString(),
        'items' => $items,
        'fuente' => Fuente::BODEGA_MANUAL,
        'idempotencyKey' => 'test-'.Str::uuid(),
    ]);
}

function log_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

function log_bodega()
{
    return f03b_admin('custom', ['logistica', 'logistica.entregas', 'logistica.entregas.registrar']);
}

test('LOG-1 entrega completa: evento con snapshots auditables y codigo estable del despacho nuevo', function () {
    $e = log_envio(5, 'BYLOPEZ');
    $bodega = log_bodega();

    $r = app(RegistrarEntregaService::class)->registrar($bodega, log_solicitud($e, [$e['si'] => 5], ['evidenciaReferencia' => 'Recibio Ana']));
    $entrega = $r->registro;

    expect($r->yaExistia)->toBeFalse()
        ->and($e['envio']->carrier_code)->toBe('BYLOPEZ') // bloque 11: texto exacto del catalogo → codigo
        ->and($entrega->order_id)->toBe((int) $e['pedido']->id)
        ->and($entrega->shipment_id)->toBe((int) $e['envio']->id)
        ->and($entrega->estado)->toBe(Estado::CONFIRMADO)
        ->and($entrega->fuente)->toBe(Fuente::BODEGA_MANUAL)
        ->and($entrega->actor_admin_id)->toBe((int) $bodega->id)
        ->and($entrega->actor_name_snapshot)->toBe($bodega->name)
        ->and($entrega->carrier_code_snapshot)->toBe('BYLOPEZ')
        ->and($entrega->track_number_snapshot)->toBe($e['envio']->track_number)
        ->and($entrega->evidencia_referencia)->toBe('Recibio Ana')
        ->and($entrega->fecha_entrega->toDateString())->toBe(now()->toDateString())
        ->and($entrega->items->sole()->cantidad)->toBe(5)
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id))
        ->toBe(['despachadas' => 5, 'entregadas' => 5, 'retornadas' => 0, 'pendientes' => 0])
        ->and(app(ConsultaEntregaService::class)->unidadesEntregadas([(int) $e['pedido']->id])->sole()->cantidad)->toBe(5);

    // Texto libre que no es EXACTAMENTE un codigo/nombre del catalogo: no se adivina.
    expect(log_envio(1, 'INTERRAPIDISMO')['envio']->carrier_code)->toBeNull();
});

test('LOG-2 entrega parcial: 2 + 3 completa el envio y una unidad mas se rechaza', function () {
    $e = log_envio(5);
    $bodega = log_bodega();
    $servicio = app(RegistrarEntregaService::class);

    $servicio->registrar($bodega, log_solicitud($e, [$e['si'] => 2]));
    $parcial = app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id);
    $servicio->registrar($bodega, log_solicitud($e, [$e['si'] => 3]));

    expect($parcial)->toBe(['despachadas' => 5, 'entregadas' => 2, 'retornadas' => 0, 'pendientes' => 3])
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['pendientes'])->toBe(0)
        ->and(log_error(fn () => $servicio->registrar($bodega, log_solicitud($e, [$e['si'] => 1]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE)
        ->and(Entrega::query()->where('shipment_id', $e['envio']->id)->count())->toBe(2);
});

test('LOG-3 idempotencia: el reintento con la misma clave devuelve la misma entrega; otra solicitud con esa clave es conflicto', function () {
    $e = log_envio(5);
    $bodega = log_bodega();
    $servicio = app(RegistrarEntregaService::class);
    $solicitud = log_solicitud($e, [$e['si'] => 2], ['idempotencyKey' => 'doble-clic-0001']);

    $primero = $servicio->registrar($bodega, $solicitud);
    $reintento = $servicio->registrar($bodega, $solicitud);

    expect($reintento->yaExistia)->toBeTrue()
        ->and($reintento->registro->id)->toBe($primero->registro->id)
        ->and(Entrega::query()->where('idempotency_key', 'doble-clic-0001')->count())->toBe(1)
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['entregadas'])->toBe(2)
        ->and(log_error(fn () => $servicio->registrar($bodega, log_solicitud($e, [$e['si'] => 3], ['idempotencyKey' => 'doble-clic-0001']))))
        ->toBe(Invalida::IDEMPOTENCIA_CONFLICTO)
        ->and(log_error(fn () => $servicio->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['idempotencyKey' => 'x']))))
        ->toBe(Invalida::IDEMPOTENCIA_INVALIDA);
});

test('LOG-4 validaciones: no mas que lo despachado, ni items ajenos, ni otro pedido, ni cantidades/fechas invalidas', function () {
    $e = log_envio(5);
    $otro = log_envio(2);
    $sinCodigo = log_envio(1, 'RECOLECCION INGRID');
    $bodega = log_bodega();
    $s = app(RegistrarEntregaService::class);

    expect(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 6]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$otro['si'] => 1]))))->toBe(Invalida::ITEM_NO_DESPACHADO)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['orderId' => (int) $otro['pedido']->id]))))->toBe(Invalida::ENVIO_DE_OTRO_PEDIDO)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 0]))))->toBe(Invalida::CANTIDAD_INVALIDA)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => -1]))))->toBe(Invalida::CANTIDAD_INVALIDA)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, []))))->toBe(Invalida::SIN_UNIDADES)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['fecha' => now()->addDay()->toDateString()]))))->toBe(Invalida::FECHA_INVALIDA)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['fecha' => now()->subYear()->toDateString()]))))->toBe(Invalida::FECHA_ANTERIOR_AL_DESPACHO)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['shipmentId' => 999999999]))))->toBe(Invalida::ENVIO_INEXISTENTE)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($e, [$e['si'] => 1], ['fuente' => Fuente::INTEGRACION_TRANSPORTADORA]))))->toBe(Invalida::FUENTE_INVALIDA)
        // Despacho con texto libre (historico o no exacto): la transportadora del catalogo es obligatoria.
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($sinCodigo, [$sinCodigo['si'] => 1]))))->toBe(Invalida::TRANSPORTADORA_REQUERIDA)
        ->and(log_error(fn () => $s->registrar($bodega, log_solicitud($sinCodigo, [$sinCodigo['si'] => 1], ['carrierCode' => 'NO_EXISTE']))))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and(Entrega::query()->whereIn('shipment_id', [$e['envio']->id, $sinCodigo['envio']->id])->count())->toBe(0);

    $ok = $s->registrar($bodega, log_solicitud($sinCodigo, [$sinCodigo['si'] => 1], ['carrierCode' => 'DOMICILIARIO']))->registro;

    expect($ok->carrier_code_snapshot)->toBe('DOMICILIARIO')
        ->and($ok->carrier_title_snapshot)->toBe('RECOLECCION INGRID')
        ->and(DB::table('shipments')->where('id', $sinCodigo['envio']->id)->value('carrier_code'))->toBeNull(); // el despacho no se toca
});

test('LOG-5 anulacion auditada: motivo obligatorio, solo supervision, sin edicion ni borrado; luego se registra la correcta', function () {
    $e = log_envio(5);
    $bodega = log_bodega();
    $admin = f03b_admin('all');
    $entrega = app(RegistrarEntregaService::class)->registrar($bodega, log_solicitud($e, [$e['si'] => 5]))->registro;
    $anular = app(AnularEventoLogisticoService::class);

    expect(log_error(fn () => $anular->anularEntrega($bodega, $entrega->id, 'error')))->toBe(Invalida::SIN_PERMISO)
        ->and(log_error(fn () => $anular->anularEntrega($admin, $entrega->id, '  ')))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log_error(fn () => $entrega->fresh()->update(['fecha_entrega' => now()->subDay()->toDateString()])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log_error(fn () => $entrega->fresh()->delete()))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log_error(fn () => $entrega->items->first()->update(['cantidad' => 1])))->toBe(Invalida::REGISTRO_INMUTABLE);

    $anulada = $anular->anularEntrega($admin, $entrega->id, 'Se registro en el pedido equivocado');

    expect($anulada->estado)->toBe(Estado::ANULADO)
        ->and($anulada->motivo_anulacion)->toBe('Se registro en el pedido equivocado')
        ->and($anulada->anulada_por_admin_id)->toBe((int) $admin->id)
        ->and($anulada->anulada_por_name_snapshot)->toBe($admin->name)
        ->and($anulada->anulada_en)->not->toBeNull()
        ->and($anulada->fresh()->fecha_entrega->toDateString())->toBe(now()->toDateString()) // el hecho original se conserva
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['pendientes'])->toBe(5)
        ->and(app(ConsultaEntregaService::class)->unidadesEntregadas([(int) $e['pedido']->id]))->toHaveCount(0)
        ->and(log_error(fn () => $anular->anularEntrega($admin, $entrega->id, 'otra vez')))->toBe(Invalida::YA_ANULADO);

    app(RegistrarEntregaService::class)->registrar($bodega, log_solicitud($e, [$e['si'] => 4]));

    expect(Entrega::query()->where('shipment_id', $e['envio']->id)->count())->toBe(2)
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['entregadas'])->toBe(4);
});

test('LOG-6 retorno de transportadora: evento separado, motivo obligatorio, consume pendientes y nunca cuenta como entrega', function () {
    $e = log_envio(5);
    $bodega = log_bodega();
    $retornos = app(RegistrarRetornoTransportadoraService::class);

    expect(log_error(fn () => $retornos->registrar($bodega, log_solicitud($e, [$e['si'] => 2]))))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log_error(fn () => $retornos->registrar($bodega, log_solicitud($e, [$e['si'] => 2], ['motivo' => 'x', 'fuente' => Fuente::RECOGIDA_BODEGA]))))->toBe(Invalida::FUENTE_INVALIDA);

    $retorno = $retornos->registrar($bodega, log_solicitud($e, [$e['si'] => 2], ['motivo' => 'Direccion no encontrada']))->registro;

    expect($retorno)->toBeInstanceOf(Retorno::class)
        ->and($retorno->motivo)->toBe('Direccion no encontrada')
        ->and(Entrega::query()->where('shipment_id', $e['envio']->id)->count())->toBe(0)
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id))
        ->toBe(['despachadas' => 5, 'entregadas' => 0, 'retornadas' => 2, 'pendientes' => 3])
        ->and(app(ConsultaEntregaService::class)->unidadesEntregadas([(int) $e['pedido']->id]))->toHaveCount(0)
        ->and(log_error(fn () => app(RegistrarEntregaService::class)->registrar($bodega, log_solicitud($e, [$e['si'] => 4]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE)
        // No toca Bagisto: el Shipment sigue y no hay Refund.
        ->and(DB::table('shipments')->where('id', $e['envio']->id)->exists())->toBeTrue()
        ->and(DB::table('refunds')->where('order_id', $e['pedido']->id)->count())->toBe(0);

    app(AnularEventoLogisticoService::class)->anularRetorno(f03b_admin('all'), $retorno->id, 'Si se entrego');

    expect(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['pendientes'])->toBe(5);
});

test('LOG-7 el asesor no registra ni anula (servicio y HTTP)', function () {
    $e = log_envio(2);
    $asesor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create', 'ventas_asesor.pedidos.view_own']);
    $entrega = app(RegistrarEntregaService::class)->registrar(log_bodega(), log_solicitud($e, [$e['si'] => 1]))->registro;

    expect(log_error(fn () => app(RegistrarEntregaService::class)->registrar($asesor, log_solicitud($e, [$e['si'] => 1]))))->toBe(Invalida::SIN_PERMISO)
        ->and(log_error(fn () => app(RegistrarRetornoTransportadoraService::class)->registrar($asesor, log_solicitud($e, [$e['si'] => 1], ['motivo' => 'x']))))->toBe(Invalida::SIN_PERMISO)
        ->and(log_error(fn () => app(AnularEventoLogisticoService::class)->anularEntrega($asesor, $entrega->id, 'x')))->toBe(Invalida::SIN_PERMISO);

    $this->actingAs($asesor, 'admin')->get(route('admin.logistica.entregas.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.logistica.entregas.store', $e['envio']->id), [
        'order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => 1], 'idempotency_key' => 'asesor-'.Str::uuid(),
    ])->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.logistica.entregas.anular', $entrega->id), ['motivo' => 'x'])->assertUnauthorized();

    expect(Entrega::query()->where('shipment_id', $e['envio']->id)->count())->toBe(1)
        ->and($entrega->fresh()->estado)->toBe(Estado::CONFIRMADO);
});

test('LOG-8 Bodega registra por el panel (bodega_manual) y no anula; Admin ve, anula y administra transportadoras', function () {
    $e = log_envio(3);
    $bodega = log_bodega();
    $admin = f03b_admin('all');

    $this->actingAs($bodega, 'admin')->get(route('admin.logistica.entregas.index'))->assertOk()->assertSee('#'.$e['pedido']->increment_id);
    $this->actingAs($bodega, 'admin')->get(route('admin.logistica.entregas.crear', $e['envio']->id))->assertOk();
    $this->actingAs($bodega, 'admin')->post(route('admin.logistica.entregas.store', $e['envio']->id), [
        'order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => '2'], 'idempotency_key' => 'panel-'.Str::uuid(),
    ])->assertRedirect(route('admin.logistica.entregas.index', ['envio' => $e['envio']->id]));

    $entrega = Entrega::query()->where('shipment_id', $e['envio']->id)->sole();

    $this->actingAs($bodega, 'admin')->post(route('admin.logistica.entregas.anular', $entrega->id), ['motivo' => 'x'])->assertUnauthorized();
    $this->actingAs($bodega, 'admin')->post(route('admin.logistica.transportadoras.store'), ['codigo' => 'NUEVA', 'nombre' => 'N', 'tipo' => 'otra'])->assertUnauthorized();

    $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['envio' => $e['envio']->id]))->assertOk()->assertSee('data-entrega="'.$entrega->id.'"', false);
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.retornos.crear', $e['envio']->id))->assertOk();
    $this->actingAs($admin, 'admin')->post(route('admin.logistica.entregas.anular', $entrega->id), ['motivo' => 'Cantidad equivocada'])->assertRedirect();
    $this->actingAs($admin, 'admin')->get(route('admin.logistica.transportadoras.index'))->assertOk()->assertSee('INTER_RAPIDISIMO');
    $this->actingAs($admin, 'admin')->post(route('admin.logistica.transportadoras.store'), ['codigo' => 'servientrega', 'nombre' => 'Servientrega', 'tipo' => 'transportadora'])->assertRedirect();

    expect($entrega->fuente)->toBe(Fuente::BODEGA_MANUAL)
        ->and($entrega->items->sole()->cantidad)->toBe(2)
        ->and($entrega->fresh()->estado)->toBe(Estado::ANULADO)
        ->and(DB::table('bylopez_lg_transportadoras')->where('codigo', 'SERVIENTREGA')->exists())->toBeTrue();
});

test('LOG-9 la cita de recogida no es entrega; el retiro real se registra como recogida_bodega', function () {
    $e = log_envio(2, 'RECOGIDA_BODEGA', 'pickup');

    if (Schema::hasTable('pickup_orders')) {
        $franja = DB::table('pickup_timeslots')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('pickup_orders')->insert([
            'order_id' => $e['pedido']->id, 'customer_id' => $e['pedido']->customer_id, 'timeslot_id' => $franja,
            'inventory_source_id' => 1, 'pickup_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id))
        ->toBe(['despachadas' => 2, 'entregadas' => 0, 'retornadas' => 0, 'pendientes' => 2]);

    $this->actingAs(f03b_admin('all'), 'admin')->post(route('admin.logistica.entregas.store', $e['envio']->id), [
        'order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => 2], 'idempotency_key' => 'retiro-'.Str::uuid(),
    ])->assertRedirect();

    expect(Entrega::query()->where('shipment_id', $e['envio']->id)->sole()->fuente)->toBe(Fuente::RECOGIDA_BODEGA)
        ->and(app(ConsultaEntregaService::class)->resumenPedido((int) $e['pedido']->id)['entregadas'])->toBe(2);
});

test('LOG-10 Entregas: el listado es operativo (estados separados, cifras, sin tallas ni SKU) y «Ver» trae el detalle con tallas', function () {
    $e = log_envio(4);
    $admin = f03b_admin('all');
    $sku = (string) DB::table('shipment_items')->where('id', $e['si'])->value('sku');

    $lista = $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index'))->assertOk()->getContent();
    // La plantilla del drawer (script x-template) vive en la pagina pero no es listado: se mide solo la bandeja.
    $bandeja = fn (string $html) => preg_replace('#<script type="text/x-template".*?</script>#s', '', $html);
    $lista = $bandeja($lista);

    // Nivel 1: entrega y validacion ByLopez son chips distintos; cifras presentes; sin SKU de variantes ni tallas.
    expect($lista)->toContain('data-estado-entrega="pendiente"')
        ->and($lista)->toContain('data-validacion="sin_registro"')
        ->and($lista)->toContain('data-cifras')
        ->and($lista)->toContain('data-tipo-producto')
        ->and($lista)->toContain('data-logistica-entregas')
        ->and($lista)->not->toContain('data-productos')
        ->and($lista)->not->toContain('class="lg-talla')->and($lista)->not->toContain('data-item=');

    if ($sku !== '') {
        expect(substr_count($lista, '>'.$sku.'<'))->toBe(0);
    }

    // Parcial: entrega registrada → estado parcial + validacion «registrada».
    $this->actingAs($admin, 'admin')->post(route('admin.logistica.entregas.store', $e['envio']->id), [
        'order_id' => $e['pedido']->id, 'fecha' => now()->toDateString(), 'items' => [$e['si'] => '1'], 'idempotency_key' => 'ui-'.Str::uuid(),
    ])->assertRedirect();

    $lista = $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => 'todos']))->getContent();

    expect($lista)->toContain('data-estado-entrega="parcial"')->and($lista)->toContain('data-validacion="registrada"');

    // Filtros nuevos: sin entrega registrada ya no lo incluye; entregados tampoco (hay pendientes).
    foreach (['sin_entrega', 'entregados'] as $estado) {
        $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['estado' => $estado, 'pedido' => $e['pedido']->id]))
            ->assertOk()->assertDontSee('data-envio="'.$e['envio']->id.'"', false);
    }

    // Nivel 2: el detalle si trae resumen, informacion general, productos con tallas y trazabilidad.
    $detalle = $this->actingAs($admin, 'admin')->get(route('admin.logistica.entregas.index', ['envio' => $e['envio']->id]))->assertOk()->getContent();

    expect($detalle)->toContain('data-general')
        ->and($detalle)->toContain('data-productos')
        ->and($detalle)->toContain('data-item="'.$e['si'].'"')
        ->and($detalle)->toContain('lg-stats--grande')
        ->and($detalle)->toContain('data-validacion="registrada"');
});
