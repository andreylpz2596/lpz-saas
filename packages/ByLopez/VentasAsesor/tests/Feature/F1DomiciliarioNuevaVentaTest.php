<?php

use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Exceptions\EntregaBorradorInvalidaException;
use ByLopez\VentasAsesor\Exceptions\NuevaVentaInvalidaException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use ByLopez\VentasAsesor\Services\Venta\EntregaBorradorService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\Support\Logistica\MunicipioDesdeCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDomicilios.php';

uses(TestCase::class);

/*
| Cierre F1 · envios locales en Nueva venta: en una ZONA local configurada (Bogota, Soacha) el DOMICILIARIO es obligatorio,
| fija la tarifa del domicilio (F1: transportadora + zona, nunca un valor generico) y el servidor no deja confirmar sin el.
| El metodo de envio sigue decidiendo QUIEN PAGA; domiciliario + zona deciden CUANTO CUESTA. Venta, catalogo DIVIPOLA,
| carrier y pedido REALES. Solo lpz_saas_testing.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();   // domicilio_local con valor GENERICO de 8000: ya NO se usa como fallback (destino no local = no se ofrece)
    geo_cargar([...geo_filas(), ['25', 'CUNDINAMARCA', '25754', 'SOACHA', 'Municipio', '-74,2168', '4,5794']]);

    $this->c = log6_catalogo();   // Jeison 10.000/12.000 · Brian 12.000/12.000 · PICAP sin tarifa
    $this->asesor = f03b_admin();
    $this->cliente = f03b_cliente(f03b_grupo('A'));
    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);
    $this->ventas = app(NuevaVentaService::class);
});

/**
 * Venta en construccion hasta la direccion de envio en `$municipio` (DIVIPOLA). El domiciliario (si se indica) se elige
 * ANTES del metodo, como en la pantalla. Con `$metodo` null no se elige metodo ni pago.
 */
function f1v_venta($test, string $municipio, ?string $metodo = 'domicilio_local', ?int $domiciliario = null): BorradorVenta
{
    $borrador = app(BorradorVentaService::class)->crear($test->cliente->id, $test->asesor, CanalVenta::WHATSAPP);
    app(SolicitudBorradorService::class)->crear($borrador->id, $test->configurable->id, $test->variante->id, 1, $test->asesor);
    app(LineasBorradorService::class)->agregar($borrador->id, $test->configurable->id, $test->variante->id, 1, $test->asesor);

    $entregas = app(EntregaBorradorService::class);
    $entregas->establecerFacturacion($borrador->id, OrigenDireccion::puntual(f03f_direccion(['municipioCodigo' => '11001'])), $test->asesor);
    $entregas->establecerEnvio($borrador->id, $metodo === PuntosRecogida::METODO
        ? OrigenDireccion::puntoRecogida()
        : OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7'], 'municipioCodigo' => $municipio])), $test->asesor);

    if ($domiciliario !== null) {
        $test->ventas->elegirDomiciliario($borrador->id, $domiciliario, $test->asesor);
    }

    if ($metodo !== null) {
        $entregas->seleccionarMetodo($borrador->id, $metodo, $test->asesor);
        app(PagoBorradorService::class)->seleccionar($borrador->id, 'moneytransfer', $test->asesor);
    }

    return $borrador->fresh();
}

function f1v_estado($test, BorradorVenta $b): array
{
    return $test->ventas->estado($b->id)->toArray();
}

function f1v_domiciliario($test, BorradorVenta $b): ?array
{
    return f1v_estado($test, $b)['entrega']['domiciliario'];
}

function f1v_metodos($test, BorradorVenta $b): array
{
    return array_column(f1v_estado($test, $b)['entrega']['metodos_disponibles'], 'precio', 'codigo');
}

function f1v_confirmar($test, BorradorVenta $b): int
{
    return f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($b->id, $test->asesor, f03f_total($b)))->orderId;
}

/** El motivo con el que el servidor rechaza confirmar una entrega invalida (null si no la rechaza). */
function f1v_rechazo_entrega($test, BorradorVenta $b): ?string
{
    $e = f03f_error(fn () => app(ConfirmarVentaService::class)->confirmar($b->id, $test->asesor, f03f_total($b)));

    return $e?->codigo === Rechazo::ENTREGA_INVALIDA ? ($e->detalles['motivo'] ?? null) : ($e?->codigo);
}

function f1v_envio_pedido(int $orderId): array
{
    $o = DB::table('orders')->where('id', $orderId)->first(['shipping_method', 'shipping_amount']);

    return [$o->shipping_method, round((float) $o->shipping_amount, 2)];
}

/** Lo que BODEGA ve preseleccionado en el drawer del pedido. */
function f1v_drawer(int $orderId): ?string
{
    return app(ContextoDespachoService::class)->paraPedido($orderId, log2_bodega())['sugerencias']['transportadora'];
}

/** Ultimo domiciliario realmente usado en Bogota (despacho real), y vuelve al resolvedor real del catalogo. */
function f1v_historial($test, $transportadora): void
{
    log6_municipio('11001');
    log6_despachar(log2_pedido(1, ['envio' => 'domicilio_local', 'pago' => 'moneytransfer']), $transportadora);
    app()->instance(ResuelveMunicipio::class, app(MunicipioDesdeCatalogo::class));
}

test('F1V-1 la tarifa sale de domiciliario + zona (no del valor generico) y se actualiza al cambiar de domiciliario o de zona', function () {
    $venta = f1v_venta($this, '11001', null);
    $d = f1v_domiciliario($this, $venta);

    // Zona local sin domiciliario: obligatorio, sin tarifa, y el domicilio NO se ofrece con valor generico.
    expect($d['zona']['id'])->toBe($this->c['bogota']->id)
        ->and($d['requerido'])->toBeTrue()
        ->and($d['listo'])->toBeFalse()
        ->and($d['tarifa'])->toBeNull()
        ->and(array_column($d['opciones'], 'tarifa', 'nombre'))->toBe(['BRIAN' => 12000.0, 'JEISON' => 10000.0, 'PICAP' => null])
        ->and(f1v_metodos($this, $venta))->not->toHaveKey('domicilio_local');

    // Jeison + Bogota = 10.000; el metodo domicilio_local aparece con ESE valor y entra al total.
    $d = $this->ventas->elegirDomiciliario($venta->id, $this->c['jeison']->id, $this->asesor)->toArray()['entrega']['domiciliario'];

    expect($d['listo'])->toBeTrue()->and($d['tarifa'])->toBe(10000.0)
        ->and(f1v_metodos($this, $venta)['domicilio_local'])->toBe(10000.0);

    app(EntregaBorradorService::class)->seleccionarMetodo($venta->id, 'domicilio_local', $this->asesor);
    $estado = f1v_estado($this, $venta);

    expect($estado['entrega']['domiciliario']['condicion'])->toBe('cobrado_en_pedido')
        ->and((float) $estado['borrador']['totales']['shipping_amount'])->toBe(10000.0);

    // Cambiar a Brian actualiza al instante: 12.000 en el metodo ya elegido, en el total y en la tarifa mostrada.
    $estado = $this->ventas->elegirDomiciliario($venta->id, $this->c['brian']->id, $this->asesor)->toArray();

    expect($estado['entrega']['domiciliario']['tarifa'])->toBe(12000.0)
        ->and($estado['entrega']['metodo_seleccionado']['codigo'])->toBe('domicilio_local')
        ->and($estado['entrega']['metodo_seleccionado']['precio'])->toBe(12000.0)
        ->and((float) $estado['borrador']['totales']['shipping_amount'])->toBe(12000.0)
        ->and($estado['requiere_revision_entrega'])->toBeFalse();

    // Soacha: la misma tarifa es por zona (Jeison 12.000).
    $soacha = f1v_venta($this, '25754', 'domicilio_local', $this->c['jeison']->id);
    expect(f1v_domiciliario($this, $soacha)['tarifa'])->toBe(12000.0)
        ->and(f1v_metodos($this, $soacha)['domicilio_local'])->toBe(12000.0);

    // Al confirmar, el pedido lleva la tarifa de la zona (no 8.000 generico) y la preferencia queda guardada para el despacho.
    app(PagoBorradorService::class)->seleccionar($venta->id, 'moneytransfer', $this->asesor);
    $pedido = f1v_confirmar($this, $venta->fresh());

    expect(f1v_envio_pedido($pedido))->toBe(['domicilio_local', 12000.0])
        ->and(DB::table('bylopez_lg_preferencias_entrega')->where('order_id', $pedido)->first())
        ->transportadora_id->toBe($this->c['brian']->id)
        ->origen->toBe('venta')
        ->seleccionado_por_admin_id->toBe((int) $this->asesor->id)
        ->and(f1v_drawer($pedido))->toBe($this->c['brian']->codigo);
});

test('F1V-2 el metodo decide quien paga: gratis no suma al pedido, pero el domiciliario y su tarifa siguen siendo obligatorios', function () {
    $venta = f1v_venta($this, '11001', 'free_free');
    $d = f1v_domiciliario($this, $venta);

    // Bogota + envio gratis es candidato local: domiciliario requerido, tarifa solo informativa (ByLopez la asume).
    expect($d['requerido'])->toBeTrue()->and($d['listo'])->toBeFalse()->and($d['condicion'])->toBe('gratis')
        ->and(f1v_estado($this, $venta)['motivos_no_confirmable'])->toContain('domiciliario_requerido')
        ->and(f1v_estado($this, $venta)['confirmable'])->toBeFalse()
        // El servidor tambien lo exige: no se confirma una entrega local sin domiciliario.
        ->and(f1v_rechazo_entrega($this, $venta))->toBe('domiciliario_requerido');

    $d = $this->ventas->elegirDomiciliario($venta->id, $this->c['jeison']->id, $this->asesor)->toArray()['entrega']['domiciliario'];
    expect($d['tarifa'])->toBe(10000.0)->and($d['listo'])->toBeTrue()
        ->and(f1v_estado($this, $venta)['motivos_no_confirmable'])->not->toContain('domiciliario_requerido');

    $pedido = f1v_confirmar($this, $venta);

    // Gratis: el pedido no cobra domicilio; la tarifa del domiciliario la asume ByLopez (lo decide Logistica al despachar).
    expect(f1v_envio_pedido($pedido)[1])->toBe(0.0)
        ->and(f1v_drawer($pedido))->toBe($this->c['jeison']->codigo);
});

test('F1V-3 la eleccion explicita gana al historial de la zona; el historial solo sugiere (y solo con tarifa en esa zona)', function () {
    f1v_historial($this, $this->c['brian']);

    $venta = f1v_venta($this, '11001', null);
    $d = f1v_domiciliario($this, $venta);

    expect($d['sugerido_id'])->toBe($this->c['brian']->id)->and($d['elegido_id'])->toBeNull()->and($d['listo'])->toBeFalse();

    // El asesor elige Jeison aunque el ultimo usado en Bogota sea Brian.
    $this->ventas->elegirDomiciliario($venta->id, $this->c['jeison']->id, $this->asesor);
    app(EntregaBorradorService::class)->seleccionarMetodo($venta->id, 'domicilio_local', $this->asesor);
    app(PagoBorradorService::class)->seleccionar($venta->id, 'moneytransfer', $this->asesor);

    expect(f1v_drawer(f1v_confirmar($this, $venta->fresh())))->toBe($this->c['jeison']->codigo);

    // Sin historial en Soacha: nada sugerido; y un historial de PICAP (sin tarifa en Bogota) nunca se sugiere.
    expect(f1v_domiciliario($this, f1v_venta($this, '25754', null))['sugerido_id'])->toBeNull();
});

test('F1V-4 destino NO local para el origen: sin Domicilio local (ni con el valor del panel), sin selector ni exigencia', function () {
    // Medellin no es zona local de la bodega: el domicilio_local NO se ofrece (el valor configurado de 8.000 ya no es un fallback).
    $medellin = f1v_venta($this, '05001', null);

    expect(f1v_domiciliario($this, $medellin))->toBeNull()
        ->and(f1v_metodos($this, $medellin))->not->toHaveKey('domicilio_local')
        ->and(fn () => app(EntregaBorradorService::class)->seleccionarMetodo($medellin->id, 'domicilio_local', $this->asesor))->toThrow(EntregaBorradorInvalidaException::class)
        ->and(f1v_estado($this, $medellin)['motivos_no_confirmable'])->not->toContain('domiciliario_requerido');

    // Free_free a Medellin tampoco: sin zona local no hay domiciliario ni sugerencia.
    expect(f1v_domiciliario($this, f1v_venta($this, '05001', 'free_free')))->toBeNull();

    // Recogida (la direccion es la bodega) y envio nacional (aunque la direccion este en Bogota): sin selector ni costo de domicilio.
    $recogida = f1v_venta($this, '11001', PuntosRecogida::METODO);
    expect(f1v_domiciliario($this, $recogida))->toBeNull();
    $pedido = f1v_confirmar($this, $recogida);
    expect(f1v_envio_pedido($pedido)[1])->toBe(0.0)
        ->and(DB::table('bylopez_lg_preferencias_entrega')->where('order_id', $pedido)->exists())->toBeFalse();

    $nacional = f1v_venta($this, '11001', 'envio_nacional');
    expect(f1v_domiciliario($this, $nacional))->toBeNull()
        ->and(f1v_estado($this, $nacional)['motivos_no_confirmable'])->not->toContain('domiciliario_requerido');

    $pedidoNacional = f1v_confirmar($this, $nacional);

    expect(f1v_envio_pedido($pedidoNacional)[0])->toBe('envio_nacional')
        ->and(DB::table('bylopez_lg_preferencias_entrega')->where('order_id', $pedidoNacional)->exists())->toBeFalse();
});

test('F1V-5 sin tarifa resoluble no se confirma: PICAP se elige a mano solo si tiene tarifa en la zona; desactivada o generico no', function () {
    $venta = f1v_venta($this, '11001', 'free_free');

    // PICAP no tiene tarifa en Bogota: queda elegido pero no resuelve tarifa → no listo y el servidor lo rechaza.
    $d = $this->ventas->elegirDomiciliario($venta->id, $this->c['picap']->id, $this->asesor)->toArray()['entrega']['domiciliario'];
    expect($d['elegido_id'])->toBe($this->c['picap']->id)->and($d['tarifa'])->toBeNull()->and($d['listo'])->toBeFalse()
        ->and(f1v_rechazo_entrega($this, $venta))->toBe('domiciliario_requerido');

    // El domicilio local tampoco se ofrece con PICAP elegido (sin tarifa no hay valor).
    expect(f1v_metodos($this, $venta))->not->toHaveKey('domicilio_local');

    // Con tarifa configurada en Bogota, PICAP es valido (seleccion MANUAL; nunca sugerido por historial).
    Tarifa::query()->create(['transportadora_id' => $this->c['picap']->id, 'zona_id' => $this->c['bogota']->id, 'valor' => 8500, 'vigente_desde' => now()->subDay()->toDateString()]);
    $d = f1v_domiciliario($this, $venta);

    expect($d['tarifa'])->toBe(8500.0)->and($d['listo'])->toBeTrue()->and($d['sugerido_id'])->toBeNull()
        ->and(f1v_estado($this, $venta)['motivos_no_confirmable'])->not->toContain('domiciliario_requerido');

    // Desactivada o generico: no se pueden elegir.
    $this->c['brian']->update(['activo' => false]);
    DB::table('bylopez_lg_transportadoras')->updateOrInsert(['codigo' => 'DOMICILIARIO'], ['nombre' => 'Domiciliario', 'tipo' => 'domiciliario', 'activo' => true]);
    $generico = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'DOMICILIARIO')->value('id');

    foreach ([$this->c['brian']->id, $generico] as $invalida) {
        $error = null;

        try {
            $this->ventas->elegirDomiciliario($venta->id, $invalida, $this->asesor);
        } catch (NuevaVentaInvalidaException $e) {
            $error = $e->codigo;
        }

        expect($error)->toBe(NuevaVentaInvalidaException::DOMICILIARIO_NO_VALIDO)
            ->and($venta->fresh()->transportadora_id)->toBe($this->c['picap']->id);
    }

    expect(array_column(f1v_domiciliario($this, $venta)['opciones'], 'id'))->not->toContain($this->c['brian']->id, $generico);

    // Quitar la eleccion en zona local vuelve a bloquear: el domiciliario es obligatorio.
    $this->ventas->elegirDomiciliario($venta->id, null, $this->asesor);
    expect($venta->fresh()->transportadora_id)->toBeNull()
        ->and(f1v_rechazo_entrega($this, $venta))->toBe('domiciliario_requerido');
});

test('F1V-6 cambiar la direccion de zona recalcula: la tarifa del elegido en la nueva zona, y sin ella vuelve a ser obligatorio', function () {
    $venta = f1v_venta($this, '11001', 'domicilio_local', $this->c['jeison']->id);

    expect(f1v_domiciliario($this, $venta)['tarifa'])->toBe(10000.0);

    // Misma venta, direccion en Soacha: Jeison paga 12.000 alli.
    $entregas = app(EntregaBorradorService::class);
    $entregas->establecerEnvio($venta->id, OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Calle 5 # 4-3'], 'municipioCodigo' => '25754'])), $this->asesor);
    $entregas->seleccionarMetodo($venta->id, 'domicilio_local', $this->asesor);

    $d = f1v_domiciliario($this, $venta);
    expect($d['zona']['id'])->toBe($this->c['soacha']->id)->and($d['tarifa'])->toBe(12000.0);

    // A una direccion que no es local para el origen: ya no es domicilio local (sin selector ni metodo).
    $entregas->establecerEnvio($venta->id, OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Calle 1 # 2-3'], 'municipioCodigo' => '05001'])), $this->asesor);

    expect(f1v_domiciliario($this, $venta))->toBeNull();
});

test('F1V-7 HTTP: el asesor elige domiciliario desde la pantalla y recibe la tarifa (ruta con ACL de Nueva venta)', function () {
    $venta = f1v_venta($this, '11001', null);

    $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.domiciliario', $venta->id), ['transportadora_id' => $this->c['brian']->id])
        ->assertOk()
        ->assertJsonPath('entrega.domiciliario.elegido_id', $this->c['brian']->id)
        ->assertJsonPath('entrega.domiciliario.tarifa', 12000)
        ->assertJsonPath('entrega.domiciliario.listo', true);

    $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.domiciliario', $venta->id), ['transportadora_id' => null])
        ->assertOk()
        ->assertJsonPath('entrega.domiciliario.elegido_id', null)
        ->assertJsonPath('entrega.domiciliario.listo', false);
});

test('F1V-8 ORIGEN + DESTINO + zonas del origen deciden lo local: desvincular Soacha la vuelve nacional y recalcula sin tocar codigo', function () {
    $locales = app(ZonaLocalDeOrigen::class);
    $origen = $locales->origenPredeterminadoId();

    // Configuracion de la prueba: la bodega predeterminada tiene Bogota y Soacha; Medellin / Barrancabermeja (68081) no.
    expect($locales->zonaDeMunicipio($origen, '11001')?->id)->toBe($this->c['bogota']->id)
        ->and($locales->zonaDeMunicipio($origen, '25754')?->id)->toBe($this->c['soacha']->id)
        ->and($locales->zonaDeMunicipio($origen, '05001'))->toBeNull()
        ->and($locales->zonaDeMunicipio($origen, '68081'))->toBeNull()
        ->and($locales->zonaDeMunicipio($origen, null))->toBeNull();

    $venta = f1v_venta($this, '25754', null);
    expect(f1v_domiciliario($this, $venta)['zona']['id'])->toBe($this->c['soacha']->id);

    // El admin desmarca Soacha para esa bodega: el mismo destino deja de ser local (sin selector, sin Domicilio local).
    $locales->asignar($origen, [$this->c['bogota']->id]);

    expect(f1v_domiciliario($this, $venta))->toBeNull()
        ->and(f1v_metodos($this, $venta))->not->toHaveKey('domicilio_local');

    // Bogota sigue siendo local; al volver a marcar Soacha vuelve a ofrecerse.
    expect(f1v_domiciliario($this, f1v_venta($this, '11001', null)))->not->toBeNull();
    $locales->asignar($origen, [$this->c['bogota']->id, $this->c['soacha']->id]);
    expect(f1v_domiciliario($this, $venta))->not->toBeNull();
});

test('F1V-9 varias bodegas: un destino puede ser local desde una y no local desde otra (la zona es unica)', function () {
    $locales = app(ZonaLocalDeOrigen::class);
    $primera = $locales->origenPredeterminadoId();
    $segunda = (int) DB::table('inventory_sources')->insertGetId([
        'code' => 'CALI'.Str::upper(Str::random(4)), 'name' => 'Bodega Cali', 'contact_name' => 'x', 'contact_email' => 'x@x.co', 'contact_number' => '1',
        'street' => 'Calle 1', 'city' => 'Cali', 'state' => 'VA', 'country' => 'CO', 'postcode' => '760001', 'priority' => 9, 'status' => 1,
    ]);
    $locales->asignar($segunda, [$this->c['soacha']->id]);

    expect($locales->zonaDeMunicipio($primera, '11001')?->id)->toBe($this->c['bogota']->id)
        ->and($locales->zonaDeMunicipio($segunda, '11001'))->toBeNull()
        ->and($locales->zonaDeMunicipio($segunda, '25754')?->id)->toBe($this->c['soacha']->id);

    // Una bodega sin zonas asignadas no tiene entregas locales.
    $locales->asignar($segunda, []);
    expect($locales->zonaDeMunicipio($segunda, '25754'))->toBeNull();
});

test('F1V-10 Admin: pantalla «Zonas locales por origen» lista bodegas y zonas, y guarda la asignacion', function () {
    $locales = app(ZonaLocalDeOrigen::class);
    $origen = $locales->origenPredeterminadoId();

    $this->actingAs(f03b_admin('all'), 'admin')->get(route('admin.logistica.zonas_locales.index'))
        ->assertOk()->assertSee('data-bodega="'.$origen.'"', false)->assertSee('Bogotá')->assertSee('Soacha');

    $this->actingAs(f03b_admin('all'), 'admin')
        ->post(route('admin.logistica.zonas_locales.guardar', $origen), ['zonas' => [$this->c['bogota']->id]])
        ->assertRedirect(route('admin.logistica.zonas_locales.index'));

    expect($locales->zonaIdsDeOrigen($origen))->toBe([$this->c['bogota']->id]);
});
