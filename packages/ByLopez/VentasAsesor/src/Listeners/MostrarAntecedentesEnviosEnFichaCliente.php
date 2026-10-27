<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Services\Clientes\AntecedentesEnviosClienteService;
use ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService;
use ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService;
use Illuminate\Support\Facades\DB;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Webkul\Theme\ViewRenderEventManager;

/**
 * «Historial reportado por 99 Envíos» en la ficha NATIVA del cliente, sin tocar Webkul: usa el mismo hook que la tarjeta de
 * Cartera (`...view.card.orders.before`). Solo LECTURA y solo informativo: son hechos reportados por el proveedor con su
 * procedencia, no una calificacion del cliente ni entregas registradas en Logistica. Visible con el permiso del modulo.
 */
class MostrarAntecedentesEnviosEnFichaCliente
{
    public const EVENTO = 'bagisto.admin.customers.customers.view.card.orders.before';

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected AntecedentesEnviosClienteService $antecedentes,
        protected DireccionesPendientesClienteService $pendientes,
        protected DatosPendientesClienteService $datos,
    ) {}

    public function handle(ViewRenderEventManager $manager): void
    {
        $admin = auth()->guard('admin')->user();
        $clienteId = (int) request()->route('id');

        if (! $admin || $clienteId < 1 || ! $this->autorizacion->tienePermiso($admin, AutorizacionVentasAsesor::PERMISO_MODULO)) {
            return;
        }

        $resumen = $this->antecedentes->resumen($clienteId);
        $pendientes = $this->pendientes->listar($clienteId);
        $datosPendientes = $this->datos->listar($clienteId);

        // Sin antecedentes ni direcciones pendientes la tarjeta no ocupa lugar en la ficha.
        if ($resumen['total'] === 0 && $pendientes === [] && $datosPendientes === []) {
            return;
        }

        $manager->addTemplate('ventasasesor::admin.clientes.antecedentes-envios', [
            'clienteId' => $clienteId, 'antecedentes' => $resumen, 'pendientes' => $pendientes, 'datosPendientes' => $datosPendientes,
            // Nombre COMPLETO tal como lo reporto el proveedor (la separacion en nombre y apellido de la ficha es una propuesta).
            'nombresReportados' => array_values(array_unique(array_filter(array_column($resumen['envios'], 'nombre_reportado')))),
            'telefonos' => DB::table('bylopez_va_customer_phones')->where('customer_id', $clienteId)->orderByDesc('principal')->orderBy('id')->get(['telefono_normalizado', 'telefono_original', 'principal'])->all(),
        ]);
    }
}
