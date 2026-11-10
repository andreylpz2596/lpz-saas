<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\ReasignacionService;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| P11: matriz de permisos por endpoint. ACL determina la accion; ownership determina los registros.
| Un 401 (ACL de ruta del middleware de Bagisto) y un 403 (gate/FormRequest/ownership) son ambos «denegado».
|--------------------------------------------------------------------------
*/

function pm_escenario(): array
{
    $admin = qa_admin('Admin Matriz '.uniqid());
    $p = Product::factory()->simple()->create();
    $corte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($admin);

    $usuarioPropio = qa_admin('TALLER CONFECCION', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller']);
    $usuarioAjeno = qa_admin('TALLER CONFECCION', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller']);
    $propio = qa_taller(['usuario_id' => $usuarioPropio->id]);
    $ajeno = qa_taller(['usuario_id' => $usuarioAjeno->id]);

    $ordenPropia = app(OrdenConfeccionService::class)->create(['orden_corte_id' => $corte->id, 'taller_id' => $propio->id, 'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 40]]]);
    $ordenAjena = app(OrdenConfeccionService::class)->create(['orden_corte_id' => $corte->id, 'taller_id' => $ajeno->id, 'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 40]]]);

    return compact('admin', 'p', 'corte', 'propio', 'ajeno', 'usuarioPropio', 'usuarioAjeno', 'ordenPropia', 'ordenAjena');
}

function pm_denegado($test, $usuario, string $metodo, string $url, array $datos = []): void
{
    $respuesta = $test->actingAs($usuario, 'admin')->{$metodo}($url, $datos);
    expect($respuesta->status())->toBeIn([401, 403], "{$metodo} {$url} debia denegarse y respondio {$respuesta->status()}");
}

test('P11 el taller solo ve y opera SUS ordenes: la URL de otra orden y el taller_id ajeno no amplian el alcance', function () {
    $e = pm_escenario();

    $this->actingAs($e['usuarioPropio'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenPropia']->id))->assertOk();
    pm_denegado($this, $e['usuarioPropio'], 'get', route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenAjena']->id));
    pm_denegado($this, $e['usuarioPropio'], 'get', route('admin.corte_confeccion.talleres.show', $e['ajeno']->id));
    pm_denegado($this, $e['usuarioPropio'], 'post', route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $e['ordenAjena']->id), ['detalles' => [['id' => $e['ordenAjena']->detalles->first()->id, 'cantidad' => 1]]]);

    // El listado (DataGrid) solo trae lo suyo aunque se pida el taller ajeno.
    $grid = $this->actingAs($e['usuarioPropio'], 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.index', ['taller_id' => $e['ajeno']->id]), ['X-Requested-With' => 'XMLHttpRequest']);
    $ids = collect($grid->json('records'))->pluck('id')->all();
    expect($ids)->not->toContain($e['ordenAjena']->id)->not->toContain($e['ordenPropia']->id);

    // Sin filtro: solo lo suyo.
    $propias = $this->actingAs($e['usuarioPropio'], 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.index'), ['X-Requested-With' => 'XMLHttpRequest']);
    $idsPropios = collect($propias->json('records'))->pluck('id')->all();
    expect($idsPropios)->toContain($e['ordenPropia']->id)->not->toContain($e['ordenAjena']->id);
});

test('P11 taller y cortador no distribuyen, reasignan, administran tarifas, ajustan inventario ni reciben, aunque tengan permission_type=all', function () {
    $e = pm_escenario();
    $detalle = $e['ordenPropia']->detalles->first();

    $usuarios = [
        'taller all' => qa_admin('TALLER CONFECCION', 'all'),
        'cortador all' => qa_admin('CORTADOR', 'all'),
    ];

    foreach ($usuarios as $nombre => $u) {
        $contexto = " ({$nombre})";
        pm_denegado($this, $u, 'get', route('admin.corte_confeccion.ordenes_confeccion.create', $e['corte']->id));
        pm_denegado($this, $u, 'get', route('admin.corte_confeccion.ordenes_confeccion.contexto', $e['corte']->id));
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.ordenes_confeccion.distribuir'), ['orden_corte_id' => $e['corte']->id, 'asignaciones' => [['taller_id' => $e['propio']->id, 'detalles' => [['product_id' => $e['p']->id, 'cantidad' => 1]]]]]);
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.ordenes_confeccion.store'), ['orden_corte_id' => $e['corte']->id, 'taller_id' => $e['propio']->id, 'detalles' => [['product_id' => $e['p']->id, 'cantidad_entregada' => 1]]]);
        pm_denegado($this, $u, 'get', route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordenPropia']->id));
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $e['ordenPropia']->id), ['taller_destino_id' => $e['ajeno']->id, 'idempotency_key' => 'k'.uniqid(), 'motivo' => 'x', 'situacion_fisica' => 'sin_entrega_fisica', 'lineas' => [['detalle_id' => $detalle->id, 'cantidad' => 1]]]);
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.ordenes_confeccion.tarifa', $e['ordenPropia']->id), ['tipo_tela_id' => 1, 'attribute_family_id' => 1, 'valor_unitario' => 100, 'motivo' => 'x'.$contexto]);
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.talleres.tarifas.store', $e['propio']->id), ['tipo_tela_id' => 1, 'attribute_family_id' => 1, 'valor_unitario' => 100, 'vigente_desde' => now()->toDateString()]);
        pm_denegado($this, $u, 'post', route('admin.corte_confeccion.inventario_movimientos.ajuste_manual'), ['product_id' => $e['p']->id, 'tipo' => 'entrada', 'cantidad' => 1, 'motivo' => 'x', 'idempotency_key' => 'z'.uniqid()]);
    }

    // Anular una orden AJENA: un taller con permission_type=all queda bloqueado por ownership. (H11 corregido: el cortador tambien queda vetado; ver CierreValidacionH11CustodiaBodegaTest.)
    pm_denegado($this, $usuarios['taller all'], 'post', route('admin.corte_confeccion.ordenes_confeccion.cancel', $e['ordenAjena']->id));
});

test('P11 un usuario sin la ACL falla cerrado en cada accion sensible; bodega y admin conservan el acceso transversal', function () {
    $e = pm_escenario();
    $sinAcl = qa_admin('Sin ACL Matriz', 'custom', ['corte_confeccion']);

    foreach ([
        ['get', route('admin.corte_confeccion.ordenes_confeccion.create', $e['corte']->id)],
        ['get', route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordenPropia']->id)],
        ['get', route('admin.corte_confeccion.ordenes_confeccion.index')],
        ['get', route('admin.corte_confeccion.talleres.index')],
        ['get', route('admin.corte_confeccion.rollos.index')],
        ['get', route('admin.corte_confeccion.compras_tela.index')],
        ['get', route('admin.corte_confeccion.proveedores_tela.index')],
        ['get', route('admin.corte_confeccion.ordenes_corte.index')],
        ['get', route('admin.corte_confeccion.inventario_movimientos.index')],
        ['post', route('admin.corte_confeccion.ordenes_confeccion.cancel', $e['ordenPropia']->id)],
        ['post', route('admin.corte_confeccion.rollos.cancel', 1)],
    ] as [$metodo, $url]) {
        pm_denegado($this, $sinAcl, $metodo, $url);
    }

    $bodega = qa_admin('Bodega Matriz', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_confeccion.create', 'corte_confeccion.ordenes_confeccion.operar', 'corte_confeccion.ordenes_confeccion.recibir_bodega', 'corte_confeccion.ordenes_confeccion.reasignar', 'corte_confeccion.talleres']);

    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenAjena']->id))->assertOk();
    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenPropia']->id))->assertOk();
    $this->actingAs($bodega, 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $e['ordenPropia']->id))->assertOk();
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.index'))->assertOk();
});

test('P11 las observaciones y snapshots con HTML se escapan en la ficha de la orden', function () {
    $e = pm_escenario();
    $e['ordenPropia']->update(['observacion' => '<script>alert("xss")</script>']);
    $e['ordenPropia']->detalles()->update(['producto_nombre' => '<img src=x onerror=alert(1)>']);

    $html = $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenPropia']->id))->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x onerror=alert(1)>')
        ->and($html)->not->toContain('<script>alert("xss")</script>');
});

test('P11 el taller que cede o recibe prendas no ve el nombre ni el enlace del otro taller', function () {
    $e = pm_escenario();
    Auth::guard('admin')->setUser($e['admin']);

    $detalle = $e['ordenPropia']->detalles->first();
    $r = app(ReasignacionService::class)->reasignar(qa_payload_reasignar($e['ordenPropia'], $e['ajeno'], [[$detalle->id, 10]]));

    $html = $this->actingAs($e['usuarioPropio'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenPropia']->id))->assertOk()->getContent();

    expect($html)->toContain('Reasignaciones')
        ->and($html)->toContain('otro taller')
        ->and($html)->not->toContain($e['ajeno']->nombre)
        ->and($html)->not->toContain(route('admin.corte_confeccion.ordenes_confeccion.show', $r->orden_confeccion_destino_id));

    // El destino tampoco puede abrir la orden del origen ni el contexto/acciones de reasignacion.
    pm_denegado($this, $e['usuarioAjeno'], 'get', route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordenPropia']->id));
    pm_denegado($this, $e['usuarioAjeno'], 'post', route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.revertir', $r->id), ['motivo' => 'x']);
    pm_denegado($this, $e['usuarioAjeno'], 'post', route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.recibir', $r->id));
});
