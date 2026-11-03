<?php

use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Services\Comisiones\AvanceAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\PanelComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\SimuladorProximaVentaService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom6.php';

uses(TestCase::class);

/*
| PANEL DE COMISIONES Y «TU AVANCE DEL MES» (jornada 2026-11-02). Solo lpz_saas_testing. Cada expectativa numerica se calcula APARTE de
| los servicios, con la formula aprobada: unidades comisionables × (precio neto − costo) × porcentaje. Datos de fixture: precio neto
| $10.000, costo $6.000 → base $4.000; 10 % → $400 por unidad comisionable.
*/

function com6_avance(array $e): array
{
    return app(AvanceAsesorService::class)->deAsesor((int) $e['asesor']->id, MesComercial::de(now()));
}

function com6_panel(array $e, bool $calculo = false): array
{
    return app(PanelComisionesService::class)->deAsesor((int) $e['asesor']->id, MesComercial::de(now()), $calculo);
}

test('AV-1 antes, exactamente en y despues del umbral (umbral 10): el servidor decide el mensaje y la estimacion', function () {
    $e = com4_escenario('10', 10);

    com4_vender($e, 8);
    $a = com6_avance($e);
    // Antes: 8 de 10, faltan 2, nada comisiona todavia.
    expect($a['situacion'])->toBe('antes')->and($a['computables'])->toBe(8)->and($a['umbral'])->toBe(10)->and($a['faltante'])->toBe(2)
        ->and($a['comisionables'])->toBe(0)->and($a['avance'])->toBe(80)->and($a['estimada'])->toBe('0.00');

    com4_vender($e, 2);
    $a = com6_avance($e);
    // Exactamente en el umbral: completo, pero las siguientes unidades son las que comisionan.
    expect($a['situacion'])->toBe('exacto')->and($a['computables'])->toBe(10)->and($a['faltante'])->toBe(0)
        ->and($a['comisionables'])->toBe(0)->and($a['avance'])->toBe(100)->and($a['estimada'])->toBe('0.00');

    com4_vender($e, 3);
    $a = com6_avance($e);
    // Despues: 13 computables → 3 comisionables × 4.000 × 10 % = $1.200.
    expect($a['situacion'])->toBe('despues')->and($a['computables'])->toBe(13)->and($a['comisionables'])->toBe(3)
        ->and($a['estimada'])->toBe('1200.00')->and($a['avance'])->toBe(100);
});

test('AV-2 ejemplo aislado 998 + 5: 3 unidades comisionables y $1.200; el panel las reparte en UNA etapa (sin sumar estimada y habilitada)', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com4_vender($e, 5);

    $a = com6_avance($e);
    expect($a['computables'])->toBe(1003)->and($a['comisionables'])->toBe(3)->and($a['estimada'])->toBe('1200.00');

    $p = com6_panel($e);
    expect($p['etapas'])->toBe(['bloqueada' => '1200.00', 'habilitada' => '0.00', 'por_pagar' => '0.00', 'pagada' => '0.00'])
        ->and($p['total_mes'])->toBe('1200.00')->and($p['total_parcial'])->toBeFalse();

    // La lista principal va agrupada por pedido: el primero (998) esta dentro del umbral y no comisiona; el segundo trae las 3 unidades.
    expect($p['pedidos'])->toHaveCount(2)
        ->and($p['pedidos'][0]['comisionables'])->toBe(0)->and($p['pedidos'][0]['importe'])->toBe('0.00')->and($p['pedidos'][0]['situacion'])->toBe('dentro_del_umbral')
        ->and($p['pedidos'][1]['comisionables'])->toBe(3)->and($p['pedidos'][1]['importe'])->toBe('1200.00')->and($p['pedidos'][1]['situacion'])->toBe('bloqueada');

    // Al habilitarse, el MISMO importe pasa a la etapa «habilitada»: nunca queda en dos a la vez.
    com4_pagar($pedido);
    com4_entregar($pedido);
    app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);
    $p = com6_panel($e);
    expect($p['etapas'])->toBe(['bloqueada' => '0.00', 'habilitada' => '1200.00', 'por_pagar' => '0.00', 'pagada' => '0.00'])->and($p['total_mes'])->toBe('1200.00');
});

test('AV-3 despachadas y entregadas son indicadores separados de las unidades computables y no se cuentan dos veces', function () {
    $e = com4_escenario('10', 10);
    $pedido = com4_vender($e, 5);
    com4_pagar($pedido);   // la recogida exige el cobro disposicionado antes de entregar

    $a = com6_avance($e);
    expect($a['computables'])->toBe(5)->and($a['despachadas'])->toBe(0)->and($a['entregadas'])->toBe(0);

    $envio = com4_entregar($pedido, false);
    $a = com6_avance($e);
    // Despachar NO cambia las computables (cuentan desde la venta) ni equivale a entregado (falta validar).
    expect($a['computables'])->toBe(5)->and($a['despachadas'])->toBe(5)->and($a['entregadas'])->toBe(0);

    app(ValidarEntregaService::class)->validar(f03b_admin('all'), $envio, 'com6-val-'.Str::uuid(), null);
    $a = com6_avance($e);
    expect($a['computables'])->toBe(5)->and($a['despachadas'])->toBe(5)->and($a['entregadas'])->toBe(5);

    // Repetir la lectura no suma otra vez.
    expect(com6_avance($e))->toBe($a);
});

test('AV-4 un cambio de porcentaje no altera las ventas anteriores: cada pedido conserva el porcentaje historico de su confirmacion', function () {
    $e = com4_escenario('10', 10);
    com4_vender($e, 12);   // 2 comisionables × 4.000 × 10 % = $800

    ComisionAcuerdo::query()->create([
        'asesor_admin_id' => $e['asesor']->id, 'version' => 2, 'porcentaje' => '20', 'umbral_mensual_unidades' => 10, 'vigente_desde' => now()->subMinute(),
        'activo' => true, 'motivo' => 'COM6', 'creado_por_admin_id' => $e['asesor']->id, 'creado_por_name_snapshot' => 'COM6',
    ]);

    com4_vender($e, 5);    // 5 comisionables × 4.000 × 20 % = $4.000

    $p = com6_panel($e, true);
    expect($p['pedidos'][0]['importe'])->toBe('800.00')->and($p['pedidos'][0]['lineas'][0]['calculo']['porcentaje'])->toBe('10')
        ->and($p['pedidos'][1]['importe'])->toBe('4000.00')->and($p['pedidos'][1]['lineas'][0]['calculo']['porcentaje'])->toBe('20')
        ->and($p['total_mes'])->toBe('4800.00');
});

test('AV-5 un dato desconocido NO se muestra como $0: queda sin calcular, el total es parcial y la estimacion es null', function () {
    $e = com4_escenario('10', 2);
    $pedido = com4_vender($e, 5);   // umbral 2: 3 comisionables
    ComisionLinea::query()->where('order_id', $pedido->id)->update(['costo_unitario' => null, 'base_unitaria' => null]);

    $p = com6_panel($e);
    expect($p['sin_calcular'])->toBe(1)->and($p['total_parcial'])->toBeTrue()->and($p['etapas']['bloqueada'])->toBe('0.00')
        ->and($p['pedidos'][0]['importe'])->toBeNull()->and($p['pedidos'][0]['situacion'])->toBe('sin_calcular')
        ->and($p['pedidos'][0]['lineas'][0]['importe'])->toBeNull();

    $a = com6_avance($e);
    expect($a['estimada'])->toBeNull()->and($a['estimada_parcial'])->toBe('0.00')->and($a['sin_calcular'])->toBe(1);

    // La asesora no recibe costo ni base ni margen: ni siquiera con el dato roto.
    expect(json_encode($p))->not->toContain('"calculo"')->not->toContain('base_unitaria')->not->toContain('margen');
});

test('AV-6 con las comisiones desactivadas lo dice UNA vez y no oculta las estimaciones; los motivos de un pedido van en el pedido', function () {
    $e = com4_escenario('10', 10);
    com4_vender($e, 12);
    config(['ventasasesor.comisiones.fecha_inicio' => null]);

    $p = com6_panel($e);
    expect($p['comisiones_activas'])->toBeFalse()->and($p['avisos'])->toContain('sin_fecha_inicio')
        ->and($p['pedidos'][0]['importe'])->toBe('800.00')                       // la estimacion sigue visible
        ->and($p['pedidos'][0]['motivos'])->not->toContain('sin_fecha_inicio');  // el aviso general NO se repite junto al pedido

    config(['ventasasesor.comisiones.fecha_inicio' => '2000-01-01']);
    $p = com6_panel($e);
    expect($p['comisiones_activas'])->toBeTrue()->and($p['avisos'])->not->toContain('sin_fecha_inicio')
        ->and($p['pedidos'][0]['motivos'])->toContain('unidades_sin_despachar');
});

test('AV-7 la vista de Pedidos muestra SU avance a la asesora y la tarjeta no atribuye cifras de otras personas', function () {
    $this->withoutVite();
    $a = com4_escenario('10', 10);
    $b = com4_escenario('10', 10);
    com6_como_en_roles($a['asesor']);
    com6_como_en_roles($b['asesor']);
    com4_vender($a, 8);
    com4_vender($b, 13);

    $html = $this->actingAs($a['asesor'], 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent();
    expect($html)->toContain('Tu avance del mes')->toContain('Vas en 8 de 10')->toContain('Te faltan 2')->not->toContain('Vas en 13')
        ->and($html)->not->toContain('unidades comisionables; revisa');

    $html = $this->actingAs($b['asesor'], 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent();
    expect($html)->toContain('Tienes 3 unidades comisionables')->toContain('Superar el umbral no significa')->not->toContain('Vas en 8');

    // Administracion que NO es asesora no ve una tarjeta con cifras de nadie.
    $admin = f03b_admin('all');
    com6_como_en_roles($admin);
    expect($this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk()->getContent())->not->toContain('Tu avance del mes');
});

test('AV-8 simular la proxima venta: calcula con el servicio, no escribe nada y explica lo que no puede calcular', function () {
    $e = com4_escenario('10', 10);
    com4_vender($e, 8);   // quedan 2 unidades libres del umbral
    $variantes = $e['configurable']->variants()->get();
    $sku = $variantes[0]->sku;
    $contar = fn () => [DB::table('orders')->count(), DB::table('bylopez_va_comision_lineas')->count(), DB::table('bylopez_va_umbral_movimientos')->count(), DB::table('cart')->count()];
    $antes = $contar();
    $simulador = app(SimuladorProximaVentaService::class);

    // 4 unidades nuevas: 2 completan el umbral y 2 comisionan → 2 × 4.000 × 10 % = $800.
    $r = $simulador->simular($e['asesor'], [['sku' => $sku, 'cantidad' => '4', 'precio_neto' => '10000']]);
    expect($r['simulacion'])->toBeTrue()->and($r['comisionables'])->toBe(2)->and($r['comision'])->toBe('800.00')->and($r['libres'])->toBe(2)
        ->and($r['limitaciones'])->toBe([]);

    // Dentro de las unidades libres del umbral: ninguna comisiona.
    $r = $simulador->simular($e['asesor'], [['sku' => $sku, 'cantidad' => '2', 'precio_neto' => '10000']]);
    expect($r['comisionables'])->toBe(0)->and($r['comision'])->toBe('0.00');

    // Sin costo: no se inventa el resultado.
    com_costo($variantes[0], null);
    $r = $simulador->simular($e['asesor'], [['sku' => $sku, 'cantidad' => '4', 'precio_neto' => '10000']]);
    expect($r['comision'])->toBeNull()->and($r['limitaciones'])->toContain('costo_sin_autoridad');
    com_costo($variantes[0], 6000.0);

    // Pedido mixto que cruza el umbral con bases distintas: politica pendiente, sin resultado.
    com_costo($variantes[1], 5000.0);
    $r = $simulador->simular($e['asesor'], [
        ['sku' => $variantes[0]->sku, 'cantidad' => '2', 'precio_neto' => '10000'],
        ['sku' => $variantes[1]->sku, 'cantidad' => '2', 'precio_neto' => '10000'],
    ]);
    expect($r['comision'])->toBeNull()->and($r['limitaciones'])->toContain('politica_pendiente_pedido_mixto');

    // Datos invalidos: rechazo claro; y un SKU del configurable (agrupa tallas) tampoco se simula.
    expect(fn () => $simulador->simular($e['asesor'], [['sku' => $sku, 'cantidad' => '0', 'precio_neto' => '10000']]))->toThrow(OperacionComisionInvalidaException::class);
    expect(fn () => $simulador->simular($e['asesor'], [['sku' => $e['configurable']->sku, 'cantidad' => '1', 'precio_neto' => '10000']]))->toThrow(OperacionComisionInvalidaException::class);
    expect(fn () => $simulador->simular(f03b_admin('custom', ['ventas_asesor.operar_como_asesor']), [['sku' => $sku, 'cantidad' => '1', 'precio_neto' => '10000']]))->toThrow(OperacionComisionInvalidaException::class);

    // Nada se creo: ni pedidos, ni lineas de comision, ni movimientos del umbral, ni carritos.
    expect($contar())->toBe($antes);
});
