<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Guias\GuiaArchivoService;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ver / descargar la guia (PDF) de un pedido desde Ventas Asesor. Es la puerta del ASESOR: la ruta vive aqui porque su
 * clave ACL es `ventas_asesor.pedidos.view_own` (el asesor no tiene ninguna clave `logistica.*`). La autorizacion real
 * es del servicio de Logistica (contrato AccesoGuiaPedido → propiedad comercial del pedido), no de la ruta: un asesor
 * pidiendo la guia de un pedido AJENO recibe 403. Solo lectura: nada de adjuntar, reemplazar ni borrar.
 */
class GuiaPedidoController extends Controller
{
    public function __construct(protected GuiaArchivoService $archivos) {}

    public function archivo(int $pedido, int $guia, string $modo): Response
    {
        try {
            return $this->archivos->responder(auth()->guard('admin')->user(), $guia, $modo, $pedido);
        } catch (Invalida $e) {
            abort(in_array($e->codigo, [Invalida::GUIA_INEXISTENTE, Invalida::GUIA_SIN_ARCHIVO], true) ? 404 : 403);
        }
    }
}
