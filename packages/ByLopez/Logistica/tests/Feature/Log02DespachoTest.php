<?php

use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Enums\FuenteEventoLogistico as Fuente;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\GuiaArchivo;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Guias\GuiaArchivoService;
use ByLopez\VentasAsesor\Support\Logistica\AccesoGuiaDelAsesor;
use ByLopez\VentasAsesor\Support\Logistica\SugiereRecaudoDesdeCartera;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';

uses(TestCase::class);

/*
| Despacho V1 — «Registrar guia y despachar», «Entregar en bodega», PDF de la guia, permisos y listados. Solo
| lpz_saas_testing. El disco privado se sustituye por uno falso: ninguna prueba escribe archivos reales.
*/

beforeEach(fn () => Storage::fake('private'));
test('DSP-1 despachar: Shipment REAL de Bagisto + guia + PDF en una operacion; el recaudo NO es un pago recibido', function () {
    $e = log2_pedido(5);
    $bodega = log2_bodega();
    $pagosAntes = DB::table('bylopez_va_pagos')->count();

    $r = app(DespachoService::class)->despachar($bodega, log2_s($e, [
        'recaudoEsperado' => '100000', 'valorFlete' => '8000', 'tracking' => 'https://rastreo.test/abc', 'observacion' => 'Frágil', 'archivo' => log2_pdf(),
    ]));

    $guia = $r->guia;
    $envio = DB::table('shipments')->find($r->shipmentId);
    $archivo = $guia->archivoVigente;

    expect($r->yaExistia)->toBeFalse()
        ->and($envio->order_id)->toBe((int) $e['pedido']->id)
        ->and($envio->carrier_code)->toBe('INTER_RAPIDISIMO')
        ->and($envio->carrier_title)->toBe('Inter Rapidísimo')
        ->and($envio->track_number)->toBe($guia->numero_guia)
        ->and((int) $envio->total_qty)->toBe(5)
        ->and((int) DB::table('order_items')->where('id', $e['item']->id)->value('qty_shipped'))->toBe(5)
        ->and((int) DB::table('product_inventories')->where('product_id', $e['item']->product_id)->where('inventory_source_id', 1)->value('qty'))->toBe(10)
        ->and($guia->shipment_id)->toBe((int) $r->shipmentId)
        ->and($guia->transportadora_codigo)->toBe('INTER_RAPIDISIMO')
        ->and($guia->origen_guia->value)->toBe('manual')
        ->and($guia->generada_por)->toBe('operaria')
        ->and($guia->flete_pagador->value)->toBe('cliente_al_recibir')
        ->and((float) $guia->valor_flete)->toBe(8000.0)
        ->and((float) $guia->recaudo_esperado)->toBe(100000.0)
        ->and($guia->recaudo_estado->value)->toBe('pendiente_liquidar')
        ->and($guia->tracking)->toBe('https://rastreo.test/abc')
        ->and($guia->actor_admin_id)->toBe((int) $bodega->id)
        ->and($guia->actor_name_snapshot)->toBe($bodega->name)
        // El PDF es otro registro: documento privado, versionado, con hash.
        ->and($archivo->version)->toBe(1)
        ->and($archivo->disco)->toBe('private')
        ->and($archivo->origen_archivo->value)->toBe('manual')
        ->and($archivo->mime)->toBe('application/pdf')
        ->and(Storage::disk('private')->exists($archivo->ruta))->toBeTrue()
        ->and(str_starts_with($archivo->ruta, 'logistica/guias/'))->toBeTrue()
        // «transportadora debe recaudar $X» ≠ «ByLopez ya recibio $X»: Cartera y Transactions no se tocan.
        ->and(DB::table('bylopez_va_pagos')->count())->toBe($pagosAntes)
        ->and(DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count())->toBe(0);
});

test('DSP-2 despacho parcial: por defecto TODO lo pendiente; ajustar permite parciales y luego «completar»', function () {
    $e = log2_pedido(5);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $contexto = app(ContextoDespachoService::class);

    expect($contexto->paraPedido((int) $e['pedido']->id, $bodega)['modo'])->toBe('despachar');

    $servicio->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 2]]));
    $c = $contexto->paraPedido((int) $e['pedido']->id, $bodega);

    expect((int) DB::table('order_items')->where('id', $e['item']->id)->value('qty_shipped'))->toBe(2)
        ->and($c['modo'])->toBe('completar')
        ->and($c['unidades']['pendientes_total'])->toBe(3)
        ->and($c['unidades']['despachadas'])->toBe(2)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 4]]))))->toBe(Invalida::CANTIDAD_EXCEDE_PENDIENTE);

    // Sin `unidades` = todas las pendientes (el caso normal, sin tocar cantidades).
    $servicio->despachar($bodega, log2_s($e));

    expect((int) DB::table('order_items')->where('id', $e['item']->id)->value('qty_shipped'))->toBe(5)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(2)
        ->and(Guia::query()->where('order_id', $e['pedido']->id)->count())->toBe(2)
        ->and($contexto->paraPedido((int) $e['pedido']->id, $bodega)['modo'])->toBe('ver')
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e))))->toBe(Invalida::PEDIDO_NO_DESPACHABLE);
});

test('DSP-3 idempotencia: doble clic = un solo despacho; la misma clave con otra solicitud es conflicto', function () {
    $e = log2_pedido(4);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);
    $s = log2_s($e, ['idempotencyKey' => 'doble-clic-0001', 'numeroGuia' => 'ABC123']);

    $primero = $servicio->despachar($bodega, $s);
    $reintento = $servicio->despachar($bodega, $s);

    expect($reintento->yaExistia)->toBeTrue()
        ->and($reintento->guia->id)->toBe($primero->guia->id)
        ->and($reintento->shipmentId)->toBe($primero->shipmentId)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(1)
        ->and(Guia::query()->where('idempotency_key', 'doble-clic-0001')->count())->toBe(1)
        ->and((int) DB::table('order_items')->where('id', $e['item']->id)->value('qty_shipped'))->toBe(4)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['idempotencyKey' => 'doble-clic-0001', 'numeroGuia' => 'OTRA999']))))->toBe(Invalida::IDEMPOTENCIA_CONFLICTO)
        ->and(log2_error(fn () => $servicio->despachar($bodega, log2_s($e, ['idempotencyKey' => 'x']))))->toBe(Invalida::IDEMPOTENCIA_INVALIDA);
});

test('DSP-4 validaciones: guia, catalogo, flete estructurado, recaudo, stock, recogida; nada se crea si falla', function () {
    $e = log2_pedido(5);
    $sinStock = log2_pedido(5, ['stock' => 2]);
    $recogida = log2_pedido(2, ['envio' => 'pickup']);
    $bodega = log2_bodega();
    $s = app(DespachoService::class);

    expect(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['numeroGuia' => '  ']))))->toBe(Invalida::GUIA_REQUERIDA)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['numeroGuia' => '<script>']))))->toBe(Invalida::DATOS_INVALIDOS)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['transportadora' => '']))))->toBe(Invalida::TRANSPORTADORA_REQUERIDA)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['transportadora' => 'NO_EXISTE']))))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['transportadora' => 'RECOGIDA_BODEGA']))))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['origenGuia' => 'texto libre']))))->toBe(Invalida::ORIGEN_GUIA_INVALIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['fletePagador' => 'el vecino']))))->toBe(Invalida::PAGADOR_FLETE_INVALIDO)
        // F1: ByLopez sobre un envio «por pagar» es una excepcion: sin motivo se rechaza; con motivo y sin valor, falta el flete.
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['fletePagador' => 'bylopez']))))->toBe(Invalida::MOTIVO_PAGADOR_REQUERIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['fletePagador' => 'bylopez', 'motivoPagador' => 'Cortesia']))))->toBe(Invalida::FLETE_REQUERIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['valorFlete' => '-5']))))->toBe(Invalida::FLETE_INVALIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['valorFlete' => 'abc']))))->toBe(Invalida::FLETE_INVALIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['recaudoEsperado' => '9999999']))))->toBe(Invalida::RECAUDO_INVALIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['unidades' => [999999 => 1]]))))->toBe(Invalida::LINEA_NO_DEL_PEDIDO)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['unidades' => [$e['item']->id => 0]]))))->toBe(Invalida::SIN_UNIDADES)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($sinStock))))->toBe(Invalida::STOCK_INSUFICIENTE)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($recogida))))->toBe(Invalida::ES_RECOGIDA)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s(['pedido' => (object) ['id' => 999999999]]))))->toBe(Invalida::PEDIDO_INEXISTENTE)
        ->and(DB::table('shipments')->whereIn('order_id', [$e['pedido']->id, $sinStock['pedido']->id, $recogida['pedido']->id])->count())->toBe(0)
        ->and(Guia::query()->count())->toBe(0);

    // Flete pagado por ByLopez con valor: queda estructurado (Finanzas podra medirlo).
    $otroPedido = log2_pedido(1);
    $ok = $s->despachar($bodega, log2_s($e, ['fletePagador' => 'bylopez', 'valorFlete' => '12500.50', 'motivoPagador' => 'Cortesia']))->guia;

    expect($ok->flete_pagador->value)->toBe('bylopez')
        ->and((float) $ok->valor_flete)->toBe(12500.5)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($otroPedido, ['numeroGuia' => $ok->numero_guia]))))->toBe(Invalida::GUIA_DUPLICADA);
});

test('DSP-5 PDF: solo PDF real (por contenido), max 5 MB, privado, versionado y sin sobrescribir en silencio', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();
    $archivos = app(GuiaArchivoService::class);
    $s = app(DespachoService::class);

    $falso = UploadedFile::fake()->createWithContent('guia.pdf', 'MZ esto es un ejecutable, no un PDF');
    $grande = UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('A', 5 * 1024 * 1024 + 10)."\n%%EOF");

    expect(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['archivo' => $falso]))))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and(log2_error(fn () => $s->despachar($bodega, log2_s($e, ['archivo' => $grande]))))->toBe(Invalida::ARCHIVO_MUY_GRANDE)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);

    // Despacho SIN PDF y luego se adjunta: primera version, sin motivo.
    $guia = $s->despachar($bodega, log2_s($e))->guia;

    expect($guia->archivoVigente)->toBeNull();

    $v1 = $archivos->adjuntarOReemplazar($bodega, $guia->id, log2_pdf('uno.pdf', 'A'), null);

    // Reemplazar exige motivo; el mismo contenido no crea otra version; uno distinto crea la 2.
    expect($v1->version)->toBe(1)
        ->and(log2_error(fn () => $archivos->adjuntarOReemplazar($bodega, $guia->id, log2_pdf('dos.pdf', 'B'), '  ')))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and($archivos->adjuntarOReemplazar($bodega, $guia->id, log2_pdf('uno.pdf', 'A'), null)->id)->toBe($v1->id);

    $v2 = $archivos->adjuntarOReemplazar($bodega, $guia->id, log2_pdf('dos.pdf', 'B'), 'Era la guia de otro pedido');

    expect($v2->version)->toBe(2)
        ->and($v2->motivo_reemplazo)->toBe('Era la guia de otro pedido')
        ->and($v2->subido_por_admin_id)->toBe((int) $bodega->id)
        ->and($guia->archivos()->count())->toBe(2)
        ->and($guia->archivoVigente()->first()->id)->toBe($v2->id)
        ->and($v1->fresh()->vigente)->toBeNull()
        ->and($v1->fresh()->reemplazado_en)->not->toBeNull()
        // La version anterior se CONSERVA en disco y en base: auditable.
        ->and(Storage::disk('private')->exists($v1->ruta))->toBeTrue()
        ->and(Storage::disk('private')->exists($v2->ruta))->toBeTrue()
        // Ni la guia ni las versiones se editan o borran.
        ->and(log2_error(fn () => $v1->fresh()->update(['ruta' => 'otra/ruta.pdf'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $v1->fresh()->delete()))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $guia->fresh()->update(['numero_guia' => 'CAMBIADO'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $guia->fresh()->delete()))->toBe(Invalida::REGISTRO_INMUTABLE);

    // Lo unico que una API / la conciliacion podran completar despues sigue permitido.
    $guia->fresh()->update(['tracking' => 'COMPLETADO-POR-API', 'proveedor_referencia' => 'prov-1']);

    expect($guia->fresh()->tracking)->toBe('COMPLETADO-POR-API');
});

test('DSP-6 ver/descargar el PDF: autenticado y autorizado; el asesor SOLO el de sus pedidos y SOLO leer', function () {
    $e = log2_pedido(2);
    $ajeno = log2_pedido(2);
    $bodega = log2_bodega();
    $duena = log2_asesor();
    $otra = log2_asesor();
    pagb_atribuir($e['pedido'], $duena);
    pagb_atribuir($ajeno['pedido'], $otra);

    $s = app(DespachoService::class);
    $guia = $s->despachar($bodega, log2_s($e, ['archivo' => log2_pdf()]))->guia;
    $sinPdf = $s->despachar($bodega, log2_s($ajeno))->guia;

    // Sin sesion (antes de cualquier actingAs): al login, nunca el PDF.
    $this->get(route('admin.logistica.guias.archivo', [$guia->id, 'ver']))->assertRedirect();
    $this->get(route('admin.ventas_asesor.pedidos.guia', [$e['pedido']->id, $guia->id, 'descargar']))->assertRedirect();

    // Bodega (logistica.guias.ver): en linea y como descarga, privado y sin cache.
    $ver = $this->actingAs($bodega, 'admin')->get(route('admin.logistica.guias.archivo', [$guia->id, 'ver']))->assertOk();
    $descarga = $this->actingAs($bodega, 'admin')->get(route('admin.logistica.guias.archivo', [$guia->id, 'descargar']))->assertOk();

    expect($ver->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($ver->headers->get('Content-Disposition'))->toContain('inline')
        ->and($descarga->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($descarga->headers->get('Content-Disposition'))->toContain('guia-inter-rapidisimo-')
        ->and($ver->headers->get('Cache-Control'))->toContain('no-store')
        ->and($ver->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    // Sin PDF: 404. Guia de otro pedido en la URL del asesor: 404.
    $this->actingAs($bodega, 'admin')->get(route('admin.logistica.guias.archivo', [$sinPdf->id, 'ver']))->assertNotFound();

    // El asesor DUEÑO lee su guia por la ruta de Ventas Asesor; la de un pedido ajeno, 403; una guia que no es del
    // pedido de la URL, 404. No tiene ninguna ruta de Logistica (401).
    $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.guia', [$e['pedido']->id, $guia->id, 'ver']))->assertOk();
    $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.guia', [$e['pedido']->id, $guia->id, 'descargar']))->assertOk();
    $this->actingAs($otra, 'admin')->get(route('admin.ventas_asesor.pedidos.guia', [$e['pedido']->id, $guia->id, 'ver']))->assertForbidden();
    $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.guia', [$ajeno['pedido']->id, $guia->id, 'ver']))->assertNotFound();
    $this->actingAs($duena, 'admin')->get(route('admin.logistica.guias.archivo', [$guia->id, 'ver']))->assertUnauthorized();
    $this->actingAs($duena, 'admin')->post(route('admin.logistica.guias.archivo.guardar', $guia->id), ['archivo' => log2_pdf('x.pdf', 'Z')])->assertUnauthorized();

    // Aunque el asesor llame al servicio: puede leer lo suyo, no modificar.
    expect(log2_error(fn () => app(GuiaArchivoService::class)->adjuntarOReemplazar($duena, $guia->id, log2_pdf('x.pdf', 'Z'), 'x')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => app(GuiaArchivoService::class)->responder($otra, $guia->id, 'ver')))->toBe(Invalida::SIN_ACCESO_GUIA)
        ->and(GuiaArchivo::query()->where('guia_id', $guia->id)->count())->toBe(1);
});

test('DSP-7 Entregar en bodega: sin transportadora ni guia, Shipment real + entrega recogida_bodega, idempotente', function () {
    $e = log2_pedido(3, ['envio' => 'pickup', 'pago' => 'moneytransfer']);
    $noRecogida = log2_pedido(1);
    $bodega = log2_bodega();
    $servicio = app(DespachoService::class);

    expect(app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega)['modo'])->toBe('entregar_bodega')
        ->and(log2_error(fn () => $servicio->entregarEnBodega($bodega, new SolicitudRecogida((int) $noRecogida['pedido']->id, 'bod-'.Str::uuid()))))->toBe(Invalida::NO_ES_RECOGIDA);

    $r = $servicio->entregarEnBodega($bodega, new SolicitudRecogida((int) $e['pedido']->id, 'bodega-0001-x', quienRetira: 'Cliente con cédula 123'));
    $entrega = $r->entrega;
    $envio = DB::table('shipments')->find($r->shipmentId);

    expect($r->guia)->toBeNull()
        ->and(Guia::query()->where('order_id', $e['pedido']->id)->count())->toBe(0)
        ->and($envio->carrier_code)->toBe('RECOGIDA_BODEGA')
        ->and((int) DB::table('order_items')->where('id', $e['item']->id)->value('qty_shipped'))->toBe(3)
        ->and($entrega->fuente)->toBe(Fuente::RECOGIDA_BODEGA)
        ->and($entrega->evidencia_referencia)->toBe('Cliente con cédula 123')
        ->and($entrega->items->sole()->cantidad)->toBe(3)
        ->and($entrega->actor_admin_id)->toBe((int) $bodega->id)
        // Idempotente: el reintento no crea otro Shipment ni otra entrega.
        ->and($servicio->entregarEnBodega($bodega, new SolicitudRecogida((int) $e['pedido']->id, 'bodega-0001-x'))->yaExistia)->toBeTrue()
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(1)
        ->and(Entrega::query()->where('order_id', $e['pedido']->id)->count())->toBe(1);

    // Hace falta poder despachar Y registrar entregas.
    $soloDespacha = f03b_admin('custom', ['logistica', 'logistica.despachos', 'logistica.despachos.despachar']);
    $otro = log2_pedido(1, ['envio' => 'pickup']);

    expect(log2_error(fn () => $servicio->entregarEnBodega($soloDespacha, new SolicitudRecogida((int) $otro['pedido']->id, 'bodega-0002-x'))))->toBe(Invalida::SIN_PERMISO);
});

test('DSP-8 HTTP: Bodega opera el modal completo (contexto, despachar con PDF) y el asesor no entra', function () {
    $e = log2_pedido(5);
    $bodega = log2_bodega();
    $asesor = log2_asesor();

    $contexto = $this->actingAs($bodega, 'admin')->getJson(route('admin.logistica.despachos.contexto', $e['pedido']->id))->assertOk()->json('contexto');

    // Resumen operativo; el valor comercial del pedido, margen, costos o comision NO viajan.
    expect($contexto['modo'])->toBe('despachar')
        ->and($contexto['pedido']['telefono'])->not->toBeEmpty()
        ->and($contexto['pedido']['ciudad'])->not->toBeEmpty()
        ->and($contexto['unidades']['pendientes_total'])->toBe(5)
        ->and((float) $contexto['recaudo_sugerido'])->toBe(100000.0)
        ->and($contexto['sugerencias']['flete_pagador'])->toBe('cliente_al_recibir')
        ->and(array_keys($contexto['pedido']))->not->toContain('grand_total', 'sub_total', 'costo', 'margen', 'comision', 'utilidad');

    $respuesta = $this->actingAs($bodega, 'admin')->post(route('admin.logistica.despachos.despachar', $e['pedido']->id), [
        'idempotency_key' => 'http-'.Str::uuid(), 'transportadora' => 'BYLOPEZ', 'numero_guia' => 'HTTP-001', 'origen_guia' => '99envios',
        'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => '9000', 'recaudo_esperado' => '100000', 'tracking' => 'T-1',
        'observacion' => 'Entregar por la tarde', 'archivo' => log2_pdf(),
    ], ['Accept' => 'application/json'])->assertOk()->assertJson(['ok' => true, 'ya_existia' => false]);

    $guia = Guia::query()->findOrFail($respuesta->json('guia_id'));

    expect($guia->numero_guia)->toBe('HTTP-001')
        ->and($guia->origen_guia->value)->toBe('99envios')
        ->and($guia->archivoVigente)->not->toBeNull()
        ->and($this->actingAs($bodega, 'admin')->getJson(route('admin.logistica.despachos.contexto', $e['pedido']->id))->json('contexto.modo'))->toBe('ver');

    // Errores de dominio → JSON con codigo y mensaje en espanol (no pagina de error).
    $otro = log2_pedido(2);
    $this->actingAs($bodega, 'admin')->post(route('admin.logistica.despachos.despachar', $otro['pedido']->id), [
        'idempotency_key' => 'http-'.Str::uuid(), 'transportadora' => 'BYLOPEZ', 'numero_guia' => '', 'origen_guia' => 'manual', 'flete_pagador' => 'otro',
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJson(['ok' => false, 'codigo' => 'guia_requerida']);

    // El asesor: sin acceso a Despachos, ni al contexto ni a despachar.
    $this->actingAs($asesor, 'admin')->get(route('admin.logistica.despachos.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->getJson(route('admin.logistica.despachos.contexto', $otro['pedido']->id))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.logistica.despachos.despachar', $otro['pedido']->id), ['idempotency_key' => 'x-'.Str::uuid()], ['Accept' => 'application/json'])->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.logistica.despachos.entregar_bodega', $otro['pedido']->id), [], ['Accept' => 'application/json'])->assertUnauthorized();

    // Ver la bandeja (despachos) no basta para despachar: el servicio tambien lo valida.
    $soloVe = f03b_admin('custom', ['logistica', 'logistica.despachos']);
    $this->actingAs($soloVe, 'admin')->post(route('admin.logistica.despachos.despachar', $otro['pedido']->id), ['idempotency_key' => 'x-'.Str::uuid()], ['Accept' => 'application/json'])->assertUnauthorized();
    expect(log2_error(fn () => app(DespachoService::class)->despachar($soloVe, log2_s($otro))))->toBe(Invalida::SIN_PERMISO);
});

test('DSP-9 bandeja: cola de trabajo con acciones contextuales segun el estado y sin datos comerciales sensibles', function () {
    $pendiente = log2_pedido(2);
    $recogida = log2_pedido(1, ['envio' => 'pickup']);
    $despachado = log2_pedido(2);
    $bodega = log2_bodega();

    $guia = app(DespachoService::class)->despachar($bodega, log2_s($despachado, ['numeroGuia' => 'BAN-777', 'archivo' => log2_pdf()]))->guia;

    $html = $this->actingAs($bodega, 'admin')->get(route('admin.logistica.despachos.index', ['estado' => 'todos']))->assertOk()->getContent();

    expect($html)->toContain('data-pedido="'.$pendiente['pedido']->id.'"')
        // Pendiente: «Despachar». Recogida: «Entregar en bodega». Despachado: «Ver envio» + guia, nunca «Despachar».
        ->and($html)->toContain('data-pedido="'.$pendiente['pedido']->id.'" data-accion="despachar"')
        ->and($html)->toContain('data-pedido="'.$recogida['pedido']->id.'" data-accion="entregar_bodega"')
        ->and($html)->toContain('data-pedido="'.$despachado['pedido']->id.'" data-modo="ver" data-accion="ver_envio"')
        ->and($html)->not->toContain('data-pedido="'.$despachado['pedido']->id.'" data-accion="despachar"')
        ->and($html)->toContain('BAN-777')
        ->and($html)->toContain(route('admin.logistica.guias.archivo', [$guia->id, 'descargar']))
        ->and($html)->toContain('v-logistica-despacho')
        ->and($html)->not->toContain('Margen')->and($html)->not->toContain('Comisión')->and($html)->not->toContain('Utilidad');

    // Por defecto solo lo pendiente (la cola); el despachado aparece al pedir el historial.
    $cola = $this->actingAs($bodega, 'admin')->get(route('admin.logistica.despachos.index'))->assertOk()->getContent();

    expect($cola)->toContain('data-pedido="'.$pendiente['pedido']->id.'"')
        ->and($cola)->not->toContain('data-pedido="'.$despachado['pedido']->id.'"');
});

test('DSP-10 listado de Pedidos: el Administrador despacha desde la fila con el MISMO componente; el asesor solo ve la guia de lo suyo', function () {
    $pendiente = log2_pedido(2);
    $despachado = log2_pedido(2);
    $admin = f03b_admin('all');
    $duena = log2_asesor();
    pagb_atribuir($despachado['pedido'], $duena);

    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s($despachado, ['archivo' => log2_pdf()]))->guia;
    $sinPdf = log2_pedido(1);
    $guiaSinPdf = app(DespachoService::class)->despachar(log2_bodega(), log2_s($sinPdf))->guia;
    pagb_atribuir($sinPdf['pedido'], $duena);

    $html = $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent();

    expect($html)->toContain('data-logistica-despacho data-pedido="'.$pendiente['pedido']->id.'" data-accion="despachar"')
        ->and($html)->toContain('data-logistica-despacho data-pedido="'.$despachado['pedido']->id.'" data-modo="ver" data-accion="ver_envio"')
        ->and($html)->not->toContain('data-logistica-despacho data-pedido="'.$despachado['pedido']->id.'" data-accion="despachar"')
        ->and($html)->toContain('v-logistica-despacho')
        // Ver / Descargar guia SOLO si hay PDF.
        ->and($html)->toContain(route('admin.ventas_asesor.pedidos.guia', ['pedido' => $despachado['pedido']->id, 'guia' => $guia->id, 'modo' => 'ver']))
        ->and($html)->not->toContain(route('admin.ventas_asesor.pedidos.guia', ['pedido' => $sinPdf['pedido']->id, 'guia' => $guiaSinPdf->id, 'modo' => 'ver']));

    // El asesor ve transportadora/guia y los enlaces de SUS pedidos; no botones de despacho ni el componente.
    $propio = $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent();

    expect($propio)->toContain($guia->numero_guia)
        ->and($propio)->toContain(route('admin.ventas_asesor.pedidos.guia', ['pedido' => $despachado['pedido']->id, 'guia' => $guia->id, 'modo' => 'descargar']))
        ->and($propio)->not->toContain('data-logistica-despacho')
        ->and($propio)->not->toContain('v-logistica-despacho')
        ->and($propio)->not->toContain('data-pedido="'.$pendiente['pedido']->id.'"');

    // Detalle del pedido: seccion de envio y guia, con el PDF solo si existe.
    $detalle = $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $despachado['pedido']->id]))->assertOk()->getContent();

    expect($detalle)->toContain('data-bloque-envio')
        ->and($detalle)->toContain($guia->numero_guia)
        ->and($detalle)->toContain('data-accion="ver_guia"');

    $detalleSinPdf = $this->actingAs($duena, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $sinPdf['pedido']->id]))->assertOk()->getContent();

    expect($detalleSinPdf)->toContain($guiaSinPdf->numero_guia)->and($detalleSinPdf)->not->toContain('data-accion="ver_guia"');
});

test('DSP-11 ACL y contratos: cada ruta la autoriza la clave prevista; Cartera aporta el recaudo; sin llamadas externas', function () {
    $roles = acl()->getRoles();

    expect($roles['admin.logistica.despachos.index'])->toBe('logistica.despachos')
        ->and($roles['admin.logistica.despachos.contexto'])->toBe('logistica.despachos')
        ->and($roles['admin.logistica.despachos.despachar'])->toBe('logistica.despachos.despachar')
        ->and($roles['admin.logistica.despachos.entregar_bodega'])->toBe('logistica.despachos.despachar')
        ->and($roles['admin.logistica.guias.archivo'])->toBe('logistica.guias.ver')
        ->and($roles['admin.logistica.guias.archivo.guardar'])->toBe('logistica.guias.archivo')
        ->and($roles['admin.ventas_asesor.pedidos.guia'])->toBe('ventas_asesor.pedidos.view_own')
        ->and($roles['admin.logistica.entregas.index'])->toBe('logistica');

    // Toda clave logistica.* apunta primero a una ruta GET existente y sin parametros.
    foreach (collect(config('acl'))->filter(fn ($c) => str_starts_with($c['key'], 'logistica')) as $clave) {
        $ruta = Arr::wrap($clave['route'])[0];
        $r = app('router')->getRoutes()->getByName($ruta);

        expect($r)->not->toBeNull($clave['key'])
            ->and($r->methods())->toContain('GET')
            ->and($r->parameterNames())->toBe([]);
    }

    // VentasAsesor reemplaza los contratos de Logistica (Cartera + propiedad comercial).
    expect(app(SugiereRecaudo::class))->toBeInstanceOf(SugiereRecaudoDesdeCartera::class)
        ->and(app(AccesoGuiaPedido::class))->toBeInstanceOf(AccesoGuiaDelAsesor::class);

    // El recaudo sugerido solo existe contra entrega.
    $cod = log2_pedido(1, ['pago' => 'cashondelivery', 'total' => 50000.0]);
    $transferencia = log2_pedido(1, ['pago' => 'moneytransfer', 'total' => 50000.0]);

    expect(app(SugiereRecaudo::class)->recaudoSugerido((int) $cod['pedido']->id))->toBe(50000.0)
        ->and(app(SugiereRecaudo::class)->recaudoSugerido((int) $transferencia['pedido']->id))->toBe(0.0);

    // La guia manual es el mismo registro que completaria una API: campos de proveedor y origen del archivo previstos.
    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s($cod))->guia;

    expect($guia->generada_por)->toBe('operaria')
        ->and($guia->proveedor_referencia)->toBeNull()
        ->and(array_keys(Guia::query()->first()->getAttributes()))->toContain('proveedor_referencia', 'generada_por', 'origen_guia');
});

test('DSP-5b PDF validos con BOM/espacios/saltos de linea antes de la firma se aceptan; con otro prefijo, disfrazados o vacios se rechazan', function () {
    $archivos = app(GuiaArchivoService::class);
    $cuerpo = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $inspeccionar = fn (string $contenido) => log2_error(fn () => $archivos->inspeccionar(UploadedFile::fake()->createWithContent('guia.pdf', $contenido)));

    // Validos: lo que generan algunas transportadoras (BOM, espacios o saltos de linea antes de la firma).
    foreach (["\n".$cuerpo, "\xEF\xBB\xBF".$cuerpo, "  \r\n".str_replace("\n", "\r\n", $cuerpo)] as $valido) {
        expect($inspeccionar($valido))->toBeNull();
    }

    // Inseguros: otro prefijo antes de la firma, un binario/HTML/imagen con extension .pdf, o un archivo vacio.
    expect($inspeccionar(str_repeat('x', 100).$cuerpo))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and($inspeccionar(str_repeat('x', 2000).$cuerpo))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and($inspeccionar("MZ\x90\x00 ejecutable"))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and($inspeccionar('<html><script>alert(1)</script> %PDF- </html>'))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and($inspeccionar('GIF89a%PDF-1.4'))->toBe(Invalida::ARCHIVO_NO_PDF)
        ->and($inspeccionar(''))->toBe(Invalida::ARCHIVO_INVALIDO);
});
