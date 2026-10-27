<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Helpers de fixtures (autosuficientes, no dependen de datos de esta BD)
|--------------------------------------------------------------------------
*/

function crearAdminConRol(string $nombreRol, string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function tipoTelaAttribute(): Attribute
{
    return Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
}

function crearRolloDisponible(int $proveedorId, int $tipoTelaId, int $referenciaId): Rollo
{
    $sufijo = uniqid();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-'.$sufijo,
        'proveedor_id' => $proveedorId,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [
            ['codigo' => 'R-QA-'.$sufijo, 'tipo_tela_id' => $tipoTelaId, 'referencia_tela_id' => $referenciaId, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000],
        ],
    ]);

    return $compra->rollos()->first();
}

function payloadOrden(int $rolloId, int $cortadorId, int $productId, int $cantidadProyectada): array
{
    return [
        'rollo_ids' => [$rolloId],
        'cortador_id' => $cortadorId,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => 'Orden QA permisos',
        'detalles' => [
            ['product_id' => $productId, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => $cantidadProyectada],
        ],
    ];
}

function crearOrdenReportada(int $rolloId, int $cortadorId, int $productId, int $cantidadReportada): OrdenCorte
{
    // Se crea y transiciona con un admin neutral (permission_type=all) para no interferir
    // con la persona que el test quiere evaluar en la accion de recepcion.
    Auth::guard('admin')->setUser(crearAdminConRol('Creador QA '.uniqid(), 'all'));

    $ordenCorteService = app(OrdenCorteService::class);

    $orden = $ordenCorteService->create(payloadOrden($rolloId, $cortadorId, $productId, $cantidadReportada));
    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    $detalle = $orden->detalles->first();

    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => [
            ['detalle_id' => $detalle->id, 'cantidad_real_cortada' => $cantidadReportada],
        ],
    ]);

    return $orden;
}

/**
 * F0: identidad por orden_corte_detalle_id (no product_id/talla) -- se
 * deriva de $orden->detalles, no se recibe como parametro.
 */
function payloadRecepcion(OrdenCorte $orden, int $cantidadRecibida): array
{
    $detalle = $orden->detalles->first();

    return [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega QA',
        'observacion' => null,
        'detalles' => [
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => $cantidadRecibida, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ],
    ];
}

beforeEach(function () {
    $option = AttributeOption::factory()->for(tipoTelaAttribute(), 'attribute')->create(['admin_name' => 'Tela QA '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA Permisos '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [
            ['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-REF', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $this->tipoTelaId = $option->id;
    $this->proveedorId = $proveedor->id;
    $this->referenciaId = $proveedor->referenciasTela()->first()->id;
    $this->product = Product::factory()->simple()->create();
    $this->cortadorAsignado = crearAdminConRol('CORTADOR');
});

dataset('personas_autorizadas_solicitud_y_recepcion', [
    'Admin (permission_type=all)' => ['Administrador QA', 'all'],
    'BODEGA' => ['BODEGA', 'custom'],
]);

/*
|--------------------------------------------------------------------------
| Accion critica 1: crear la SOLICITUD de corte (Card 1). Solo Admin/BODEGA;
| el Cortador queda excluido a proposito (el solicita, no crea la solicitud).
|--------------------------------------------------------------------------
*/

test('crear la solicitud de corte es permitido para Admin y BODEGA', function (string $nombreRol, string $permissionType) {
    $persona = crearAdminConRol($nombreRol, $permissionType);
    $this->actingAs($persona, 'admin');

    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create(
        payloadOrden($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50)
    );

    expect($orden->estado)->toBe(EstadoOrdenCorte::PendienteEntrega->value)
        ->and((int) $orden->detalles->sum('cantidad_proyectada'))->toBe(50)
        ->and((int) $orden->detalles->sum('cantidad_real_cortada'))->toBe(0)
        ->and($rollo->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
})->with('personas_autorizadas_solicitud_y_recepcion');

test('crear la solicitud de corte es rechazado para el CORTADOR', function () {
    $persona = crearAdminConRol('CORTADOR', 'custom');
    $this->actingAs($persona, 'admin');

    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    expect(fn () => app(OrdenCorteService::class)->create(
        payloadOrden($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50)
    ))->toThrow(ValidationException::class);

    // El rollo no quedo reservado: la operacion se rechazo antes de tocar datos.
    expect($rollo->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
});

test('crear la solicitud de corte es rechazado para un usuario sin permisos', function () {
    $persona = crearAdminConRol('ASESOR VENTAS', 'custom');
    $this->actingAs($persona, 'admin');

    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    expect(fn () => app(OrdenCorteService::class)->create(
        payloadOrden($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50)
    ))->toThrow(ValidationException::class);

    // El rollo no quedo reservado: la operacion se rechazo antes de tocar datos.
    expect($rollo->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
});

/*
|--------------------------------------------------------------------------
| Accion critica 2: "Confirmar recepcion" de bodega. Mas estricto que
| CantidadCortadaGate::userCanEdit(): solo Admin/BODEGA, el Cortador queda
| excluido aunque pueda reportar su propio resultado real.
|--------------------------------------------------------------------------
*/

test('confirmar la recepcion (con diferencia frente a lo reportado) es permitido para Admin y BODEGA', function (string $nombreRol, string $permissionType) {
    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = crearOrdenReportada($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);

    $persona = crearAdminConRol($nombreRol, $permissionType);
    $this->actingAs($persona, 'admin');

    $recepcion = app(RecepcionCorteService::class)->create($orden, payloadRecepcion($orden, 45));

    expect((int) $recepcion->detalles->sum('cantidad_recibida'))->toBe(45)
        ->and((int) $orden->fresh()->detalles->sum('cantidad_real_cortada'))->toBe(50);
})->with('personas_autorizadas_solicitud_y_recepcion');

test('confirmar la recepcion es rechazado para el CORTADOR aunque reporto el resultado', function () {
    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = crearOrdenReportada($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);

    $this->actingAs($this->cortadorAsignado, 'admin');

    // Sin diferencia: aun asi debe rechazarse, porque confirmar recepcion
    // esta restringido a Admin/BODEGA independientemente de la cantidad.
    expect(fn () => app(RecepcionCorteService::class)->create($orden, payloadRecepcion($orden, 50)))
        ->toThrow(ValidationException::class);
});

test('confirmar la recepcion es rechazado para un usuario sin permisos, incluso sin diferencia', function () {
    $rollo = crearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = crearOrdenReportada($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);

    $persona = crearAdminConRol('ASESOR VENTAS', 'custom');
    $this->actingAs($persona, 'admin');

    expect(fn () => app(RecepcionCorteService::class)->create($orden, payloadRecepcion($orden, 50)))
        ->toThrow(ValidationException::class);
});
