<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorteDetalle;
use ByLopez\CorteConfeccion\Models\RecepcionCorteDetalle;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Support\AgrupadorDetallesCorte;
use ByLopez\CorteConfeccion\Support\CantidadCortadaGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Helpers de fixtures (autosuficientes, prefijo f16 para no chocar con
| los nombres de funciones globales de otros archivos de test).
|--------------------------------------------------------------------------
*/

function f16CrearAdmin(string $nombreRol, string $permissionType = 'custom'): Admin
{
    if (strtolower(trim($nombreRol)) === 'cortador') {
        $admin = Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first();

        if (! $admin) {
            $role = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => $permissionType]);
            $admin = Admin::factory()->create(['role_id' => $role->id]);
        }

        Cortador::query()->firstOrCreate(
            ['usuario_id' => $admin->id],
            ['nombre' => 'Cortador F16 '.$admin->id, 'estado' => 'activo']
        );

        return $admin;
    }

    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f16TipoTelaAttribute(): Attribute
{
    return Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
}

function f16CrearRolloDisponible(int $proveedorId, int $tipoTelaId, int $referenciaId)
{
    $sufijo = uniqid();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-F16-'.$sufijo,
        'proveedor_id' => $proveedorId,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [
            ['codigo' => 'R-F16-'.$sufijo, 'tipo_tela_id' => $tipoTelaId, 'referencia_tela_id' => $referenciaId, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000],
        ],
    ]);

    return $compra->rollos()->first();
}

/**
 * Crea una solicitud de corte y la transiciona hasta "en_corte" (SIN reportar
 * resultado), actuando como un admin neutral (permission_type=all).
 */
function f16CrearSolicitudEnCorte(int $rolloId, int $cortadorId, int $productId, int $cantidadProyectada): OrdenCorte
{
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $ordenCorteService = app(OrdenCorteService::class);

    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rolloId],
        'cortador_id' => $cortadorId,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $productId, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => $cantidadProyectada],
        ],
    ]);

    $orden = $ordenCorteService->entregar($orden);

    return $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
}

beforeEach(function () {
    $option = AttributeOption::factory()->for(f16TipoTelaAttribute(), 'attribute')->create(['admin_name' => 'Tela F16 '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA F16 '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [
            ['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'F16-REF', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $this->tipoTelaId = $option->id;
    $this->proveedorId = $proveedor->id;
    $this->referenciaId = $proveedor->referenciasTela()->first()->id;
    $this->product = Product::factory()->simple()->create();
    $this->cortadorAsignado = f16CrearAdmin('CORTADOR');
});

/*
|--------------------------------------------------------------------------
| reportarResultado(): resultado real del cortador + adicionales
|--------------------------------------------------------------------------
*/

test('reportarResultado registra la cantidad real y mueve la orden a reportado_por_cortador', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    $cortador = f16CrearAdmin('CORTADOR');
    Auth::guard('admin')->setUser($cortador);

    $orden = app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [
            ['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 48],
        ],
    ]);

    expect($orden->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($orden->reportado_por_id)->toBe($cortador->id)
        ->and($orden->reportado_en)->not->toBeNull()
        ->and((int) $orden->detalles->firstWhere('id', $detalle->id)->cantidad_real_cortada)->toBe(48)
        // La cantidad SOLICITADA nunca la toca reportarResultado.
        ->and((int) $orden->detalles->firstWhere('id', $detalle->id)->cantidad_proyectada)->toBe(50);
});

test('reportarResultado rechaza un detalle_id que no pertenece a la orden', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));

    expect(fn () => app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [
            ['detalle_id' => 999999999, 'cantidad_real_cortada' => 10],
        ],
    ]))->toThrow(ValidationException::class);
});

test('reportarResultado respeta la maquina de estados y rechaza una orden que aun no esta en_corte', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $detalle = $orden->detalles->first();

    // La orden sigue en "pendiente_entrega": aun no se puede reportar resultado.
    expect(fn () => app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [
            ['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 10],
        ],
    ]))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Confirmar recepcion (HTTP): diferencia exige observacion
|--------------------------------------------------------------------------
*/

/**
 * Admin con el permiso ACL explicito 'corte_confeccion.recepciones_corte',
 * necesario para pasar StoreRecepcionCorteRequest::authorize() via HTTP
 * (a diferencia de llamar el Service directamente, que no pasa por el FormRequest).
 */
function f16CrearBodegaConPermisoRecepcion(): Admin
{
    $role = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion.recepciones_corte'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

/**
 * F0: la identidad de cada fila es orden_corte_detalle_id (no product_id/talla
 * -- esos se derivan en el Service desde el propio OrdenCorteDetalle, nunca
 * se leen del payload). Ver StoreRecepcionCorteRequest y RecepcionCorteService.
 */
function f16PayloadRecepcion(int $ordenCorteDetalleId, int $cantidadRecibida, ?string $observacion = null): array
{
    return [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega QA F16',
        'observacion' => null,
        'detalles' => [
            ['orden_corte_detalle_id' => $ordenCorteDetalleId, 'cantidad_recibida' => $cantidadRecibida, 'cantidad_defectuosa' => 0, 'observacion' => $observacion],
        ],
    ];
}

test('confirmar recepcion sin diferencia no exige observacion', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 50)
    );

    $response->assertSessionDoesntHaveErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value);
});

test('confirmar recepcion con diferencia sin observacion es rechazada', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 45)
    );

    $response->assertSessionHasErrors(['detalles.0.observacion']);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);
});

test('confirmar recepcion con diferencia y observacion es aceptada', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 45, 'Faltante por merma en bodega')
    );

    $response->assertSessionDoesntHaveErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value);
});

/*
|--------------------------------------------------------------------------
| Historico: las rutas de "Recepciones de corte" siguen accesibles por
| URL directa aunque ya no aparezcan en el menu.
|--------------------------------------------------------------------------
*/

test('las rutas historicas de recepciones_corte siguen resolviendo y respondiendo', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    Auth::guard('admin')->setUser(f16CrearAdmin('BODEGA'));
    $recepcion = app(RecepcionCorteService::class)->create(
        $orden->fresh(),
        f16PayloadRecepcion($detalle->id, 50)
    );

    $admin = f16CrearAdmin('Admin F16', 'all');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.recepciones_corte.index'))
        ->assertOk();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.recepciones_corte.show', $recepcion->id))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| AgrupadorDetallesCorte: agrupa por producto + genero/linea para las
| 3 cards (solicitud, resultado, adicionales) sin duplicar logica en Blade.
|--------------------------------------------------------------------------
*/

test('AgrupadorDetallesCorte agrupa varias tallas del mismo producto y suma totales', function () {
    $detalles = collect([
        new OrdenCorteDetalle(['product_id' => 1, 'producto_nombre' => 'Camiseta', 'producto_sku' => 'CAM-1', 'genero_linea' => 'Nino', 'talla' => '6', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 9]),
        new OrdenCorteDetalle(['product_id' => 1, 'producto_nombre' => 'Camiseta', 'producto_sku' => 'CAM-1', 'genero_linea' => 'Nino', 'talla' => '8', 'cantidad_proyectada' => 15, 'cantidad_real_cortada' => 14]),
        new OrdenCorteDetalle(['product_id' => 2, 'producto_nombre' => 'Pantalon', 'producto_sku' => 'PAN-1', 'genero_linea' => 'Nina', 'talla' => 'U', 'cantidad_proyectada' => 5, 'cantidad_real_cortada' => 5]),
    ]);

    $grupos = AgrupadorDetallesCorte::agrupar($detalles);

    $grupoCamiseta = $grupos->firstWhere('producto_nombre', 'Camiseta');

    expect($grupos)->toHaveCount(2)
        ->and($grupoCamiseta['cantidad_tallas'])->toBe(2)
        ->and($grupoCamiseta['total_proyectado'])->toBe(25)
        ->and($grupoCamiseta['total_real'])->toBe(23);
});

test('AgrupadorDetallesCorte preserva las claves originales de la coleccion en cada grupo', function () {
    // Relevante para card-recepcion.blade.php: el formulario genera
    // name="detalles[$i][...]" usando la clave de cada fila dentro de
    // $grupo['filas']. Desde F0 ese $i ya NO es la identidad real de la fila
    // (RecepcionCorteService::create() identifica cada una por
    // orden_corte_detalle_id, nunca por posicion) -- pero preservar la clave
    // original sigue siendo necesario para que old("detalles.$i....") y los
    // <x-admin::form.control-group.error> resuelvan al campo correcto cuando
    // la validacion falla y la pagina se vuelve a renderizar.
    $detalles = collect([
        new OrdenCorteDetalle(['product_id' => 1, 'producto_nombre' => 'Camiseta', 'producto_sku' => 'CAM-1', 'genero_linea' => 'Nino', 'talla' => '6', 'cantidad_proyectada' => 10, 'cantidad_real_cortada' => 9]),
        new OrdenCorteDetalle(['product_id' => 2, 'producto_nombre' => 'Pantalon', 'producto_sku' => 'PAN-1', 'genero_linea' => 'Nina', 'talla' => 'U', 'cantidad_proyectada' => 5, 'cantidad_real_cortada' => 5]),
        new OrdenCorteDetalle(['product_id' => 1, 'producto_nombre' => 'Camiseta', 'producto_sku' => 'CAM-1', 'genero_linea' => 'Nino', 'talla' => '8', 'cantidad_proyectada' => 15, 'cantidad_real_cortada' => 14]),
    ]);

    $grupos = AgrupadorDetallesCorte::agrupar($detalles);
    $grupoCamiseta = $grupos->firstWhere('producto_nombre', 'Camiseta');

    // Las dos filas de Camiseta estaban en las posiciones 0 y 2 de la coleccion original.
    expect($grupoCamiseta['filas']->keys()->all())->toBe([0, 2]);
});

/*
|--------------------------------------------------------------------------
| Microcopy accionable cuando la orden aun no esta en_corte: quien la ve
| (admin o cortador) debe saber exactamente que boton falta pulsar, en vez
| de un generico "espera a que este en corte".
|--------------------------------------------------------------------------
*/

test('la vista de la orden indica que falta entregar peso y marcar en corte cuando esta pendiente_entrega', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Viewer', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee('Faltan 2 pasos', false);
    $response->assertSee('Entregar rollos', false);
    $response->assertSee('Marcar en corte', false);
});

test('la vista de la orden indica que falta marcar en corte cuando ya esta entregado_a_cortador', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);
    $orden = $ordenCorteService->entregar($orden);

    $this->cortadorAsignado->role->update([
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.ordenes_corte.operar'],
    ]);

    $response = $this->actingAs($this->cortadorAsignado, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee('Ya se entrego el peso', false);
    $response->assertDontSee('entregar el peso', false);
});

test('el banner de proximo paso indica marcar en corte cuando la orden esta entregado_a_cortador', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);
    $orden = $ordenCorteService->entregar($orden);

    $admin = f16CrearAdmin('Admin F16 Banner', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee('Proximo paso: haz clic en &quot;Marcar en corte&quot;', false);
});

test('la vista de la orden carga la hoja de estilos propia del paquete', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Estilos', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee('themes/admin/default/build-bylopez/corteconfeccion.css', false);
});

test('el formulario de recepcion muestra cantidades fisicas como cards de producto, no como tabla con columnas sueltas', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.recepciones_corte'],
    ]);
    $bodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    $response = $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    // Cada fila muestra SKU y Talla junto a los datos, no en un encabezado de tabla separado.
    $response->assertSee('SKU:', false);
    $response->assertSee('Talla:', false);
    $response->assertSee('(requerida solo si hay diferencia)', false);
    // Ya no existe la tabla anterior con encabezados sueltos de columna.
    $response->assertDontSee('<th class="px-3 py-2">Producto</th>', false);
});

test('los encabezados de accordion con nombres de producto largos no fuerzan overflow horizontal', function () {
    // Un span de texto dentro de un flex "justify-between" no se encoge por debajo de
    // su ancho natural salvo que tenga min-w-0, lo que puede empujar toda la pagina
    // mas ancha que la pantalla con nombres de producto largos. min-w-0 + flex-1 en el
    // texto y shrink-0 en el badge de la derecha evitan ese overflow.
    $productoNombreLargo = 'Camiseta Piel Durazno Verde Cali Hombre Talla Extra Grande XXL';

    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Hombre', 'talla' => 'XXL', 'cantidad_proyectada' => 50],
        ],
    ]);

    // producto_nombre es un snapshot guardado en el detalle: se sobreescribe directo
    // para simular un nombre de producto largo sin depender del catalogo/atributos de Bagisto.
    $orden->detalles->first()->update(['producto_nombre' => $productoNombreLargo]);

    $admin = f16CrearAdmin('Admin F16 Overflow', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee($productoNombreLargo, false);
    $response->assertSee('min-w-0 flex-1 break-words text-base font-semibold text-gray-900', false);
});

/*
|--------------------------------------------------------------------------
| Entregar rollos: ya no es un campo que el usuario escribe, se calcula
| automaticamente a partir de los rollos reservados en la orden.
|--------------------------------------------------------------------------
*/

test('la vista de la orden muestra la modal de entrega con el peso calculado y no un campo editable', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Entrega', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertDontSee('name="peso_entregado"', false);
    $response->assertSee('Confirmar entrega de rollos', false);
    $response->assertSee('Peso a entregar', false);
    $response->assertSee('Total peso reservado / entregado', false);
    $response->assertSee(number_format((float) $rollo->peso_disponible, 3, ',', '.').' kg', false);
});

test('la pantalla independiente get de entrega ya no esta disponible', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Entrega GET', 'all');

    expect(Route::has('admin.corte_confeccion.ordenes_corte.entregar_form'))->toBeFalse();

    $this->actingAs($admin, 'admin')
        ->get('/'.config('app.admin_url').'/corte-confeccion/ordenes-corte/entregar/'.$orden->id)
        ->assertNotFound();
});

test('entregar peso usa automaticamente el peso reservado de los rollos, sin recibir un valor manual', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Entrega Post', 'all');

    // El POST no incluye peso_entregado: el sistema debe calcularlo solo.
    $response = $this->actingAs($admin, 'admin')->post(
        route('admin.corte_confeccion.ordenes_corte.entregar', $orden->id),
        []
    );

    $response->assertSessionDoesntHaveErrors();

    $orden->refresh();

    expect($orden->estado)->toBe(EstadoOrdenCorte::EntregadoACortador->value)
        ->and((float) $orden->peso_entregado)->toBe(round((float) $rollo->peso_disponible, 3));
});

/*
|--------------------------------------------------------------------------
| CSS propio de ByLopez aislado bajo .bylopez-scope: sin esto, utilidades
| genericas propias (ej. .px-2) cargan despues del bundle de Webkul y le
| ganan en cascada a clases responsive suyas como lg:pl-[286px] (el padding
| que reserva el espacio del sidebar fijo), causando que el sidebar tape el
| contenido. Verificado en vivo con un navegador headless: antes de este
| fix el padding-left computado era 8px; con el fix, 286px.
|--------------------------------------------------------------------------
*/

test('el CSS propio de ByLopez esta escopado bajo .bylopez-scope para no pisar el layout de Webkul', function () {
    $cssPath = public_path('themes/admin/default/build-bylopez/corteconfeccion.css');

    expect(file_exists($cssPath))->toBeTrue('El CSS de ByLopez debe existir (ejecutar npm run build en packages/ByLopez/CorteConfeccion).');

    $css = file_get_contents($cssPath);

    // Toda ocurrencia de la regla ".px-2{" debe venir precedida por el selector
    // de ambito: si el conteo no coincide, hay al menos una regla suelta (sin
    // ".bylopez-scope ") que podria pisar clases responsive del layout de Webkul
    // (ej. lg:pl-[286px], el padding que reserva el espacio del sidebar fijo).
    $totalOcurrencias = substr_count($css, '.px-2{');
    $ocurrenciasEscopadas = substr_count($css, '.bylopez-scope .px-2{');

    expect($totalOcurrencias)->toBeGreaterThan(0)
        ->and($ocurrenciasEscopadas)->toBe($totalOcurrencias);
});

test('las vistas de orden de corte envuelven su contenido en el wrapper .bylopez-scope', function () {
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 50],
        ],
    ]);

    $admin = f16CrearAdmin('Admin F16 Scope', 'all');

    $show = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));
    $show->assertOk();
    $show->assertSee('class="bylopez-scope"', false);

    $create = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.create'));
    $create->assertOk();
    $create->assertSee('class="bylopez-scope"', false);

    expect(Route::has('admin.corte_confeccion.ordenes_corte.entregar_form'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Bug real reportado: los detalles de una orden de corte pueden tener
| talla = '' (muchos productos de este catalogo codifican la talla en el
| SKU). La regla "required" en detalles.*.talla hacia fallar SIEMPRE la
| validacion de "Confirmar recepcion" para esas ordenes, sin que el error
| se mostrara en ningun lado (parecia que el boton "no hacia nada").
|--------------------------------------------------------------------------
*/

test('confirmar recepcion funciona cuando la talla del detalle esta vacia', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);

    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));
    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $this->cortadorAsignado->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        // Talla en blanco a proposito: reproduce el dato real que causaba el bug.
        'detalles' => [
            ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => '', 'cantidad_proyectada' => 50],
        ],
    ]);
    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    $detalle = $orden->detalles->first();
    expect($detalle->talla)->toBe('');

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 50)
    );

    $response->assertSessionDoesntHaveErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value);
});

test('cuando falla la validacion de recepcion, el formulario muestra un resumen de errores visible', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $roleBodega = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.recepciones_corte'],
    ]);
    $bodega = Admin::factory()->create(['role_id' => $roleBodega->id]);

    // Diferencia (45 vs 50 reportado) sin observacion: debe fallar y el error
    // debe ser visible en la respuesta, no silencioso.
    $response = $this->actingAs($bodega, 'admin')->from(
        route('admin.corte_confeccion.ordenes_corte.show', $orden->id)
    )->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 45)
    );

    $response->assertSessionHasErrors();

    $followUp = $this->actingAs($bodega, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));
    $followUp->assertOk();
    $followUp->assertSee('No se pudo confirmar la recepcion', false);
});

test('usuariosConfirmarRecepcion solo incluye usuarios con rol BODEGA o Administrador', function () {
    $roleBodega = Role::factory()->create(['name' => 'BODEGA', 'permission_type' => 'custom']);
    $roleCortador = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => 'custom']);
    $roleAdmin = Role::factory()->create(['name' => 'Administrador', 'permission_type' => 'all']);

    $bodegaUser = Admin::factory()->create(['role_id' => $roleBodega->id, 'name' => 'Usuario Bodega QA', 'status' => 1]);
    $cortadorUser = Admin::factory()->create(['role_id' => $roleCortador->id, 'name' => 'Usuario Cortador QA', 'status' => 1]);
    $adminUser = Admin::factory()->create(['role_id' => $roleAdmin->id, 'name' => 'Usuario Admin QA', 'status' => 1]);

    $nombres = CantidadCortadaGate::usuariosConfirmarRecepcion()->pluck('name');

    expect($nombres)->toContain('Usuario Bodega QA')
        ->toContain('Usuario Admin QA')
        ->not->toContain('Usuario Cortador QA');
});

test('el selector Recibido por muestra solo usuarios BODEGA/Administrador en el formulario de recepcion', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $roleBodegaVisible = Role::factory()->create(['name' => 'BODEGA', 'permission_type' => 'custom']);
    $bodegaVisible = Admin::factory()->create(['role_id' => $roleBodegaVisible->id, 'name' => 'Bodega Visible QA', 'status' => 1]);

    $roleCortadorNoVisible = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => 'custom']);
    Admin::factory()->create(['role_id' => $roleCortadorNoVisible->id, 'name' => 'Cortador No Visible QA', 'status' => 1]);

    $roleBodegaViewer = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte', 'corte_confeccion.recepciones_corte'],
    ]);
    $viewer = Admin::factory()->create(['role_id' => $roleBodegaViewer->id]);

    $response = $this->actingAs($viewer, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee('Bodega Visible QA', false);
    $response->assertDontSee('Cortador No Visible QA', false);
});

/*
|--------------------------------------------------------------------------
| F0 - Recepcion por identidad de OrdenCorteDetalle
| (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2 y §17)
|
| El bug original: la recepcion comparaba "reportado por el cortador" vs
| "recibido por bodega" por POSICION dentro del array (indice del POST,
| orden de una lista SQL ordenada por id, orden de la coleccion Eloquent),
| en vez de por la identidad real de cada fila. Desde F0, cada fila del
| formulario de recepcion identifica su OrdenCorteDetalle explicitamente
| via orden_corte_detalle_id, y producto/SKU/talla/cantidad reportada se
| derivan SIEMPRE de ese detalle en el servidor -- nunca se leen del
| navegador.
|--------------------------------------------------------------------------
*/

/**
 * Crea una solicitud de corte con VARIOS detalles (a diferencia de
 * f16CrearSolicitudEnCorte, que solo acepta uno) y la transiciona hasta
 * "en_corte", igual que su contraparte de un solo detalle.
 */
function f16CrearSolicitudEnCorteMultiDetalle(int $rolloId, int $cortadorId, array $detalles): OrdenCorte
{
    Auth::guard('admin')->setUser(f16CrearAdmin('Creador QA F16 '.uniqid(), 'all'));

    $ordenCorteService = app(OrdenCorteService::class);

    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rolloId],
        'cortador_id' => $cortadorId,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => $detalles,
    ]);

    $orden = $ordenCorteService->entregar($orden);

    return $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
}

/**
 * Variante de f16PayloadRecepcion() para enviar varias filas explicitas, en
 * el orden exacto que el test necesite (para poder reproducir un POST
 * "fuera de orden" respecto al id de los detalles).
 */
function f16PayloadRecepcionMulti(array $filas): array
{
    return [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega QA F16',
        'observacion' => null,
        'detalles' => $filas,
    ];
}

test('CASO 1 - la recepcion compara por orden_corte_detalle_id aunque el POST llegue en un orden distinto al de los detalles', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $productoB = Product::factory()->simple()->create();

    $orden = f16CrearSolicitudEnCorteMultiDetalle($rollo->id, $this->cortadorAsignado->id, [
        ['product_id' => $this->product->id, 'genero_linea' => 'Nino', 'talla' => 'M', 'cantidad_proyectada' => 50],
        ['product_id' => $productoB->id, 'genero_linea' => 'Nino', 'talla' => 'L', 'cantidad_proyectada' => 30],
    ]);

    $detalleA = $orden->detalles->firstWhere('product_id', $this->product->id);
    $detalleB = $orden->detalles->firstWhere('product_id', $productoB->id);

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [
            ['detalle_id' => $detalleA->id, 'cantidad_real_cortada' => 30],
            ['detalle_id' => $detalleB->id, 'cantidad_real_cortada' => 20],
        ],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    // El POST llega en el orden CONTRARIO al id de los detalles (B primero,
    // A despues), con cantidades que coinciden EXACTAMENTE con lo reportado
    // para cada uno -- no deberia haber ninguna diferencia. Bajo el bug
    // original (comparacion por posicion contra una lista ordenada por id),
    // el indice 0 del POST (fila de B) se habria comparado contra el
    // reportado de A (30, no 20), generando una "diferencia" falsa que exige
    // observacion aunque no exista ninguna diferencia real.
    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcionMulti([
            ['orden_corte_detalle_id' => $detalleB->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'observacion' => null],
            ['orden_corte_detalle_id' => $detalleA->id, 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ])
    );

    $response->assertSessionDoesntHaveErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value);

    $recepcion = $orden->fresh()->recepcion;
    $filaA = $recepcion->detalles->firstWhere('orden_corte_detalle_id', $detalleA->id);
    $filaB = $recepcion->detalles->firstWhere('orden_corte_detalle_id', $detalleB->id);

    expect((int) $filaA->cantidad_recibida)->toBe(30)
        ->and((int) $filaA->product_id)->toBe($this->product->id)
        ->and((int) $filaB->cantidad_recibida)->toBe(20)
        ->and((int) $filaB->product_id)->toBe($productoB->id);
});

test('CASO 3 - enviar un orden_corte_detalle_id de otra orden es rechazado por HTTP', function () {
    $rolloA = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $ordenA = f16CrearSolicitudEnCorte($rolloA->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalleA = $ordenA->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($ordenA, [
        'resultados' => [['detalle_id' => $detalleA->id, 'cantidad_real_cortada' => 50]],
    ]);

    // Segunda orden, completamente independiente, con su propio detalle.
    $productoB = Product::factory()->simple()->create();
    $rolloB = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $ordenB = f16CrearSolicitudEnCorte($rolloB->id, $this->cortadorAsignado->id, $productoB->id, 20);
    $detalleB = $ordenB->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($ordenB, [
        'resultados' => [['detalle_id' => $detalleB->id, 'cantidad_real_cortada' => 20]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    // Se intenta recibir ordenA pero enviando el detalle_id de ordenB.
    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $ordenA->id),
        f16PayloadRecepcion($detalleB->id, 20)
    );

    $response->assertSessionHasErrors(['detalles.0.orden_corte_detalle_id']);
    expect($ordenA->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($ordenA->fresh()->recepcion)->toBeNull();
});

test('CASO 3b - RecepcionCorteService::create() tambien rechaza un detalle de otra orden al llamarse directamente, sin pasar por el FormRequest', function () {
    $rolloA = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $ordenA = f16CrearSolicitudEnCorte($rolloA->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalleA = $ordenA->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($ordenA, [
        'resultados' => [['detalle_id' => $detalleA->id, 'cantidad_real_cortada' => 50]],
    ]);

    $productoB = Product::factory()->simple()->create();
    $rolloB = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $ordenB = f16CrearSolicitudEnCorte($rolloB->id, $this->cortadorAsignado->id, $productoB->id, 20);
    $detalleB = $ordenB->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('BODEGA'));

    expect(fn () => app(RecepcionCorteService::class)->create(
        $ordenA->fresh(),
        f16PayloadRecepcion($detalleB->id, 20)
    ))->toThrow(ValidationException::class);

    expect($ordenA->fresh()->recepcion)->toBeNull();
});

test('CASO 4 - enviar dos veces el mismo orden_corte_detalle_id es rechazado por HTTP', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcionMulti([
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0, 'observacion' => null],
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ])
    );

    $response->assertSessionHasErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($orden->fresh()->recepcion)->toBeNull();
});

test('CASO 4b - RecepcionCorteService::create() tambien rechaza un orden_corte_detalle_id duplicado dentro del mismo envio', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    Auth::guard('admin')->setUser(f16CrearAdmin('BODEGA'));

    expect(fn () => app(RecepcionCorteService::class)->create(
        $orden->fresh(),
        f16PayloadRecepcionMulti([
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0, 'observacion' => null],
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ])
    ))->toThrow(ValidationException::class);

    expect($orden->fresh()->recepcion)->toBeNull();
});

test('CASO 5 - manipular product_id en el payload no cambia el producto real de la fila recibida', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $productoAjeno = Product::factory()->simple()->create();
    $bodega = f16CrearBodegaConPermisoRecepcion();

    $payload = f16PayloadRecepcion($detalle->id, 50);
    // product_id manipulado a proposito: desde F0 no forma parte del
    // contrato del payload y no debe leerse ni usarse en ningun punto (el
    // producto se deriva SIEMPRE del OrdenCorteDetalle real).
    $payload['detalles'][0]['product_id'] = $productoAjeno->id;

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        $payload
    );

    $response->assertSessionDoesntHaveErrors();

    $filaRecepcion = $orden->fresh()->recepcion->detalles->first();

    expect((int) $filaRecepcion->product_id)->toBe($this->product->id)
        ->and((int) $filaRecepcion->product_id)->not->toBe($productoAjeno->id);
});

test('CASO 6 - diferencia entre cantidad reportada y recibida mantiene las reglas actuales de faltante/autorizacion/observacion', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 30);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 25]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    // Sin observacion: rechazada, la orden no avanza de estado.
    $sinObservacion = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 24)
    );
    $sinObservacion->assertSessionHasErrors(['detalles.0.observacion']);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);

    // Con observacion: aceptada, la diferencia (24 recibido vs 25 reportado) queda registrada.
    $conObservacion = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcion($detalle->id, 24, 'Falto 1 unidad, retazo insuficiente')
    );
    $conObservacion->assertSessionDoesntHaveErrors();
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value);

    $fila = $orden->fresh()->recepcion->detalles->first();
    expect((int) $fila->cantidad_recibida)->toBe(24);
});

test('CASO 7 - cantidad_defectuosa mayor que cantidad_recibida sigue siendo rechazada', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        f16PayloadRecepcionMulti([
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 51, 'observacion' => null],
        ])
    );

    $response->assertSessionHasErrors(['detalles.0.cantidad_defectuosa']);
    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);
});

test('CASO 8 - una recepcion historica con orden_corte_detalle_id NULL sigue siendo legible en la vista de la orden', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    Auth::guard('admin')->setUser(f16CrearAdmin('BODEGA'));
    $recepcion = app(RecepcionCorteService::class)->create(
        $orden->fresh(),
        f16PayloadRecepcion($detalle->id, 50)
    );

    // Simula una recepcion creada ANTES de F0: se limpia orden_corte_detalle_id
    // a proposito (no se reescribe historico real; esto solo recrea el
    // estado que ya tienen las filas anteriores a esta fase).
    DB::table('bylopez_cc_recepciones_corte_detalles')
        ->where('recepcion_corte_id', $recepcion->id)
        ->update(['orden_corte_detalle_id' => null]);

    $admin = f16CrearAdmin('Admin F16 Historico', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    // La pagina no debe romperse (ni 500 ni excepcion) por el fallback de
    // match por product_id+talla, y debe seguir mostrando la cantidad
    // reportada correcta via ese fallback.
    $response->assertOk();
    $response->assertSee('Reportado', false);
});

test('CASO 10 - Bodega no puede crear un producto adicional durante la recepcion', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);
    $detalle = $orden->detalles->first();

    Auth::guard('admin')->setUser(f16CrearAdmin('CORTADOR'));
    app(OrdenCorteService::class)->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 50]],
    ]);

    $productoNuevo = Product::factory()->simple()->create();
    $bodega = f16CrearBodegaConPermisoRecepcion();

    // Fila "fantasma": sin orden_corte_detalle_id, como si Bodega intentara
    // agregar un producto nuevo directamente en la recepcion -- exactamente
    // lo que si permitia la pantalla retirada recepciones-corte/create.blade.php.
    $payload = f16PayloadRecepcionMulti([
        ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ['product_id' => $productoNuevo->id, 'talla' => 'L', 'cantidad_recibida' => 5, 'cantidad_defectuosa' => 0, 'observacion' => null],
    ]);

    $response = $this->actingAs($bodega, 'admin')->post(
        route('admin.corte_confeccion.recepciones_corte.store', $orden->id),
        $payload
    );

    $response->assertSessionHasErrors(['detalles.1.orden_corte_detalle_id']);
    expect($orden->fresh()->recepcion)->toBeNull()
        ->and(OrdenCorteDetalle::where('product_id', $productoNuevo->id)->exists())->toBeFalse()
        ->and(RecepcionCorteDetalle::where('product_id', $productoNuevo->id)->exists())->toBeFalse();
});

test('la pantalla independiente de creacion de recepcion ahora redirige a la orden de corte en vez de permitir seleccion manual de producto', function () {
    $rollo = f16CrearRolloDisponible($this->proveedorId, $this->tipoTelaId, $this->referenciaId);
    $orden = f16CrearSolicitudEnCorte($rollo->id, $this->cortadorAsignado->id, $this->product->id, 50);

    $bodega = f16CrearBodegaConPermisoRecepcion();

    $response = $this->actingAs($bodega, 'admin')->get(
        route('admin.corte_confeccion.recepciones_corte.create', $orden->id)
    );

    $response->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));
});
