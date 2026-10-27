<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use Illuminate\Support\Facades\Auth;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Regresion HOTFIX: la vista (show/view) de una Orden de Corte debe abrir
| sin error sin importar el estado, incluido "cerrado". Helpers con prefijo
| ovc_ para no chocar con los de otros archivos de test.
|--------------------------------------------------------------------------
*/

function ovc_crearAdmin(string $nombreRol, string $permissionType = 'custom'): Admin
{
    if (strtolower(trim($nombreRol)) === 'cortador') {
        $admin = Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first();

        if (! $admin) {
            $role = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => $permissionType]);
            $admin = Admin::factory()->create(['role_id' => $role->id]);
        }

        Cortador::query()->firstOrCreate(
            ['usuario_id' => $admin->id],
            ['nombre' => 'Cortador OVC '.$admin->id, 'estado' => 'activo']
        );

        return $admin;
    }

    $role = Role::factory()->create(['name' => $nombreRol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

/**
 * Crea una OrdenCorte real completa (proveedor -> compra -> rollo -> orden)
 * y la deja en el estado pedido, recorriendo siempre las transiciones reales
 * del flujo (nunca un ->update(['estado' => ...]) directo), para que la
 * vista se pruebe contra datos tan reales como los de produccion.
 */
function ovc_crearOrdenCorte(string $estadoObjetivo = EstadoOrdenCorte::Cerrado->value): OrdenCorte
{
    Auth::guard('admin')->setUser(ovc_crearAdmin('Creador QA OVC '.uniqid(), 'all'));

    $atributoTipoTela = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
    $option = AttributeOption::factory()->for($atributoTipoTela, 'attribute')->create(['admin_name' => 'Tela QA OVC '.uniqid()]);
    $color = cc_test_color_option();

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor QA OVC '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [
            ['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-OVC', 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-QA-OVC-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [
            ['codigo' => 'R-QA-OVC-'.uniqid(), 'tipo_tela_id' => $option->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => 10, 'valor_kilo' => 20000],
        ],
    ]);
    $rollo = $compra->rollos()->first();

    $cortador = ovc_crearAdmin('CORTADOR');
    $producto = Product::factory()->simple()->create();

    $ordenCorteService = app(OrdenCorteService::class);
    $orden = $ordenCorteService->create([
        'rollo_ids' => [$rollo->id],
        'cortador_id' => $cortador->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [
            ['product_id' => $producto->id, 'genero_linea' => 'Nino', 'talla' => 'U', 'cantidad_proyectada' => 20],
        ],
    ]);

    if ($estadoObjetivo === EstadoOrdenCorte::PendienteEntrega->value) {
        return $orden->fresh();
    }

    $orden = $ordenCorteService->entregar($orden);
    $orden = $ordenCorteService->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    if ($estadoObjetivo === EstadoOrdenCorte::EnCorte->value) {
        return $orden->fresh();
    }

    $detalle = $orden->detalles->first();
    Auth::guard('admin')->setUser(ovc_crearAdmin('CORTADOR'));
    $orden = $ordenCorteService->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $detalle->id, 'cantidad_real_cortada' => 20]],
    ]);

    if ($estadoObjetivo === EstadoOrdenCorte::ReportadoPorCortador->value) {
        return $orden->fresh();
    }

    Auth::guard('admin')->setUser(ovc_crearAdmin('Bodega QA OVC '.uniqid(), 'all'));
    app(RecepcionCorteService::class)->create($orden, [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => 0,
        'recibido_por' => 'Bodega QA OVC',
        'observacion' => null,
        // F0: identidad por orden_corte_detalle_id (no product_id/talla).
        'detalles' => [
            ['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0, 'observacion' => null],
        ],
    ]);

    if ($estadoObjetivo === EstadoOrdenCorte::RecibidoBodega->value) {
        return $orden->fresh();
    }

    return $ordenCorteService->cerrar($orden->fresh());
}

test('una orden de corte cerrada abre su vista con HTTP 200', function () {
    $orden = ovc_crearOrdenCorte(EstadoOrdenCorte::Cerrado->value);
    $admin = ovc_crearAdmin('Admin QA OVC Viewer', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
});

test('una orden de corte cerrada muestra sus datos historicos principales', function () {
    $orden = ovc_crearOrdenCorte(EstadoOrdenCorte::Cerrado->value);
    $admin = ovc_crearAdmin('Admin QA OVC Historico', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee($orden->codigo, false);
    $response->assertSee('Cerrado', false);
    $response->assertSee('Solicitud de corte', false);
    $response->assertSee('Resultado real del corte', false);
    $response->assertSee('Recepcion / validacion de Bodega', false);
    $response->assertSee('Bodega QA OVC', false);
});

test('una orden de corte cerrada no muestra acciones operativas invalidas para ese estado', function () {
    $orden = ovc_crearOrdenCorte(EstadoOrdenCorte::Cerrado->value);
    $admin = ovc_crearAdmin('Admin QA OVC Acciones', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertDontSee('Entregar rollos', false);
    $response->assertDontSee('Marcar en corte', false);
    $response->assertDontSee('>Cerrar<', false);
    $response->assertDontSee('>Anular<', false);
});

test('una orden de corte en estado operativo (recibido_bodega) sigue abriendo correctamente', function () {
    $orden = ovc_crearOrdenCorte(EstadoOrdenCorte::RecibidoBodega->value);
    $admin = ovc_crearAdmin('Admin QA OVC Operativa', 'all');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $response->assertOk();
    $response->assertSee($orden->codigo, false);
    $response->assertSee('>Cerrar<', false);
});
