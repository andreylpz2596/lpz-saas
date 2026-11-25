<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Services\Pedidos\ConsultaPedidosAsesorService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Core\Traits\PDFHandler;
use Webkul\Sales\Repositories\InvoiceRepository;

/**
 * «Ver factura» desde Ventas Asesor: abre en otra pestaña (inline, sin descargar) el MISMO PDF que genera la factura de
 * Bagisto, solo para pedidos que el asesor puede ver (propios o todos, segun su alcance) y solo cuando el pedido esta
 * PAGADO y tiene factura emitida. Solo lectura: no crea ni modifica nada. Las reglas viven en ConsultaPedidosAsesorService.
 */
class FacturaPedidoController extends Controller
{
    use PDFHandler;

    public function __construct(
        protected ConsultaPedidosAsesorService $consulta,
        protected AutorizacionVentasAsesor $autorizacion,
        protected InvoiceRepository $facturas,
    ) {}

    public function ver(int $pedido): Response
    {
        $admin = auth()->guard('admin')->user();
        $detalle = $this->consulta->detalle($pedido);

        abort_if($detalle === null, 404);
        abort_unless($this->autorizacion->puedeVerRegistroDe($admin, $detalle['advisor_id']), 403);
        abort_if($detalle['factura'] === null, 404);

        $invoice = $this->facturas->findOrFail($detalle['factura']['id']);
        $orderCurrencyCode = $invoice->order->order_currency_code;

        $pdf = $this->generatePdf(view('shop::customers.account.orders.pdf', compact('invoice', 'orderCurrencyCode'))->render());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($detalle['factura']['numero'] ?: 'factura').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
