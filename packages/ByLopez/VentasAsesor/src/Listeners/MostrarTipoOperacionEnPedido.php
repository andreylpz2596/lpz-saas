<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Comisiones\TipoOperacionPedidoService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\Theme\ViewRenderEventManager;

/**
 * G-11 (bloque 24): en la vista NATIVA del pedido (hook `bagisto.admin.sales.order.title.after`, sin tocar Webkul)
 * muestra el tipo de operacion cuando NO es venta normal, con advertencia administrativa si una reposicion o garantia
 * tiene total > 0. No impone precio 0: solo advierte. Visible para quien ve todos los pedidos del modulo.
 */
class MostrarTipoOperacionEnPedido
{
    public const EVENTO = 'bagisto.admin.sales.order.title.after';

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected TipoOperacionPedidoService $tipos,
    ) {}

    public function handle(ViewRenderEventManager $manager): void
    {
        $pedido = $manager->getParam('order');

        if (! $pedido || ! $this->autorizacion->tienePermiso(auth()->guard('admin')->user(), AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS)) {
            return;
        }

        $tipo = $this->tipos->dePedido((int) $pedido->id);

        if ($tipo === null || $tipo->comisionable()) {
            return;
        }

        $manager->addTemplate('ventasasesor::admin.pedidos.tipo-operacion', [
            'tipo' => $tipo,
            'advertir' => $tipo->esperaPrecioCero() && (float) $pedido->grand_total > 0,
        ]);
    }
}
