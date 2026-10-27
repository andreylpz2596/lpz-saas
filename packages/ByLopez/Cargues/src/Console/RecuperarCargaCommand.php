<?php

namespace ByLopez\Cargues\Console;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use Illuminate\Console\Command;

/**
 * Recupera una ejecucion interrumpida (07 §8.4): `php artisan cargues:recuperar {carga}`. No clasifica nada mientras el
 * bloqueo con nombre siga tomado o la sesion ejecutora siga abierta; con el servidor libre decide por las marcas
 * confirmadas (consolida) o determina que se revirtio (la carga queda lista para ejecutar de nuevo).
 */
class RecuperarCargaCommand extends Command
{
    protected $signature = 'cargues:recuperar {carga : Id de la carga}';

    protected $description = 'Recupera una ejecucion de Cargues interrumpida (consolida o marca que no se aplico nada)';

    public function handle(CargasService $cargas): int
    {
        $carga = Carga::find((int) $this->argument('carga'));

        if (! $carga) {
            $this->error('No existe la carga.');

            return self::FAILURE;
        }

        try {
            $c = $cargas->recuperar($carga);
        } catch (OperacionInvalidaException $e) {
            $this->warn("[{$e->codigo}] {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Carga {$c->id}: {$c->estado}".($c->mensaje ? " — {$c->mensaje}" : ''));

        return self::SUCCESS;
    }
}
