<?php

use ByLopez\Logistica\Support\Moneda;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';

/*
| RECORRIDO K — «MIS COMISIONES» (vista de la asesora), por HTTP con las sesiones reales. Requiere haber corrido H (998 + 5, liquidacion con
| abonos de $500 y $700). Se comprueba que:
|   1. la asesora del ejemplo ve su comision de $1.200 en UNA sola etapa (pagada) y no duplicada entre «estimada» y «habilitada»;
|   2. la otra asesora (demo.asesora) no ve nada ajeno, aunque pida `?asesor=` o `?pedido=` ajenos;
|   3. ninguna asesora puede aprobar, liquidar, abonar, reevaluar ni cambiar acuerdos (rechazo del servidor y cero escrituras);
|   4. entrar a Comisiones sin parametros lleva a «Mis comisiones».
*/
function recorrido_k(Demo $d): void
{
    $uid = (int) DB::table('admins')->where('email', 'demo.umbral@bylopez.test')->value('id');
    $liq = (int) DB::table('bylopez_va_comision_liquidaciones')->where('asesor_admin_id', $uid)->where('estado', 'aprobada')->orderByDesc('id')->value('id');

    if ($liq === 0) {
        throw new RuntimeException('Falta la liquidacion del recorrido H: corra primero el recorrido H.');
    }

    $pedidosPropios = DB::table('bylopez_va_comision_lineas')->where('asesor_admin_id', $uid)->pluck('order_id')->unique()->all();
    $incrementos = DB::table('orders')->whereIn('id', $pedidosPropios)->pluck('increment_id')->all();
    $html = fn (string $rol, string $ruta) => (string) $d->s[$rol]->http->get($ruta, ['headers' => ['Accept' => 'text/html'], 'allow_redirects' => false])->getBody();
    $estado = fn (string $rol, string $ruta) => $d->s[$rol]->http->get($ruta, ['headers' => ['Accept' => 'text/html'], 'allow_redirects' => false]);

    // 1. La asesora del ejemplo: $1.200 en una sola etapa.
    $pagina = $html('umbral', '/admin/ventas-asesor/comisiones/mias');
    preg_match('/data-total-mes>\s*([^<]+)</', $pagina, $total);
    $etapa = fn (string $e) => preg_match('/data-etapa="'.$e.'".*?<p class="mt-2[^>]*>\s*([^<]+)</s', $pagina, $m) ? trim($m[1]) : null;
    $moneda = fn (float $v) => Moneda::formatear($v);
    echo '  Mis comisiones (umbral): total '.trim($total[1] ?? '?').' · bloqueada '.$etapa('bloqueada').' · habilitada '.$etapa('habilitada').' · por pagar '.$etapa('por_pagar').' · pagada '.$etapa('pagada')."\n";
    esperar_igual('La asesora ve la comision del mes de $1.200 (una sola cifra total)', $moneda(1200), trim($total[1] ?? ''));
    esperar_igual('Etapas: nada bloqueado, nada por liquidar, nada por pagar y TODO pagado', [$moneda(0), $moneda(0), $moneda(0), $moneda(1200)], [$etapa('bloqueada'), $etapa('habilitada'), $etapa('por_pagar'), $etapa('pagada')]);
    esperar_igual('Se ve su liquidacion con los abonos de $500 y $700', [true, true, true], [str_contains($pagina, $moneda(500)), str_contains($pagina, $moneda(700)), str_contains($pagina, 'LQC-')]);
    esperar_igual('La vista no muestra costo, base ni margen', false, (bool) preg_match('/costo|base comisionable|margen/i', strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $pagina))));

    // 2. La otra asesora no ve nada ajeno; ?asesor= y ?pedido= ajenos no cambian a quien se consulta.
    $ajena = $html('asesora', '/admin/ventas-asesor/comisiones/mias?asesor='.$uid);
    $verAjeno = array_filter($incrementos, fn ($i) => str_contains($ajena, '#'.$i));
    esperar_igual('demo.asesora no ve ningun pedido de la asesora del ejemplo (aunque pida ?asesor=)', [], array_values($verAjeno));
    $d->paso('demo.asesora pide el detalle de un pedido ajeno: no existe para ella (404)', [$estado('asesora', '/admin/ventas-asesor/comisiones?pedido='.$pedidosPropios[0])->getStatusCode(), []], [404]);

    // 3. Ninguna asesora aprueba, liquida, abona, reevalua ni toca acuerdos.
    $escrituras = fn () => [DB::table('bylopez_va_comision_liquidaciones')->count(), DB::table('bylopez_va_comision_abonos')->count(), DB::table('bylopez_va_comision_acuerdos')->count(), DB::table('bylopez_va_comision_aplicaciones')->count()];
    $antes = $escrituras();

    foreach (['umbral', 'asesora'] as $rol) {
        foreach ([
            "/admin/ventas-asesor/comisiones/liquidaciones/{$liq}/abonos" => ['monto' => '1', 'fecha_pago' => now()->toDateString(), 'medio' => 'transferencia', 'clave' => Demo::clave()],
            "/admin/ventas-asesor/comisiones/liquidaciones/{$liq}/aprobar" => [],
            '/admin/ventas-asesor/comisiones/liquidaciones' => ['asesor_admin_id' => $uid],
            '/admin/ventas-asesor/comisiones/evaluar' => [],
            "/admin/ventas-asesor/comisiones/acuerdos/{$uid}" => ['porcentaje' => '50', 'umbral' => '1'],
        ] as $ruta => $datos) {
            [$codigo] = $d->s[$rol]->formulario($ruta, $datos, '/admin/ventas-asesor/comisiones/mias');
            echo sprintf("  [%s] %s intenta POST %s → HTTP %d\n", in_array($codigo, [401, 403], true) ? 'OK' : 'FALLO', $rol, $ruta, $codigo);

            if (! in_array($codigo, [401, 403], true)) {
                throw new RuntimeException("{$rol} pudo ejecutar {$ruta} (HTTP {$codigo})");
            }
        }
    }

    esperar_igual('Cero escrituras tras los intentos (liquidaciones, abonos, acuerdos, aplicaciones)', $antes, $escrituras());

    // 4. Comisiones sin parametros lleva a «Mis comisiones».
    $r = $estado('umbral', '/admin/ventas-asesor/comisiones');
    esperar_igual('Comisiones sin parametros redirige a «Mis comisiones»', [302, true], [$r->getStatusCode(), str_ends_with($r->getHeaderLine('Location'), '/comisiones/mias')]);
    guardar_evidencia('K', ['asesora' => $uid, 'liquidacion' => $liq, 'total_mostrado' => trim($total[1] ?? ''), 'pedidos' => $pedidosPropios]);
}
