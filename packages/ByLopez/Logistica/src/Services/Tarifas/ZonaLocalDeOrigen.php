<?php

namespace ByLopez\Logistica\Services\Tarifas;

use ByLopez\Logistica\Contracts\ResuelveMunicipio;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Support\PuntosRecogida;
use Illuminate\Support\Facades\DB;

/**
 * UNICA definicion de «entrega local»: ORIGEN (bodega) + DESTINO + zonas locales configuradas para ese origen
 * (`bylopez_lg_origen_zonas`). Devuelve la zona F1 del destino solo si esta vinculada al origen; si no, null (no es local:
 * no hay domicilio local, ni tarifa local, ni domiciliario local). Nada depende de nombres de ciudades.
 *
 * `$origenId` null = bodega predeterminada del canal (hoy la unica opcion en Nueva Venta). La resolucion del origen esta
 * solo en `origenPredeterminadoId()`; los demas metodos reciben el origen explicito, listos para varias bodegas.
 */
class ZonaLocalDeOrigen
{
    public function __construct(protected ResuelveMunicipio $municipios) {}

    /** Bodega activa de mayor prioridad del canal actual. */
    public function origenPredeterminadoId(): ?int
    {
        $bodega = app(PuntosRecogida::class)->disponibles()->first();

        return $bodega ? (int) $bodega->id : null;
    }

    /** Zona local de una direccion (carrito o pedido) para el origen; null si no es local. */
    public function zonaDe(?int $origenId, ?object $direccion): ?Zona
    {
        if ($direccion === null) {
            return null;
        }

        return $this->zonaDeMunicipio($origenId, $this->municipios->municipioDeDireccion($direccion->city ?? null, $direccion->state ?? null, $direccion->country ?? null));
    }

    public function zonaDePedido(?int $origenId, int $orderId): ?Zona
    {
        return $this->zonaDeMunicipio($origenId, $this->municipios->municipioDePedido($orderId));
    }

    /** Zona activa del municipio DIVIPOLA (5 digitos) si es local para el origen. */
    public function zonaDeMunicipio(?int $origenId, ?string $municipio): ?Zona
    {
        $origenId ??= $this->origenPredeterminadoId();

        if ($origenId === null || $municipio === null || preg_match('/^\d{5}$/', $municipio) !== 1) {
            return null;
        }

        return Zona::query()->where('activo', true)
            ->whereIn('id', DB::table('bylopez_lg_zona_municipios')->where('municipio_codigo', $municipio)->select('zona_id'))
            ->whereIn('id', DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origenId)->select('zona_id'))
            ->first();
    }

    /** @return list<int> ids de zonas locales del origen */
    public function zonaIdsDeOrigen(int $origenId): array
    {
        return DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origenId)->pluck('zona_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Reemplaza las zonas locales del origen por las indicadas (solo zonas activas existentes). */
    public function asignar(int $origenId, array $zonaIds): void
    {
        $validas = Zona::query()->where('activo', true)->whereIn('id', array_map('intval', $zonaIds))->pluck('id')->all();

        DB::transaction(function () use ($origenId, $validas) {
            DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origenId)->whereNotIn('zona_id', $validas)->delete();

            foreach (array_diff($validas, $this->zonaIdsDeOrigen($origenId)) as $zonaId) {
                DB::table('bylopez_lg_origen_zonas')->insert([
                    'inventory_source_id' => $origenId, 'zona_id' => $zonaId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
