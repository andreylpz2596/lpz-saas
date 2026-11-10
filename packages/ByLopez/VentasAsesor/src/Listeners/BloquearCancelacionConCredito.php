<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * D10 V1 — un pedido con CREDITO aplicado ACTIVO no se cancela: primero se reversa el credito (la cancelacion NO lo reversa sola).
 * Protege la cancelacion NATIVA de Bagisto (`OrderRepository::cancel`, boton del panel o codigo) que no revisa el dinero aplicado:
 * sin esto el pedido pasaria a cancelado (fuera de Cartera) con el credito atrapado. Se detiene ANTES de cualquier cambio
 * (`sales.order.cancel.before`). Misma forma que el bloqueo de Logistica: volver a la ficha con el aviso en el panel, excepcion en codigo.
 *
 * El otro camino de cancelacion (CancelarPendienteService) ya se bloquea solo: el credito cuenta como dinero verificado aplicado.
 */
class BloquearCancelacionConCredito
{
    public const EVENTO = 'sales.order.cancel.before';

    public function __construct(protected CreditoClienteService $credito) {}

    public function handle($pedido): void
    {
        $id = (int) ($pedido->id ?? $pedido);
        $aplicado = $this->credito->aplicadoActivoEnDestino($id, true);

        if ($aplicado <= 0) {
            return;
        }

        $mensaje = trans('ventasasesor::credito.errores.cancelacion_con_credito', ['monto' => '$ '.number_format($aplicado, 0, ',', '.')]);

        if (request()->route() !== null && request()->hasSession() && ! request()->expectsJson()) {
            throw new HttpResponseException(redirect()->back()->with('error', $mensaje));
        }

        throw new Rechazo(Rechazo::CREDITO_APLICADO_ACTIVO, $mensaje, ['pedido' => $id, 'credito_aplicado' => $aplicado]);
    }
}
