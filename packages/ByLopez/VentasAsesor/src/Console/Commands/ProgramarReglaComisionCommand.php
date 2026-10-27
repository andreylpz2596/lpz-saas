<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Exceptions\ReglaComisionInvalidaException;
use ByLopez\VentasAsesor\Services\Comisiones\ReglasComisionService;
use Illuminate\Console\Command;

/**
 * COM-03 — programa una regla de comision de forma EXPLICITA y auditada (mecanismo `cli`). Es la unica via para
 * crear el porcentaje: ningun servicio tiene un valor por defecto. Sin --persistir solo muestra lo que haria.
 */
class ProgramarReglaComisionCommand extends Command
{
    protected $signature = 'ventas-asesor:comisiones-programar-regla
        {--codigo= : Codigo unico de la regla (p. ej. COM-GLOBAL-2026-10)}
        {--nombre= : Nombre visible}
        {--porcentaje= : Porcentaje sobre la utilidad positiva, en puntos (20 = 20 %)}
        {--desde= : Inicio de vigencia (por defecto, ahora). Nunca en el pasado}
        {--motivo= : Motivo o decision que la respalda}
        {--persistir : Crea la regla. Sin esta opcion no se escribe nada}';

    protected $description = 'Programa (con --persistir) una regla de comision global con vigencia; cierra la regla abierta anterior sin cambiar su porcentaje';

    public function handle(ReglasComisionService $reglas): int
    {
        $datos = [
            'codigo' => trim((string) $this->option('codigo')),
            'nombre' => trim((string) $this->option('nombre')),
            'porcentaje' => (string) $this->option('porcentaje'),
            'vigente_desde' => $this->option('desde'),
            'motivo' => $this->option('motivo'),
        ];

        if ($datos['codigo'] === '' || $datos['nombre'] === '' || $datos['porcentaje'] === '') {
            $this->error('--codigo, --nombre y --porcentaje son obligatorios.');

            return self::INVALID;
        }

        $this->table(['codigo', 'nombre', 'porcentaje', 'desde', 'motivo'], [[
            $datos['codigo'], $datos['nombre'], $datos['porcentaje'].' %', $datos['vigente_desde'] ?? 'ahora', $datos['motivo'] ?? '—',
        ]]);

        if (! $this->option('persistir')) {
            $this->line('<fg=cyan>Sin --persistir: no se escribio nada.</>');

            return self::SUCCESS;
        }

        try {
            $regla = $reglas->programar($datos, null, 'cli');
        } catch (ReglaComisionInvalidaException $e) {
            $this->error("[{$e->codigo}] {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Regla #%d creada: %s %% desde %s.', $regla->id, $regla->porcentaje, $regla->vigente_desde->toDateTimeString()));

        return self::SUCCESS;
    }
}
