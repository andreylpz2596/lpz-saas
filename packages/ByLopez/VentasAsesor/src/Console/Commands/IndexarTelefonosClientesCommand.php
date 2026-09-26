<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Enums\ResultadoIndexacionTelefono as Resultado;
use ByLopez\VentasAsesor\Services\Clientes\IndexadorTelefonosClientesService;
use Illuminate\Console\Command;

/**
 * Indexacion historica de customers.phone (RT-04B1a).
 *
 * MODO POR DEFECTO: dry-run, que no escribe NADA. Escribir exige --persistir. Nunca
 * modifica customers.phone; solo el indice bylopez_va_customer_phones. Los telefonos
 * se muestran enmascarados.
 */
class IndexarTelefonosClientesCommand extends Command
{
    protected $signature = 'ventas-asesor:indexar-telefonos
        {--dry-run : Solo analiza y reporta; no escribe nada (es el modo por defecto)}
        {--persistir : Escribe el indice. Sin esta opcion no se escribe}
        {--customer= : Analiza solo el cliente con este id}
        {--limite= : Analiza como maximo N clientes con telefono}';

    protected $description = 'Analiza (dry-run) o indexa el telefono de la ficha de los clientes como telefono principal comercial, sin modificar customers.phone';

    public function handle(IndexadorTelefonosClientesService $indexador): int
    {
        if ($this->option('dry-run') && $this->option('persistir')) {
            $this->error('--dry-run y --persistir son excluyentes.');

            return self::INVALID;
        }

        $persistir = (bool) $this->option('persistir');
        $customerId = $this->enteroOpcional('customer');
        $limite = $this->enteroOpcional('limite');

        if ($customerId === false || $limite === false) {
            $this->error('--customer y --limite exigen un entero positivo.');

            return self::INVALID;
        }

        $this->newLine();
        $this->line($persistir
            ? '<fg=yellow>MODO PERSISTENTE — se escribira el indice de telefonos (customers.phone no se modifica)</>'
            : '<fg=cyan;options=bold>MODO DRY-RUN — NO SE REALIZARON ESCRITURAS</>');
        $this->newLine();

        $conteo = array_fill_keys(array_column(Resultado::cases(), 'value'), 0);
        $filas = [];

        foreach ($indexador->ejecutar($persistir, $customerId, $limite) as $analisis) {
            $conteo[$analisis->resultado->value]++;

            if ($analisis->resultado !== Resultado::YA_INDEXADO) {
                $filas[] = [
                    $analisis->customerId,
                    $analisis->telefonoEnmascarado,
                    $analisis->resultado->value,
                    $analisis->motivo ?? '—',
                    $analisis->customerIdRelacionado ?? '—',
                ];
            }
        }

        if ($filas !== []) {
            $this->table(['customer_id', 'telefono', 'resultado', 'motivo', 'otro customer_id'], $filas);
        }

        $this->line(sprintf('Normalizables (indexables/indexados): %d', $conteo['indexable'] + $conteo['indexado']));
        $this->line(sprintf('Ya indexados:                   %d', $conteo['ya_indexado']));
        $this->line(sprintf('Invalidos:                      %d', $conteo['invalido']));
        $this->line(sprintf('Ambiguos:                       %d', $conteo['ambiguo']));
        $this->line(sprintf('Duplicados entre fichas:        %d', $conteo['duplicado']));
        $this->line(sprintf('Conflictos:                     %d', $conteo['conflicto']));
        $this->line(sprintf('Divergentes / reindexados:      %d / %d', $conteo['divergente'], $conteo['reindexado']));
        $this->line(sprintf('Errores:                        %d', $conteo['error']));
        $this->newLine();

        if (! $persistir) {
            $this->line('<fg=cyan>Dry-run: no se escribio nada. Use --persistir para escribir el indice.</>');
        }

        return $conteo['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** null si no se envio; false si no es un entero positivo. */
    private function enteroOpcional(string $opcion): int|false|null
    {
        $valor = $this->option($opcion);

        if ($valor === null) {
            return null;
        }

        return ctype_digit((string) $valor) && (int) $valor > 0 ? (int) $valor : false;
    }
}
