<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\Http\Controllers\Concerns\ResponderOperacionesLogisticas;
use ByLopez\Logistica\Models\IncidenciaStock;
use ByLopez\Logistica\Services\Stock\IncidenciasStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\User\Contracts\Admin;

/**
 * Logistica › Despachos › Incidencias de stock. Controlador delgado: el servicio valida permiso, conteos, confirmacion
 * e idempotencia. BODEGA solo SOLICITA; regularizar y rechazar son del Administrador (se valida en el servicio).
 */
class IncidenciasStockController extends Controller
{
    use ResponderOperacionesLogisticas;

    public function __construct(protected IncidenciasStockService $incidencias) {}

    public function solicitar(int $pedido, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($pedido, $request) {
            $incidencia = $this->incidencias->solicitar(
                $this->actor(), $pedido, (array) $request->input('conteos', []), $request->input('nota'), (string) $request->input('idempotency_key'),
            );

            return $this->respuesta($incidencia, 'solicitada');
        });
    }

    /** El Administrador solicita y resuelve en una sola operacion (queda como incidencia con solicitante y resolutor). */
    public function regularizarDirecto(int $pedido, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($pedido, $request) {
            $incidencia = $this->incidencias->regularizarDirecto(
                $this->actor(), $pedido, (array) $request->input('conteos', []), $request->input('motivo'),
                $request->boolean('confirma_conteo_fisico'), (string) $request->input('idempotency_key'),
            );

            return $this->respuesta($incidencia, 'regularizada');
        });
    }

    public function detalle(int $incidencia): JsonResponse
    {
        return $this->envolver(fn () => ['incidencia' => $this->incidencias->detalle($this->actor(), $incidencia)]);
    }

    public function regularizar(int $incidencia, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($incidencia, $request) {
            $resuelta = $this->incidencias->regularizar(
                $this->actor(), $incidencia, (array) $request->input('conteos', []), $request->input('motivo'), $request->boolean('confirma_conteo_fisico'),
            );

            return $this->respuesta($resuelta, 'regularizada');
        });
    }

    public function rechazar(int $incidencia, Request $request): JsonResponse
    {
        return $this->envolver(function () use ($incidencia, $request) {
            return $this->respuesta($this->incidencias->rechazar($this->actor(), $incidencia, $request->input('motivo')), 'rechazada');
        });
    }

    /** @return array<string, mixed> */
    private function respuesta(IncidenciaStock $incidencia, string $accion): array
    {
        return [
            'incidencia_id' => (int) $incidencia->id,
            'pedido_id' => (int) $incidencia->order_id,
            'estado' => $incidencia->estado->value,
            'mensaje' => trans('logistica::app.despachos.stock.flash.'.$accion),
        ];
    }

    private function actor(): ?Admin
    {
        return auth()->guard('admin')->user();
    }
}
