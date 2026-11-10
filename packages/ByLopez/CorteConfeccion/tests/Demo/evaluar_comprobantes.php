<?php

/*
| Evalua la lectura LOCAL (OCR real, sin simulacion) contra comprobantes verificados a mano.
| Uso:  php evaluar_comprobantes.php <carpeta con las imagenes> <esperados.json> [salida.json]
|
| esperados.json: {"archivo.jpg": {"valor":"50000.00","fecha":"2026-01-05","hora":"09:15","referencia":"M00000001",
|                  "autorizacion":null,"medio":"Nequi","banco_origen":null,"banco_destino":null,"remitente":null,"contraparte":"..."}}
| Un archivo con valor "vacio" => se espera que NO se proponga ninguna operacion. Los esperados los verifica una persona mirando
| la imagen; el lector no los conoce. Imprime aciertos por campo y tiempo de lectura.
*/

use ByLopez\CorteConfeccion\Comprobantes\ComprobanteLector;
use Illuminate\Contracts\Console\Kernel;

$raiz = dirname(__DIR__, 5);

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$dir, $esperadosRuta] = [$argv[1] ?? '', $argv[2] ?? ''];
$esperados = json_decode((string) @file_get_contents($esperadosRuta), true);

if (! is_dir($dir) || ! is_array($esperados)) {
    fwrite(STDERR, "Uso: php evaluar_comprobantes.php <carpeta> <esperados.json> [salida.json]\n");
    exit(2);
}

$lector = app(ComprobanteLector::class);
echo 'Lector: ', $lector->nombre(), ' · disponible: ', $lector->disponible() ? 'si' : 'NO', "\n\n";

$campos = ['valor', 'fecha', 'hora', 'referencia', 'autorizacion', 'medio', 'banco_origen', 'banco_destino', 'remitente', 'contraparte'];
$norm = fn ($v) => $v === null ? null : mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $v)) ?? '');
$aciertos = array_fill_keys($campos, 0);
$total = array_fill_keys($campos, 0);
$informe = [];

foreach ($esperados as $archivo => $exp) {
    $ruta = $dir.DIRECTORY_SEPARATOR.$archivo;
    $mime = mime_content_type($ruta);
    $t0 = microtime(true);

    try {
        $borradores = $lector->leer((string) file_get_contents($ruta), $mime, $archivo);
        $error = null;
    } catch (Throwable $e) {
        $borradores = [];
        $error = $e->getMessage();
    }

    $seg = round(microtime(true) - $t0, 2);
    $fila = ['archivo' => $archivo, 'segundos' => $seg, 'error' => $error, 'operaciones' => count($borradores), 'campos' => []];
    echo "== {$archivo} ({$seg}s) · operaciones propuestas: ".count($borradores).($error ? " · ERROR: {$error}" : '')."\n";

    if (($exp['valor'] ?? null) === 'vacio') {
        $ok = count($borradores) === 0;
        echo '   '.($ok ? 'OK   ' : 'FALLA')." se esperaba que no se propusiera ninguna operación\n";
        $fila['sin_operacion_ok'] = $ok;
        $informe[] = $fila;

        continue;
    }

    $b = $borradores[0] ?? null;
    $leido = $b ? [
        'valor' => $b->valor, 'fecha' => $b->fecha, 'hora' => $b->hora, 'referencia' => $b->referencia, 'autorizacion' => $b->autorizacion,
        'medio' => $b->medio, 'banco_origen' => $b->bancoOrigen, 'banco_destino' => $b->bancoDestino, 'remitente' => $b->remitente, 'contraparte' => $b->contraparte,
    ] : array_fill_keys($campos, null);

    foreach ($campos as $c) {
        if (! array_key_exists($c, $exp)) {
            continue;
        }

        $total[$c]++;
        $bien = $norm($leido[$c]) === $norm($exp[$c]);
        $bien && $aciertos[$c]++;
        $fila['campos'][$c] = ['esperado' => $exp[$c], 'leido' => $leido[$c], 'ok' => $bien];
        echo sprintf("   %s %-13s esperado=%-32s leido=%s\n", $bien ? 'OK   ' : 'FALLA', $c, json_encode($exp[$c], JSON_UNESCAPED_UNICODE), json_encode($leido[$c], JSON_UNESCAPED_UNICODE));
    }

    if ($b && $b->advertencias) {
        echo '   avisos: '.implode(' | ', $b->advertencias)."\n";
        $fila['advertencias'] = $b->advertencias;
    }

    $informe[] = $fila;
}

echo "\nResumen por campo (aciertos/esperados):\n";

foreach ($campos as $c) {
    if ($total[$c]) {
        echo sprintf("  %-13s %d/%d\n", $c, $aciertos[$c], $total[$c]);
    }
}

if (! empty($argv[3])) {
    file_put_contents($argv[3], json_encode(['lector' => $lector->nombre(), 'archivos' => $informe, 'aciertos' => $aciertos, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
