<?php

/*
| Arma el ZIP de evidencia de la jornada (sin secretos, sin bases, sin cookies, sin comprobantes privados) a partir de lo que dejaron las
| corridas en storage/logs y de los archivos versionados. Uso:
|
|   php packages/ByLopez/VentasAsesor/tests/Demo/empaquetar_evidencia.php <carpeta_destino> [<manifiesto.json>]
|
| El manifiesto (JSON) lo escribe quien entrega: lista de {zip, origen, caso, esperado, resultado}. El INDICE.md del ZIP se genera desde el.
| Se rechaza cualquier archivo cuyo contenido (texto) incluya la clave de la demo, una cookie de sesion o el contenido de un .env.
*/

$raiz = dirname(__DIR__, 5);
$destino = $argv[1] ?? null;
$manifiesto = $argv[2] ?? null;

if (! $destino || ! $manifiesto || ! is_file($manifiesto)) {
    fwrite(STDERR, "Uso: empaquetar_evidencia.php <carpeta_destino> <manifiesto.json>\n");
    exit(1);
}

$items = json_decode((string) file_get_contents($manifiesto), true, 512, JSON_THROW_ON_ERROR);
@mkdir($destino, 0777, true);
$zipRuta = rtrim($destino, '/\\').DIRECTORY_SEPARATOR.'ByLopez_evidencia_jornada_'.date('Ymd').'.zip';
@unlink($zipRuta);

$zip = new ZipArchive;
$zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

// Secretos que NUNCA deben entrar: la clave de la demo y la de la cuenta restringida (si existen en el entorno o en sus archivos locales).
$secretos = array_filter([
    getenv('DEMO_PASSWORD') ?: null,
    is_file(getenv('DEMO_CLAVE_ARCHIVO') ?: '') ? trim((string) file_get_contents(getenv('DEMO_CLAVE_ARCHIVO'))) : null,
]);
$cuenta = $raiz.'/storage/logs/demo/cuenta_cargues.env';

if (is_file($cuenta) && preg_match('/PASSWORD=(\S+)/', (string) file_get_contents($cuenta), $m)) {
    $secretos[] = $m[1];
}

if (preg_match('/^DB_PASSWORD=(\S+)/m', (string) @file_get_contents($raiz.'/.env'), $m) && strlen($m[1]) >= 6) {
    $secretos[] = $m[1];
}

$patrones = ['/laravel_session=/i', '/bylopez_demo_session=/i', '/XSRF-TOKEN=/i', '/^\s*(APP_KEY|DB_PASSWORD|MAIL_PASSWORD)=/m'];
$rechazados = [];
$filas = [];

foreach ($items as $it) {
    $origen = str_starts_with($it['origen'], '/') || preg_match('#^[A-Za-z]:#', $it['origen']) ? $it['origen'] : $raiz.'/'.$it['origen'];
    $archivos = is_dir($origen) ? array_filter(glob(rtrim($origen, '/').'/*') ?: [], 'is_file') : (glob($origen) ?: []);

    if ($archivos === []) {
        $rechazados[] = "SIN ORIGEN: {$it['origen']} ({$it['zip']})";

        continue;
    }

    foreach ($archivos as $a) {
        $dentro = rtrim($it['zip'], '/').(count($archivos) > 1 || is_dir($origen) || str_ends_with($it['zip'], '/') ? '/'.basename($a) : '');
        $dentro = ltrim($dentro, '/');
        $binario = (bool) preg_match('/\.(png|jpg|jpeg|zip|xlsx)$/i', $a);

        if (! $binario && basename($a) !== 'empaquetar_evidencia.php') {
            $texto = (string) file_get_contents($a);

            foreach ($secretos as $s) {
                if (str_contains($texto, $s)) {
                    $rechazados[] = "SECRETO en {$a}";

                    continue 2;
                }
            }

            foreach ($patrones as $p) {
                if (preg_match($p, $texto)) {
                    $rechazados[] = "PATRON {$p} en {$a}";

                    continue 2;
                }
            }
        }

        $zip->addFile($a, $dentro);
        $filas[] = ['zip' => $dentro, 'caso' => $it['caso'], 'esperado' => $it['esperado'] ?? '', 'resultado' => $it['resultado'] ?? ''];
    }
}

$md = "# Índice de la evidencia\n\nJornada ByLopez F3–F6 + demo (cierre de las cinco tareas). Cada fila relaciona un archivo con el caso, lo esperado y el resultado observado.\nNo contiene `.env`, claves, cookies, sesiones, bases de datos ni comprobantes privados; todos los datos son ficticios (prefijo DEMO / `@bylopez.test`).\n\n| Archivo | Caso | Esperado | Resultado |\n|---|---|---|---|\n";

foreach ($filas as $f) {
    $md .= sprintf("| `%s` | %s | %s | %s |\n", $f['zip'], $f['caso'], str_replace('|', '/', $f['esperado']), str_replace('|', '/', $f['resultado']));
}

$zip->addFromString('INDICE.md', $md);
$zip->close();

echo "ZIP: {$zipRuta} (".count($filas).' archivos, '.round(filesize($zipRuta) / 1024)." KB)\n";

foreach ($rechazados as $r) {
    echo "  EXCLUIDO: {$r}\n";
}

exit($rechazados === [] ? 0 : 3);
