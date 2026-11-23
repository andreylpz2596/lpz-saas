<?php

namespace ByLopez\VentasAsesor\Services\Venta;

use ByLopez\VentasAsesor\DataObjects\OtraVentaSinConfirmar;
use ByLopez\VentasAsesor\DataObjects\VentasSimultaneasVariante;
use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\User\Contracts\Admin;

/**
 * Senal comercial de ventas simultaneas por variante (Doc. 05 §40). SOLO LECTURA: no escribe
 * ninguna tabla, no reserva, no modifica stock, no bloquea y no participa en
 * ConfirmarVentaService ni en el cerco D-54. Bagisto sigue siendo la unica autoridad de
 * inventario: `stockBagisto` se devuelve intacto y nada se le resta.
 *
 * Todo se deriva de datos existentes, sin tablas nuevas:
 * - stock: product_inventory_indices del canal (el mismo que muestra el catalogo);
 * - ventas sin confirmar: borradores `activo`/`confirmando` + su Cart + cart_items. En un
 *   configurable la VARIANTE sale del hijo y la CANTIDAD del padre (el hijo vale 0 en Bagisto
 *   2.4.9); en un simple, de la propia linea. Sin exclusion por antiguedad (decision de
 *   el responsable funcional): se informa la ultima actividad;
 * - confirmados sin despachar: order_items con la formula nativa qty_to_ship (ordenado −
 *   despachado − reembolsado − cancelado), en pedidos que Bagisto aun puede despachar (no
 *   `closed` ni `fraud`, como Order::canShip). En un configurable, del padre.
 *
 * Lote obligatorio (matriz S/M/L/XL): un numero FIJO de consultas agrupadas, sin N+1.
 * Uso interno de asesores (Nueva Venta). No es stock oficial para tienda, clientes, WhatsApp,
 * n8n ni IA.
 */
class VentasSimultaneasService
{
    /** Estados cuyo Cart sigue vivo y puede terminar en pedido. */
    public const ESTADOS_EN_CURSO = [Estado::ACTIVO, Estado::CONFIRMANDO];

    /** Pedidos que Bagisto ya no despacha (Order::canShip). */
    public const ESTADOS_SIN_DESPACHO = [Order::STATUS_CLOSED, Order::STATUS_FRAUD];

    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    /**
     * @param  int|null  $borradorId  La venta en la que trabaja el asesor: su cantidad va a
     *                                `enEstaVenta` y nunca a "otras".
     */
    public function paraVariante(int $varianteId, Admin $actor, ?int $borradorId = null): VentasSimultaneasVariante
    {
        return $this->paraVariantes([$varianteId], $actor, $borradorId)[$varianteId];
    }

    /**
     * @param  list<int>  $varianteIds
     * @return array<int, VentasSimultaneasVariante> Por variante_id, en el orden recibido.
     */
    public function paraVariantes(array $varianteIds, Admin $actor, ?int $borradorId = null): array
    {
        if (! $this->autorizacion->tienePermiso($actor, BorradorVentaService::PERMISO)) {
            // Doc. 33 P3: el cliente de autoservicio nunca recibe la senal de otras ventas (nombres de asesores, cantidades ajenas).
            if ($this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_AUTOSERVICIO_COMPRAR)) {
                return [];
            }

            throw new BorradorInvalido(BorradorInvalido::SIN_PERMISO);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $varianteIds), fn ($id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $canalId = (int) core()->getCurrentChannel()->id;
        $stock = $this->stockBagisto($ids, $canalId);
        $lineas = $this->lineasEnCarritos($ids);
        $actividad = $this->ultimaActividad($lineas);
        $nombres = $this->nombresDeAsesores($lineas, $borradorId);
        $pendientes = $this->confirmadosSinDespachar($ids, $canalId);
        $ahora = Carbon::now();

        $resultado = [];

        foreach ($ids as $id) {
            $enEstaVenta = 0;
            $porBorrador = [];

            foreach ($lineas[$id] ?? [] as $linea) {
                if ($borradorId !== null && $linea->borrador_id === $borradorId) {
                    $enEstaVenta += $linea->cantidad;

                    continue;
                }

                $porBorrador[$linea->borrador_id] ??= ['linea' => $linea, 'cantidad' => 0];
                $porBorrador[$linea->borrador_id]['cantidad'] += $linea->cantidad;
            }

            $otras = [];

            foreach ($porBorrador as $venta) {
                if ($venta['cantidad'] <= 0) {
                    continue;
                }

                $otras[] = new OtraVentaSinConfirmar(
                    asesorId: $venta['linea']->propietario_admin_id,
                    asesorNombre: $nombres[$venta['linea']->propietario_admin_id] ?? null,
                    cantidad: $venta['cantidad'],
                    estado: $venta['linea']->estado,
                    ultimaActividad: $actividad[$venta['linea']->cart_id] ?? null,
                );
            }

            usort($otras, fn ($a, $b) => $b->cantidad <=> $a->cantidad);

            $enOtras = array_sum(array_map(fn ($v) => $v->cantidad, $otras));
            $pendiente = $pendientes[$id] ?? ['total' => 0, 'no_descontado' => 0];
            $stockVariante = $stock[$id] ?? 0;

            $resultado[$id] = new VentasSimultaneasVariante(
                varianteId: $id,
                stockBagisto: $stockVariante,
                enEstaVenta: $enEstaVenta,
                enOtrasVentasSinConfirmar: $enOtras,
                confirmadosSinDespachar: $pendiente['total'],
                confirmadosSinDespacharNoDescontados: $pendiente['no_descontado'],
                otrasVentas: $otras,
                // Solo contexto: se compara, nunca se resta ni se publica un "disponible".
                advertencia: ($enOtras > 0 || $pendiente['no_descontado'] > 0)
                    && $enEstaVenta + $enOtras + $pendiente['no_descontado'] > $stockVariante,
                consultadoEn: $ahora,
            );
        }

        return $resultado;
    }

    /** @return array<int, int> */
    protected function stockBagisto(array $ids, int $canalId): array
    {
        return DB::table('product_inventory_indices')
            ->whereIn('product_id', $ids)
            ->where('channel_id', $canalId)
            ->pluck('qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Lineas de los Cart de borradores en curso que llevan las variantes pedidas.
     *
     * @return array<int, list<object>> Por variante_id.
     */
    protected function lineasEnCarritos(array $ids): array
    {
        $estados = array_map(fn (Estado $e) => $e->value, self::ESTADOS_EN_CURSO);
        $columnas = ['b.id as borrador_id', 'b.propietario_admin_id', 'b.estado', 'c.id as cart_id', 'c.updated_at as cart_actualizado'];

        // Configurable: variante en el hijo, cantidad en el padre.
        $configurables = DB::table('cart_items as h')
            ->join('cart_items as l', 'l.id', '=', 'h.parent_id')
            ->join('cart as c', 'c.id', '=', 'l.cart_id')
            ->join('bylopez_va_borradores_venta as b', 'b.cart_id', '=', 'c.id')
            ->whereIn('h.product_id', $ids)
            ->where('l.type', 'configurable')
            ->whereIn('b.estado', $estados)
            ->select(['h.product_id as variante_id', 'l.quantity as cantidad', ...$columnas]);

        // Simple (u otra linea sin hijos): la propia linea.
        $simples = DB::table('cart_items as l')
            ->join('cart as c', 'c.id', '=', 'l.cart_id')
            ->join('bylopez_va_borradores_venta as b', 'b.cart_id', '=', 'c.id')
            ->whereIn('l.product_id', $ids)
            ->whereNull('l.parent_id')
            ->where('l.type', '!=', 'configurable')
            ->whereIn('b.estado', $estados)
            ->select(['l.product_id as variante_id', 'l.quantity as cantidad', ...$columnas]);

        $lineas = [];

        foreach ($configurables->unionAll($simples)->get() as $fila) {
            $fila->variante_id = (int) $fila->variante_id;
            $fila->borrador_id = (int) $fila->borrador_id;
            $fila->propietario_admin_id = (int) $fila->propietario_admin_id;
            $fila->cart_id = (int) $fila->cart_id;
            $fila->cantidad = (int) $fila->cantidad;
            $lineas[$fila->variante_id][] = $fila;
        }

        return $lineas;
    }

    /**
     * Mejor evidencia real de actividad: el ultimo cambio del Cart (collectTotals lo guarda) o
     * de cualquiera de sus lineas. No borradores.updated_at, que no cambia al editar lineas.
     *
     * @return array<int, Carbon> Por cart_id.
     */
    protected function ultimaActividad(array $lineas): array
    {
        $carritos = [];

        foreach ($lineas as $filas) {
            foreach ($filas as $fila) {
                $carritos[$fila->cart_id] = $fila->cart_actualizado;
            }
        }

        if ($carritos === []) {
            return [];
        }

        $items = DB::table('cart_items')
            ->whereIn('cart_id', array_keys($carritos))
            ->groupBy('cart_id')
            ->selectRaw('cart_id, MAX(updated_at) as ultima')
            ->pluck('ultima', 'cart_id');

        $actividad = [];

        foreach ($carritos as $cartId => $carrito) {
            $fechas = array_filter([$carrito, $items[$cartId] ?? null]);
            $actividad[$cartId] = $fechas === [] ? null : Carbon::parse(max($fechas));
        }

        return array_filter($actividad);
    }

    /**
     * Solo el nombre visible (decision del responsable funcional: coordinacion interna). Sin correo ni otros
     * datos del administrador. Sin FK: un administrador borrado queda sin nombre.
     *
     * @return array<int, string>
     */
    protected function nombresDeAsesores(array $lineas, ?int $borradorId): array
    {
        $ids = [];

        foreach ($lineas as $filas) {
            foreach ($filas as $fila) {
                if ($fila->borrador_id !== $borradorId) {
                    $ids[$fila->propietario_admin_id] = true;
                }
            }
        }

        return $ids === [] ? [] : DB::table('admins')->whereIn('id', array_keys($ids))->pluck('name', 'id')->all();
    }

    /**
     * qty_to_ship nativo por variante. `no_descontado` = lo vendido a traves de un
     * configurable en pedidos ANTERIORES a F5 (hijo con qty_ordered 0: reserva nativa 0, el
     * stock Bagisto aun lo cuenta). Desde F5 la hija lleva cantidad y ese pendiente ya esta
     * reservado y descontado, igual que lo vendido como simple.
     *
     * @return array<int, array{total: int, no_descontado: int}>
     */
    protected function confirmadosSinDespachar(array $ids, int $canalId): array
    {
        $pendiente = 'GREATEST(l.qty_ordered - l.qty_shipped - l.qty_refunded - l.qty_canceled, 0)';

        $configurables = DB::table('order_items as h')
            ->join('order_items as l', 'l.id', '=', 'h.parent_id')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('h.product_id', $ids)
            ->where('l.type', 'configurable')
            ->where('o.channel_id', $canalId)
            ->whereNotIn('o.status', self::ESTADOS_SIN_DESPACHO)
            ->selectRaw("h.product_id as variante_id, CASE WHEN h.qty_ordered = 0 THEN 1 ELSE 0 END as via_configurable, {$pendiente} as pendiente");

        // qty_to_ship es 0 para tipos no almacenables (virtual, descargable): solo `simple`.
        $simples = DB::table('order_items as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('l.product_id', $ids)
            ->whereNull('l.parent_id')
            ->where('l.type', 'simple')
            ->where('o.channel_id', $canalId)
            ->whereNotIn('o.status', self::ESTADOS_SIN_DESPACHO)
            ->selectRaw("l.product_id as variante_id, 0 as via_configurable, {$pendiente} as pendiente");

        $filas = DB::query()
            ->fromSub($configurables->unionAll($simples), 'p')
            ->groupBy('variante_id', 'via_configurable')
            ->selectRaw('variante_id, via_configurable, SUM(pendiente) as pendiente')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->variante_id;
            $resultado[$id] ??= ['total' => 0, 'no_descontado' => 0];
            $resultado[$id]['total'] += (int) $fila->pendiente;

            if ((int) $fila->via_configurable === 1) {
                $resultado[$id]['no_descontado'] += (int) $fila->pendiente;
            }
        }

        return $resultado;
    }
}
