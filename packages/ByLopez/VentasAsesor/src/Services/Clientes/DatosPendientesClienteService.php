<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del cliente que el archivo no trajo y el modelo exige (hoy: el APELLIDO de un destinatario de una sola palabra). El cliente
 * se crea igual; aqui queda que falta y el texto original. Nada se deduce: el marcador es explicito y la pendiente se retira sola
 * cuando el apellido del cliente deja de ser el marcador (alguien lo completo desde la ficha).
 */
class DatosPendientesClienteService
{
    public const TABLA = 'bylopez_va_cliente_datos_pendientes';

    /** Valor explicito (no un apellido inventado) que ocupa `customers.last_name` mientras no se complete. */
    public const APELLIDO_PENDIENTE = '(pendiente)';

    public function registrar(int $customerId, string $campo, string $textoOriginal, string $motivo, ?int $cargaId, ?int $fila): string
    {
        if (DB::table(self::TABLA)->where('customer_id', $customerId)->where('campo', $campo)->exists()) {
            return 'sin_cambio';
        }

        DB::table(self::TABLA)->insert([
            'customer_id' => $customerId, 'campo' => $campo, 'texto_original' => mb_substr(trim($textoOriginal), 0, 191), 'motivo' => $motivo,
            'carga_id' => $cargaId, 'fila_origen' => $fila, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'creada';
    }

    /** @return list<array{campo:string, texto_original:string, motivo:string, carga_id:?int, fila_origen:?int}> solo las que siguen pendientes */
    public function listar(int $customerId): array
    {
        if (! Schema::hasTable(self::TABLA)) {
            return [];
        }

        $apellido = DB::table('customers')->where('id', $customerId)->value('last_name');

        return DB::table(self::TABLA)->where('customer_id', $customerId)->orderBy('id')->get()
            ->filter(fn ($p) => ! ($p->campo === 'apellido' && $apellido !== self::APELLIDO_PENDIENTE))
            ->map(fn ($p) => ['campo' => $p->campo, 'texto_original' => $p->texto_original, 'motivo' => $p->motivo, 'carga_id' => $p->carga_id === null ? null : (int) $p->carga_id, 'fila_origen' => $p->fila_origen])
            ->values()->all();
    }

    public function contar(int $customerId): int
    {
        return count($this->listar($customerId));
    }
}
