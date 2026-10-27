<?php

namespace ByLopez\Cargues\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Job de comprobacion de `cargues:diagnostico`: si un worker lo toma, deja una marca en la cache compartida. */
class PingCola implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $clave)
    {
        $this->onConnection(config('cargues.cola.conexion'));
        $this->onQueue(config('cargues.cola.nombre'));
    }

    public function handle(): void
    {
        Cache::put($this->clave, ['pid' => getmypid(), 'en' => now()->toIso8601String()], 300);
    }
}
