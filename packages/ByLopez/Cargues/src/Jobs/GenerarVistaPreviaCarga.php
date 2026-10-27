<?php

namespace ByLopez\Cargues\Jobs;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Support\Estados;

/**
 * Vista previa de un XLSX que no cabe en el proceso web (memoria del request) pero si en el worker. La carga sigue
 * `recibida`; la UI consulta hasta que existan los encabezados.
 */
class GenerarVistaPreviaCarga extends ProcesoCargaJob
{
    protected function estadoDeFallo(): string
    {
        return Estados::RECIBIDA;
    }

    public function handle(CargasService $cargas): void
    {
        $carga = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->where('etapa', 'vista_previa')->first();

        if (! $carga) {
            return;
        }

        try {
            $cargas->prepararVistaPrevia($carga);
        } catch (\Throwable $e) {
            Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->update([
                'etapa' => 'vista_previa_error', 'progreso_mensaje' => null, 'mensaje' => '['.($e instanceof OperacionInvalidaException ? $e->codigo : 'ERROR_INESPERADO').'] '.mb_substr($e->getMessage(), 0, 400),
            ]);

            return;
        }

        Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->update(['job_uuid' => null, 'etapa' => null, 'progreso_mensaje' => null]);
    }

    public function failed(\Throwable $e): void
    {
        Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->where('etapa', 'vista_previa')->update([
            'etapa' => 'vista_previa_error', 'progreso_mensaje' => null, 'mensaje' => 'La vista previa no se pudo generar: '.mb_substr($e->getMessage(), 0, 300),
        ]);
    }
}
