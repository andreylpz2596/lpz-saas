<?php

use ByLopez\Logistica\DataObjects\SolicitudEventoEnvio;
use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\MensajePedidoNoDisponibleException as NoDisponible;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use ByLopez\Logistica\Services\Guias\CorregirGuiaService;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Mensajes\LectorPedidoParaMensaje;
use ByLopez\VentasAsesor\Services\Mensajes\MensajePedidoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';

uses(TestCase::class);

/*
| Mensajes de WhatsApp al cliente (solo lectura). Pedidos REALES de Bagisto despachados por DespachoService: el estado que lee el
| servicio es el que dejaron Logistica y Cartera. Solo lpz_saas_testing.
*/

function wa_pedido(int $qty = 5, array $o = []): array
{
    $e = log2_pedido($qty, $o);
    $e['pedido']->update(['customer_first_name' => 'MARTA', 'customer_last_name' => 'PEREZ']);

    return $e;
}

function wa_admin()
{
    return f03b_admin('all');
}

function wa_servicio(): MensajePedidoService
{
    return app(MensajePedidoService::class);
}

function wa_claves(array $e, $actor = null): array
{
    return array_column(wa_servicio()->opciones((int) $e['pedido']->id, $actor ?? wa_admin()), 'clave');
}

function wa_texto(array $e, string $clave, $actor = null): string
{
    return wa_servicio()->generar((int) $e['pedido']->id, $clave, $actor ?? wa_admin())['texto'];
}

function wa_despachar(array $e, array $extra = []): object
{
    return app(DespachoService::class)->despachar(log2_bodega(), log2_s($e, $extra));
}

function wa_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (NoDisponible $x) {
        return $x->codigo;
    }

    return null;
}

/** Entrega registrada + validada del envio (como la Administracion). */
function wa_validar(object $r, bool $registrar = true): void
{
    $envio = (int) $r->shipmentId;
    $items = DB::table('shipment_items')->where('shipment_id', $envio)->pluck('qty', 'id')->map(fn ($q) => (int) $q)->all();
    $actor = f03b_admin('custom', ['logistica', 'logistica.entregas', 'logistica.entregas.registrar', 'logistica.entregas.validar']);

    // La recogida en bodega ya nace con su entrega registrada: solo falta validarla.
    if ($registrar) {
        app(RegistrarEntregaService::class)->registrar($actor, new SolicitudEventoEnvio(
            shipmentId: $envio, orderId: (int) $r->orderId, fecha: now()->toDateString(), items: $items,
            fuente: Fuente::BODEGA_MANUAL, idempotencyKey: 'wa-ent-'.Str::uuid(),
        ));
    }

    app(ValidarEntregaService::class)->validar($actor, $envio, 'wa-val-'.Str::uuid());
}

test('pedido sin despachar: resumen con productos reales, entrega y total; nada de SKU; sin efectos', function () {
    $e = wa_pedido(5);
    $antes = [DB::table('shipments')->count(), DB::table('bylopez_lg_guias')->count(), (int) DB::table('product_inventories')->sum('qty'), DB::table('order_transactions')->count(), $e['pedido']->fresh()->updated_at->timestamp];

    expect(wa_claves($e))->toBe(['resumen']);
    $t = wa_texto($e, 'resumen');

    expect($t)->toContain('Hola Marta 👋🏻')
        ->and($t)->toContain('Este es el resumen de tu pedido de LÓPEZ LÓPEZ #'.$e['pedido']->increment_id)
        ->and($t)->toContain('📦 Total: 5 prendas')
        ->and($t)->toContain('🚚 Entrega: Envío nacional')
        ->and($t)->toContain('💰 Total del pedido: $ 100.000')
        ->and($t)->not->toContain($e['item']->sku);

    expect(wa_servicio()->generar((int) $e['pedido']->id, 'resumen', wa_admin())['telefono'])->not->toBeNull();

    // Generar mensajes es LECTURA: ni envios, ni guias, ni stock, ni pagos, ni el propio pedido cambian.
    expect([DB::table('shipments')->count(), DB::table('bylopez_lg_guias')->count(), (int) DB::table('product_inventories')->sum('qty'), DB::table('order_transactions')->count(), $e['pedido']->fresh()->updated_at->timestamp])->toBe($antes);
});

test('despacho completo con guia; al CORREGIR la guia el mensaje sale con la vigente; la guia interna se rotula como interna', function () {
    $e = wa_pedido(5);
    $guia = wa_despachar($e, ['numeroGuia' => '111111'])->guia;

    expect(wa_claves($e))->toBe(['resumen', 'despacho-'.$guia->shipment_id]);
    $t = wa_texto($e, 'despacho-'.$guia->shipment_id);

    expect($t)->toContain('ya fue despachado!')->and($t)->toContain('🚚 Transportadora: Inter Rapidísimo')->and($t)->toContain('🔎 Guía: 111111')
        ->and($t)->toContain('📦 Total despachado: 5 prendas')->and($t)->not->toContain('despacho parcial');

    app(CorregirGuiaService::class)->corregir(log2_bodega(), $guia->id, '222222', null, 'Se digito mal la guia');
    $corregido = wa_texto($e, 'despacho-'.$guia->shipment_id);

    expect($corregido)->toContain('🔎 Guía: 222222')->and($corregido)->not->toContain('111111');

    // Guia interna (la genera el sistema): «Guía interna: GI-…», con el nombre real de quien la lleva.
    Transportadora::query()->create(['codigo' => 'WA_INT', 'nombre' => 'Mensajeria Interna', 'tipo' => 'transportadora', 'activo' => true, 'liquida_cuenta_corriente' => false, 'genera_guia_interna' => true]);
    $i = wa_pedido(2);
    $g = wa_despachar($i, ['transportadora' => 'WA_INT'])->guia;
    $texto = wa_texto($i, 'despacho-'.$g->shipment_id);

    expect($g->esInterna())->toBeTrue()
        ->and($texto)->toContain('🔎 Guía interna: '.$g->numero_guia)
        ->and($texto)->toContain('🚚 Transportadora: Mensajeria Interna')
        ->and($texto)->not->toContain('Inter Rapid');
});

test('despacho parcial y segundo despacho: un mensaje por despacho, cada uno con SU guia y SUS prendas; entregado no se ofrece', function () {
    $e = wa_pedido(5);
    $primero = wa_despachar($e, ['unidades' => [$e['item']->id => 2], 'numeroGuia' => 'PRIM-0001']);

    $claves = wa_claves($e);
    expect($claves)->toBe(['resumen', 'despacho-'.$primero->shipmentId]);
    $p = wa_texto($e, 'despacho-'.$primero->shipmentId);

    expect($p)->toContain('Realizamos un despacho parcial.')
        ->and($p)->toContain('✅ Productos enviados:')->and($p)->toContain('⏳ Pendientes:')
        ->and($p)->toContain('📦 Total despachado: 2 prendas')
        ->and($p)->toContain('🔎 Guía: PRIM-0001')
        ->and($p)->toContain('Las prendas pendientes continúan asociadas al mismo pedido.')
        ->and($p)->not->toContain('ya fue despachado');

    $segundo = wa_despachar($e, ['unidades' => [$e['item']->id => 3], 'transportadora' => '03', 'numeroGuia' => 'SEG-0002']);

    $opciones = wa_servicio()->opciones((int) $e['pedido']->id, wa_admin());
    expect(array_column($opciones, 'clave'))->toBe(['resumen', 'despacho-'.$primero->shipmentId, 'despacho-'.$segundo->shipmentId])
        ->and($opciones[1]['etiqueta'])->toBe('Despacho 1 de 2 · Inter Rapidísimo')
        ->and($opciones[2]['etiqueta'])->toBe('Despacho 2 de 2 · SERVIENTREGA')
        ->and(wa_claves($e))->not->toContain('entregado');

    $uno = wa_texto($e, 'despacho-'.$primero->shipmentId);
    $dos = wa_texto($e, 'despacho-'.$segundo->shipmentId);

    expect($uno)->toContain('Realizamos un despacho parcial.')->and($uno)->toContain('📦 Total despachado: 2 prendas')->and($uno)->toContain('PRIM-0001')->and($uno)->not->toContain('SEG-0002')
        ->and($dos)->toContain('Realizamos el último despacho de tu pedido')->and($dos)->toContain('Con este despacho tu pedido queda completo.')
        ->and($dos)->toContain('📦 Total despachado: 3 prendas')->and($dos)->toContain('🔎 Guía: SEG-0002')->and($dos)->toContain('SERVIENTREGA')
        ->and($dos)->not->toContain('PRIM-0001')->and($dos)->not->toContain('despacho parcial');
});

test('domicilio local: antes de salir ofrece «listo para domicilio»; ya despachado, sin transportadora ni guia, con la direccion', function () {
    $e = wa_pedido(3, ['envio' => 'domicilio_local']);

    expect(wa_claves($e))->toBe(['resumen', 'listo-domicilio']);
    $listo = wa_texto($e, 'listo-domicilio');
    expect($listo)->toContain('está listo para salir a domicilio. 🛵')->and($listo)->toContain('📍 Dirección:')->and($listo)->toContain('📦 3 prendas')
        ->and($listo)->toContain('Por favor mantente pendiente del teléfono.')->and($listo)->not->toContain('Guía');

    $r = wa_despachar($e, ['transportadora' => 'DOMICILIARIO', 'numeroGuia' => 'DOM-777', 'motivoPagador' => 'Prueba de mensajes: el flete lo define la operacion']);

    expect(wa_claves($e))->toBe(['resumen', 'despacho-'.$r->shipmentId]);
    $t = wa_texto($e, 'despacho-'.$r->shipmentId);

    expect($t)->toContain('🛵 Tu pedido va en camino a domicilio.')->and($t)->toContain('📍 Dirección:')
        ->and($t)->not->toContain('Transportadora')->and($t)->not->toContain('DOM-777')->and($t)->not->toContain('Guía')->and($t)->not->toContain('seguimiento');
});

test('recogida en bodega: «listo para recoger» sin guia ni transportadora; ya entregada en bodega no se ofrece ningun despacho', function () {
    $e = wa_pedido(3, ['envio' => 'pickup', 'pago' => 'moneytransfer']);

    expect(wa_claves($e))->toBe(['resumen', 'listo-recoger']);
    $t = wa_texto($e, 'listo-recoger');
    expect($t)->toContain('ya está listo para recoger. 🛍️')->and($t)->toContain('📍 Puedes recogerlo en:')->and($t)->toContain('📦 3 prendas')
        ->and($t)->not->toContain('Guía')->and($t)->not->toContain('Transportadora')->and($t)->not->toContain('🕒');

    $r = app(DespachoService::class)->entregarEnBodega(log2_bodega(), new SolicitudRecogida((int) $e['pedido']->id, 'wa-bod-'.Str::uuid()));

    // Entregada en mano: no hay despacho con guia que avisar y «listo para recoger» ya no corresponde; falta validar para «entregado».
    expect(wa_claves($e))->toBe(['resumen']);

    wa_validar($r, false);
    expect(wa_claves($e))->toBe(['resumen', 'entregado']);
});

test('entregado: solo con la entrega VALIDADA; anular la validacion lo retira; mensaje corto', function () {
    $e = wa_pedido(4);
    $r = wa_despachar($e, ['numeroGuia' => 'ENT-0001']);

    expect(wa_claves($e))->not->toContain('entregado');

    wa_validar($r);
    expect(wa_claves($e))->toBe(['resumen', 'despacho-'.$r->shipmentId, 'entregado']);

    $t = wa_texto($e, 'entregado');
    expect($t)->toContain('Nos aparece que tu pedido LÓPEZ LÓPEZ #'.$e['pedido']->increment_id.' ya fue entregado.')
        ->and($t)->not->toContain('$')->and($t)->not->toContain('ENT-0001')->and(mb_strlen($t))->toBeLessThan(260);

    $validacion = DB::table('bylopez_lg_validaciones_entrega')->where('shipment_id', $r->shipmentId)->where('estado', 'confirmado')->value('id');
    app(ValidarEntregaService::class)->anular(f03b_admin('custom', ['logistica', 'logistica.entregas', 'logistica.entregas.validar', 'logistica.entregas.anular']), (int) $validacion, 'Validada por error');

    expect(wa_claves($e))->not->toContain('entregado')
        ->and(wa_codigo(fn () => wa_texto($e, 'entregado')))->toBe(NoDisponible::MENSAJE_NO_DISPONIBLE);
});

test('permisos: asesor propio si, ajeno no, administrador si, BODEGA solo lo logistico y SIN dinero; sin permisos ni existencia revelada', function () {
    $e = wa_pedido(3);
    $r = wa_despachar($e, ['numeroGuia' => 'PERM-001', 'recaudoEsperado' => '100000', 'valorFlete' => '9000']);
    $clave = 'despacho-'.$r->shipmentId;

    $propio = log2_asesor();
    pagb_atribuir($e['pedido'], $propio);
    $ajeno = log2_asesor();
    $bodega = log2_bodega();
    $sinNada = f03b_admin('custom', ['dashboard']);

    // Asesor propio: resumen + despacho, con el valor del pedido.
    expect(wa_claves($e, $propio))->toBe(['resumen', $clave])
        ->and(wa_texto($e, 'resumen', $propio))->toContain('Total del pedido');

    // Ajeno: ni la lista ni el texto (el pedido no es suyo).
    expect(wa_codigo(fn () => wa_claves($e, $ajeno)))->toBe(NoDisponible::SIN_PERMISO)
        ->and(wa_codigo(fn () => wa_texto($e, $clave, $ajeno)))->toBe(NoDisponible::SIN_PERMISO);

    // BODEGA: lo operativo, sin resumen comercial y SIN ningun valor; tampoco recaudo ni flete.
    expect(wa_claves($e, $bodega))->toBe([$clave]);
    $b = wa_texto($e, $clave, $bodega);
    expect($b)->toContain('PERM-001')->and($b)->not->toContain('$')->and($b)->not->toContain('Total del pedido')->and($b)->not->toContain('9.000')
        ->and(wa_codigo(fn () => wa_texto($e, 'resumen', $bodega)))->toBe(NoDisponible::MENSAJE_NO_DISPONIBLE);

    // Sin ningun camino de acceso: sin_permiso, incluso para un pedido que no existe (no se revela la existencia).
    expect(wa_codigo(fn () => wa_claves($e, $sinNada)))->toBe(NoDisponible::SIN_PERMISO)
        ->and(wa_codigo(fn () => wa_servicio()->opciones(987654321, $sinNada)))->toBe(NoDisponible::SIN_PERMISO)
        ->and(wa_codigo(fn () => wa_servicio()->opciones(987654321, wa_admin())))->toBe(NoDisponible::PEDIDO_INEXISTENTE);

    // Pedido cancelado: ningun mensaje.
    $e['pedido']->update(['status' => 'canceled']);
    expect(wa_claves($e))->toBe([])->and(wa_codigo(fn () => wa_texto($e, 'resumen')))->toBe(NoDisponible::MENSAJE_NO_DISPONIBLE);
});

test('HTTP: cada puerta con su ACL (Pedidos, Despachos, Entregas), 403 al ajeno y 409 a lo que no corresponde', function () {
    $e = wa_pedido(3);
    $r = wa_despachar($e, ['numeroGuia' => 'HTTP-001']);
    $pid = (int) $e['pedido']->id;
    $clave = 'despacho-'.$r->shipmentId;

    $propio = log2_asesor();
    pagb_atribuir($e['pedido'], $propio);
    $ajeno = log2_asesor();
    $bodega = log2_bodega();

    $ventas = fn ($u, $c = null) => $this->actingAs($u, 'admin')->getJson($c === null
        ? route('admin.ventas_asesor.pedidos.mensajes.opciones', $pid) : route('admin.ventas_asesor.pedidos.mensajes.mensaje', [$pid, $c]));
    $despachos = fn ($u, $c = null) => $this->actingAs($u, 'admin')->getJson($c === null
        ? route('admin.logistica.despachos.mensajes.opciones', $pid) : route('admin.logistica.despachos.mensajes.mensaje', [$pid, $c]));

    // Asesor propio por Pedidos: lista y texto; telefono y numero para un consumidor futuro (n8n).
    $ventas($propio)->assertOk()->assertJsonPath('pedido_id', $pid)->assertJsonPath('opciones.0.clave', 'resumen')->assertJsonPath('opciones.1.clave', $clave);
    $m = $ventas($propio, $clave)->assertOk()->assertJsonPath('tipo', 'despacho_completo')->assertJsonPath('pedido.id', $pid)->assertJsonPath('pedido.numero', $e['pedido']->increment_id);
    expect($m->json('texto'))->toContain('HTTP-001')->and($m->json('telefono'))->not->toBeNull();

    // Ajeno: 403 con codigo estable. Una clave que no corresponde: 409.
    $ventas($ajeno)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
    $ventas($propio, 'entregado')->assertStatus(409)->assertJsonPath('codigo', 'mensaje_no_disponible');

    // BODEGA entra por Despachos (sin resumen, sin dinero) pero NO por la ruta de Ventas (su rol no tiene clave de Ventas).
    $despachos($bodega)->assertOk()->assertJsonCount(1, 'opciones')->assertJsonPath('opciones.0.clave', $clave);
    expect($despachos($bodega, $clave)->assertOk()->json('texto'))->not->toContain('$');
    $ventas($bodega)->assertStatus(401);

    // Asesor por la ruta de Logistica: el Bouncer corta (no tiene logistica.despachos).
    $despachos($propio)->assertStatus(401);

    // Entregas tiene su propia ruta/clave (BODEGA #4 tiene logistica.entregas).
    $this->actingAs($bodega, 'admin')->getJson(route('admin.logistica.entregas.mensajes.opciones', $pid))->assertOk()->assertJsonPath('opciones.0.clave', $clave);
    $this->actingAs($propio, 'admin')->getJson(route('admin.logistica.entregas.mensajes.opciones', $pid))->assertStatus(401);
});

test('dinero: solo el total; con abono el saldo de Cartera; pagado; pedido en revision o fuera de Cartera no afirma nada; sin alcance de Cartera no hay abonos', function () {
    $cliente = pagb_cliente();
    $lector = fn (int $id, bool $abonos = true) => app(LectorPedidoParaMensaje::class)->leer($id, true, $abonos)['dinero'];

    pagb_corte('2000-01-01');
    app()->forgetInstance(CarteraConsultaService::class);

    // Sin ningun abono: solo el total (jamas «debes pagar»).
    $sin = pagb_pedido($cliente, 100000.0);
    expect($lector((int) $sin->id))->toBe(['total' => 100000.0, 'abonos' => null, 'saldo' => null]);

    // Abono parcial CONFIRMADO de 50.000: total, abonos y el saldo que calcula Cartera.
    $parcial = pagb_pedido($cliente, 100000.0);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 50000, 'estado' => 'confirmado']), $parcial, 50000.0);
    expect($lector((int) $parcial->id))->toBe(['total' => 100000.0, 'abonos' => 50000.0, 'saldo' => 50000.0]);

    // Sin alcance de Cartera sobre el pedido: el total si, abonos y saldo no.
    expect($lector((int) $parcial->id, false))->toBe(['total' => 100000.0, 'abonos' => null, 'saldo' => null]);

    // Pagado.
    $pagado = pagb_pedido($cliente, 100000.0);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 100000, 'estado' => 'confirmado']), $pagado, 100000.0);
    expect($lector((int) $pagado->id))->toBe(['total' => 100000.0, 'abonos' => 100000.0, 'saldo' => 0.0]);

    // Sobreaplicado = Cartera lo marca «requiere revision»: no se afirma ningun abono ni saldo.
    $revision = pagb_pedido($cliente, 100000.0);
    pagb_aplicar(pagb_pago($cliente, ['monto' => 150000, 'estado' => 'confirmado']), $revision, 150000.0);
    expect($lector((int) $revision->id))->toBe(['total' => 100000.0, 'abonos' => null, 'saldo' => null]);

    // Cartera inactiva (pedido fuera de Cartera): solo el total del pedido.
    pagb_corte(null);
    app()->forgetInstance(CarteraConsultaService::class);
    expect($lector((int) $parcial->id))->toBe(['total' => 100000.0, 'abonos' => null, 'saldo' => null]);
});

test('las cuatro pantallas renderizan el boton y el componente (Pedidos lista y detalle, Despachos, Entregas)', function () {
    $e = wa_pedido(3);
    wa_despachar($e, ['numeroGuia' => 'PANT-001']);
    $admin = wa_admin();
    $id = (int) $e['pedido']->id;
    $numero = $e['pedido']->increment_id;

    $paginas = [
        'detalle' => route('admin.ventas_asesor.pedidos.index', ['pedido' => $id]),
        'lista' => route('admin.ventas_asesor.pedidos.index', ['q' => $numero]),
        'despachos' => route('admin.logistica.despachos.index', ['estado' => 'todos', 'q' => $numero]),
        'entregas' => route('admin.logistica.entregas.index', ['pedido' => $id]),
    ];

    foreach ($paginas as $nombre => $url) {
        $r = $this->actingAs($admin, 'admin')->get($url);
        $r->assertOk();
        expect($r->getContent())->toContain('data-whatsapp-pedido="'.$id.'"')->and($r->getContent())->toContain('window.ByLopezWhatsApp')->and($r->getContent())->toContain('"copiar":"Copiar mensaje"');
    }

    // Cada puerta apunta a su propia ruta (y por lo tanto a su propia clave ACL).
    $r = $this->actingAs($admin, 'admin')->get($paginas['despachos']);
    expect($r->getContent())->toContain('despachos\/pedidos\/__ID__\/mensajes');
    $r = $this->actingAs($admin, 'admin')->get($paginas['entregas']);
    expect($r->getContent())->toContain('entregas\/pedidos\/__ID__\/mensajes');
    $r = $this->actingAs($admin, 'admin')->get($paginas['detalle']);
    expect($r->getContent())->toContain('ventas-asesor\/pedidos\/__ID__\/mensajes');
});
