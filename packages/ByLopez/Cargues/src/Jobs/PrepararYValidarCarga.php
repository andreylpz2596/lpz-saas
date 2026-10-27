<?php

namespace ByLopez\Cargues\Jobs;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\LlenadoIntermediaService;
use ByLopez\Cargues\Services\Cargas\ProgresoService;
use ByLopez\Cargues\Services\Cargas\ResumenService;
use ByLopez\Cargues\Services\Cargas\ValidarProcedimientoService;
use ByLopez\Cargues\Support\Estados;

/** P7 + P8: llena la intermedia con las filas aceptadas y ejecuta el procedimiento en modo VALIDAR. No toca negocio. */
class PrepararYValidarCarga extends ProcesoCargaJob
{
    /** Si el job murio durante el CALL, el fallo es de VALIDACION; antes, de PREPARACION. */
    protected function estadoDeFallo(): string
    {
        $c = Carga::find($this->cargaId);

        return $c && $c->estado === Estados::VALIDANDO ? Estados::FALLIDA_VALIDACION : Estados::FALLIDA_PREPARACION;
    }

    public function handle(LlenadoIntermediaService $llenado, ValidarProcedimientoService $validar, ResumenService $resumen, ProgresoService $progreso): void
    {
        $n = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->where('estado', Estados::PREPARANDO)->where('etapa', 'en_cola_2')->update([
            'etapa' => 'llenando_intermedia', 'filas_procesadas' => 0, 'progreso_actualizado_en' => now(), 'progreso_mensaje' => null,
        ]);

        if ($n === 0) {
            return;
        }

        $this->protegido(function () use ($llenado, $validar, $resumen) {
            $carga = Carga::findOrFail($this->cargaId);
            $r = $llenado->llenar($carga, $this->jobUuid);

            Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->update(['huella_entrada' => $r['huella_entrada']]);
            $carga->refresh();

            // A partir de aqui un fallo es de VALIDACION (el llenado ya termino).
            $paso = Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->where('estado', Estados::PREPARANDO)->update([
                'estado' => Estados::VALIDANDO, 'etapa' => 'procedimiento', 'filas_procesadas' => 0, 'progreso_actualizado_en' => now(),
            ]);

            if ($paso === 0) {
                throw new OperacionInvalidaException('JOB_SUPERADO', 'Otro proceso tomó la carga.');
            }

            try {
                $out = $validar->validar($carga, $this->jobUuid);
            } catch (OperacionInvalidaException $e) {
                if ($e->codigo === 'JOB_SUPERADO') {
                    throw $e;
                }

                Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->update([
                    'estado' => Estados::FALLIDA_VALIDACION, 'mensaje' => '['.$e->codigo.'] '.$e->getMessage(), 'finalizada_en' => now(),
                    'resultado' => json_encode(['error' => ['codigo' => $e->codigo, 'detalles' => $e->detalles]], JSON_UNESCAPED_UNICODE),
                ]);

                return;
            }

            $carga->refresh();
            $contadores = $resumen->calcular($carga);

            Carga::where('id', $carga->id)->where('job_uuid', $this->jobUuid)->update([
                'estado' => Estados::VALIDADA, 'etapa' => 'finalizado', 'contadores' => json_encode($contadores, JSON_UNESCAPED_UNICODE),
                // Al terminar, el progreso refleja el archivo entero (las etapas intermedias llevan su propio total).
                'filas_total' => $contadores['filas_total'], 'filas_procesadas' => $contadores['filas_total'],
                'resultado' => json_encode(['procedimiento' => $out, 'intermedia' => ['insertadas' => $r['insertadas'], 'rechazadas_bd' => $r['rechazadas_bd']]], JSON_UNESCAPED_UNICODE),
                'progreso_mensaje' => 'Validación terminada', 'progreso_actualizado_en' => now(), 'finalizada_en' => now(),
            ]);
        });
    }
}
