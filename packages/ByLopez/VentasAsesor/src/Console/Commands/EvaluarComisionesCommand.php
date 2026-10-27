<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use Illuminate\Console\Command;

/**
 * R3 — conciliacion IDEMPOTENTE de comisiones (Doc. 23): sincroniza lineas y libro del umbral, habilita lo que cumple A-04 y
 * deshabilita lo que dejo de cumplir. Repetirlo no duplica nada. No crea lineas para pedidos anteriores a esta version
 * (sin snapshot de rentabilidad) y no habilita nada sin VENTASASESOR_COMISIONES_FECHA_INICIO.
 *
 *  --bandeja   procesa la bandeja automatica (lo marcado por los hechos y sus reintentos vencidos). Es lo que corre el
 *              programador cada 5 minutos (Doc. 23 §10.2).
 *  --pedido=ID un solo pedido.
 *  (sin opciones) recuperacion manual completa: todos los pedidos con lineas vivas.
 */
class EvaluarComisionesCommand extends Command
{
    protected $signature = 'ventas-asesor:comisiones:evaluar {--pedido= : Solo este order_id} {--bandeja : Procesa la bandeja automatica} {--limite=200 : Maximo de pedidos por corrida de la bandeja}';

    protected $description = 'Reevalua (idempotente) las comisiones pendientes, la bandeja automatica o un pedido';

    public function handle(EvaluarComisionesService $servicio, ReevaluacionComisionesService $bandeja): int
    {
        if ($this->option('bandeja')) {
            $r = $bandeja->procesar(null, max(1, (int) $this->option('limite')));
            $this->info("Evaluados {$r['evaluados']} · fallidos {$r['fallidos']} · cascada {$r['cascada']} · pendientes {$r['pendientes']}");

            return self::SUCCESS;
        }

        $pedido = $this->option('pedido');
        $resultados = $pedido !== null && ctype_digit((string) $pedido)
            ? [(int) $pedido => $servicio->evaluarPedido((int) $pedido)]
            : $servicio->evaluarPendientes();

        $this->table(['pedido', 'lineas', 'habilitadas', 'deshabilitadas', 'incidencias', 'ajustes propuestos', 'pendiente por'], collect($resultados)->map(fn ($r, $id) => [
            $id, $r['lineas'], $r['habilitadas'], $r['deshabilitadas'], $r['incidencias'], $r['ajustes'], implode(', ', $r['motivos']) ?: '—',
        ])->values()->all());

        return self::SUCCESS;
    }
}
