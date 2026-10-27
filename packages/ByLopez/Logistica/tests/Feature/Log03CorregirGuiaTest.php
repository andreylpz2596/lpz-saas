<?php

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\GuiaCorreccion;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Guias\CorregirGuiaService;
use ByLopez\Logistica\Services\Guias\GuiaArchivoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';

uses(TestCase::class);

/*
| CORREGIR GUIA — auditada, sin tocar el Shipment, el inventario, las cantidades ni Cartera. Solo lpz_saas_testing.
*/

beforeEach(fn () => Storage::fake('private'));

/** Instantanea de todo lo que una correccion NO debe tocar. */
function log3_intocable(int $orderId, int $productId): array
{
    return [
        'shipments' => DB::table('shipments')->where('order_id', $orderId)->count(),
        'shipment_items' => DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')->where('s.order_id', $orderId)->sum('si.qty'),
        'qty_shipped' => (int) DB::table('order_items')->where('order_id', $orderId)->sum('qty_shipped'),
        'qty_ordered' => (int) DB::table('order_items')->where('order_id', $orderId)->sum('qty_ordered'),
        'inventario' => (int) DB::table('product_inventories')->where('product_id', $productId)->sum('qty'),
        'estado' => DB::table('orders')->where('id', $orderId)->value('status'),
        'total' => (float) DB::table('orders')->where('id', $orderId)->value('base_grand_total'),
        'pagos' => DB::table('bylopez_va_pagos')->count(),
        'aplicaciones' => DB::table('bylopez_va_aplicaciones_pago')->count(),
        'transacciones' => DB::table('order_transactions')->where('order_id', $orderId)->count(),
        'facturas' => DB::table('invoices')->where('order_id', $orderId)->count(),
    ];
}

test('COR-1 corregir el NUMERO: la guia y el Shipment quedan con el valor nuevo y el historial guarda el anterior; nada mas se toca', function () {
    $e = log2_pedido(5);
    $bodega = log2_bodega();
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'MAL-0001', 'archivo' => log2_pdf(), 'recaudoEsperado' => '100000', 'valorFlete' => '8000']))->guia;
    $antes = log3_intocable((int) $e['pedido']->id, (int) $e['item']->product_id);
    $envioAntes = DB::table('shipments')->find($guia->shipment_id);

    $c = app(CorregirGuiaService::class)->corregir($bodega, $guia->id, 'BIEN-0001', null, 'Se digito mal un caracter');

    $guia = $guia->fresh();
    $envio = DB::table('shipments')->find($guia->shipment_id);

    expect($guia->numero_guia)->toBe('BIEN-0001')
        ->and($guia->transportadora_codigo)->toBe('INTER_RAPIDISIMO')
        // El Shipment refleja la guia VIGENTE (solo texto); su id y su salida de inventario son los mismos.
        ->and($envio->track_number)->toBe('BIEN-0001')
        ->and($envio->carrier_code)->toBe('INTER_RAPIDISIMO')
        ->and($envio->carrier_title)->toBe($envioAntes->carrier_title)
        ->and((int) $envio->id)->toBe((int) $envioAntes->id)
        // Auditoria completa en las tablas de ByLopez.
        ->and($c->numero_anterior)->toBe('MAL-0001')
        ->and($c->numero_nuevo)->toBe('BIEN-0001')
        ->and($c->transportadora_anterior)->toBe('INTER_RAPIDISIMO')
        ->and($c->transportadora_nueva)->toBe('INTER_RAPIDISIMO')
        ->and($c->motivo)->toBe('Se digito mal un caracter')
        ->and($c->actor_admin_id)->toBe((int) $bodega->id)
        ->and($c->actor_name_snapshot)->toBe($bodega->name)
        ->and($c->created_at)->not->toBeNull()
        ->and($c->guia_id)->toBe((int) $guia->id)
        ->and($c->shipment_id)->toBe((int) $guia->shipment_id)
        // NO crea otro Shipment, no descuenta inventario, no cambia cantidades, no vende, no toca Cartera ni facturas.
        ->and(log3_intocable((int) $e['pedido']->id, (int) $e['item']->product_id))->toBe($antes)
        // Lo demas de la guia queda igual: flete, recaudo, PDF.
        ->and((float) $guia->valor_flete)->toBe(8000.0)
        ->and((float) $guia->recaudo_esperado)->toBe(100000.0)
        ->and($guia->archivoVigente->version)->toBe(1);
});

test('COR-2 corregir la TRANSPORTADORA (con o sin cambiar el numero): el Shipment sincroniza codigo, titulo y guia', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();
    $servicio = app(CorregirGuiaService::class);
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'T-100', 'transportadora' => 'OTRA']))->guia;

    $c = $servicio->corregir($bodega, $guia->id, null, 'BYLOPEZ', 'Era reparto propio');
    $envio = DB::table('shipments')->find($guia->shipment_id);

    expect($c->numero_anterior)->toBe('T-100')
        ->and($c->numero_nuevo)->toBe('T-100')
        ->and($c->transportadora_anterior)->toBe('OTRA')
        ->and($c->transportadora_nueva)->toBe('BYLOPEZ')
        ->and($guia->fresh()->transportadora_codigo)->toBe('BYLOPEZ')
        ->and($envio->carrier_code)->toBe('BYLOPEZ')
        ->and($envio->carrier_title)->toBe('ByLopez')
        ->and($envio->track_number)->toBe('T-100');

    // Ambos a la vez; el historial encadena: el «anterior» de la segunda es el «nuevo» de la primera. Nada se borra.
    $c2 = $servicio->corregir($bodega, $guia->id, 'T-200', 'DOMICILIARIO', 'Cambio definitivo');

    expect($c2->numero_anterior)->toBe('T-100')
        ->and($c2->transportadora_anterior)->toBe('BYLOPEZ')
        ->and(GuiaCorreccion::query()->where('guia_id', $guia->id)->orderBy('id')->pluck('numero_nuevo')->all())->toBe(['T-100', 'T-200'])
        ->and(DB::table('shipments')->find($guia->shipment_id)->track_number)->toBe('T-200')
        ->and(DB::table('shipments')->find($guia->shipment_id)->carrier_code)->toBe('DOMICILIARIO');
});

test('COR-3 validaciones: motivo obligatorio, sin cambios, formato, duplicado y catalogo; si falla, NADA cambia', function () {
    $e = log2_pedido(2);
    $otro = log2_pedido(1);
    $bodega = log2_bodega();
    $servicio = app(CorregirGuiaService::class);
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'AAA-1']))->guia;
    $ocupada = app(DespachoService::class)->despachar($bodega, log2_s($otro, ['numeroGuia' => 'BBB-2']))->guia;

    expect(log2_error(fn () => $servicio->corregir($bodega, $guia->id, 'AAA-9', null, '  ')))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, 'AAA-9', null, null)))->toBe(Invalida::MOTIVO_REQUERIDO)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, 'AAA-1', null, 'igual')))->toBe(Invalida::SIN_CAMBIOS)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, null, null, 'nada')))->toBe(Invalida::SIN_CAMBIOS)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, 'AAA-1', 'INTER_RAPIDISIMO', 'igual todo')))->toBe(Invalida::SIN_CAMBIOS)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, '<b>', null, 'x')))->toBe(Invalida::DATOS_INVALIDOS)
        // La misma transportadora no puede tener dos guias con el mismo numero (la de otro pedido).
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, 'BBB-2', null, 'x')))->toBe(Invalida::GUIA_DUPLICADA)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, null, 'NO_EXISTE', 'x')))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and(log2_error(fn () => $servicio->corregir($bodega, $guia->id, null, 'RECOGIDA_BODEGA', 'x')))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and(log2_error(fn () => $servicio->corregir($bodega, 999999999, 'Z', null, 'x')))->toBe(Invalida::GUIA_INEXISTENTE);

    // Una transportadora DESACTIVADA del catalogo no se puede elegir como destino de la correccion.
    DB::table('bylopez_lg_transportadoras')->where('codigo', 'OTRA')->update(['activo' => false]);

    expect(log2_error(fn () => $servicio->corregir($bodega, $guia->id, null, 'OTRA', 'x')))->toBe(Invalida::TRANSPORTADORA_INVALIDA)
        ->and($guia->fresh()->numero_guia)->toBe('AAA-1')
        ->and(DB::table('shipments')->find($guia->shipment_id)->track_number)->toBe('AAA-1')
        ->and(GuiaCorreccion::query()->count())->toBe(0);

    // El mismo numero en OTRA transportadora si es valido (la unicidad es por transportadora + numero).
    $c = $servicio->corregir($bodega, $guia->id, 'BBB-2', 'BYLOPEZ', 'Era de otra transportadora');

    expect($c->numero_nuevo)->toBe('BBB-2')
        ->and($ocupada->fresh()->numero_guia)->toBe('BBB-2');
});

test('COR-4 permisos: solo roles autorizados, validado en el servicio y en HTTP; el asesor nunca', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'PER-1']))->guia;
    $servicio = app(CorregirGuiaService::class);

    $sinPermiso = f03b_admin('custom', array_values(array_diff(LOG2_PERMISOS_BODEGA, ['logistica.guias.corregir'])));
    $asesor = log2_asesor();
    $admin = f03b_admin('all');

    expect(log2_error(fn () => $servicio->corregir($sinPermiso, $guia->id, 'X-1', null, 'x')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->corregir($asesor, $guia->id, 'X-1', null, 'x')))->toBe(Invalida::SIN_PERMISO)
        ->and(log2_error(fn () => $servicio->corregir(null, $guia->id, 'X-1', null, 'x')))->toBe(Invalida::SIN_PERMISO);

    $url = route('admin.logistica.guias.corregir', $guia->id);
    $cuerpo = ['numero_guia' => 'PER-2', 'motivo' => 'x'];

    // Sin la clave, el Bouncer ni deja llegar; con ver el envio no basta.
    $this->actingAs($sinPermiso, 'admin')->postJson($url, $cuerpo)->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->postJson($url, $cuerpo)->assertUnauthorized();

    expect($guia->fresh()->numero_guia)->toBe('PER-1')
        ->and(GuiaCorreccion::query()->count())->toBe(0);

    // BODEGA (con la clave) y el Administrador (all) si.
    $this->actingAs($bodega, 'admin')->postJson($url, ['numero_guia' => 'PER-2', 'motivo' => 'Bodega corrige'])
        ->assertOk()->assertJson(['ok' => true, 'guia_id' => $guia->id]);
    $this->actingAs($admin, 'admin')->postJson($url, ['numero_guia' => 'PER-3', 'motivo' => 'Admin corrige'])->assertOk();

    expect($guia->fresh()->numero_guia)->toBe('PER-3')
        ->and(GuiaCorreccion::query()->where('guia_id', $guia->id)->orderBy('id')->get(['numero_anterior', 'numero_nuevo', 'actor_admin_id'])->map->only(['numero_anterior', 'numero_nuevo', 'actor_admin_id'])->all())
        ->toBe([
            ['numero_anterior' => 'PER-1', 'numero_nuevo' => 'PER-2', 'actor_admin_id' => (int) $bodega->id],
            ['numero_anterior' => 'PER-2', 'numero_nuevo' => 'PER-3', 'actor_admin_id' => (int) $admin->id],
        ]);

    // Errores de dominio por HTTP: JSON con codigo y mensaje, nunca pagina de error.
    $this->actingAs($bodega, 'admin')->postJson($url, ['numero_guia' => 'PER-4', 'motivo' => ''])->assertStatus(422)->assertJson(['ok' => false, 'codigo' => 'motivo_requerido']);
    $this->actingAs($bodega, 'admin')->postJson($url, ['numero_guia' => 'PER-3', 'motivo' => 'igual'])->assertStatus(422)->assertJson(['ok' => false, 'codigo' => 'sin_cambios']);
});

test('COR-5 sin edicion silenciosa: la guia no cambia por fuera del servicio y el historial no se edita ni se borra', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'INM-1']))->guia;
    $c = app(CorregirGuiaService::class)->corregir($bodega, $guia->id, 'INM-2', null, 'Primera');

    // Numero y transportadora siguen siendo inmutables salvo por la correccion auditada.
    expect(log2_error(fn () => $guia->fresh()->update(['numero_guia' => 'SILENCIOSO'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $guia->fresh()->update(['transportadora_codigo' => 'OTRA'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $guia->fresh()->delete()))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and($guia->fresh()->numero_guia)->toBe('INM-2')
        // El historial es solo de adicion.
        ->and(log2_error(fn () => $c->fresh()->update(['motivo' => 'reescrito'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $c->fresh()->update(['numero_anterior' => 'otro'])))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and(log2_error(fn () => $c->fresh()->delete()))->toBe(Invalida::REGISTRO_INMUTABLE)
        ->and($c->fresh()->motivo)->toBe('Primera')
        // La ventana de correccion se cierra siempre (aun si la operacion falla): despues no hay edicion posible.
        ->and(log2_error(fn () => Guia::corrigiendo(fn () => throw new Invalida(Invalida::SIN_CAMBIOS))))->toBe(Invalida::SIN_CAMBIOS)
        ->and(log2_error(fn () => $guia->fresh()->update(['numero_guia' => 'SILENCIOSO-2'])))->toBe(Invalida::REGISTRO_INMUTABLE);
});

test('COR-6 el drawer recibe el historial y el permiso; la bandeja y el listado muestran el numero corregido', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();
    $asesor = log2_asesor();
    pagb_atribuir($e['pedido'], $asesor);
    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e, ['numeroGuia' => 'VIS-1']))->guia;
    app(CorregirGuiaService::class)->corregir($bodega, $guia->id, 'VIS-2', null, 'Typo');

    $contexto = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $g = $contexto['guias'][0];

    expect($contexto['permisos']['corregir'])->toBeTrue()
        ->and($g['numero_guia'])->toBe('VIS-2')
        ->and($g['correcciones'])->toHaveCount(1)
        ->and($g['correcciones'][0])->toMatchArray(['numero_anterior' => 'VIS-1', 'numero_nuevo' => 'VIS-2', 'motivo' => 'Typo', 'actor_name_snapshot' => $bodega->name])
        ->and(app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, f03b_admin('custom', ['logistica', 'logistica.despachos']))['permisos']['corregir'])->toBeFalse();

    // La bandeja de BODEGA y el listado del asesor (dueño) ya muestran el numero vigente, no el viejo.
    $bandeja = $this->actingAs($bodega, 'admin')->get(route('admin.logistica.despachos.index', ['estado' => 'todos']))->assertOk()->getContent();
    $listado = $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent();

    expect($bandeja)->toContain('VIS-2')->and($bandeja)->not->toContain('VIS-1')
        ->and($listado)->toContain('VIS-2')->and($listado)->not->toContain('VIS-1')
        // El drawer trae la accion y su formulario (el motivo es obligatorio en la interfaz y en el servidor).
        ->and($bandeja)->toContain('abrir-correccion')->and($bandeja)->toContain('guardar-correccion')->and($bandeja)->toContain('correccion-motivo');
});

test('COR-8 limite del servidor: si PHP rechaza el archivo, el mensaje habla del limite ACTUAL (no del maximo logico de 5 MB) y nada se crea', function () {
    $e = log2_pedido(2);
    $bodega = log2_bodega();

    // Lo que PHP entrega cuando el archivo supera upload_max_filesize: un UploadedFile con error.
    $rechazado = new UploadedFile(tempnam(sys_get_temp_dir(), 'pdf'), 'grande.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    expect(log2_error(fn () => app(DespachoService::class)->despachar($bodega, log2_s($e, ['archivo' => $rechazado]))))->toBe(Invalida::ARCHIVO_LIMITE_SERVIDOR)
        ->and(DB::table('shipments')->where('order_id', $e['pedido']->id)->count())->toBe(0);

    $guia = app(DespachoService::class)->despachar($bodega, log2_s($e))->guia;

    expect(log2_error(fn () => app(GuiaArchivoService::class)->adjuntarOReemplazar($bodega, $guia->id, $rechazado, null)))->toBe(Invalida::ARCHIVO_LIMITE_SERVIDOR);

    // Otro error de subida (p. ej. archivo parcial) no se confunde con el limite.
    $parcial = new UploadedFile(tempnam(sys_get_temp_dir(), 'pdf'), 'x.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);

    expect(log2_error(fn () => app(GuiaArchivoService::class)->adjuntarOReemplazar($bodega, $guia->id, $parcial, null)))->toBe(Invalida::ARCHIVO_NO_RECIBIDO);

    // El mensaje que ve la operaria nombra el limite vigente del servidor y no promete 5 MB.
    $mensaje = trans('logistica::app.errores.archivo_limite_servidor', ['limite' => ini_get('upload_max_filesize')]);

    expect($mensaje)->toContain((string) ini_get('upload_max_filesize'))->and($mensaje)->not->toContain('5 MB');

    // Por HTTP, si el CUERPO supera post_max_size, Laravel (ValidatePostSize) corta con 413 ANTES de llegar a los
    // controladores y sin nuestro JSON: el drawer lo traduce con el limite vigente que recibe en el contexto.
    $this->actingAs($bodega, 'admin')->call('POST', route('admin.logistica.guias.archivo.guardar', $guia->id), [], [], [], [
        'CONTENT_LENGTH' => 99999999, 'HTTP_ACCEPT' => 'application/json',
    ])->assertStatus(413);

    $contexto = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $componente = file_get_contents(base_path('packages/ByLopez/Logistica/src/Resources/views/admin/despachos/componente.blade.php'));

    expect($contexto['limite_subida'])->toBe(['archivo' => (string) ini_get('upload_max_filesize'), 'cuerpo' => (string) ini_get('post_max_size')])
        ->and($componente)->toContain('respuesta.status === 413')->and($componente)->toContain('error-limite-servidor')
        ->and(trans('logistica::app.despachos.modal.error-limite-servidor', ['limite' => '2M']))->toContain('2M');
});

test('COR-7 ACL: la ruta la autoriza logistica.guias.corregir y existe como GET-padre sin parametros', function () {
    $roles = acl()->getRoles();

    expect($roles['admin.logistica.guias.corregir'])->toBe('logistica.guias.corregir')
        // Las demas rutas de Despachos siguen en su clave (agregar la nueva no pisa ninguna).
        ->and($roles['admin.logistica.despachos.index'])->toBe('logistica.despachos')
        ->and($roles['admin.logistica.guias.archivo'])->toBe('logistica.guias.ver')
        ->and($roles['admin.logistica.guias.archivo.guardar'])->toBe('logistica.guias.archivo');

    $clave = collect(config('acl'))->firstWhere('key', 'logistica.guias.corregir');
    $primera = app('router')->getRoutes()->getByName(Arr::wrap($clave['route'])[0]);

    expect($primera->methods())->toContain('GET')->and($primera->parameterNames())->toBe([]);
});
