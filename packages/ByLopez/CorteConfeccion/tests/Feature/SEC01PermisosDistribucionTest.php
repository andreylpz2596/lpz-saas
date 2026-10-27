<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use ByLopez\CorteConfeccion\Support\DistribucionConfeccionGate;
use Illuminate\Support\Facades\Auth;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Fase SEC-01 -- correccion de permisos operativos de Corte/Confeccion.
| Prefijo "sec01_" (independiente de los helpers "dist_" de
| DistribucionConfeccionTest.php) para no depender del orden de carga de
| otros archivos de test.
|--------------------------------------------------------------------------
*/

function sec01_role(string $nombre, string $permissionType, array $permisos = []): Role
{
    return Role::factory()->create([
        'name' => $nombre,
        'permission_type' => $permissionType,
        'permissions' => $permisos,
    ]);
}

function sec01_admin(string $nombreRol, string $permissionType, array $permisos = []): Admin
{
    return Admin::factory()->create(['role_id' => sec01_role($nombreRol, $permissionType, $permisos)->id]);
}

function sec01_crearTaller(array $overrides = [])
{
    $usuarioId = array_key_exists('usuario_id', $overrides)
        ? $overrides['usuario_id']
        : sec01_admin('TALLER CONFECCION', 'all')->id;

    return app(TallerService::class)->create(array_merge([
        'nombre' => 'SEC01 Taller '.uniqid(),
        'estado' => 'activo',
        'nit' => null,
        'contacto' => null,
        'telefono' => null,
        'direccion' => null,
        'observacion' => null,
        'usuario_id' => $usuarioId,
    ], $overrides));
}

/**
 * Permisos objetivo (post SEC-01) para BODEGA: los ya existentes hoy en
 * produccion para ese rol, mas los dos que le faltaban para operar la
 * distribucion (indice/consulta y create/contexto/distribuir).
 */
function sec01_permisosBodegaObjetivo(): array
{
    return [
        'corte_confeccion',
        'corte_confeccion.ordenes_corte',
        'corte_confeccion.ordenes_corte.create',
        'corte_confeccion.ordenes_corte.operar',
        'corte_confeccion.recepciones_corte',
        'corte_confeccion.ordenes_confeccion',
        'corte_confeccion.ordenes_confeccion.create',
    ];
}

/**
 * Permisos objetivo (post SEC-01) para TALLER CONFECCION: bandeja scoped de
 * "Mis ordenes", sin permisos de distribucion, recepcion o administracion de
 * Talleres.
 */
function sec01_permisosTallerObjetivo(): array
{
    return ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.reportar_taller'];
}

function sec01_crearOrdenCorteRecibida(array $productosCantidades): OrdenCorte
{
    Auth::guard('admin')->setUser(sec01_admin('SEC01 Creador '.uniqid(), 'all'));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela SEC01 '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor SEC01 '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'SEC01', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-SEC01-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-SEC01-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 200, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $cortador = sec01_admin('CORTADOR', 'all');

    $ordenCorteService = app(OrdenCorteService::class);

    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $cortador->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => collect($productosCantidades)->map(fn ($p) => [
            'product_id' => $p['product']->id,
            'genero_linea' => 'Nino',
            'talla' => $p['talla'] ?? '',
            'cantidad_proyectada' => $p['cantidad_recibida'] + $p['cantidad_defectuosa'],
        ])->values()->all(),
    ]);

    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    Auth::guard('admin')->setUser(sec01_admin('SEC01 Cortador Reporte '.uniqid(), 'all'));
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'detalle_id' => $detalleOrden->id,
            'cantidad_real_cortada' => $productosCantidades[$i]['cantidad_recibida'] + $productosCantidades[$i]['cantidad_defectuosa'],
        ])->all(),
    ]);

    Auth::guard('admin')->setUser(sec01_admin('SEC01 Bodega '.uniqid(), 'all'));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'SEC01 Bodega',
        'observacion' => null,
        // F0: identidad por orden_corte_detalle_id (no product_id/talla). El
        // indice corresponde 1:1 con $productosCantidades porque
        // $orden->detalles se creo, dos bloques arriba, en ese mismo orden.
        'detalles' => $orden->detalles->values()->map(fn ($detalleOrden, $i) => [
            'orden_corte_detalle_id' => $detalleOrden->id,
            'cantidad_recibida' => $productosCantidades[$i]['cantidad_recibida'],
            'cantidad_defectuosa' => $productosCantidades[$i]['cantidad_defectuosa'],
            'observacion' => null,
        ])->all(),
    ]);

    $ordenCorteService->cerrar($orden->fresh());

    return $orden->fresh()->load(['recepcion.detalles', 'ordenesConfeccion.detalles']);
}

/*
|--------------------------------------------------------------------------
| 1. Admin puede distribuir.
|--------------------------------------------------------------------------
*/
test('SEC-01 - Admin (permission_type=all) puede distribuir', function () {
    $admin = sec01_admin('Administrador', 'all');
    Auth::guard('admin')->setUser($admin);

    $producto = Product::factory()->simple()->create();
    $taller = sec01_crearTaller();
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);

    $response = $this->actingAs($admin, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 50]]]]]
    );

    $response->assertOk()->assertJson(['success' => true]);
});

/*
|--------------------------------------------------------------------------
| 2. Bodega puede create/contexto/distribuir -- y ademas confirma que
| SEC-01 no rompe 03C3 (escenario multi-taller completo con el rol real
| que efectivamente lo va a usar en produccion).
|--------------------------------------------------------------------------
*/
test('SEC-01 - Bodega (permisos objetivo) puede GET create, GET contexto y POST distribuir; genera 2 ordenes reales', function () {
    $bodega = sec01_admin('BODEGA', 'custom', sec01_permisosBodegaObjetivo());

    $productoL = Product::factory()->simple()->create();
    $tallerA = sec01_crearTaller(['nombre' => 'SEC01 Taller 1 '.uniqid()]);
    $tallerB = sec01_crearTaller(['nombre' => 'SEC01 Taller 2 '.uniqid()]);

    Auth::guard('admin')->setUser($bodega);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $productoL, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);

    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id))->assertOk();
    $this->actingAs($bodega, 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id))->assertOk();

    $response = $this->actingAs($bodega, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        [
            'orden_corte_id' => $ordenCorte->id,
            'asignaciones' => [
                ['taller_id' => $tallerA->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 60]]],
                ['taller_id' => $tallerB->id, 'detalles' => [['product_id' => $productoL->id, 'cantidad' => 20]]],
            ],
        ]
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 3-5. Taller Confeccion (permisos objetivo scoped) no puede
| create/contexto/distribuir.
|--------------------------------------------------------------------------
*/
test('SEC-01 - Taller Confeccion (permisos objetivo) NO puede GET create de distribucion', function () {
    $taller = sec01_admin('TALLER CONFECCION', 'custom', sec01_permisosTallerObjetivo());
    $producto = Product::factory()->simple()->create();
    Auth::guard('admin')->setUser(sec01_admin('SEC01 Creador Aux', 'all'));
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    // Bloqueado ya en la capa ACL (401, Bouncer::checkIfAuthorized) -- ni
    // siquiera llega a evaluar DistribucionConfeccionGate. Ver test dedicado
    // de permission_type=all mas abajo para la defensa cuando la ACL SI pasa.
    $this->actingAs($taller, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id))
        ->assertStatus(401);
});

test('SEC-01 - Taller Confeccion (permisos objetivo) NO puede GET contexto de distribucion', function () {
    $taller = sec01_admin('TALLER CONFECCION', 'custom', sec01_permisosTallerObjetivo());
    $producto = Product::factory()->simple()->create();
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    $this->actingAs($taller, 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id))
        ->assertStatus(401);
});

test('SEC-01 - Taller Confeccion (permisos objetivo) NO puede POST distribuir', function () {
    $taller = sec01_admin('TALLER CONFECCION', 'custom', sec01_permisosTallerObjetivo());
    $producto = Product::factory()->simple()->create();
    $tallerDestino = sec01_crearTaller(['nombre' => 'SEC01 Taller Destino '.uniqid()]);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    $response = $this->actingAs($taller, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $tallerDestino->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]]]
    );

    $response->assertStatus(401);
    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 6. Taller Confeccion no puede mutar RecepcionCorte incluso si, por un
| error de configuracion futuro, la ACL volviera a concederselo -- el
| gate de negocio (CantidadCortadaGate) debe seguir bloqueandolo.
|--------------------------------------------------------------------------
*/
test('SEC-01 - Taller Confeccion con ACL de recepcion mal configurada (simulada) sigue sin poder confirmar recepcion', function () {
    // Simula deliberadamente el estado ANTERIOR (incorrecto) para demostrar
    // que la proteccion real no depende de la ACL: aunque el rol tuviera el
    // permiso de recepciones_corte, el gate de negocio debe rechazarlo.
    $taller = sec01_admin('TALLER CONFECCION', 'custom', ['corte_confeccion', 'corte_confeccion.recepciones_corte']);

    expect(CantidadCortadaGate::userCanConfirmarRecepcion($taller))->toBeFalse();

    $producto = Product::factory()->simple()->create();
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    // El fixture ya genera y cierra la recepcion real de esta orden --
    // irrelevante para esta prueba: authorize() se evalua ANTES de
    // cualquier regla de negocio del controller/Service, asi que el 403
    // debe producirse sin importar el estado real de la orden.
    $response = $this->actingAs($taller, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $ordenCorte->id),
        [
            'fecha_recepcion' => now()->toDateString(),
            'merma_total' => 0,
            'recibido_por' => 'Intento no autorizado',
            'detalles' => [['product_id' => $producto->id, 'talla' => '', 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]],
        ]
    );

    $response->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| 7. Taller Confeccion con permission_type=all sigue sin poder distribuir
| (la defensa NO depende de la ACL/permission_type).
|--------------------------------------------------------------------------
*/
test('SEC-01 - Taller Confeccion con permission_type=all (bypass total de ACL) sigue sin poder distribuir', function () {
    $taller = sec01_admin('TALLER CONFECCION', 'all');
    $producto = Product::factory()->simple()->create();
    $tallerDestino = sec01_crearTaller(['nombre' => 'SEC01 Taller Destino2 '.uniqid()]);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    expect(DistribucionConfeccionGate::puedeDistribuir($taller))->toBeFalse();

    $this->actingAs($taller, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_confeccion.create', $ordenCorte->id))
        ->assertStatus(403);

    $this->actingAs($taller, 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id))
        ->assertStatus(403);

    $response = $this->actingAs($taller, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $tallerDestino->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]]]
    );
    $response->assertStatus(403);

    expect(OrdenConfeccion::query()->where('orden_corte_id', $ordenCorte->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 8. Cortador sigue sin poder distribuir.
|--------------------------------------------------------------------------
*/
test('SEC-01 - Cortador sigue sin poder distribuir', function () {
    $cortador = sec01_admin('CORTADOR', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.ordenes_corte.operar']);
    $producto = Product::factory()->simple()->create();
    $tallerDestino = sec01_crearTaller(['nombre' => 'SEC01 Taller Destino3 '.uniqid()]);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    expect(DistribucionConfeccionGate::puedeDistribuir($cortador))->toBeFalse();

    $response = $this->actingAs($cortador, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $tallerDestino->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]]]
    );

    // Bloqueado ya en la capa ACL (401) -- Cortador nunca tuvo
    // corte_confeccion.ordenes_confeccion.create. La asercion unitaria de
    // arriba confirma ademas que, aunque la tuviera, el Gate lo bloquearia.
    $response->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| 9. Bodega puede consultar las ordenes generadas (index + show).
|--------------------------------------------------------------------------
*/
test('SEC-01 - Bodega puede consultar el index y el show de ordenes de confeccion ya generadas', function () {
    $bodega = sec01_admin('BODEGA', 'custom', sec01_permisosBodegaObjetivo());
    $producto = Product::factory()->simple()->create();
    $taller = sec01_crearTaller(['nombre' => 'SEC01 Taller Consulta '.uniqid()]);

    Auth::guard('admin')->setUser($bodega);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    $ordenes = app(OrdenConfeccionService::class)->distribuir([
        'orden_corte_id' => $ordenCorte->id,
        'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 30]]]],
    ]);

    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.index'))->assertOk();
    $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $ordenes->first()->id))->assertOk();
});

/*
|--------------------------------------------------------------------------
| 10. La configuracion de permisos de SEC-01 no rompe el contrato JSON de
| 03C3 (success/errors) -- regresion explicita con el rol Bodega real.
|--------------------------------------------------------------------------
*/
test('SEC-01 - el contrato JSON de 03C3 (success y 422) sigue intacto para Bodega', function () {
    $bodega = sec01_admin('BODEGA', 'custom', sec01_permisosBodegaObjetivo());
    $producto = Product::factory()->simple()->create();
    $taller = sec01_crearTaller(['nombre' => 'SEC01 Taller 03C3 '.uniqid()]);
    $tallerInactivo = sec01_crearTaller(['nombre' => 'SEC01 Taller Inactivo '.uniqid(), 'estado' => 'inactivo']);

    Auth::guard('admin')->setUser($bodega);
    $ordenCorte = sec01_crearOrdenCorteRecibida([['product' => $producto, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);

    // Camino de exito.
    $exito = $this->actingAs($bodega, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $taller->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 20]]]]]
    );
    $exito->assertOk()->assertJsonStructure(['success', 'message', 'ordenes' => [['id', 'codigo']], 'redirect_url']);

    // Camino de error de validacion (taller inactivo) -- 422 estandar de Laravel.
    $error = $this->actingAs($bodega, 'admin')->postJson(
        route('admin.corte_confeccion.ordenes_confeccion.distribuir'),
        ['orden_corte_id' => $ordenCorte->id, 'asignaciones' => [['taller_id' => $tallerInactivo->id, 'detalles' => [['product_id' => $producto->id, 'cantidad' => 10]]]]]
    );
    $error->assertStatus(422)->assertJsonStructure(['message', 'errors']);
});
