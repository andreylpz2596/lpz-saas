<?php

namespace ByLopez\Logistica\Support\Inventario;

use ByLopez\CorteConfeccion\Models\InventarioMovimiento;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\Logistica\Contracts\AjustaInventario;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\User\Contracts\Admin;

/**
 * Adaptador al ajuste manual de CorteConfeccion (InventarioMovimientoService::registrarAjusteManual), SIN modificarlo. Es el
 * UNICO archivo de Logistica que conoce ese paquete.
 *
 * El servicio de CorteConfeccion trabaja por delta (entrada/salida) y exige un Administrador autenticado en el guard
 * `admin` (su compuerta excluye a BODEGA). Aqui se traduce «subir al conteo fisico» a un delta calculado BAJO el bloqueo de
 * la fila de inventario, y se verifica que el resultado sea exactamente el conteo: si el stock cambio entre la solicitud y
 * la resolucion, el delta se recalcula (el resultado final es siempre el conteo confirmado, nunca stock + delta viejo).
 */
class AjusteViaCorteConfeccion implements AjustaInventario
{
    public function __construct(protected InventarioMovimientoService $inventario) {}

    public function fuente(): object
    {
        try {
            return $this->inventario->resolverFuenteInventario();
        } catch (ValidationException $e) {
            throw new Invalida(Invalida::AJUSTE_FALLIDO, ['causa' => $this->mensaje($e)]);
        }
    }

    public function stockActual(int $productId): int
    {
        return $this->leerStock($productId, (int) $this->fuente()->id, false);
    }

    public function elevarAConteo(Admin $actor, int $productId, int $conteo, string $motivo, string $clave): array
    {
        // La compuerta de CorteConfeccion mira al usuario autenticado: debe ser quien actua (nunca se suplanta a otro).
        if ((int) auth()->guard('admin')->id() !== (int) $actor->id) {
            throw new Invalida(Invalida::AJUSTE_NO_AUTORIZADO);
        }

        $fuente = (int) $this->fuente()->id;

        // Reintento con la misma clave: devuelve el movimiento ya hecho (no vuelve a calcular contra un stock ya corregido).
        if ($previo = InventarioMovimiento::query()->where('idempotency_key', $clave)->first()) {
            return $this->resultado($previo);
        }

        return DB::transaction(function () use ($productId, $conteo, $motivo, $clave, $fuente) {
            $actual = $this->leerStock($productId, $fuente, true);
            $delta = $conteo - $actual;

            if ($delta <= 0) {
                throw new Invalida($delta === 0 ? Invalida::SIN_DIFERENCIA : Invalida::CONTEO_MENOR_AL_SISTEMA, [
                    'product_id' => $productId, 'sistema' => $actual, 'conteo' => $conteo,
                ]);
            }

            try {
                $movimiento = $this->inventario->registrarAjusteManual([
                    'product_id' => $productId,
                    'tipo' => 'entrada',
                    'cantidad' => $delta,
                    'motivo' => $motivo,
                    'idempotency_key' => $clave,
                ]);
            } catch (ValidationException $e) {
                $autorizacion = isset($e->errors()['admin_id']);

                throw new Invalida($autorizacion ? Invalida::AJUSTE_NO_AUTORIZADO : Invalida::AJUSTE_FALLIDO, ['causa' => $this->mensaje($e)]);
            }

            // Garantia: el stock queda EXACTAMENTE en el conteo confirmado (si no, se revierte todo).
            if ((int) $movimiento->cantidad_nueva !== $conteo) {
                throw new Invalida(Invalida::AJUSTE_FALLIDO, ['causa' => 'El stock resultante no coincide con el conteo confirmado.']);
            }

            return $this->resultado($movimiento);
        });
    }

    public function ultimosMovimientos(int $productId, int $limite = 5): array
    {
        return InventarioMovimiento::query()->where('product_id', $productId)->orderByDesc('id')->limit($limite)
            ->get(['id', 'tipo_movimiento', 'cantidad_anterior', 'cantidad_movimiento', 'cantidad_nueva', 'nombre_usuario_snapshot', 'observacion', 'created_at'])
            ->map(fn ($m) => $m->only(['id', 'tipo_movimiento', 'cantidad_anterior', 'cantidad_movimiento', 'cantidad_nueva', 'nombre_usuario_snapshot', 'observacion', 'created_at']))
            ->all();
    }

    private function leerStock(int $productId, int $fuente, bool $bloquear): int
    {
        $consulta = DB::table('product_inventories')->where('product_id', $productId)->where('inventory_source_id', $fuente)->where('vendor_id', 0);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return (int) $consulta->value('qty');
    }

    /** @return array{movimiento_id: int, anterior: int, nueva: int, diferencia: int} */
    private function resultado(InventarioMovimiento $m): array
    {
        return [
            'movimiento_id' => (int) $m->id,
            'anterior' => (int) $m->cantidad_anterior,
            'nueva' => (int) $m->cantidad_nueva,
            'diferencia' => (int) $m->cantidad_movimiento,
        ];
    }

    private function mensaje(ValidationException $e): string
    {
        return (string) collect($e->errors())->flatten()->first();
    }
}
