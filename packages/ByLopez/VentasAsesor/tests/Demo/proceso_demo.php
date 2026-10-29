<?php

/*
| Lanza un comando artisan de larga duracion (schedule:work, queue:work) DENTRO de un proceso que, antes de empezar, registra su configuracion
| EFECTIVA en storage/logs/demo/pids/<nombre>.entorno.json (base real consultada, caché, cola...). Asi `demo.sh estado/verificar` compara lo que cada
| proceso usa de verdad con demo.env. Uso: php proceso_demo.php <nombre> <comando artisan> [opciones...]
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__.'/router_demo_funciones.php';

$raiz = dirname(__DIR__, 5);
chdir($raiz);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[$nombre, $comando] = [$argv[1] ?? 'proceso', $argv[2] ?? null];

if ($comando === null) {
    fwrite(STDERR, "Uso: proceso_demo.php <nombre> <comando> [opciones]\n");
    exit(1);
}

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "BLOQUEADO: el proceso {$nombre} apunta a '{$base}'.\n");
    exit(2);
}

$dir = $raiz.'/storage/logs/demo/pids';
@mkdir($dir, 0775, true);
file_put_contents("{$dir}/{$nombre}.entorno.json", json_encode(entorno_efectivo($nombre, $raiz), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

exit($kernel->handle(new ArgvInput(array_merge(['artisan'], array_slice($argv, 2))), new ConsoleOutput));
