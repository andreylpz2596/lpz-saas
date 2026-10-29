<?php

/*
| Verifica la CONFIGURACION EFECTIVA de la demo (la que ve cualquier proceso lanzado con demo.env):
|   php verificar_entorno.php --solo-seguridad   -> solo base/cache (lo usa demo.sh antes de arrancar nada)
|   php verificar_entorno.php --http             -> ademas, comprueba que el SERVIDOR en marcha atiende esa misma base
| No imprime credenciales.
*/

use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fallos = [];

try {
    $base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOQUEADO: no se pudo conectar a la base configurada: '.substr($e->getMessage(), 0, 120)."\n");
    exit(2);
}

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "BLOQUEADO: la conexion efectiva apunta a '{$base}', no a una base lpz_saas_demo_*.\n");
    exit(2);
}

if (file_exists($raiz.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "BLOQUEADO: config cacheada: ignora las variables del proceso.\n");
    exit(2);
}

if (($argv[1] ?? '') === '--solo-seguridad') {
    exit(0);
}

$esperado = [
    'database.connections.mysql.database' => getenv('DB_DATABASE'),
    'mail.default' => 'log',
    'queue.default' => getenv('QUEUE_CONNECTION') ?: 'sync',
    'ventasasesor.comisiones.fecha_inicio' => getenv('VENTASASESOR_COMISIONES_FECHA_INICIO'),
    'logistica.cuentas.recaudo_desde_cobro_desde' => getenv('LOGISTICA_RECAUDO_DESDE_COBRO'),
];

foreach ($esperado as $clave => $valor) {
    $real = config($clave);
    $ok = (string) $real === (string) $valor;
    echo sprintf("%-46s %-28s %s\n", $clave, var_export($real, true), $ok ? 'OK' : 'DIFIERE (esperado '.var_export($valor, true).')');
    $ok || $fallos[] = $clave;
}

echo sprintf("%-46s %s\n", 'config cacheada', 'no');
echo sprintf("%-46s %s\n", 'usuarios demo en la base', DB::table('admins')->where('email', 'like', 'demo.%@bylopez.test')->count());

if (in_array('--http', $argv, true)) {
    $url = 'http://'.getenv('DEMO_HOST').':'.getenv('DEMO_PORT');
    $http = new Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 30, 'cookies' => true, 'allow_redirects' => false]);
    $clave = (string) getenv('DEMO_PASSWORD');
    $r = $http->get('/admin/login');
    echo 'HTTP /admin/login: '.$r->getStatusCode()."\n";
    $r->getStatusCode() === 200 || $fallos[] = 'http_responde';

    if (strlen($clave) < 10) {
        echo "HTTP: sin DEMO_PASSWORD no se comprueba QUE BASE atiende el servidor (solo que responde)\n";
    } else {
        preg_match('/name="_token" value="([^"]+)"/', (string) $r->getBody(), $m);
        $r = $http->post('/admin/login', ['form_params' => ['_token' => $m[1] ?? '', 'email' => 'demo.admin@bylopez.test', 'password' => $clave]]);
        $ok = $r->getStatusCode() === 302 && ! str_contains($r->getHeaderLine('Location'), 'login');
        echo 'HTTP el servidor autentica a demo.admin (existe solo en la base demo): '.($ok ? 'OK' : 'FALLA ('.$r->getStatusCode().')')."\n";
        $ok || $fallos[] = 'http_login';
    }
}

// --- Configuracion EFECTIVA de cada proceso en marcha, informada desde dentro de el (no inferida por un login) ---
$propia = $base;
$compara = function (string $nombre, array $e) use (&$fallos, $propia) {
    $problemas = [];
    $e['base_consultada'] === $propia || $problemas[] = 'base '.$e['base_consultada'].' != '.$propia;
    $e['base_configurada'] === $propia || $problemas[] = 'base configurada '.$e['base_configurada'];
    $e['config_cacheada'] === false || $problemas[] = 'config cacheada';
    $e['cache'] === getenv('CACHE_STORE') || $problemas[] = 'cache '.$e['cache'];
    $e['cola'] === (getenv('QUEUE_CONNECTION') ?: 'sync') || $problemas[] = 'cola '.$e['cola'];
    $e['correo'] === 'log' || $problemas[] = 'correo '.$e['correo'];
    (string) $e['comisiones_desde'] === (string) getenv('VENTASASESOR_COMISIONES_FECHA_INICIO') || $problemas[] = 'comisiones_desde '.$e['comisiones_desde'];
    (string) $e['c6_desde'] === (string) getenv('LOGISTICA_RECAUDO_DESDE_COBRO') || $problemas[] = 'c6_desde '.$e['c6_desde'];
    echo sprintf('%-10s pid %-7s base consultada %-28s cache %-6s cola %-5s %s
', $nombre, $e['pid'], $e['base_consultada'], $e['cache'], $e['cola'], $problemas === [] ? 'OK' : 'DIFIERE: '.implode('; ', $problemas));
    $problemas === [] || $fallos[] = 'proceso_'.$nombre;
};

if (in_array('--http', $argv, true) || in_array('--procesos', $argv, true)) {
    $url = 'http://'.getenv('DEMO_HOST').':'.getenv('DEMO_PORT');
    $h = new Client(['base_uri' => $url, 'http_errors' => false, 'timeout' => 20]);

    try {
        $r = $h->get('/__demo/entorno');
        $j = $r->getStatusCode() === 200 ? json_decode((string) $r->getBody(), true) : null;
    } catch (Throwable) {
        $j = null;
    }

    $j ? $compara('servidor', $j) : print 'servidor   no responde en /__demo/entorno (¿detenido, o se arranco sin router_demo.php?)
';

    foreach (['scheduler', 'worker'] as $n) {
        $f = $raiz."/storage/logs/demo/pids/{$n}.entorno.json";
        $pid = $raiz."/storage/logs/demo/pids/{$n}.pid";

        if (is_file($f) && is_file($pid)) {
            $compara($n, json_decode((string) file_get_contents($f), true));
        } else {
            echo sprintf('%-10s sin registro de entorno (no esta corriendo o se lanzo sin proceso_demo.php)
', $n);
        }
    }

    if (in_array('--procesos', $argv, true)) {
        exit($fallos === [] ? 0 : 1);
    }
}

echo $fallos === [] ? "ENTORNO OK\n" : 'ENTORNO CON DIFERENCIAS: '.implode(', ', $fallos)."\n";
exit($fallos === [] ? 0 : 1);
