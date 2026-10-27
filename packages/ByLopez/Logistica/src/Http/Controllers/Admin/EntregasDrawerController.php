<?php

namespace ByLopez\Logistica\Http\Controllers\Admin;

use ByLopez\Logistica\DataObjects\SolicitudRecepcion;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Http\Controllers\Concerns\ArmaSolicitudEvento;
use ByLopez\Logistica\Http\Controllers\Concerns\ResponderOperacionesLogisticas;
use ByLopez\Logistica\Services\Entregas\AnularEventoLogisticoService;
use ByLopez\Logistica\Services\Entregas\CobrarEntregaService;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Entregas\ContextoEntregaService;
use ByLopez\Logistica\Services\Entregas\RecibirDevolucionService;
use ByLopez\Logistica\Services\Entregas\RegistrarEntregaService;
use ByLopez\Logistica\Services\Entregas\RegistrarRetornoTransportadoraService;
use ByLopez\Logistica\Services\Entregas\ValidarEntregaService;
use ByLopez\Logistica\Support\FuentePanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\User\Contracts\Admin;

/**
 * Drawer unico de Entregas (JSON). Es una CAPA DELGADA: cada operacion llama al mismo servicio de siempre
 * (RegistrarEntregaService, RegistrarRetornoTransportadoraService, AnularEventoLogisticoService) y la recepcion fisica a
 * RecibirDevolucionService; el servidor vuelve a validar permisos, idempotencia y cantidades. Tras guardar devuelve el
 * contexto fresco del envio para que el drawer y la bandeja se actualicen sin recargar.
 */
class EntregasDrawerController extends Controller
{
    use ArmaSolicitudEvento;
    use ResponderOperacionesLogisticas;

    public function __construct(
        protected ContextoEntregaService $contexto,
        protected ConsultaEntregaService $consulta,
        protected FuentePanel $fuentes,
    ) {}

    public function contexto(int $envio): JsonResponse
    {
        return $this->envolver(fn () => ['contexto' => $this->contexto->paraEnvio($envio, $this->actor())]);
    }

    public function entregar(int $envio, Request $request, RegistrarEntregaService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($envio, $request, $servicio) {
            $admin = $this->actor();
            $detalle = $this->consulta->envio($envio) ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);
            $codigo = $detalle['envio']->carrier_code ?: $request->input('carrier_code');

            $r = $servicio->registrar($admin, $this->solicitudEvento($envio, $request,
                $this->fuentes->paraEntrega($admin, $codigo, $detalle['envio']->shipping_method)));

            return $this->respuesta($envio, $r->yaExistia ? 'entrega-ya-registrada' : 'entrega-registrada', $r->yaExistia);
        });
    }

    public function devolver(int $envio, Request $request, RegistrarRetornoTransportadoraService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($envio, $request, $servicio) {
            $admin = $this->actor();
            $r = $servicio->registrar($admin, $this->solicitudEvento($envio, $request, $this->fuentes->paraRetorno($admin)));

            return $this->respuesta($envio, $r->yaExistia ? 'devolucion-ya-registrada' : 'devolucion-registrada', $r->yaExistia);
        });
    }

    /** Recepcion fisica en bodega + reingreso de las aptas (una sola operacion). */
    public function recibir(int $retorno, Request $request, RecibirDevolucionService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($retorno, $request, $servicio) {
            $r = $servicio->recibir($this->actor(), new SolicitudRecepcion(
                retornoId: $retorno,
                fecha: (string) $request->input('fecha'),
                lineas: (array) $request->input('lineas', []),
                idempotencyKey: (string) $request->input('idempotency_key'),
                observacion: $request->input('observacion'),
            ));

            return $this->respuesta((int) $r->recepcion->shipment_id, $r->yaExistia ? 'recepcion-ya-registrada' : 'recepcion-registrada', $r->yaExistia);
        });
    }

    /** El administrador ratifica la entrega (hito para comision y cierre). No toca pagos: el cobro va por Cartera. */
    public function validar(int $envio, Request $request, ValidarEntregaService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($envio, $request, $servicio) {
            $r = $servicio->validar($this->actor(), $envio, (string) $request->input('idempotency_key'), $request->input('observacion'));

            return $this->respuesta($envio, $r['ya_existia'] ? 'validacion-ya-registrada' : 'validacion-registrada', $r['ya_existia']);
        });
    }

    /** Cobro contra entrega: registra (y confirma si puede) el pago por Cartera; la factura/pedido se cierran por esa via. */
    public function cobrar(int $envio, Request $request, CobrarEntregaService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($envio, $request, $servicio) {
            $r = $servicio->cobrar(
                $this->actor(), $envio, (string) $request->input('monto'), (string) $request->input('medio_cobro'),
                $request->input('referencia'), $request->input('fecha_pago'), (string) $request->input('idempotency_key'),
            );

            return $this->respuesta($envio, $r['confirmado'] ? ($r['duplicado'] ? 'cobro-ya-registrado' : 'cobro-confirmado') : 'cobro-por-confirmar', $r['duplicado']) + ['aviso' => $r['aviso']];
        });
    }

    public function anularValidacion(int $validacion, Request $request, ValidarEntregaService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($validacion, $request, $servicio) {
            $v = $servicio->anular($this->actor(), $validacion, $request->input('motivo'));

            return $this->respuesta((int) $v->shipment_id, 'validacion-anulada');
        });
    }

    public function anularEntrega(int $entrega, Request $request, AnularEventoLogisticoService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($entrega, $request, $servicio) {
            $e = $servicio->anularEntrega($this->actor(), $entrega, $request->input('motivo'));

            return $this->respuesta((int) $e->shipment_id, 'entrega-anulada');
        });
    }

    public function anularDevolucion(int $retorno, Request $request, AnularEventoLogisticoService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($retorno, $request, $servicio) {
            $r = $servicio->anularRetorno($this->actor(), $retorno, $request->input('motivo'));

            return $this->respuesta((int) $r->shipment_id, 'devolucion-anulada');
        });
    }

    public function anularRecepcion(int $recepcion, Request $request, AnularEventoLogisticoService $servicio): JsonResponse
    {
        return $this->envolver(function () use ($recepcion, $request, $servicio) {
            $r = $servicio->anularRecepcion($this->actor(), $recepcion, $request->input('motivo'));

            return $this->respuesta((int) $r->shipment_id, 'recepcion-anulada');
        });
    }

    /** @return array<string, mixed> */
    private function respuesta(int $envio, string $mensaje, bool $yaExistia = false): array
    {
        return [
            'envio_id' => $envio,
            'ya_existia' => $yaExistia,
            'mensaje' => trans('logistica::app.drawer.flash.'.$mensaje),
            'contexto' => $this->contexto->paraEnvio($envio, $this->actor()),
        ];
    }

    private function actor(): ?Admin
    {
        return auth()->guard('admin')->user();
    }
}
