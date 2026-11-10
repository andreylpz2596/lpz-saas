<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\RolloService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Plan de validacion Corte y Confeccion, seccion 3: hallazgos H01-H10.
| Cada prueba afirma el comportamiento CORRECTO; si fallaba antes de la
| correccion, el defecto quedo reproducido (ver informe).
|--------------------------------------------------------------------------
*/

test('H01 dos filas del mismo product_id que juntas exceden el disponible se rechazan (servicio)', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H01'));
    $p = Product::factory()->simple()->create();
    $ordenCorte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    $taller = qa_taller();
    $antesOrdenes = OrdenConfeccion::count();

    expect(fn () => app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'detalles' => [
            ['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 80],
            ['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 80],
        ],
    ]))->toThrow(ValidationException::class);

    expect(OrdenConfeccion::count())->toBe($antesOrdenes);
});

test('H01 el endpoint antiguo rechaza el mismo product_id repetido que suma mas que el disponible', function () {
    $admin = qa_admin('Admin H01 HTTP');
    $p = Product::factory()->simple()->create();
    $ordenCorte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    $taller = qa_taller();
    $antesOrdenes = OrdenConfeccion::count();

    $this->actingAs($admin, 'admin')->postJson(route('admin.corte_confeccion.ordenes_confeccion.store'), [
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $taller->id,
        'detalles' => [
            ['product_id' => $p->id, 'cantidad_entregada' => 80],
            ['product_id' => $p->id, 'cantidad_entregada' => 80],
        ],
    ])->assertStatus(422);

    expect(OrdenConfeccion::count())->toBe($antesOrdenes);
});

test('H02 un taller con la ACL de crear (o permission_type=all) no puede usar el endpoint antiguo de distribucion', function () {
    $p = Product::factory()->simple()->create();
    $ordenCorte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    $tallerDestino = qa_taller();
    $antesOrdenes = OrdenConfeccion::count();
    $usuarioTaller = qa_admin('TALLER CONFECCION', 'all');

    $this->actingAs($usuarioTaller, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.store'), [
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $tallerDestino->id,
        'detalles' => [['product_id' => $p->id, 'cantidad_entregada' => 10]],
    ])->assertForbidden();

    $cortador = qa_admin('CORTADOR', 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion.create']);

    $this->actingAs($cortador, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.store'), [
        'orden_corte_id' => $ordenCorte->id,
        'taller_id' => $tallerDestino->id,
        'detalles' => [['product_id' => $p->id, 'cantidad_entregada' => 10]],
    ])->assertForbidden();

    expect(OrdenConfeccion::count())->toBe($antesOrdenes);
});

test('H03 no se puede anular un rollo reservado ni uno ya utilizado en un corte', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H03'));
    $p = Product::factory()->simple()->create();

    // Corte sin cerrar: el rollo queda reservado_en_corte.
    $ordenAbierta = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]], ['cerrar' => false]);
    $rolloReservado = $ordenAbierta->rollosCorte()->first()->rollo;
    expect($rolloReservado->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);

    expect(fn () => app(RolloService::class)->cancel($rolloReservado))->toThrow(ValidationException::class);
    expect($rolloReservado->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);

    // Corte cerrado: rollo agotado.
    $ordenCerrada = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]]);
    $rolloUsado = $ordenCerrada->rollosCorte()->first()->rollo;
    expect($rolloUsado->estado)->toBe(EstadoRollo::Agotado->value);

    expect(fn () => app(RolloService::class)->cancel($rolloUsado))->toThrow(ValidationException::class);
    expect($rolloUsado->fresh()->estado)->toBe(EstadoRollo::Agotado->value);
});

test('H03 no se pueden modificar pesos ni costo de un rollo ya utilizado, ni reactivarlo por peso', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H03b'));
    $p = Product::factory()->simple()->create();
    $ordenCerrada = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]]);
    $rollo = $ordenCerrada->rollosCorte()->first()->rollo;
    $pesoInicial = (float) $rollo->peso_inicial;
    $costo = (float) $rollo->costo_total;

    foreach ([
        ['peso_disponible' => 50],
        ['peso_inicial' => $pesoInicial + 10, 'peso_disponible' => $pesoInicial + 10],
        ['costo_total' => $costo + 1000],
    ] as $cambio) {
        expect(fn () => app(RolloService::class)->update($rollo->fresh(), array_merge([
            'peso_inicial' => $pesoInicial, 'peso_disponible' => (float) $rollo->fresh()->peso_disponible, 'costo_total' => $costo,
        ], $cambio)))->toThrow(ValidationException::class);
    }

    $fresco = $rollo->fresh();
    expect($fresco->estado)->toBe(EstadoRollo::Agotado->value)
        ->and((float) $fresco->peso_disponible)->toBe(0.0)
        ->and((float) $fresco->costo_total)->toBe($costo);
});

test('H03 por endpoint: el POST de anulacion de un rollo reservado responde con error y no cambia el estado', function () {
    $admin = qa_admin('Admin H03 HTTP');
    $p = Product::factory()->simple()->create();
    $orden = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]], ['cerrar' => false]);
    $rollo = $orden->rollosCorte()->first()->rollo;

    $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.rollos.cancel', $rollo->id))
        ->assertSessionHasErrors();

    expect($rollo->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
});

test('H04 la recepcion unica exige cobertura de TODOS los detalles del corte', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H04'));
    $a = Product::factory()->simple()->create();
    $b = Product::factory()->simple()->create();

    $orden = qa_orden_corte_recibida([
        ['product' => $a, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
        ['product' => $b, 'cantidad_recibida' => 40, 'cantidad_defectuosa' => 0],
    ], ['sin_recepcion' => true]);

    expect($orden->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);

    $detalleA = $orden->detalles->first();

    expect(fn () => app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'QA',
        'detalles' => [['orden_corte_detalle_id' => $detalleA->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]],
    ]))->toThrow(ValidationException::class);

    expect(DB::table('bylopez_cc_recepciones_corte')->where('orden_corte_id', $orden->id)->count())->toBe(0)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);
});

test('H05 el camino antiguo de agregar detalle rechaza configurables y productos que no son variante simple', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H05'));
    $p = Product::factory()->simple()->create();
    $configurable = Product::factory()->create(['type' => 'configurable']);

    $orden = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]], ['sin_entrega' => true]);

    expect(fn () => app(OrdenCorteService::class)->agregarDetalle($orden, [
        'product_id' => $configurable->id, 'talla' => 'M', 'cantidad_proyectada' => 5,
    ]))->toThrow(ValidationException::class);

    expect($orden->fresh()->detalles()->where('product_id', $configurable->id)->exists())->toBeFalse();
});

test('H06 anular una orden con la instancia desactualizada no libera unidades ya reportadas', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H06'));
    $p = Product::factory()->simple()->create();
    $ordenCorte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    $taller = qa_taller();
    $antesOrdenes = OrdenConfeccion::count();

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id, 'taller_id' => $taller->id,
        'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 100]],
    ]);

    $instanciaVieja = OrdenConfeccion::find($orden->id); // cargada cuando aun estaba "activa"

    Auth::guard('admin')->setUser($tallerUser = $taller->usuario ?? qa_admin('TALLER'));
    Auth::guard('admin')->setUser(qa_admin('Admin H06b'));
    app(OrdenConfeccionService::class)->reportarTaller($orden, [
        'detalles' => [['id' => $orden->detalles->first()->id, 'cantidad' => 40]],
    ]);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);

    expect(fn () => app(OrdenConfeccionService::class)->cancel($instanciaVieja))->toThrow(ValidationException::class);

    expect($orden->fresh()->estado)->toBe(EstadoOrdenConfeccion::EnConfeccion->value);
});

test('H07 la lectura de tarifas de una orden no cambia ni pierde tarifas guardadas si el catalogo cambia de familia', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H07'));
    $p = Product::factory()->simple()->create();
    $ordenCorte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0]]);
    $taller = qa_taller();
    $antesOrdenes = OrdenConfeccion::count();

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $ordenCorte->id, 'taller_id' => $taller->id,
        'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => 60]],
    ]);

    $servicio = app(OrdenTarifaService::class);
    $antes = $servicio->combinaciones($orden->fresh())['lineas'];
    expect($antes)->toHaveCount(1);
    $familiaOriginal = $antes[0]['attribute_family_id'];

    // Se guarda una tarifa para esa combinacion.
    $servicio->pactar($orden->fresh(), $antes[0]['tipo_tela_id'], $familiaOriginal, 1450, 'Pacto QA', Auth::guard('admin')->id());

    // El catalogo reasigna el producto a otra familia.
    $otraFamilia = AttributeFamily::factory()->create()->id;
    expect($otraFamilia)->not->toBe($familiaOriginal);
    DB::table('products')->where('id', $p->id)->update(['attribute_family_id' => $otraFamilia]);

    $despues = $servicio->combinaciones($orden->fresh())['lineas'];
    expect($despues)->toHaveCount(1)
        ->and($despues[0]['attribute_family_id'])->toBe($familiaOriginal);

    $resumen = $servicio->resumen($orden->fresh());
    expect($resumen['lineas'])->toHaveCount(1)
        ->and((float) $resumen['lineas'][0]['tarifa'])->toBe(1450.0)
        ->and($resumen['total_referencial'])->toBe(87000.0);
});

test('H08 el cierre del corte no sobrescribe las piezas verificadas por bodega con las reportadas por el cortador', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H08'));
    $p = Product::factory()->simple()->create();

    // Cortador reporta 120 (100 + 20 defectuosas); bodega recibe 110 (diferencia con observacion).
    $orden = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 20]], ['sin_recepcion' => true]);
    $detalle = $orden->detalles->first();
    expect((int) $detalle->cantidad_real_cortada)->toBe(120);

    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'QA',
        'detalles' => [['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 110, 'cantidad_defectuosa' => 10, 'observacion' => 'faltaron 10']],
    ]);

    $trasRecepcion = $orden->fresh();
    expect($trasRecepcion->total_piezas_cortadas)->toBe(110);

    app(OrdenCorteService::class)->cerrar($trasRecepcion);

    $cerrada = $orden->fresh();
    expect($cerrada->total_piezas_cortadas)->toBe(110)
        ->and((int) $cerrada->detalles->first()->cantidad_real_cortada)->toBe(120);
});

test('H09 editar la cabecera de una compra no desincroniza el proveedor de rollos ya vinculados a un corte', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H09'));
    $p = Product::factory()->simple()->create();
    $orden = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]]);
    $rollo = $orden->rollosCorte()->first()->rollo;
    $compra = $rollo->compraTela ?? DB::table('bylopez_cc_compras_tela')->where('id', $rollo->compra_tela_id)->first();

    expect($compra)->not->toBeNull();

    $otroProveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Otro proveedor QA '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [], 'referencias' => [], 'estado' => 'activo', 'observacion' => null,
    ]);

    $proveedorAntes = $rollo->fresh()->proveedor_tela_id ?? null;

    $modelo = CompraTela::find($compra->id);

    try {
        app(CompraTelaService::class)->update($modelo, [
            'codigo' => $modelo->codigo, 'proveedor_id' => $otroProveedor->id, 'numero_factura' => null,
            'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
            'rollos' => $modelo->rollos->map(fn ($r) => ['id' => $r->id, 'codigo' => $r->codigo, 'observacion' => 'x'])->all(),
        ]);
    } catch (ValidationException $e) {
        // Rechazo explicito: coherente con el contrato (proveedor inmutable con rollos vinculados).
    }

    $rolloDespues = $rollo->fresh();
    $compraDespues = CompraTela::find($compra->id);

    // Invariante: el proveedor de la compra y el de sus rollos nunca divergen.
    expect((int) ($rolloDespues->proveedor_tela_id ?? $compraDespues->proveedor_id))->toBe((int) $compraDespues->proveedor_id);
    expect($proveedorAntes === null || (int) $rolloDespues->proveedor_tela_id === (int) $proveedorAntes)->toBeTrue();
});

test('H10 un segundo reporte de resultado y una segunda recepcion sobre el mismo corte fallan con error controlado', function () {
    Auth::guard('admin')->setUser(qa_admin('Admin H10'));
    $p = Product::factory()->simple()->create();
    $orden = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]], ['sin_recepcion' => true]);
    $detalle = $orden->detalles->first();

    $payload = [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'QA',
        'detalles' => [['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]],
    ];

    $instanciaVieja = OrdenCorte::find($orden->id);

    app(RecepcionCorteService::class)->create($orden, $payload);

    // Doble envio con la instancia cargada ANTES de la primera transaccion.
    expect(fn () => app(RecepcionCorteService::class)->create($instanciaVieja, $payload))->toThrow(ValidationException::class);

    expect(DB::table('bylopez_cc_recepciones_corte')->where('orden_corte_id', $orden->id)->count())->toBe(1);

    expect(fn () => app(OrdenCorteService::class)->reportarResultado($instanciaVieja, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 999]],
    ]))->toThrow(ValidationException::class);

    expect((int) $detalle->fresh()->cantidad_real_cortada)->toBe(10);
});
