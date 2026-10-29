<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';

/*
| RECORRIDO H — UMBRAL Y PAGO AL ASESOR (ejemplo aislado 998 + 5).
| Asesora EXCLUSIVA (DEMO Asesora Umbral) con 998 unidades acumuladas en el mes por la fixture de preparar_demo.php.
| Pedido nuevo: 5 unidades de UNA referencia a $10.000 netos, costo $6.000, 10 %. Las unidades 999 y 1000 completan el umbral (sin
| comision); 1001-1003 comisionan:  3 × (10.000 − 6.000) × 10 % = $1.200.
| Estimada → no habilitada sin validacion / cobro → habilitada → liquidacion → abono $500 (saldo $700) → abono $700 (saldo $0) →
| reintento sin duplicar → no se puede abonar mas que el saldo.
*/
function recorrido_h(Demo $d): void
{
    // `recorridos.php H <pedido>` retoma en la liquidacion de un pedido ya habilitado (sin repetir la venta).
    $o = (int) ($GLOBALS['argv'][2] ?? 0) ?: h_venta_y_habilitacion($d);
    h_liquidacion($d, $o);
}

function h_venta_y_habilitacion(Demo $d): int
{
    $umbral = (int) DB::table('admins')->where('email', 'demo.umbral@bylopez.test')->value('id');
    $mes = now()->format('Y-m');
    $acumulado = (int) DB::table('bylopez_va_umbral_movimientos')->where('asesor_admin_id', $umbral)->where('mes', $mes)->sum('unidades');
    esperar_igual('Acumulado inicial del mes de la asesora exclusiva', 998, $acumulado);

    $cliente = (int) DB::table('customers')->where('first_name', 'Fixture')->value('id');
    $dir = direccion_de($d, $cliente, 'umbral');

    // 5 unidades de UNA referencia (producto 215, variante 218). Recogida en bodega, transferencia.
    $v = $d->venta($cliente, [[215, [218 => 5]]], ['envio' => ['tipo' => 'punto_recogida'], 'facturacion' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'pickup'], 'moneytransfer', null, 'umbral');
    $o = $v['orderId'];
    esperar_igual('Total del pedido (5 × 10.000)', 50000.0, $v['total']);
    $c = mostrar_comision('estimada (nada cumplido aun)', $o);
    $linea = $c['lineas'][0] ?? [];
    esperar_igual('Unidades comisionables estimadas (2 completan el umbral, 3 comisionan)', 3, (int) ($linea['comisionables'] ?? -1));
    esperar_igual('Comision estimada = 3 × (10.000 − 6.000) × 10 %', 1200.0, (float) ($linea['estimada'] ?? 0));
    esperar_igual('Aun no habilitada', 'estimada', $linea['estado'] ?? null);

    $pago = reportar_pago($d, $o, '50000', 'DEMO-UMBRAL-0001', 'umbral');
    $c = mostrar_comision('pago reportado, sin confirmar ni entrega', $o);
    esperar_igual('Sigue sin habilitar con el pago solo reportado', 'estimada', $c['lineas'][0]['estado'] ?? null);

    confirmar_pago($d, $pago);
    $c = mostrar_comision('pago confirmado, SIN entrega validada', $o);
    esperar_igual('Cobro confirmado pero sin entrega validada: no se habilita', 'estimada', $c['lineas'][0]['estado'] ?? null);

    $r = $d->paso('Bodega entrega en el punto de recogida (sin transportadora ni guia)', $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$o}/entregar-en-bodega", ['idempotency_key' => Demo::clave(), 'quien_retira' => 'DEMO Cliente (ficticio)']));
    esperar_igual('Recogida: sin guia', 0, DB::table('bylopez_lg_guias')->where('order_id', $o)->count());
    $c = mostrar_comision('entregado en bodega pero NO validado', $o);
    esperar_igual('Entrega sin validar: no se habilita', 'estimada', $c['lineas'][0]['estado'] ?? null);

    validar_entrega($d, (int) $r['envio_id']);
    $c = mostrar_comision('entrega validada + cobro confirmado + recogida (el pago confirmado es la prueba del dinero)', $o);
    $linea = $c['lineas'][0] ?? [];
    esperar_igual('Linea HABILITADA automaticamente', 'habilitada', $linea['estado'] ?? null);
    esperar_igual('Unidades comisionables', 3, (int) ($linea['comisionables'] ?? 0));
    esperar_igual('Comision habilitada', 1200.0, (float) ($linea['habilitada'] ?? 0));
    mostrar_foto('H · pedido final', foto($o));

    return $o;
}

function h_liquidacion(Demo $d, int $o): void
{
    $umbral = (int) DB::table('admins')->where('email', 'demo.umbral@bylopez.test')->value('id');

    // Liquidacion y abonos (otra cuenta distinta de Cartera; no ejecuta transferencias).
    $volver = '/admin/ventas-asesor/comisiones';
    $d->form('Administrador crea el borrador de liquidacion', 'admin', '/admin/ventas-asesor/comisiones/liquidaciones', [
        'asesor_id' => $umbral, 'periodo_desde' => now()->startOfMonth()->toDateString(), 'periodo_hasta' => now()->endOfMonth()->toDateString(),
        'observacion' => 'DEMO: liquidacion del ejemplo 998 + 5', 'clave' => Demo::clave(),
    ], $volver);
    $liq = (int) DB::table('bylopez_va_comision_liquidaciones')->where('asesor_admin_id', $umbral)->orderByDesc('id')->value('id');
    $row = DB::table('bylopez_va_comision_liquidaciones')->where('id', $liq)->first();
    esperar_igual('Total de la liquidacion', 1200.0, (float) $row->total);

    $d->form('Administrador aprueba la liquidacion', 'admin', "/admin/ventas-asesor/comisiones/liquidaciones/{$liq}/aprobar", [], $volver);

    $abono = fn (string $monto, string $clave) => $d->form("Abono de {$monto}", 'admin', "/admin/ventas-asesor/comisiones/liquidaciones/{$liq}/abonos", [
        'monto' => $monto, 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => 'DEMO-ABONO-'.$monto, 'observacion' => 'DEMO (ficticio)', 'clave' => $clave,
    ], $volver, ['comprobante' => Demo::comprobante()]);
    $saldo = fn () => round((float) DB::table('bylopez_va_comision_liquidaciones')->where('id', $liq)->value('total') - (float) DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq)->where('tipo', 'abono')->sum('monto'), 2);

    $k1 = Demo::clave();
    $abono('500', $k1);
    esperar_igual('Saldo tras abonar $500', 700.0, $saldo());
    $abono('500', $k1);
    esperar_igual('Reintento con la MISMA clave: sin duplicar (sigue 1 abono, saldo 700)', [1, 700.0], [DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq)->where('tipo', 'abono')->count(), $saldo()]);
    // Formulario COMPLETO y valido salvo el importe (con comprobante, fecha, medio, referencia y clave nuevos): el rechazo solo puede deberse al saldo.
    $abonoRechazado = function (string $texto, string $monto, string $referencia, string $fragmento) use ($d, $liq, $volver) {
        $tablas = fn () => [
            DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq)->count(),
            DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq)->sum('monto') + 0,
            DB::table('bylopez_va_comision_liquidaciones')->where('id', $liq)->value('estado'),
            DB::table('bylopez_va_comision_aplicaciones')->count(),
        ];
        $antes = $tablas();
        $r = $d->formRechazo($texto, 'admin', "/admin/ventas-asesor/comisiones/liquidaciones/{$liq}/abonos", [
            'monto' => $monto, 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'referencia' => $referencia, 'observacion' => 'DEMO (ficticio)', 'clave' => Demo::clave(),
        ], $volver, $fragmento, ['comprobante' => Demo::comprobante()]);
        esperar_igual("{$texto}: cero escrituras (abonos, suma, estado, aplicaciones)", $antes, $tablas());
        echo '  Motivo exacto devuelto: «'.$r['mensaje']."»\n";
    };
    $abonoRechazado('Abonar mas que el saldo ($800 con saldo $700)', '800', 'DEMO-EXCESO', 'supera el saldo pendiente');
    esperar_igual('El abono excesivo no se registro (saldo 700)', 700.0, $saldo());
    $abono('700', Demo::clave());
    esperar_igual('Saldo final tras el abono de $700', 0.0, $saldo());
    // Con saldo cero la liquidacion ya no admite pagos: el motivo exacto es el estado/saldo, no un campo faltante.
    $abonoRechazado('Abonar con saldo cero', '1', 'DEMO-EXCESO2', '');
    $abonos = DB::table('bylopez_va_comision_abonos')->where('liquidacion_id', $liq)->orderBy('id')->pluck('monto')->map(fn ($m) => (float) $m)->all();
    echo '  Historial de abonos: '.json_encode($abonos)."\n";
    guardar_evidencia('H', ['pedido' => $o, 'liquidacion' => $liq, 'comision' => 1200.0, 'abonos' => $abonos, 'saldo_final' => $saldo()]);
}
