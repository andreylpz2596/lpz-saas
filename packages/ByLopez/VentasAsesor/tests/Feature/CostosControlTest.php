<?php

use ByLopez\VentasAsesor\Enums\EstadoControlCosto as Estado;
use ByLopez\VentasAsesor\Exceptions\CostoComisionableInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\CambioCostoComisionable;
use ByLopez\VentasAsesor\Models\CostoVenta;
use ByLopez\VentasAsesor\Services\Costos\ActualizarCostoComisionableService;
use ByLopez\VentasAsesor\Services\Costos\ControlCostosService;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom.php';

uses(TestCase::class);

/*
| D-COST — costo comisionable oficial, Control de costos y actualizacion masiva. Solo lpz_saas_testing.
*/

function costos_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido $e) {
        return $e->codigo;
    }

    return null;
}

/** Orden de corte RECIBIDA en bodega con un rollo y un detalle (fixture de CorteConfeccion, solo testing). */
function costos_ordenCorte(int $productId, float $kilos, float $valorKilo, int $piezas, float $sobrante = 0): void
{
    $sufijo = uniqid();
    $rollo = DB::table('bylopez_cc_rollos')->insertGetId([
        'codigo' => 'R-'.$sufijo, 'proveedor' => 'P', 'color' => 'rojo', 'tipo_tela' => 'piel', 'peso_inicial' => $kilos,
        'peso_disponible' => 0, 'valor_kilo' => $valorKilo, 'estado' => 'agotado', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $orden = DB::table('bylopez_cc_ordenes_corte')->insertGetId([
        'codigo' => 'OC-'.$sufijo, 'rollo_id' => $rollo, 'color' => 'rojo', 'genero_linea' => 'L', 'estado' => 'recibido_bodega',
        'peso_total_rollos' => $kilos, 'peso_sobrante_total' => $sobrante, 'total_piezas_cortadas' => $piezas,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bylopez_cc_orden_corte_rollos')->insert(['orden_corte_id' => $orden, 'rollo_id' => $rollo, 'peso_inicial_usado' => $kilos]);
    DB::table('bylopez_cc_ordenes_corte_detalles')->insert([
        'orden_corte_id' => $orden, 'product_id' => $productId, 'talla' => 'M', 'cantidad_proyectada' => $piezas,
        'cantidad_real_cortada' => $piezas, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('COST-1 actualizacion masiva: el pedido ANTERIOR conserva 5.000 y el POSTERIOR congela 5.200; queda bitacora del lote', function () {
    Mail::fake();
    f03f_configurarBase();
    $admin = f03b_admin();
    $cliente = f03b_cliente(f03b_grupo('K'));
    $configurable = f03b_configurable(8500.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, 20);
    com_costo($variante, 5000.0);

    $antes = com_vender($cliente->id, $admin, $configurable, $variante, 2);

    $servicio = app(ActualizarCostoComisionableService::class);
    $vista = $servicio->previsualizar($admin, [$variante->id], '5200');
    $r = $servicio->confirmar($admin, [$variante->id], '5200', $vista->token, 'Sube la tela');

    $despues = com_vender($cliente->id, $admin, $configurable, $variante, 1);
    $bitacora = CambioCostoComisionable::query()->where('lote', $r['lote'])->sole();

    expect($vista->conCambio)->toBe(1)
        ->and($r['actualizados'])->toBe(1)
        ->and((float) CostoVenta::query()->where('order_id', $antes->id)->value('costo_unitario'))->toBe(5000.0)
        ->and((float) CostoVenta::query()->where('order_id', $despues->id)->value('costo_unitario'))->toBe(5200.0)
        ->and((bool) CostoVenta::query()->where('order_id', $despues->id)->value('autoridad_comision'))->toBeTrue()
        ->and($bitacora->product_id)->toBe($variante->id)
        ->and($bitacora->sku_snapshot)->toBe($variante->sku)
        ->and((float) $bitacora->costo_anterior)->toBe(5000.0)
        ->and((float) $bitacora->costo_nuevo)->toBe(5200.0)
        ->and($bitacora->actor_admin_id)->toBe($admin->id)
        ->and($bitacora->mecanismo)->toBe('panel')
        ->and($bitacora->motivo)->toBe('Sube la tela');
});

test('COST-2 sin vista previa valida no se escribe: token falso, costo cambiado entretanto, costo 0/negativo/vacio y seleccion vacia se rechazan', function () {
    $admin = f03b_admin();
    $producto = f03f_simple(9000.0);
    com_costo($producto, 5000.0);
    $servicio = app(ActualizarCostoComisionableService::class);

    $tokenFalso = costos_error(fn () => $servicio->confirmar($admin, [$producto->id], '5200', 'inventado'));

    $vista = $servicio->previsualizar($admin, [$producto->id], '5200');
    com_costo($producto, 5100.0); // alguien lo cambia desde la ficha nativa entre la vista previa y la confirmacion
    $desactualizada = costos_error(fn () => $servicio->confirmar($admin, [$producto->id], '5200', $vista->token));

    expect($tokenFalso)->toBe(Invalido::VISTA_PREVIA_REQUERIDA)
        ->and($desactualizada)->toBe(Invalido::VISTA_PREVIA_REQUERIDA)
        ->and(costos_error(fn () => $servicio->previsualizar($admin, [$producto->id], '0')))->toBe(Invalido::COSTO_INVALIDO)
        ->and(costos_error(fn () => $servicio->previsualizar($admin, [$producto->id], '-5')))->toBe(Invalido::COSTO_INVALIDO)
        ->and(costos_error(fn () => $servicio->previsualizar($admin, [$producto->id], '')))->toBe(Invalido::COSTO_INVALIDO)
        ->and(costos_error(fn () => $servicio->previsualizar($admin, [], '5200')))->toBe(Invalido::SELECCION_VACIA)
        ->and(app(CostoComisionableCatalogo::class)->costosDe([$producto->id])[$producto->id])->toBe(5100.0)
        ->and(CambioCostoComisionable::query()->where('product_id', $producto->id)->count())->toBe(0);
});

test('COST-3 comparacion: tela parcial > comisionable = REVISAR (+1.000, +20 %); menor = PARCIAL (nunca OK); sin costo y sin produccion se distinguen', function () {
    $caro = f03f_simple(9000.0);
    $barato = f03f_simple(9000.0);
    $sinCosto = f03f_simple(9000.0);
    $sinProduccion = f03f_simple(9000.0);
    com_costo($caro, 5000.0);
    com_costo($barato, 5000.0);
    com_costo($sinCosto, null);
    com_costo($sinProduccion, 5000.0);

    costos_ordenCorte($caro->id, kilos: 10, valorKilo: 60000, piezas: 100);              // 6.000 por pieza
    costos_ordenCorte($barato->id, kilos: 11, valorKilo: 16000, piezas: 200, sobrante: 1); // 800 por pieza
    costos_ordenCorte($sinCosto->id, kilos: 10, valorKilo: 16000, piezas: 100);

    $filas = app(ControlCostosService::class)
        ->filas([], [$caro->id, $barato->id, $sinCosto->id, $sinProduccion->id])
        ->keyBy('productId');

    expect($filas[$caro->id]->estado)->toBe(Estado::REVISAR)
        ->and($filas[$caro->id]->costoProductivo)->toBe(6000.0)
        ->and($filas[$caro->id]->diferencia)->toBe(1000.0)
        ->and($filas[$caro->id]->diferenciaPorcentaje)->toBe(20.0)
        ->and($filas[$caro->id]->productivoCompleto)->toBeFalse()
        ->and($filas[$barato->id]->estado)->toBe(Estado::PARCIAL)
        ->and($filas[$barato->id]->costoProductivo)->toBe(800.0)
        ->and($filas[$sinCosto->id]->estado)->toBe(Estado::SIN_COSTO_COMISIONABLE)
        ->and($filas[$sinProduccion->id]->estado)->toBe(Estado::SIN_DATOS_PRODUCCION)
        // Solo informa: el costo comisionable no cambio.
        ->and(app(CostoComisionableCatalogo::class)->costosDe([$caro->id])[$caro->id])->toBe(5000.0);
});

test('COST-4 permisos: el administrador ve la pantalla; el asesor no ve, no previsualiza ni actualiza costos', function () {
    $admin = f03b_admin();
    $asesor = f03b_admin('custom', [
        'ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create',
        'ventas_asesor.pedidos.view_own', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own',
        'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.abono_general', 'ventas_asesor.asesores',
        'ventas_asesor.asesores.view_own', 'catalog', 'catalog.products',
    ]);
    $producto = f03f_simple(9000.0);
    com_costo($producto, 5000.0);

    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.costos.index'))
        ->assertOk()->assertSee(trans('ventasasesor::app.costos.titulo'))->assertSee('data-form="actualizar-costos"', false);

    // La vista previa se renderiza y NO escribe: el costo sigue igual hasta confirmar.
    $this->actingAs($admin, 'admin')->post(route('admin.ventas_asesor.costos.previsualizar'), ['alcance' => 'seleccion', 'ids' => [$producto->id], 'nuevo_costo' => '5200'])
        ->assertOk()->assertSee('data-form="confirmar-costos"', false)->assertSee($producto->sku);
    expect(app(CostoComisionableCatalogo::class)->costosDe([$producto->id])[$producto->id])->toBe(5000.0);

    $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.costos.index'))->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.ventas_asesor.costos.previsualizar'), ['ids' => [$producto->id], 'nuevo_costo' => '1'])->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.ventas_asesor.costos.actualizar'), ['ids' => [$producto->id], 'nuevo_costo' => '1', 'token' => 'x'])->assertUnauthorized();

    expect(costos_error(fn () => app(ActualizarCostoComisionableService::class)->previsualizar($asesor, [$producto->id], '1')))->toBe(Invalido::SIN_PERMISO)
        ->and(app(CostoComisionableCatalogo::class)->costosDe([$producto->id])[$producto->id])->toBe(5000.0);
});
