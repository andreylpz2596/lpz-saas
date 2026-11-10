<?php

/*
| Ayudante del recorrido de navegador de comprobantes (SOLO base demo aislada lpz_saas_demo_*).
|   php consulta_demo_comprobantes.php estado
|   php consulta_demo_comprobantes.php arranque 2026-09-01
|   php consulta_demo_comprobantes.php limpiar <fecha_arranque_original>   (reversa lo creado por el recorrido y restaura el arranque)
*/

use ByLopez\CorteConfeccion\Models\CarteraMovimiento;
use ByLopez\CorteConfeccion\Services\CarteraMovimientoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS base')->base;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "BLOQUEADO: '$base' no es una base demo aislada.\n");
    exit(2);
}

$accion = $argv[1] ?? 'estado';

if ($accion === 'arranque') {
    DB::table('bylopez_cc_cartera_config')->where('clave', 'fecha_arranque')->update(['valor' => $argv[2]]);
    echo "arranque={$argv[2]}\n";
} elseif ($accion === 'purgar') {
    // Borra SOLO lo creado por el recorrido (observacion RECORRIDO-COMPROBANTES%) y los soportes que quedan sin uso, con sus archivos.
    $ids = DB::table('bylopez_cc_cartera_movimientos')->where('observacion', 'like', 'RECORRIDO-COMPROBANTES%')->pluck('id');
    DB::table('bylopez_cc_cartera_aplicaciones')->whereIn('movimiento_id', $ids)->delete();
    DB::table('bylopez_cc_cartera_operaciones')->whereIn('movimiento_id', $ids)->update(['duplicado_de_id' => null]);
    DB::table('bylopez_cc_cartera_operaciones')->whereIn('movimiento_id', $ids)->delete();
    DB::table('bylopez_cc_cartera_soportes')->whereIn('movimiento_id', $ids)->update(['movimiento_id' => null]);
    DB::table('bylopez_cc_cartera_movimientos')->whereIn('id', $ids)->delete();
    $huerfanos = DB::table('bylopez_cc_cartera_soportes as s')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bylopez_cc_cartera_operaciones as o')->whereColumn('o.soporte_id', 's.id'))->get(['id', 'ruta']);

    foreach ($huerfanos as $h) {
        Storage::disk('local')->delete($h->ruta);
    }

    DB::table('bylopez_cc_cartera_soportes')->whereIn('id', $huerfanos->pluck('id'))->delete();
    echo 'purgados movimientos='.count($ids).' soportes='.count($huerfanos).'
';
} elseif ($accion === 'limpiar') {
    Auth::guard('admin')->setUser(Admin::query()->where('email', 'cc.admin@bylopez.test')->firstOrFail());

    foreach (CarteraMovimiento::query()->where('observacion', 'like', 'RECORRIDO-COMPROBANTES%')->where('estado', '!=', 'reversado')->get() as $m) {
        app(CarteraMovimientoService::class)->reversar($m, 'Limpieza del recorrido de prueba de comprobantes');
        echo "reversado {$m->codigo}\n";
    }

    DB::table('bylopez_cc_cartera_config')->where('clave', 'fecha_arranque')->update(['valor' => $argv[2]]);
    echo "arranque restaurado={$argv[2]}\n";
} else {
    $movs = DB::table('bylopez_cc_cartera_movimientos')->where('observacion', 'like', 'RECORRIDO-COMPROBANTES%')->get(['id', 'codigo', 'tipo', 'estado', 'valor', 'fecha_efectiva', 'medio', 'taller_id']);
    $ops = DB::table('bylopez_cc_cartera_operaciones')->whereIn('movimiento_id', $movs->pluck('id'))->get(['movimiento_id', 'referencia', 'autorizacion', 'remitente', 'valor', 'fecha_operacion', 'hora_operacion', 'banco_destino', 'medio', 'contraparte', 'estado', 'soporte_id']);

    echo json_encode([
        'arranque' => DB::table('bylopez_cc_cartera_config')->where('clave', 'fecha_arranque')->value('valor'),
        'movimientos_total' => DB::table('bylopez_cc_cartera_movimientos')->count(),
        'movimientos_recorrido' => $movs,
        'operaciones' => $ops,
        'soportes' => DB::table('bylopez_cc_cartera_soportes')->get(['id', 'nombre_original', 'mime', 'lectura_estado', 'lectura_proveedor', 'movimiento_id']),
    ], JSON_UNESCAPED_UNICODE), "\n";
}
