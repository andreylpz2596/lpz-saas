<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Cupo\ExcesoCupoService;
use ByLopez\VentasAsesor\Support\Venta\ConsumoExcesoEnCurso;

/**
 * Liga la autorizacion de exceso de cupo que consumio el cerco con el pedido recien creado (Doc. 35 §8). Corre en
 * `checkout.order.save.after`, DENTRO de la transaccion del pedido: si el pedido no llega a confirmarse, el consumo y el enlace se
 * deshacen juntos. Sin una autorizacion consumida en este intento no hace nada: ni consultas ni resolver servicios.
 */
class VincularExcesoCupoAlPedido
{
    public const EVENTO = 'checkout.order.save.after';

    public function __construct(protected ConsumoExcesoEnCurso $consumo) {}

    public function handle(mixed $pedido): void
    {
        $excesoId = $this->consumo->tomar();

        if ($excesoId !== null && is_object($pedido) && isset($pedido->id)) {
            app(ExcesoCupoService::class)->vincularPedido($excesoId, (int) $pedido->id);
        }
    }
}
