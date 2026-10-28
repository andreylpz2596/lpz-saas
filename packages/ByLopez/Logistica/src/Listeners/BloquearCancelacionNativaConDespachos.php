<?php

namespace ByLopez\Logistica\Listeners;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * La cancelacion NATIVA de Bagisto (`OrderRepository::cancel`, ruta admin.sales.orders.cancel, o cualquier llamada de codigo)
 * reintegra al stock FISICO las unidades ya despachadas (OrderItemRepository::returnQtyToProductInventory): inflaria el inventario
 * con mercancia que salio. Se detiene en el servidor, ANTES de cualquier cambio (`sales.order.cancel.before`), cuando el pedido ya
 * tiene unidades despachadas. El camino correcto es CancelarPendienteService («Cancelar pendiente» en Despachos), que libera solo la
 * reserva de lo pendiente. Ocultar el boton no basta: este bloqueo protege el endpoint y los servicios.
 *
 * Un pedido sin despachos conserva el comportamiento nativo (libera su reserva; no hay stock fisico que reintegrar).
 */
class BloquearCancelacionNativaConDespachos
{
    public const EVENTO = 'sales.order.cancel.before';

    public function handle($pedido): void
    {
        $id = (int) ($pedido->id ?? $pedido);

        $despachadas = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->where('s.order_id', $id)->where('si.qty', '>', 0)->exists();

        if (! $despachadas) {
            return;
        }

        $mensaje = trans('logistica::app.errores.cancelacion_nativa_bloqueada');

        // Peticion web del panel (boton nativo): vuelve a la ficha con el aviso, sin pagina de error ni trazas.
        if (request()->route() !== null && request()->hasSession() && ! request()->expectsJson()) {
            throw new HttpResponseException(redirect()->back()->with('error', $mensaje));
        }

        throw new Invalida('cancelacion_nativa_bloqueada', ['pedido' => $id], $mensaje);
    }
}
