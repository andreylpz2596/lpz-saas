<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\AjusteObligacion;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * D10 V1 — AJUSTES DE OBLIGACION (hoy: condonacion de flete). Reducen lo que el cliente debe sin tocar `orders.shipping_amount` ni
 * ningun importe historico: Cartera resta los ajustes ACTIVOS del total operativo. Si el cliente ya habia pagado, la diferencia
 * aparece como saldo a favor (credito derivado) sujeto a las mismas reglas de recepcion de dinero.
 *
 * Politica de flete aprobada: NO se condona automaticamente por «cliente rechaza / cambia de opinion»; se registra un ajuste solo
 * con causa `error_bylopez`, `transportadora` o `decision_administrativa` (esta ultima para parcial/ambiguo, nunca prorrateado solo)
 * y SIEMPRE con motivo. El tope es el flete cobrado en el pedido menos lo ya condonado.
 *
 * Reversar un ajuste solo procede si no deja credito ya consumido sin respaldo (el disponible nunca queda negativo).
 */
class AjusteObligacionService extends OperacionCredito
{
    public function __construct(
        AutorizacionVentasAsesor $autorizacion,
        CarteraConsultaService $cartera,
        CreditoClienteService $credito,
        FechaCorteCartera $fechaCorte,
        protected SatisfaccionFacturaService $facturas,
    ) {
        parent::__construct($autorizacion, $cartera, $credito, $fechaCorte);
    }

    /**
     * @return array{ajuste_id: int, monto: float, duplicado: bool}
     *
     * @throws Rechazo
     */
    public function condonarFlete(AdminContract $actor, int $orderId, mixed $monto, string $causa, string $motivo, string $idempotencyKey): array
    {
        $this->exigir($actor, AutorizacionVentasAsesor::PERMISO_CREDITO_AJUSTAR);

        $clave = 'aju:'.$this->clave($idempotencyKey);
        $monto = $this->monto($monto);
        $motivo = $this->motivo($motivo, true);

        if (! in_array($causa, AjusteObligacion::CAUSAS, true)) {
            throw new Rechazo(Rechazo::CAUSA_INVALIDA, 'La causa del ajuste no es valida.');
        }

        $huella = $this->huella(['o' => $orderId, 'm' => $monto, 'c' => $causa]);

        if ($previo = AjusteObligacion::query()->where('idempotency_key', $clave)->first()) {
            return $this->estableAlta($previo, $huella);
        }

        try {
            return DB::transaction(function () use ($actor, $orderId, $monto, $causa, $motivo, $clave, $huella) {
                $pedidos = $this->bloquearPedidos([$orderId]);

                if ($previo = AjusteObligacion::query()->where('idempotency_key', $clave)->sharedLock()->first()) {
                    return $this->estableAlta($previo, $huella);
                }

                $saldo = $this->cartera->saldosDePedidos([$orderId], true)[$orderId] ?? null;

                if ($saldo === null || ! $saldo->enCartera) {
                    throw new Rechazo(Rechazo::DESTINO_NO_ADMITE, 'El pedido no esta en Cartera: no admite ajustes de obligacion.', ['motivo' => $saldo?->motivoFuera]);
                }

                $flete = (float) DB::table('orders')->where('id', $orderId)->value('base_shipping_amount');
                $yaCondonado = (float) DB::table('bylopez_va_ajustes_obligacion')->where('order_id', $orderId)->where('concepto', AjusteObligacion::CONCEPTO_FLETE)
                    ->where('estado', AjusteObligacion::ACTIVO)->sharedLock()->sum('monto');

                if ((float) $monto > round($flete - $yaCondonado, 2) + 0.000001) {
                    throw new Rechazo(Rechazo::AJUSTE_EXCEDE_FLETE, 'El ajuste supera el flete cobrado en el pedido menos lo ya condonado.', ['flete' => $flete, 'condonado' => $yaCondonado]);
                }

                $ajuste = AjusteObligacion::query()->create([
                    'order_id' => $orderId, 'customer_id' => (int) $pedidos[$orderId]->customer_id, 'concepto' => AjusteObligacion::CONCEPTO_FLETE,
                    'causa' => $causa, 'monto' => $monto, 'motivo' => $motivo, 'estado' => AjusteObligacion::ACTIVO,
                    'actor_admin_id' => (int) $actor->id, 'actor_nombre' => $this->nombre($actor), 'idempotency_key' => $clave, 'huella_solicitud' => $huella,
                    'datos' => ['flete_pedido' => $flete, 'condonado_antes' => $yaCondonado, 'saldo_antes' => $saldo->saldo, 'total_operativo_antes' => $saldo->totalOperativo],
                ]);

                $this->facturas->sincronizar($orderId);

                return ['ajuste_id' => (int) $ajuste->id, 'monto' => (float) $monto, 'duplicado' => false];
            }, self::INTENTOS);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') && ($previo = AjusteObligacion::query()->where('idempotency_key', $clave)->first())) {
                return $this->estableAlta($previo, $huella);
            }

            throw $e;
        }
    }

    /**
     * @return array{ajuste_id: int, monto: float, duplicado: bool}
     *
     * @throws Rechazo
     */
    public function reversar(AdminContract $actor, int $ajusteId, string $motivo, string $idempotencyKey): array
    {
        $this->exigir($actor, AutorizacionVentasAsesor::PERMISO_CREDITO_AJUSTAR);

        $clave = 'ajr:'.$this->clave($idempotencyKey);
        $motivo = $this->motivo($motivo, true);
        $fila = AjusteObligacion::query()->find($ajusteId) ?? throw new Rechazo(Rechazo::AJUSTE_INEXISTENTE, 'El ajuste no existe.');

        if ($fila->reverso_idempotency_key === $clave) {
            return ['ajuste_id' => (int) $fila->id, 'monto' => (float) $fila->monto, 'duplicado' => true];
        }

        return DB::transaction(function () use ($actor, $fila, $motivo, $clave) {
            $this->bloquearPedidos([(int) $fila->order_id]);
            $ajuste = AjusteObligacion::query()->lockForUpdate()->findOrFail($fila->id);

            if ($ajuste->reverso_idempotency_key === $clave) {
                return ['ajuste_id' => (int) $ajuste->id, 'monto' => (float) $ajuste->monto, 'duplicado' => true];
            }

            if ($ajuste->estado !== AjusteObligacion::ACTIVO) {
                throw new Rechazo(Rechazo::AJUSTE_YA_REVERSADO, 'Este ajuste ya fue reversado.');
            }

            $ajuste->forceFill([
                'estado' => AjusteObligacion::REVERSADO, 'reversado_en' => now(), 'reversado_por_admin_id' => (int) $actor->id,
                'motivo_reverso' => $motivo, 'reverso_idempotency_key' => $clave,
            ])->save();

            // Sin el ajuste puede desaparecer credito que ya se consumio: el disponible NUNCA queda negativo (rollback si ocurre).
            $c = $this->credito->dePedido((int) $ajuste->order_id, true);

            if ($c['usado'] > $c['generado'] + 0.004) {
                throw new Rechazo(Rechazo::REVERSO_DEJA_CREDITO_NEGATIVO, 'Este ajuste origino credito que ya fue aplicado o reembolsado; regulariza primero esos movimientos.', ['generado' => $c['generado'], 'usado' => $c['usado']]);
            }

            $this->facturas->sincronizar((int) $ajuste->order_id);

            return ['ajuste_id' => (int) $ajuste->id, 'monto' => (float) $ajuste->monto, 'duplicado' => false];
        }, self::INTENTOS);
    }

    /** @return array{ajuste_id: int, monto: float, duplicado: bool} */
    protected function estableAlta(AjusteObligacion $a, string $huella): array
    {
        if (! hash_equals((string) $a->huella_solicitud, $huella)) {
            throw new Rechazo(Rechazo::IDEMPOTENCIA_CONFLICTO, 'Esa clave de idempotencia ya se uso con una solicitud distinta.');
        }

        return ['ajuste_id' => (int) $a->id, 'monto' => (float) $a->monto, 'duplicado' => true];
    }
}
