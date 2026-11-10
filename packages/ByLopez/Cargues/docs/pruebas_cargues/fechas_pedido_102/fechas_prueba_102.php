<?php
/*
 * Fechas PREPARADAS PARA PRUEBA del pedido 102 (cliente 48, guía 240099900003) en la BD de DESARROLLO lpz_saas.
 * Modos:  --respaldo   guarda los valores originales (JSON) de los registros y campos del alcance
 *         --simular    aplica el ajuste DENTRO de una transacción, verifica filas y reglas, y hace ROLLBACK (sin efectos)
 *         --aplicar    aplica y confirma (NO usar sin la decisión de Camilo sobre comisiones)
 *         --restaurar  devuelve EXACTAMENTE los valores del respaldo, solo en esos registros y campos
 * Alcance cerrado: ids exactos; cada UPDATE exige el valor original actual (si alguien lo cambió, aborta todo).
 * No toca cantidades, stock, importes, pagos, comisiones, el Excel ni la fecha reportada por el proveedor.
 */
chdir('C:/xampp/htdocs/lpz-saas');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

$modo = $argv[1] ?? '';
$respaldo = __DIR__.'/fechas_prueba_102.respaldo.json';

if (DB::selectOne('SELECT DATABASE() AS b, @@port AS p')->b !== 'lpz_saas' || (int) DB::selectOne('SELECT @@port AS p')->p !== 3306 || config('app.env') !== 'local') {
    exit("ABORTA: no es la BD de desarrollo lpz_saas:3306 en entorno local\n");
}

// Desplazamiento constante: conserva los intervalos internos (pedido → despacho 5 min 54 s).
// Pedido 2026-11-02 20:51:06 → 2026-10-27 20:00:06 (antes de fecha_envio del proveedor 2026-10-27 20:05:01);
// despacho 20:57:00 → 2026-10-27 20:06:00 (después de fecha_envio); entrega del proveedor 2026-10-30 (sin cambios).
const DELTA_SEG = -((6 * 86400) + (51 * 60));

$alcance = [
    'orders' => ['ids' => [102], 'campos' => ['created_at', 'updated_at']],
    'order_items' => ['donde' => ['order_id', 102], 'campos' => ['created_at', 'updated_at'], 'esperadas' => 6],
    'addresses' => ['ids' => [477, 478], 'campos' => ['created_at', 'updated_at']],
    'order_payment' => ['ids' => [102], 'campos' => ['created_at', 'updated_at']],
    'invoices' => ['ids' => [100], 'campos' => ['created_at', 'updated_at']],
    'shipments' => ['ids' => [83], 'campos' => ['created_at', 'updated_at']],
    'shipment_items' => ['ids' => [470, 471, 472], 'campos' => ['created_at', 'updated_at']],
    'bylopez_lg_guias' => ['ids' => [42], 'campos' => ['created_at', 'updated_at']],
];

function filas(string $t, array $a): array
{
    $q = DB::table($t);
    isset($a['ids']) ? $q->whereIn('id', $a['ids']) : $q->where($a['donde'][0], $a['donde'][1]);

    return $q->orderBy('id')->get(array_merge(['id'], $a['campos']))->map(fn ($f) => (array) $f)->all();
}

function comprobar(string $titulo): void
{
    $env = DB::table('shipments')->where('id', 83)->first(['order_id', 'created_at']);
    $ped = DB::table('orders')->where('id', 102)->value('created_at');
    $prov = '2026-10-30';      // fecha del estado «Entregada» del proveedor (Excel fila 15: 2026-10-30 00:46:41), solo fecha
    $envioProv = '2026-10-27 20:05:01';
    // Regla real de RegistrarEventoEnvio: fecha >= DATE(shipments.created_at) y no futura.
    $ok = $prov >= CarbonImmutable::parse($env->created_at)->toDateString() && $prov <= CarbonImmutable::today()->toDateString();
    echo "[$titulo] pedido {$ped} · despacho {$env->created_at} · fecha_envio proveedor {$envioProv} · entrega proveedor {$prov}\n";
    echo '   secuencia pedido < envío proveedor < despacho < entrega: '.json_encode($ped < $envioProv && $envioProv < $env->created_at && substr($env->created_at, 0, 10) <= $prov)."\n";
    echo '   regla FECHA_ANTERIOR_AL_DESPACHO superada: '.json_encode($ok)."\n";
    $mesPedido = \ByLopez\VentasAsesor\Support\Comisiones\MesComercial::de((string) $ped);
    $mesLineas = DB::table('bylopez_va_comision_lineas')->where('order_id', 102)->distinct()->pluck('mes_comercial')->implode(',');
    $mesUmbral = DB::table('bylopez_va_umbral_movimientos')->where('order_id', 102)->distinct()->pluck('mes')->implode(',');
    echo "   mes comercial según el pedido: {$mesPedido} · guardado en líneas de comisión: {$mesLineas} · en umbral: {$mesUmbral}".($mesPedido === $mesLineas ? '' : '  ← INCOHERENTE')."\n";
}

function huellaNegocio(): string
{
    $partes = [];
    foreach ([
        "SELECT SUM(qty) FROM product_inventories",
        "SELECT CONCAT_WS('|', base_grand_total, grand_total, status, total_qty_ordered) FROM orders WHERE id=102",
        "SELECT GROUP_CONCAT(CONCAT_WS(':', id, qty_ordered, qty_shipped, qty_invoiced, base_total) ORDER BY id) FROM order_items WHERE order_id=102",
        "SELECT CONCAT_WS('|', total_qty, carrier_code, track_number, inventory_source_id) FROM shipments WHERE id=83",
        "SELECT CONCAT_WS('|', recaudo_esperado, recaudo_estado, numero_guia, transportadora_codigo) FROM bylopez_lg_guias WHERE id=42",
        "SELECT CONCAT_WS('|', state, base_grand_total) FROM invoices WHERE id=100",
        "SELECT COUNT(*) FROM bylopez_va_aplicaciones_pago WHERE order_id=102",
        "SELECT GROUP_CONCAT(CONCAT_WS(':', id, mes_comercial, estado, created_at) ORDER BY id) FROM bylopez_va_comision_lineas WHERE order_id=102",
        "SELECT GROUP_CONCAT(CONCAT_WS(':', id, mes, created_at) ORDER BY id) FROM bylopez_va_umbral_movimientos WHERE order_id=102",
        "SELECT COUNT(*) FROM bylopez_lg_entregas", "SELECT COUNT(*) FROM bylopez_lg_retornos", "SELECT COUNT(*) FROM bylopez_lg_validaciones_entrega", "SELECT COUNT(*) FROM shipments",
    ] as $sql) {
        $partes[] = (string) (array_values((array) DB::selectOne($sql))[0] ?? '');
    }

    return hash('sha256', implode("\n", $partes));
}

if ($modo === '--respaldo') {
    $datos = [];
    foreach ($alcance as $t => $a) {
        $datos[$t] = filas($t, $a);
        if (isset($a['esperadas']) && count($datos[$t]) !== $a['esperadas']) { exit("ABORTA: $t tiene ".count($datos[$t])." filas, se esperaban {$a['esperadas']}\n"); }
        if (isset($a['ids']) && count($datos[$t]) !== count($a['ids'])) { exit("ABORTA: faltan filas en $t\n"); }
    }
    if (is_file($respaldo)) { exit("Ya existe un respaldo; no se sobrescribe: $respaldo\n"); }
    file_put_contents($respaldo, json_encode(['creado' => now()->toDateTimeString(), 'delta_seg' => DELTA_SEG, 'datos' => $datos], JSON_PRETTY_PRINT));
    echo "respaldo: $respaldo\n";
    foreach ($datos as $t => $fs) { foreach ($fs as $f) { echo "  $t#{$f['id']} ".json_encode(array_diff_key($f, ['id' => 1]))."\n"; } }
    exit;
}

if ($modo === '--simular-restauracion') {
    // Ida (ajuste) y vuelta (restauración) en UNA transacción revertida: demuestra que --restaurar deja los valores del respaldo.
    $orig = json_decode((string) @file_get_contents($respaldo), true) ?: exit("ABORTA: falta el respaldo\n");
    DB::beginTransaction();
    foreach ([false, true] as $vuelta) {
        foreach ($orig['datos'] as $t => $fs) {
            foreach ($fs as $f) {
                $set = []; $q = DB::table($t)->where('id', $f['id']);
                foreach ($alcance[$t]['campos'] as $c) {
                    $nuevo = $f[$c] === null ? null : CarbonImmutable::parse($f[$c])->addSeconds(DELTA_SEG)->format('Y-m-d H:i:s');
                    [$desde, $hacia] = $vuelta ? [$nuevo, $f[$c]] : [$f[$c], $nuevo];
                    $set[$c] = $hacia; $desde === null ? $q->whereNull($c) : $q->where($c, $desde);
                }
                if ($q->update($set) !== 1) { DB::rollBack(); exit("ABORTA: $t#{$f['id']}\n"); }
            }
        }
    }
    $igual = true;
    foreach ($alcance as $t => $a) { $igual = $igual && filas($t, $a) === $orig['datos'][$t]; }
    DB::rollBack();
    echo 'ida y vuelta: valores idénticos al respaldo: '.json_encode($igual)." · ROLLBACK, sin efectos\n";
    exit;
}

if (in_array($modo, ['--simular', '--aplicar', '--restaurar'], true)) {
    $orig = json_decode((string) @file_get_contents($respaldo), true) ?: exit("ABORTA: falta el respaldo (ejecute --respaldo primero)\n");
    $antesNeg = huellaNegocio();
    comprobar('ANTES');
    DB::beginTransaction();
    $total = 0;
    foreach ($orig['datos'] as $t => $fs) {
        foreach ($fs as $f) {
            $set = []; $where = [];
            foreach ($alcance[$t]['campos'] as $c) {
                $original = $f[$c];
                $nuevo = $original === null ? null : CarbonImmutable::parse($original)->addSeconds(DELTA_SEG)->format('Y-m-d H:i:s');
                [$desde, $hacia] = $modo === '--restaurar' ? [$nuevo, $original] : [$original, $nuevo];
                $set[$c] = $hacia; $where[$c] = $desde;
            }
            $q = DB::table($t)->where('id', $f['id']);
            foreach ($where as $c => $v) { $v === null ? $q->whereNull($c) : $q->where($c, $v); }
            $n = $q->update($set);   // 'timestamps' no aplica: query builder, updated_at solo el indicado
            if ($n !== 1) { DB::rollBack(); exit("ABORTA (rollback): $t#{$f['id']} afectó $n filas (¿valor actual distinto del esperado?)\n"); }
            $total += $n;
            echo "  $t#{$f['id']}: ".json_encode($where).' → '.json_encode($set)."\n";
        }
    }
    echo "filas afectadas: $total\n";
    comprobar('DESPUÉS (dentro de la transacción)');
    echo '   negocio intacto (stock, importes, cantidades, pagos, comisiones, eventos): '.json_encode(huellaNegocio() === $antesNeg)."\n";
    if ($modo === '--simular') { DB::rollBack(); echo "SIMULACIÓN: ROLLBACK, sin efectos\n"; comprobar('TRAS ROLLBACK'); exit; }
    if (huellaNegocio() !== $antesNeg) { DB::rollBack(); exit("ABORTA (rollback): cambió un dato de negocio\n"); }
    DB::commit();
    echo "CONFIRMADO ($modo)\n";
    exit;
}

echo "uso: php fechas_prueba_102.php --respaldo|--simular|--aplicar|--restaurar\n";
