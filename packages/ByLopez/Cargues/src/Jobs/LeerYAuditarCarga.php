<?php

namespace ByLopez\Cargues\Jobs;

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\AuditoriaService;
use ByLopez\Cargues\Support\Estados;

/** P5 + P6: lee el archivo, guarda el original por fila en T6, normaliza y valida con las reglas del motor. */
class LeerYAuditarCarga extends ProcesoCargaJob
{
    protected function estadoDeFallo(): string
    {
        return Estados::FALLIDA_LECTURA;
    }

    public function handle(AuditoriaService $auditoria): void
    {
        // CAS de arranque: solo el job dueno de la carga y solo desde `en_cola` (una entrega duplicada sale aqui).
        $n = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->where('estado', Estados::EN_COLA)->update([
            'estado' => Estados::LEYENDO, 'etapa' => 'leyendo', 'iniciada_en' => now(), 'progreso_actualizado_en' => now(), 'progreso_mensaje' => null,
        ]);

        if ($n === 0) {
            return;
        }

        $this->protegido(function () use ($auditoria) {
            $carga = Carga::findOrFail($this->cargaId);
            $r = $auditoria->procesar($carga, $this->jobUuid);

            $paso = Carga::where('id', $this->cargaId)->where('job_uuid', $this->jobUuid)->where('estado', Estados::LEYENDO)->update([
                'estado' => Estados::PREPARANDO, 'etapa' => 'en_cola_2', 'filas_total' => $r['total'], 'filas_procesadas' => $r['total'],
                'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Archivo auditado; preparando la validación',
            ]);

            if ($paso === 1) {
                PrepararYValidarCarga::dispatch($this->cargaId, $this->jobUuid);
            }
        });
    }
}
