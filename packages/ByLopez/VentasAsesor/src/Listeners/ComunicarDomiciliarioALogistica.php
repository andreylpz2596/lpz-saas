<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Support\Venta\ConfirmacionEnCurso;

/**
 * Cierre F1 (D1): si el asesor eligio domiciliario en Nueva venta, se comunica a Logistica (contrato DomiciliarioDeVenta)
 * con el pedido recien creado, DENTRO de la transaccion del pedido (como la clasificacion G-11): no queda pedido sin su
 * eleccion ni eleccion sin pedido. Logistica decide si aplica (domicilio local, transportadora aun elegible); si no
 * aplica no guarda nada y el despacho usa la sugerencia por historial. Pedidos de otros canales: sin consultas.
 */
class ComunicarDomiciliarioALogistica
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(
        protected ConfirmacionEnCurso $confirmaciones,
        protected DomiciliarioDeVenta $domiciliarios,
    ) {}

    public function handle(mixed $pedido): void
    {
        $cartId = (int) ($pedido->cart_id ?? 0);
        $intento = $cartId > 0 ? $this->confirmaciones->para($cartId) : null;

        if ($intento === null) {
            return;
        }

        $transportadoraId = BorradorVenta::query()->whereKey($intento['borrador_id'])->first(['transportadora_id'])?->transportadora_id;

        if ($transportadoraId !== null) {
            $this->domiciliarios->registrarDesdeVenta((int) $pedido->id, (int) $transportadoraId, $intento['actor_id'] ?? null);
        }
    }
}
