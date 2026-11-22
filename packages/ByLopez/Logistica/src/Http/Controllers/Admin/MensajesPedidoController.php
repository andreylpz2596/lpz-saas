<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Contracts\MensajesPedidoCliente;
use ByLopez\Logistica\Exceptions\MensajePedidoNoDisponibleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Capa delgada sobre el contrato MensajesPedidoCliente: UN solo controlador para las tres puertas (Pedidos de VentasAsesor, bandeja
 * de Despachos y bandeja de Entregas). Cada puerta tiene su propia ruta y su clave ACL; el servicio vuelve a autorizar por pedido.
 * Solo lectura.
 */
class MensajesPedidoController extends Controller
{
    public function __construct(protected MensajesPedidoCliente $mensajes) {}

    public function opciones(int $pedido): JsonResponse
    {
        return $this->responder(fn () => [
            'pedido_id' => $pedido,
            'opciones' => $this->mensajes->opciones($pedido, $this->actor()),
        ]);
    }

    public function mensaje(int $pedido, string $clave): JsonResponse
    {
        return $this->responder(fn () => $this->mensajes->generar($pedido, $clave, $this->actor()));
    }

    protected function responder(callable $accion): JsonResponse
    {
        try {
            return response()->json($accion())->header('Cache-Control', 'private, no-store');
        } catch (MensajePedidoNoDisponibleException $e) {
            return response()->json(['codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->estadoHttp());
        }
    }

    protected function actor()
    {
        return auth()->guard('admin')->user();
    }
}
