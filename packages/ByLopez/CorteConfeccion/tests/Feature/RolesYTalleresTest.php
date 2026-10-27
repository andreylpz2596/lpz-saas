<?php

use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use ByLopez\CorteConfeccion\Support\CortadorResolver;
use ByLopez\CorteConfeccion\Support\TallerConfeccionResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function crearAdminRolFase2(string $nombreRol, string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function datosTaller(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Taller QA '.uniqid(),
        'nit' => null,
        'contacto' => 'Contacto QA',
        'telefono' => '3000000000',
        'direccion' => null,
        'estado' => 'activo',
        'observacion' => null,
        'usuario_id' => crearAdminRolFase2('TALLER CONFECCION')->id,
    ], $overrides);
}

test('un usuario con rol CORTADOR aparece como cortador', function () {
    $usuarioA = crearAdminRolFase2('CORTADOR');

    expect(CortadorResolver::isCortador($usuarioA->id))->toBeTrue();
    expect(CortadorResolver::options()->pluck('id'))->toContain($usuarioA->id);
});

test('un usuario con rol BODEGA NO aparece como cortador', function () {
    $usuarioB = crearAdminRolFase2('BODEGA');

    expect(CortadorResolver::isCortador($usuarioB->id))->toBeFalse();
    expect(CortadorResolver::options()->pluck('id'))->not->toContain($usuarioB->id);
});

test('un usuario con rol TALLER CONFECCION NO aparece como cortador', function () {
    $usuarioC = crearAdminRolFase2('TALLER CONFECCION');

    expect(CortadorResolver::isCortador($usuarioC->id))->toBeFalse();
    expect(CortadorResolver::options()->pluck('id'))->not->toContain($usuarioC->id);
});

test('un usuario con rol TALLER CONFECCION puede asociarse a un Taller', function () {
    $usuarioC = crearAdminRolFase2('TALLER CONFECCION');

    expect(TallerConfeccionResolver::tieneRol($usuarioC->id))->toBeTrue();

    $taller = app(TallerService::class)->create(datosTaller(['usuario_id' => $usuarioC->id]));

    expect($taller->usuario_id)->toBe($usuarioC->id)
        ->and($taller->usuario->id)->toBe($usuarioC->id);
});

test('un usuario con rol CORTADOR NO puede asociarse como usuario de Taller', function () {
    $usuarioA = crearAdminRolFase2('CORTADOR');

    expect(fn () => app(TallerService::class)->create(datosTaller(['usuario_id' => $usuarioA->id])))
        ->toThrow(ValidationException::class);
});

test('un usuario con rol BODEGA NO puede asociarse como usuario de Taller', function () {
    $usuarioB = crearAdminRolFase2('BODEGA');

    expect(fn () => app(TallerService::class)->create(datosTaller(['usuario_id' => $usuarioB->id])))
        ->toThrow(ValidationException::class);
});

test('un Taller sin usuario no es valido', function () {
    expect(fn () => app(TallerService::class)->create(datosTaller(['usuario_id' => null])))
        ->toThrow(ValidationException::class);
});

test('un Taller con usuario vacio no es valido', function () {
    expect(fn () => app(TallerService::class)->create(datosTaller(['usuario_id' => ''])))
        ->toThrow(ValidationException::class);
});

test('un Taller con usuario TALLER CONFECCION es valido', function () {
    $usuarioC = crearAdminRolFase2('TALLER CONFECCION');

    $taller = app(TallerService::class)->create(datosTaller(['usuario_id' => $usuarioC->id]));

    expect($taller->id)->not->toBeNull()
        ->and($taller->usuario_id)->toBe($usuarioC->id);
});

test('un Taller inactivo no puede utilizarse para una nueva orden de confeccion', function () {
    $taller = app(TallerService::class)->create(datosTaller(['estado' => 'inactivo']));

    $orden = fase2CrearOrdenReportadaConRecepcion();

    expect(fn () => app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $orden->id,
        'taller_id' => $taller->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $orden->detalles->first()->product_id, 'talla' => $orden->detalles->first()->talla, 'cantidad_entregada' => 1]],
    ]))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Fixture minima y autosuficiente para el caso de taller inactivo:
| proveedor -> compra -> rollo -> orden de corte -> recibida en bodega.
|--------------------------------------------------------------------------
*/
function fase2CrearOrdenReportadaConRecepcion()
{
    Auth::guard('admin')->setUser(crearAdminRolFase2('Creador QA Fase2 '.uniqid(), 'all'));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);

    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela QA Fase2 '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA Fase2 '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-REF', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo']],
        'estado' => 'activo', 'observacion' => null,
    ]);
    $referencia = $proveedor->referenciasTela()->first();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-F2-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
        'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
        'rollos' => [['codigo' => 'R-QA-F2-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $referencia->id, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000]],
    ]);
    $rollo = $compra->rollos()->first();
    $producto = Product::factory()->simple()->create();
    $cortador = crearAdminRolFase2('CORTADOR');

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id], 'cortador_id' => $cortador->id, 'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 10]],
    ]);
    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, 'entregado_a_cortador', 'en_corte');
    $orden = $ordenCorteService->cambiarEstado($orden, 'en_corte', 'reportado_por_cortador');

    // F0: identidad por orden_corte_detalle_id (no product_id/talla).
    $detalle = $orden->detalles->first();

    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0,
        'recibido_por' => 'Bodega QA', 'observacion' => null,
        'detalles' => [['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0, 'observacion' => null]],
    ]);

    return $orden->refresh()->load('detalles');
}
