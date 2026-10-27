<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Support\Geo\NombresGeograficos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Dirección pendiente de completar»: direccion que un archivo trajo pero que NO se pudo guardar como direccion del cliente (ciudad
 * ambigua, no encontrada, sin ciudad). Conserva el texto ORIGINAL y el motivo; no es una fila rechazada (el cliente y sus
 * antecedentes si se guardaron). Idempotente: la misma direccion + ciudad no se duplica al repetir el archivo.
 */
class DireccionesPendientesClienteService
{
    public const TABLA = 'bylopez_va_cliente_direcciones_pendientes';

    public function registrar(int $customerId, string $direccion, ?string $ciudad, ?string $departamento, string $motivo, ?int $cargaId, ?int $fila): string
    {
        $clave = $this->clave($direccion, $ciudad);
        $existe = DB::table(self::TABLA)->where('customer_id', $customerId)->where('clave', $clave)->exists();

        if ($existe) {
            return 'sin_cambio';
        }

        DB::table(self::TABLA)->insert([
            'customer_id' => $customerId, 'clave' => $clave, 'direccion_original' => mb_substr(trim($direccion), 0, 255),
            'ciudad_original' => $ciudad === null || trim($ciudad) === '' ? null : mb_substr(trim($ciudad), 0, 191),
            'departamento_original' => $departamento === null || trim($departamento) === '' ? null : mb_substr(trim($departamento), 0, 100),
            'motivo' => $motivo, 'carga_id' => $cargaId, 'fila_origen' => $fila, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'creada';
    }

    /** Retira las pendientes con la misma direccion (texto normalizado) cuando el cliente ya la tiene como direccion guardada. */
    public function resolver(int $customerId, string $direccion): int
    {
        $linea = NombresGeograficos::busqueda($direccion);
        $n = 0;

        foreach (DB::table(self::TABLA)->where('customer_id', $customerId)->get(['id', 'direccion_original']) as $p) {
            if (NombresGeograficos::busqueda($p->direccion_original) === $linea) {
                $n += DB::table(self::TABLA)->where('id', $p->id)->delete();
            }
        }

        return $n;
    }

    /** @return list<array{direccion:string, ciudad:?string, departamento:?string, motivo:string, carga_id:?int, fila_origen:?int}> */
    public function listar(int $customerId): array
    {
        if (! Schema::hasTable(self::TABLA)) {
            return [];
        }

        return DB::table(self::TABLA)->where('customer_id', $customerId)->orderBy('id')->get()->map(fn ($p) => [
            'direccion' => $p->direccion_original, 'ciudad' => $p->ciudad_original, 'departamento' => $p->departamento_original,
            'motivo' => $p->motivo, 'carga_id' => $p->carga_id === null ? null : (int) $p->carga_id, 'fila_origen' => $p->fila_origen,
        ])->all();
    }

    public function contar(int $customerId): int
    {
        return Schema::hasTable(self::TABLA) ? (int) DB::table(self::TABLA)->where('customer_id', $customerId)->count() : 0;
    }

    protected function clave(string $direccion, ?string $ciudad): string
    {
        return sha1(NombresGeograficos::busqueda($direccion).'|'.NombresGeograficos::busqueda((string) $ciudad));
    }
}
