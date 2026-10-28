<?php

/*
| Prueba la REEVALUACION PROGRAMADA sin usar el boton manual ni el comando a mano:
|   1) (fase "marcar")  marca un pedido en la bandeja con la reevaluacion automatica APAGADA solo en este script, de modo que nadie la procese.
|   2) (fase "esperar") espera a que el scheduler del proceso `schedule:work` (cada 5 minutos) la tome; falla si no ocurre en el tiempo dado.
| Uso: bash demo.sh php packages/ByLopez/VentasAsesor/tests/Demo/probar_scheduler.php [minutos_maximos=8]
*/

use ByLopez\VentasAsesor\Models\ComisionReevaluacion;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: base {$base}\n");
    exit(2);
}

$max = (int) ($argv[1] ?? 8);
$orderId = (int) DB::table('bylopez_va_comision_lineas')->orderByDesc('order_id')->value('order_id');

if ($orderId === 0) {
    fwrite(STDERR, "No hay lineas de comision en la demo: corra antes los recorridos.\n");
    exit(1);
}

config(['ventasasesor.comisiones.reevaluacion.automatica' => false]);
app(ReevaluacionComisionesService::class)->solicitar([$orderId], 'prueba_scheduler');
$fila = ComisionReevaluacion::query()->where('order_id', $orderId)->first();
$antes = ['version' => (int) $fila->version, 'procesada' => (int) $fila->version_procesada, 'origen' => $fila->origen];
echo date('H:i:s')." marcado pedido {$orderId}: ".json_encode($antes)." (pendiente; nadie la proceso: automatica=false solo en este script)\n";

if ($antes['version'] <= $antes['procesada']) {
    fwrite(STDERR, "La fila no quedo pendiente.\n");
    exit(1);
}

$limite = time() + $max * 60;

while (time() < $limite) {
    sleep(15);
    $f = ComisionReevaluacion::query()->where('order_id', $orderId)->first();

    if ((int) $f->version_procesada >= (int) $f->version) {
        echo date('H:i:s')." PROCESADA por el scheduler: version={$f->version} procesada={$f->version_procesada} intentos={$f->intentos}\n";
        echo "RESULTADO: OK (sin boton manual)\n";
        exit(0);
    }
}

echo "RESULTADO: FALLA: el scheduler no proceso la bandeja en {$max} minutos\n";
exit(1);
