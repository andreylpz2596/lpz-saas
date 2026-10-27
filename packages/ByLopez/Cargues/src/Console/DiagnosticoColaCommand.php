<?php

namespace ByLopez\Cargues\Console;

use ByLopez\Cargues\Jobs\PingCola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifica la cola DEDICADA de Cargues y comprueba que hay un worker vivo:
 *   php artisan cargues:diagnostico            (espera hasta 15 s a que un worker procese un job de prueba)
 *   php artisan cargues:diagnostico --sin-ping (solo configuracion y estado de la cola)
 *
 * El worker se inicia aparte (ver docs/11_...): `php -d memory_limit=512M artisan queue:work cargues --queue=cargues
 * --tries=1 --timeout=900 --memory=512 --sleep=3`.
 */
class DiagnosticoColaCommand extends Command
{
    protected $signature = 'cargues:diagnostico {--esperar=15 : Segundos de espera al worker} {--sin-ping : No enviar el job de prueba}';

    protected $description = 'Diagnostico de la cola dedicada de Cargues y del worker';

    public function handle(): int
    {
        $ok = true;
        $conexion = (string) config('cargues.cola.conexion');
        $cola = (string) config('cargues.cola.nombre');
        $cfg = (array) config("queue.connections.{$conexion}");
        $timeout = (int) config('cargues.cola.timeout');

        $this->line('<info>Configuración</info>');
        $this->line("  Conexión de cola de Cargues: {$conexion} (driver ".($cfg['driver'] ?? '?').", cola «{$cola}»)");
        $this->line('  Cola por defecto de la aplicación (no se modifica): '.config('queue.default'));
        $this->line("  Timeout del job: {$timeout} s · retry_after: ".($cfg['retry_after'] ?? '?').' s');

        if (($cfg['driver'] ?? null) !== 'database') {
            $this->warn('  El driver no es «database»; el diagnóstico de la tabla jobs no aplica.');
        }

        if ((int) ($cfg['retry_after'] ?? 0) <= $timeout) {
            $this->error('  ERROR: retry_after debe ser MAYOR que el timeout del job; si no, un job largo se reentrega a otro worker mientras el primero sigue vivo.');
            $ok = false;
        }

        $mem = ini_get('memory_limit');
        $this->line("  memory_limit de este proceso: {$mem} (el worker debe iniciarse con -d memory_limit=512M)");

        if (($cfg['driver'] ?? null) === 'database') {
            $ok = $this->estadoCola($cfg, $cola) && $ok;
        }

        if ($this->option('sin-ping')) {
            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Worker</info>');

        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            $this->warn('  La cache es «'.config('cache.default').'» (no se comparte entre procesos): no se puede verificar el worker con el job de prueba.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $clave = 'cargues:ping:'.Str::uuid();

        try {
            PingCola::dispatch($clave);
        } catch (Throwable $e) {
            $this->error('  No se pudo encolar el job de prueba: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('  Job de prueba encolado; esperando al worker…');
        $limite = microtime(true) + max(1, (int) $this->option('esperar'));

        while (microtime(true) < $limite) {
            if ($r = Cache::get($clave)) {
                $this->info("  Worker ACTIVO (pid {$r['pid']}, {$r['en']}).");
                Cache::forget($clave);

                return $ok ? self::SUCCESS : self::FAILURE;
            }

            usleep(500_000);
        }

        $this->error('  Ningún worker procesó el job de prueba. ¿Está corriendo `queue:work cargues --queue=cargues`? (el job queda en la cola y se procesará cuando arranque)');

        return self::FAILURE;
    }

    /** Estado de la tabla `jobs` para la cola de Cargues. */
    protected function estadoCola(array $cfg, string $cola): bool
    {
        try {
            $db = DB::connection($cfg['connection'] ?? null);
            $tabla = $cfg['table'] ?? 'jobs';
            $pend = (int) $db->table($tabla)->where('queue', $cola)->whereNull('reserved_at')->count();
            $res = (int) $db->table($tabla)->where('queue', $cola)->whereNotNull('reserved_at')->count();
            $viejo = $db->table($tabla)->where('queue', $cola)->whereNull('reserved_at')->min('available_at');
            $fallidos = (int) $db->table('failed_jobs')->where('queue', $cola)->count();
        } catch (Throwable $e) {
            $this->error('  No se pudo leer la tabla de la cola: '.$e->getMessage());

            return false;
        }

        $this->line("  Jobs en espera: {$pend} · en proceso: {$res} · fallidos registrados: {$fallidos}");

        if ($viejo !== null) {
            $edad = max(0, time() - (int) $viejo);
            $this->line("  El más antiguo en espera lleva {$edad} s".($edad > 60 ? ' <comment>(¿hay un worker corriendo?)</comment>' : ''));
        }

        return true;
    }
}
