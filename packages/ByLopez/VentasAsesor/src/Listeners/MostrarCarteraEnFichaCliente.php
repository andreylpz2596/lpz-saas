<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\Theme\ViewRenderEventManager;

/**
 * Acceso contextual a la cartera desde la ficha NATIVA del cliente (PAG-D), sin tocar Webkul: la vista de Bagisto
 * ya emite `bagisto.admin.customers.customers.view.card.orders.before` y el modulo le agrega UNA tarjeta pequena
 * (saldo y boton «Ver cartera»). Solo se muestra a quien tenga alcance de cartera y modulo; el cliente sale de la
 * ruta (`customers/view/{id}`).
 */
class MostrarCarteraEnFichaCliente
{
    public const EVENTO = 'bagisto.admin.customers.customers.view.card.orders.before';

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $consulta,
    ) {}

    public function handle(ViewRenderEventManager $manager): void
    {
        $admin = auth()->guard('admin')->user();
        $clienteId = (int) request()->route('id');

        if (! $admin || $clienteId < 1
            || ! $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO)
            || $this->autorizacion->alcanceDeCartera($admin) === AlcanceVisibilidad::NINGUNO) {
            return;
        }

        $ficha = $this->consulta->fichaDelCliente($clienteId, $admin);

        if ($ficha === null) {
            return;
        }

        $manager->addTemplate('ventasasesor::admin.cartera.tarjeta-ficha', [
            'clienteId' => $clienteId,
            'cartera' => $ficha['cartera'],
        ]);
    }
}
