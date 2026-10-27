<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Http\Controllers\Concerns\ResponderOperacionesLogisticas;
use ByLopez\Logistica\Services\Guias\CorregirGuiaService;
use ByLopez\Logistica\Services\Guias\GuiaArchivoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Webkul\User\Contracts\Admin;

/** PDF de la guia: ver/descargar (autenticado y autorizado en el servicio) y adjuntar/reemplazar con motivo. */
class GuiasController extends Controller
{
    use ResponderOperacionesLogisticas;

    public function __construct(protected GuiaArchivoService $archivos) {}

    public function archivo(int $guia, string $modo): Response
    {
        try {
            return $this->archivos->responder($this->actor(), $guia, $modo);
        } catch (Invalida $e) {
            abort(in_array($e->codigo, [Invalida::GUIA_INEXISTENTE, Invalida::GUIA_SIN_ARCHIVO], true) ? 404 : 403);
        }
    }

    public function guardarArchivo(int $guia, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($guia, $request) {
            $this->exigirCuerpoCompleto($request);

            $archivo = $request->file('archivo') ?? throw new Invalida(Invalida::ARCHIVO_NO_RECIBIDO);
            $version = $this->archivos->adjuntarOReemplazar($this->actor(), $guia, $archivo, $request->input('motivo'));

            return [
                'guia_id' => $guia,
                'version' => $version->version,
                'pedido_id' => (int) $version->guia->order_id,
                'mensaje' => trans('logistica::app.guias.flash.guardado'),
            ];
        });
    }

    /** Corregir numero y/o transportadora de la guia (motivo obligatorio). No toca el Shipment, el inventario ni Cartera. */
    public function corregir(int $guia, Request $request, CorregirGuiaService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($guia, $request, $servicio) {
            $c = $servicio->corregir(
                $this->actor(), $guia, $request->input('numero_guia'), $request->input('transportadora'), $request->input('motivo'),
            );

            return [
                'guia_id' => $guia,
                'correccion_id' => $c->id,
                'pedido_id' => $c->order_id,
                'mensaje' => trans('logistica::app.guias.flash.corregida', ['anterior' => $c->numero_anterior, 'nuevo' => $c->numero_nuevo]),
            ];
        });
    }

    private function actor(): ?Admin
    {
        return auth()->guard('admin')->user();
    }
}
