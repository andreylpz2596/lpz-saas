<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Exceptions\MensajeCarteraNoDisponibleException;
use ByLopez\VentasAsesor\Services\Mensajes\MensajeCarteraClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Estado de cuenta de WhatsApp del cliente (Cartera › cliente). Mismo formato JSON que los mensajes del pedido, para que lo use
 * el mismo componente (logistica::admin.mensajes.whatsapp). Solo lectura; la ruta exige `ventas_asesor.cartera.view_all` y el
 * servicio vuelve a autorizar.
 */
class MensajesCarteraController extends Controller
{
    public function __construct(protected MensajeCarteraClienteService $mensajes) {}

    public function opciones(int $cliente): JsonResponse
    {
        return $this->responder(fn () => [
            'cliente_id' => $cliente,
            'opciones' => $this->mensajes->opciones($cliente, $this->actor()),
        ]);
    }

    public function mensaje(int $cliente, string $clave): JsonResponse
    {
        return $this->responder(fn () => $this->mensajes->generar($cliente, $clave, $this->actor()));
    }

    protected function responder(callable $accion): JsonResponse
    {
        try {
            return response()->json($accion())->header('Cache-Control', 'private, no-store');
        } catch (MensajeCarteraNoDisponibleException $e) {
            return response()->json(['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->estadoHttp());
        }
    }

    protected function actor()
    {
        return auth()->guard('admin')->user();
    }
}
