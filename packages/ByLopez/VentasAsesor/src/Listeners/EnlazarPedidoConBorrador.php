<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Enums\EstadoBorradorVenta as Estado;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as Rechazo;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;

/**
 * Enlace borrador → pedido (03F): PRIMER listener de `checkout.order.save.after`, dentro de
 * la transaccion del pedido y antes de la atribucion, los correos, la factura y el
 * reindexado. Si el pedido hace commit, el enlace hace commit con el; si se revierte, el
 * enlace tambien. No existe la ventana «pedido creado sin enlazar».
 *
 * UPDATE condicionado por el token del intento: debe afectar EXACTAMENTE una fila. Con 0,
 * lanza y el pedido se revierte (con el cerco de `save.before` no deberia ocurrir).
 *
 * Solo actua sobre confirmaciones ByLopez (ConfirmacionEnCurso); cualquier otro pedido pasa
 * sin consultas.
 */
class EnlazarPedidoConBorrador
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(protected ConfirmacionEnCurso $confirmaciones) {}

    public function handle(mixed $pedido): void
    {
        $cartId = (int) ($pedido->cart_id ?? 0);
        $intento = $cartId > 0 ? $this->confirmaciones->para($cartId) : null;

        if ($intento === null) {
            return;
        }

        $enlazados = BorradorVenta::query()
            ->whereKey($intento['borrador_id'])
            ->where('confirmation_token', $intento['token'])
            ->where('estado', Estado::CONFIRMANDO->value)
            ->whereNull('order_id')
            ->update([
                'order_id' => (int) $pedido->id,
                'estado' => Estado::CONFIRMADO->value,
                'confirmation_token' => null,
                'confirmacion_iniciada_en' => null,
            ]);

        if ($enlazados !== 1) {
            throw new Rechazo(Rechazo::CONFIRMACION_OBSOLETA, ['borrador_id' => $intento['borrador_id']]);
        }
    }
}
