<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento;
use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetornoDetalle;
use ByLopez\CorteConfeccion\Support\InventarioMovimientoGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Models\InventorySource;

class InventarioMovimientoService
{
    public function resolverFuenteInventario(): InventorySource
    {
        $channel = core()->getDefaultChannel();

        if (! $channel) {
            throw ValidationException::withMessages([
                'inventory_source_id' => 'No hay canal default para resolver fuente de inventario.',
            ]);
        }

        $sources = $channel->inventory_sources()
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('inventory_sources.id')
            ->get();

        if ($sources->count() !== 1) {
            throw ValidationException::withMessages([
                'inventory_source_id' => $sources->isEmpty()
                    ? 'El canal default no tiene una fuente de inventario activa.'
                    : 'Hay multiples fuentes activas para el canal default; seleccion explicita de source aun no esta implementada.',
            ]);
        }

        return $sources->first();
    }

    public function previewRetorno(OrdenConfeccionRetorno $retorno): Collection
    {
        $source = $this->resolverFuenteInventario();

        $retorno->loadMissing('detalles');

        return $retorno->detalles->map(function (OrdenConfeccionRetornoDetalle $detalle) use ($source) {
            $stockActual = (int) DB::table('product_inventories')
                ->where('product_id', $detalle->product_id)
                ->where('inventory_source_id', $source->id)
                ->where('vendor_id', 0)
                ->value('qty');

            $entrada = max((int) $detalle->cantidad_recibida, 0);

            return [
                'retorno_detalle_id' => $detalle->id,
                'inventory_source_id' => $source->id,
                'inventory_source_nombre' => $source->name,
                'stock_actual' => $stockActual,
                'entrada' => $entrada,
                'stock_nuevo' => $stockActual + $entrada,
            ];
        })->keyBy('retorno_detalle_id');
    }

    public function registrarEntradaConfeccion(OrdenConfeccionRetorno $retorno): Collection
    {
        $source = $this->resolverFuenteInventario();

        $retorno->loadMissing(['ordenConfeccion', 'detalles']);

        return $retorno->detalles->map(function (OrdenConfeccionRetornoDetalle $detalle) use ($retorno, $source) {
            $cantidad = (int) $detalle->cantidad_recibida;

            if ($cantidad <= 0) {
                return null;
            }

            $existente = InventarioMovimiento::query()
                ->where('tipo_movimiento', TipoInventarioMovimiento::EntradaConfeccion->value)
                ->where('retorno_detalle_id', $detalle->id)
                ->where('inventory_source_id', $source->id)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                return $existente;
            }

            $inventario = $this->bloquearInventarioProducto((int) $detalle->product_id, (int) $source->id);
            $cantidadAnterior = (int) $inventario->qty;
            $cantidadNueva = $cantidadAnterior + $cantidad;

            DB::table('product_inventories')
                ->where('id', $inventario->id)
                ->update(['qty' => $cantidadNueva]);

            $this->incrementarIndicesVendibles((int) $detalle->product_id, (int) $source->id, $cantidad);

            $admin = auth()->guard('admin')->user();

            return InventarioMovimiento::query()->create([
                'product_id' => $detalle->product_id,
                'inventory_source_id' => $source->id,
                'tipo_movimiento' => TipoInventarioMovimiento::EntradaConfeccion->value,
                'cantidad_anterior' => $cantidadAnterior,
                'cantidad_movimiento' => $cantidad,
                'cantidad_nueva' => $cantidadNueva,
                'orden_confeccion_id' => $retorno->orden_confeccion_id,
                'retorno_id' => $retorno->id,
                'retorno_detalle_id' => $detalle->id,
                'admin_id' => $admin?->id,
                'nombre_usuario_snapshot' => $admin?->name,
                'observacion' => sprintf('Entrada por %s / %s', $retorno->ordenConfeccion?->codigo, $retorno->codigo),
            ]);
        })->filter()->values();
    }

    public function movimientoExisteParaDetalle(OrdenConfeccionRetornoDetalle $detalle): bool
    {
        return InventarioMovimiento::query()
            ->where('tipo_movimiento', TipoInventarioMovimiento::EntradaConfeccion->value)
            ->where('retorno_detalle_id', $detalle->id)
            ->exists();
    }

    public function registrarAjusteManual(array $data): InventarioMovimiento
    {
        if (! InventarioMovimientoGate::puedeAjustarManual()) {
            throw ValidationException::withMessages([
                'admin_id' => trans('corteconfeccion::app.errors.ajuste-manual-no-autorizado'),
            ]);
        }

        return DB::transaction(function () use ($data) {
            $source = $this->resolverFuenteInventario();
            $idempotencyKey = (string) $data['idempotency_key'];

            $existente = InventarioMovimiento::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                $this->validarReintentoAjusteManual($existente, $data, (int) $source->id);

                return $existente;
            }

            $productId = (int) $data['product_id'];

            $this->validarProductoSimple($productId);

            $cantidad = (int) $data['cantidad'];
            $delta = $data['tipo'] === 'salida' ? -$cantidad : $cantidad;
            $inventario = $this->bloquearInventarioProducto($productId, (int) $source->id);
            $cantidadAnterior = (int) $inventario->qty;
            $cantidadNueva = $cantidadAnterior + $delta;

            if ($cantidadNueva < 0) {
                throw ValidationException::withMessages([
                    'cantidad' => trans('corteconfeccion::app.errors.stock-negativo-no-permitido'),
                ]);
            }

            DB::table('product_inventories')
                ->where('id', $inventario->id)
                ->update(['qty' => $cantidadNueva]);

            $this->incrementarIndicesVendibles($productId, (int) $source->id, $delta);

            $admin = auth()->guard('admin')->user();

            return InventarioMovimiento::query()->create([
                'product_id' => $productId,
                'inventory_source_id' => $source->id,
                'tipo_movimiento' => TipoInventarioMovimiento::AjusteManual->value,
                'cantidad_anterior' => $cantidadAnterior,
                'cantidad_movimiento' => $delta,
                'cantidad_nueva' => $cantidadNueva,
                'admin_id' => $admin?->id,
                'nombre_usuario_snapshot' => $admin?->name,
                'observacion' => trim((string) $data['motivo']),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * ENTRADA de inventario motivada por otro modulo (hoy: reingreso por devolucion de Logistica). Capacidad generica y
     * auditada, sin la compuerta de «ajuste manual»: QUIEN LLAMA es responsable de haber autorizado la operacion (el permiso
     * es del modulo de origen). Reglas:
     *
     * - la bodega la indica quien llama (`inventory_source_id`): nunca se adivina ni se usa la bodega «unica» del canal;
     * - `cantidad` > 0; solo variantes fisicas (no configurables);
     * - idempotente por `idempotency_key` (UNIQUE): el reintento devuelve el MISMO movimiento; la misma clave con otra
     *   solicitud es un conflicto;
     * - fila de inventario bloqueada, stock fisico y indice vendible actualizados juntos, y movimiento en el libro con
     *   cantidad anterior/nueva, tipo, origen, usuario y motivo.
     *
     * Debe llamarse dentro de la transaccion de quien la invoca (anida con DB::transaction).
     *
     * @param  array{product_id: int, inventory_source_id: int, cantidad: int, tipo: TipoInventarioMovimiento, motivo: string, idempotency_key: string, origen_tipo: string, origen_id: int, admin_id: ?int, admin_nombre: ?string}  $data
     */
    public function registrarEntradaExterna(array $data): InventarioMovimiento
    {
        $cantidad = (int) $data['cantidad'];
        $sourceId = (int) $data['inventory_source_id'];
        $productId = (int) $data['product_id'];
        $tipo = $data['tipo'];
        $clave = (string) $data['idempotency_key'];

        if ($cantidad <= 0) {
            throw ValidationException::withMessages(['cantidad' => trans('corteconfeccion::app.errors.entrada-cantidad-invalida')]);
        }

        if (! DB::table('inventory_sources')->where('id', $sourceId)->exists()) {
            throw ValidationException::withMessages(['inventory_source_id' => trans('corteconfeccion::app.errors.entrada-fuente-invalida')]);
        }

        return DB::transaction(function () use ($data, $cantidad, $sourceId, $productId, $tipo, $clave) {
            $existente = InventarioMovimiento::query()->where('idempotency_key', $clave)->lockForUpdate()->first();

            if ($existente) {
                if (
                    $existente->tipo_movimiento !== $tipo->value
                    || (int) $existente->product_id !== $productId
                    || (int) $existente->inventory_source_id !== $sourceId
                    || (int) $existente->cantidad_movimiento !== $cantidad
                    || (string) $existente->origen_tipo !== (string) $data['origen_tipo']
                    || (int) $existente->origen_id !== (int) $data['origen_id']
                ) {
                    throw ValidationException::withMessages(['idempotency_key' => trans('corteconfeccion::app.errors.idempotency-key-en-uso')]);
                }

                return $existente;
            }

            $this->validarProductoSimple($productId);

            $inventario = $this->bloquearInventarioProducto($productId, $sourceId);
            $cantidadAnterior = (int) $inventario->qty;
            $cantidadNueva = $cantidadAnterior + $cantidad;

            DB::table('product_inventories')->where('id', $inventario->id)->update(['qty' => $cantidadNueva]);

            $this->incrementarIndicesVendibles($productId, $sourceId, $cantidad);

            return InventarioMovimiento::query()->create([
                'product_id' => $productId,
                'inventory_source_id' => $sourceId,
                'tipo_movimiento' => $tipo->value,
                'cantidad_anterior' => $cantidadAnterior,
                'cantidad_movimiento' => $cantidad,
                'cantidad_nueva' => $cantidadNueva,
                'admin_id' => $data['admin_id'] ?? null,
                'nombre_usuario_snapshot' => $data['admin_nombre'] ?? null,
                'observacion' => trim((string) $data['motivo']),
                'idempotency_key' => $clave,
                'origen_tipo' => (string) $data['origen_tipo'],
                'origen_id' => (int) $data['origen_id'],
            ]);
        });
    }

    protected function bloquearInventarioProducto(int $productId, int $sourceId): object
    {
        $inventario = DB::table('product_inventories')
            ->where('product_id', $productId)
            ->where('inventory_source_id', $sourceId)
            ->where('vendor_id', 0)
            ->lockForUpdate()
            ->first();

        if ($inventario) {
            return $inventario;
        }

        DB::table('product_inventories')->insert([
            'product_id' => $productId,
            'inventory_source_id' => $sourceId,
            'vendor_id' => 0,
            'qty' => 0,
        ]);

        return DB::table('product_inventories')
            ->where('product_id', $productId)
            ->where('inventory_source_id', $sourceId)
            ->where('vendor_id', 0)
            ->lockForUpdate()
            ->first();
    }

    protected function validarProductoSimple(int $productId): void
    {
        $tipo = DB::table('products')->where('id', $productId)->value('type');

        if ($tipo === 'configurable') {
            throw ValidationException::withMessages([
                'product_id' => trans('corteconfeccion::app.errors.producto-configurable-no-valido'),
            ]);
        }
    }

    protected function validarReintentoAjusteManual(InventarioMovimiento $movimiento, array $data, int $sourceId): void
    {
        $cantidad = (int) $data['cantidad'];
        $delta = $data['tipo'] === 'salida' ? -$cantidad : $cantidad;

        if (
            $movimiento->tipo_movimiento !== TipoInventarioMovimiento::AjusteManual->value
            || (int) $movimiento->product_id !== (int) $data['product_id']
            || (int) $movimiento->inventory_source_id !== $sourceId
            || (int) $movimiento->cantidad_movimiento !== $delta
            || trim((string) $movimiento->observacion) !== trim((string) $data['motivo'])
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => trans('corteconfeccion::app.errors.idempotency-key-en-uso'),
            ]);
        }
    }

    protected function incrementarIndicesVendibles(int $productId, int $sourceId, int $cantidad): void
    {
        $channelIds = DB::table('channel_inventory_sources')
            ->join('inventory_sources', 'inventory_sources.id', '=', 'channel_inventory_sources.inventory_source_id')
            ->where('channel_inventory_sources.inventory_source_id', $sourceId)
            ->where('inventory_sources.status', 1)
            ->pluck('channel_inventory_sources.channel_id');

        foreach ($channelIds as $channelId) {
            $indice = DB::table('product_inventory_indices')
                ->where('product_id', $productId)
                ->where('channel_id', $channelId)
                ->lockForUpdate()
                ->first();

            if ($indice) {
                $cantidadNueva = (int) $indice->qty + $cantidad;

                if ($cantidadNueva < 0) {
                    throw ValidationException::withMessages([
                        'cantidad' => trans('corteconfeccion::app.errors.stock-negativo-no-permitido'),
                    ]);
                }

                DB::table('product_inventory_indices')
                    ->where('id', $indice->id)
                    ->update([
                        'qty' => $cantidadNueva,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            if ($cantidad < 0) {
                throw ValidationException::withMessages([
                    'cantidad' => trans('corteconfeccion::app.errors.stock-negativo-no-permitido'),
                ]);
            }

            DB::table('product_inventory_indices')->insert([
                'product_id' => $productId,
                'channel_id' => $channelId,
                'qty' => $cantidad,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
