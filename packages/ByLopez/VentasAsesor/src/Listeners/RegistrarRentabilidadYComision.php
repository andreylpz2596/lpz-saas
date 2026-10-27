<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Comisiones\LineasComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use ByLopez\VentasAsesor\Services\Rentabilidad\RentabilidadVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * R2/R3 — al crear CUALQUIER pedido (Nueva venta, panel nativo, tienda), en la MISMA transaccion y DESPUES del costo
 * congelado (COM-01) y de la atribucion: guarda el snapshot de rentabilidad y, si es venta normal de un asesor, sus lineas
 * de comision ESTIMADAS con el acuerdo y % vigentes al confirmar (A-02) y el movimiento `venta` del umbral. La fecha de
 * confirmacion es `orders.created_at` (el instante del pedido), nunca la hora en que corra un proceso posterior.
 *
 * Como los demas listeners de `save.after`, NUNCA relanza (si lanzara, Bagisto reintentaria o perderia la venta): las
 * escrituras van en un savepoint y un fallo solo se registra. Ademas marca el pedido en la bandeja de reevaluacion como
 * CREACION: si el snapshot fallo, se registra tarde con los parametros vigentes al confirmar; y los pedidos posteriores del
 * mismo asesor y mes se reevaluan (commits fuera de orden). Este listener no bloquea nada: el bloqueo vive en
 * ConfirmarVentaService, antes de crear el pedido.
 */
class RegistrarRentabilidadYComision
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(
        protected RentabilidadVentaService $rentabilidad,
        protected LineasComisionService $lineas,
        protected ReevaluacionComisionesService $reevaluacion,
    ) {}

    public function handle(mixed $pedido): void
    {
        $orderId = is_numeric($pedido->id ?? null) ? (int) $pedido->id : 0;

        if ($orderId <= 0) {
            return;
        }

        try {
            DB::transaction(function () use ($orderId) {
                $this->rentabilidad->registrarDePedido($orderId);
                $this->lineas->asegurar($orderId);
            });
        } catch (Throwable $e) {
            try {
                Log::warning('ventas_asesor.rentabilidad.no_registrada', ['order_id' => $orderId, 'excepcion' => $e::class]);
            } catch (Throwable) {
                // Sin log disponible no se arriesga la venta.
            }
        }

        // Fuera del savepoint anterior: aunque el snapshot falle, la marca queda (solicitar() nunca lanza).
        $this->reevaluacion->solicitar([$orderId], 'pedido_creado', creacion: true);
    }
}
