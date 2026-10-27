<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Services\Comisiones\DiagnosticoCostosVentaService;
use Illuminate\Console\Command;

/**
 * COM-01 — estado de los snapshots de costo. SOLO LECTURA: no escribe, no rellena historicos (COM-D-13).
 */
class DiagnosticoCostosVentaCommand extends Command
{
    protected $signature = 'ventas-asesor:diagnostico-costos';

    protected $description = 'Muestra (solo lectura) cuantos pedidos y lineas tienen snapshot de costo, cuantas sin costo y con que fuente';

    public function handle(DiagnosticoCostosVentaService $diagnostico): int
    {
        $r = $diagnostico->resumen();

        $this->newLine();
        $this->line('<fg=cyan;options=bold>SOLO LECTURA — snapshots de costo (COM-01)</>');
        $this->line('Costo CAPTURADO ≠ costo AUTORITATIVO: ninguna fila sin autoridad sirve para causar o pagar comisiones.');
        $this->newLine();

        $this->line(sprintf('Pedidos totales:                   %d', $r['pedidos_totales']));
        $this->line(sprintf('Pedidos con snapshot:              %d', $r['pedidos_con_snapshot']));
        $this->line(sprintf('Lineas con snapshot:               %d', $r['lineas_con_snapshot']));
        $this->line(sprintf('Lineas sin costo:                  %d', $r['lineas_sin_costo']));
        $this->line(sprintf('Lineas con autoridad de comision:  %d', $r['lineas_con_autoridad']));
        $this->line(sprintf('Primer snapshot (activacion):      %s', $r['activacion'] ?? '—'));
        $this->newLine();

        if ($r['por_fuente'] !== []) {
            $this->table(['fuente', 'lineas'], collect($r['por_fuente'])->map(fn ($n, $f) => [$f, $n])->values()->all());
        }

        if ($r['por_referencia_sin_costo'] !== []) {
            $this->table(['motivo sin costo', 'lineas'], collect($r['por_referencia_sin_costo'])->map(fn ($n, $f) => [$f, $n])->values()->all());
        }

        if ($r['pedidos_sin_snapshot_desde_activacion'] !== []) {
            $this->warn('Pedidos creados desde la activacion SIN snapshot (revisar el log ventas_asesor.costo_venta.no_capturado): '
                .implode(', ', $r['pedidos_sin_snapshot_desde_activacion']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
