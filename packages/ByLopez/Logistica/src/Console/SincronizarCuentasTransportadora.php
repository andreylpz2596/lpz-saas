<?php

namespace ByLopez\Logistica\Console;

use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Services\Cuentas\GeneradorMovimientosCuenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conciliacion EXPLICITA de las cuentas corrientes con los envios ya existentes (las cuentas no hacen backfill solas).
 * Por defecto SIMULA (todo dentro de una transaccion que se revierte) y muestra lo que se generaria; `--aplicar` escribe.
 * Es idempotente: repetirlo no duplica nada (misma conciliacion que hacen las entregas y anulaciones).
 */
class SincronizarCuentasTransportadora extends Command
{
    protected $signature = 'logistica:cuentas:sincronizar {--aplicar : Escribe los movimientos (sin esta opcion solo simula)} {--envio=* : Solo estos shipment_id}';

    protected $description = 'Concilia las cuentas corrientes de transportadoras con las entregas registradas (simula por defecto).';

    public function handle(GeneradorMovimientosCuenta $generador): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $envios = array_map('intval', (array) $this->option('envio'));

        $ids = Guia::query()->where('liquida_cuenta_corriente', true)
            ->when($envios !== [], fn ($q) => $q->whereIn('shipment_id', $envios))
            ->orderBy('shipment_id')->pluck('shipment_id')->map(fn ($id) => (int) $id)->all();

        $antes = (int) MovimientoCuenta::query()->max('id');

        DB::beginTransaction();

        try {
            foreach ($ids as $id) {
                $generador->sincronizarEnvio($id);
            }

            $nuevos = MovimientoCuenta::query()->where('id', '>', $antes)->orderBy('id')->get();

            $this->table(['Envio', 'Transportadora', 'Concepto', 'Importe', 'Origen'], $nuevos->map(fn ($m) => [
                $m->shipment_id, $m->transportadora_id, $m->concepto->value, number_format((float) $m->importe, 2, ',', '.'), $m->origen_tipo,
            ])->all());

            $aplicar ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->info(sprintf('%d envios revisados · %d movimientos %s.', count($ids), $nuevos->count(), $aplicar ? 'creados' : 'se crearian (simulacion, nada escrito)'));

        return self::SUCCESS;
    }
}
