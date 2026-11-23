<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\Theme\ViewRenderEventManager;

/**
 * Doc. 33 P1: «Acceso al aplicativo» dentro de la ficha NATIVA del cliente (sin tocar Webkul, mismo hook que la tarjeta de
 * cartera). Solo para quien tenga ventas_asesor.clientes_acceso.manage; el cliente sale de la ruta (`customers/view/{id}`).
 */
class MostrarAccesoEnFichaCliente
{
    public const EVENTO = 'bagisto.admin.customers.customers.view.card.orders.before';

    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    public function handle(ViewRenderEventManager $manager): void
    {
        $admin = auth()->guard('admin')->user();
        $clienteId = (int) request()->route('id');

        if (! $admin || $clienteId < 1 || ! $this->autorizacion->tienePermiso($admin, ClienteAccesoService::PERMISO)) {
            return;
        }

        $manager->addTemplate('ventasasesor::admin.clientes.acceso-ficha', ['clienteId' => $clienteId]);
    }
}
