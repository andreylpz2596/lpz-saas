<?php

use Illuminate\Support\Facades\DB;

/** Configuracion efectiva del proceso actual (sin credenciales). La comparten el servidor, el scheduler y el worker. */
function entorno_efectivo(string $proceso, string $raiz): array
{
    return [
        'proceso' => $proceso,
        'pid' => getmypid(),
        'base_consultada' => (string) DB::selectOne('SELECT DATABASE() AS b')->b,
        'base_configurada' => config('database.connections.'.config('database.default').'.database'),
        'config_cacheada' => file_exists($raiz.'/bootstrap/cache/config.php'),
        'cache' => config('cache.default'),
        'cola' => config('queue.default'),
        'correo' => config('mail.default'),
        'sesion_cookie' => config('session.cookie'),
        'respuesta_cache' => (bool) config('responsecache.enabled', false),
        'comisiones_desde' => config('ventasasesor.comisiones.fecha_inicio'),
        'c6_desde' => config('logistica.cuentas.recaudo_desde_cobro_desde'),
        'debug' => (bool) config('app.debug'),
        'directorio' => getcwd(),
        'iniciado' => date('c'),
    ];
}
