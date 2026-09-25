<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\DataObjects\AnalisisPedidoHistorico;
use ByLopez\VentasAsesor\Enums\ResultadoReconciliacion;
use ByLopez\VentasAsesor\Services\ReconciliadorAtribucionesService;
use Illuminate\Console\Command;

/**
 * Reconciliacion de atribuciones de pedidos existentes (02B1B).
 *
 * MODO POR DEFECTO: dry-run, que no escribe NADA (ni siquiera llama a los servicios
 * de escritura). Escribir exige --persistir de forma explicita. No existe --force:
 * el comando nunca sobrescribe una atribucion existente.
 */
class ReconciliarAtribucionesCommand extends Command
{
    protected $signature = 'ventas-asesor:reconciliar-atribuciones
        {--dry-run : Solo analiza y reporta; no escribe nada (es el modo por defecto)}
        {--persistir : Registra lo reconciliable y lo no determinable. Sin esta opcion no se escribe}
        {--order= : Analiza solo el pedido con este id}
        {--limite= : Analiza como maximo N pedidos}';

    protected $description = 'Analiza (dry-run) o reconcilia las atribuciones comerciales de pedidos anteriores al listener, sin inventar historia';

    public function handle(ReconciliadorAtribucionesService $servicio): int
    {
        if ($this->option('dry-run') && $this->option('persistir')) {
            $this->error('--dry-run y --persistir son excluyentes.');

            return self::INVALID;
        }

        $persistir = (bool) $this->option('persistir');
        $orderId = $this->enteroOpcional('order');
        $limite = $this->enteroOpcional('limite');

        if ($orderId === false || $limite === false) {
            $this->error('--order y --limite exigen un entero positivo.');

            return self::INVALID;
        }

        $this->newLine();
        $this->line($persistir
            ? '<fg=yellow>MODO PERSISTENTE — se registraran atribuciones e incidencias</>'
            : '<fg=cyan;options=bold>MODO DRY-RUN — NO SE REALIZARON ESCRITURAS</>');
        $this->newLine();

        $conteo = array_fill_keys(array_column(ResultadoReconciliacion::cases(), 'value'), 0);
        $analizados = 0;
        $filas = [];

        foreach ($servicio->ejecutar($persistir, $orderId, $limite) as $analisis) {
            $analizados++;
            $conteo[$analisis->resultado->value]++;

            if ($analisis->resultado !== ResultadoReconciliacion::YA_ATRIBUIDO) {
                $filas[] = $this->fila($analisis, $persistir);
            }
        }

        if ($filas !== []) {
            $this->table(
                ['order_id', 'increment_id', 'cart_id', 'estado pedido', 'resultado', 'razon', $persistir ? 'accion' : 'que haria el modo persistente'],
                $filas,
            );
        }

        $reconciliables = $conteo['reconciliable'] + $conteo['reconciliado'];
        $this->line(sprintf('Pedidos analizados:            %d', $analizados));
        $this->line(sprintf('Ya atribuidos:                %d', $conteo['ya_atribuido']));
        $this->line(sprintf('Reconciliables:               %d', $reconciliables));
        $this->line(sprintf('Reconciliados:                %d', $conteo['reconciliado']));
        $this->line(sprintf('Sin evidencia suficiente:     %d', $conteo['sin_evidencia']));
        $this->line(sprintf('Fallos tecnicos:              %d', $conteo['fallo_tecnico']));
        $this->line(sprintf('Conflictos:                   %d', $conteo['conflicto']));
        $this->line(sprintf('Errores:                      %d', $conteo['error']));
        $this->newLine();

        if (! $persistir) {
            $this->line('MODO DRY-RUN — NO SE REALIZARON ESCRITURAS');
        }

        return $conteo['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return int|false|null null = ausente, false = invalido */
    protected function enteroOpcional(string $nombre): int|false|null
    {
        $valor = $this->option($nombre);

        if ($valor === null) {
            return null;
        }

        return ctype_digit((string) $valor) && (int) $valor > 0 ? (int) $valor : false;
    }

    /** @return list<string|int|null> */
    protected function fila(AnalisisPedidoHistorico $a, bool $persistir): array
    {
        return [
            $a->orderId,
            $a->incrementId,
            $a->cartId,
            $a->estadoPedido,
            $a->resultado->value,
            $a->codigo,
            $a->accionPersistente,
        ];
    }
}
