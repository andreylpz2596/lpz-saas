<?php

namespace ByLopez\Logistica\Listeners;

use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Webkul\Theme\ViewRenderEventManager;

/**
 * Bloque 17: acceso «Entregas» desde la vista NATIVA del pedido sin tocar Webkul. La vista de Bagisto emite
 * `bagisto.admin.sales.order.page_action.before` con el pedido; se agrega UN boton con el saldo de unidades, solo si el
 * pedido tiene despachos y el usuario puede ver entregas.
 */
class MostrarEntregasEnPedido
{
    public const EVENTO = 'bagisto.admin.sales.order.page_action.before';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaEntregaService $consulta,
    ) {}

    public function handle(ViewRenderEventManager $manager): void
    {
        $pedido = $manager->getParam('order');
        $admin = auth()->guard('admin')->user();

        if (! $pedido || ! $this->autorizacion->tienePermiso($admin, AutorizacionLogistica::PERMISO_ENTREGAS)) {
            return;
        }

        $resumen = $this->consulta->resumenPedido((int) $pedido->id);

        if ($resumen['despachadas'] === 0) {
            return;
        }

        $manager->addTemplate('logistica::admin.entregas.boton-pedido', ['pedidoId' => (int) $pedido->id, 'resumen' => $resumen]);
    }
}
