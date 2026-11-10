<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento as Mov;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * D10 V1 — REEMBOLSAR saldo a favor: SALIDA REAL de dinero de ByLopez al cliente. No usa Bagisto Refund (marcaria el pedido como
 * `pedido_con_reembolso` y pediria revision en Cartera) ni crea Pago, Transaction, recaudo ni custodia: solo consume credito en el
 * ledger (`reembolso`) con medio, referencia, motivo y actor.
 *
 * Solo se reembolsa dinero VERIFICADO y RECIBIDO por ByLopez. Si el origen sigue en custodia del transportador, se BLOQUEA con un
 * mensaje claro (el reembolso directo desde la transportadora queda para V2). Un reembolso NO se reversa en V1.
 *
 * Bloqueo: pedido origen `FOR UPDATE` y despues credito releido con lectura bloqueante: reembolso y aplicacion simultaneos del
 * mismo credito se serializan y solo gana uno (el disponible nunca queda negativo).
 */
class ReembolsarCreditoService extends OperacionCredito
{
    /**
     * @return array{movimiento_id: int, monto: float, disponible_despues: float, duplicado: bool}
     *
     * @throws Rechazo
     */
    public function reembolsar(AdminContract $actor, int $origenId, mixed $monto, string $medio, ?string $referencia, string $motivo, string $idempotencyKey, ?string $comprobanteRuta = null): array
    {
        $this->exigir($actor, AutorizacionVentasAsesor::PERMISO_CREDITO_REEMBOLSAR);

        $clave = 'ree:'.$this->clave($idempotencyKey);
        $monto = $this->monto($monto);
        $motivo = $this->motivo($motivo, true);
        $medio = trim($medio);
        $referencia = trim((string) $referencia) === '' ? null : mb_substr(trim((string) $referencia), 0, 100);

        if (! in_array($medio, (array) config('ventasasesor.credito.medios_devolucion', []), true)) {
            throw new Rechazo(Rechazo::MEDIO_INVALIDO, 'El medio de devolucion no es valido.');
        }

        if (in_array($medio, (array) config('ventasasesor.credito.medios_con_referencia', []), true) && $referencia === null && $comprobanteRuta === null) {
            throw new Rechazo(Rechazo::REFERENCIA_REQUERIDA, 'Indica la referencia o adjunta el comprobante de la transferencia.');
        }

        $huella = $this->huella(['o' => $origenId, 'm' => $monto, 'medio' => $medio, 'ref' => $referencia]);

        if ($previo = $this->repeticion($clave, $huella)) {
            return $this->estable($previo);
        }

        try {
            return DB::transaction(function () use ($actor, $origenId, $monto, $medio, $referencia, $motivo, $clave, $huella, $comprobanteRuta) {
                $pedidos = $this->bloquearPedidos([$origenId]);

                if ($previo = $this->repeticion($clave, $huella, true)) {
                    return $this->estable($previo);
                }

                $c = $this->credito->dePedido($origenId, true);
                $valor = (float) $monto;

                if ($valor > $c['disponible'] + 0.000001) {
                    $this->rechazarNoDisponible($c, $valor);
                }

                $mov = $this->registrarMovimiento([
                    'customer_id' => (int) $pedidos[$origenId]->customer_id, 'tipo' => Mov::REEMBOLSO, 'order_origen_id' => $origenId, 'monto' => $monto,
                    'medio_devolucion' => $medio, 'referencia' => $referencia, 'comprobante_ruta' => $comprobanteRuta, 'motivo' => $motivo,
                    'actor_admin_id' => (int) $actor->id, 'actor_nombre' => $this->nombre($actor), 'idempotency_key' => $clave, 'huella_solicitud' => $huella,
                    'datos' => ['fecha' => now()->toDateString(), 'credito_generado' => $c['generado'], 'credito_usado_antes' => $c['usado'], 'recibido' => $c['recibido']],
                ]);

                return ['movimiento_id' => (int) $mov->id, 'monto' => $valor, 'disponible_despues' => round($c['disponible'] - $valor, 2), 'duplicado' => false];
            }, self::INTENTOS);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') && ($previo = $this->repeticion($clave, $huella))) {
                return $this->estable($previo);
            }

            throw $e;
        }
    }

    protected function rechazarNoDisponible(array $c, float $valor): never
    {
        $economicoLibre = round($c['economico'] - $c['usado'], 2);

        if ($valor <= $economicoLibre + 0.000001) {
            if ($c['en_custodia'] > 0) {
                throw new Rechazo(Rechazo::CREDITO_EN_CUSTODIA, 'El transportador aun tiene el dinero; primero debe liquidarse y conciliarse antes de reembolsar.', ['en_custodia' => $c['en_custodia'], 'disponible' => $c['disponible']]);
            }

            throw new Rechazo(Rechazo::CREDITO_ORIGEN_SIN_RECEPCION, 'El dinero que origina este credito aun no esta verificado y recibido por ByLopez.', ['sin_recibir' => $c['sin_recibir'], 'disponible' => $c['disponible']]);
        }

        throw new Rechazo(Rechazo::CREDITO_INSUFICIENTE, 'El credito disponible no alcanza para ese monto.', ['disponible' => $c['disponible'], 'monto' => $valor]);
    }

    /** @return array{movimiento_id: int, monto: float, disponible_despues: float, duplicado: bool} */
    protected function estable(object $m): array
    {
        return ['movimiento_id' => (int) $m->id, 'monto' => (float) $m->monto, 'disponible_despues' => $this->credito->dePedido((int) $m->order_origen_id)['disponible'], 'duplicado' => true];
    }
}
