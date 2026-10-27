<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\Logistica\Services\Cuentas\RecaudoDesdeCobro;
use ByLopez\VentasAsesor\DataObjects\ResultadoRevisionPago;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\AlcanceDePagos;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\OrderTransactionRepository;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Confirmar un pago `sin_revisar` (PAG-E, D-59 / Doc. 05 §42.5): el dinero REPORTADO pasa a VERIFICADO. Es la unica
 * operacion donde nace una `order_transaction` nativa de Bagisto.
 *
 * CONFIRMAR NO VUELVE A BAJAR LA DEUDA: el saldo ya bajo cuando se registro el pago (PAG-C/PAG-D). Aqui solo cambia la
 * composicion (Sin revisar ↓ · Verificado ↑). El anti doble conteo es estructural: la Transaction se vincula a su
 * aplicacion (`order_transaction_id`) y por eso sale de las «Transactions ajenas» de CarteraConsultaService.
 *
 * Integracion con Bagisto 2.4.9 (microauditoria, Doc. 05 §42.21):
 *  - `order_transactions.invoice_id` es NOT NULL: una Transaction siempre cuelga de una factura. Sin factura utilizable
 *    NO se confirma (no se crean facturas aqui) y se rechaza con motivo claro.
 *  - Una Transaction pertenece a UN pedido: un pago multipedido genera UNA Transaction por aplicacion, cada una en su
 *    pedido, y cada aplicacion enlaza la suya (`order_transaction_id` es unico).
 *  - Igual que el flujo nativo (TransactionController::store): la factura debe estar abierta (no `paid`) y la suma de
 *    sus Transactions no puede superar su `base_grand_total`. Se elige, por pedido, la factura de menor id con
 *    capacidad para TODA la aplicacion (nunca se parte una aplicacion entre facturas: no podria enlazarse 1:1 y
 *    contaria doble). Si la factura queda cubierta pasa a `paid` y el pedido recalcula su estado con
 *    `OrderRepository::updateOrderStatus($order)` sin estado explicito (por cantidades); jamas un UPDATE directo.
 *  - `payment_method` de la Transaction es el metodo de pago DEL PEDIDO (un codigo que Bagisto conoce: su accesor
 *    `payment_title` revienta con codigos ajenos como `transferencia`). El medio de cobro real va en `type` y en
 *    `data` junto con la referencia y el vinculo al pago.
 *
 * Idempotencia: pago ya `confirmado` → devuelve el resultado estable sin escribir; la guarda de estado bajo bloqueo y
 * el UNIQUE de `order_transaction_id` impiden una segunda Transaction.
 */
class ConfirmarPagoService extends RevisionPagoService
{
    private const TOLERANCIA = 0.0001;

    public function __construct(
        AutorizacionVentasAsesor $autorizacion,
        CarteraConsultaService $consulta,
        FechaCorteCartera $fechaCorte,
        AlcanceDePagos $alcancePagos,
        protected OrderTransactionRepository $transacciones,
        protected InvoiceRepository $facturas,
        protected OrderRepository $pedidos,
    ) {
        parent::__construct($autorizacion, $consulta, $fechaCorte, $alcancePagos);
    }

    /**
     * @throws Rechazo
     */
    public function confirmar(AdminContract $actor, int $pagoId, string $mecanismo = MecanismoCreacion::VENTAS_ASESOR->value): ResultadoRevisionPago
    {
        $this->exigirRevisor($actor);

        return DB::transaction(fn () => $this->confirmarBajoBloqueo($actor, $pagoId, $mecanismo), self::REINTENTOS);
    }

    protected function confirmarBajoBloqueo(AdminContract $actor, int $pagoId, string $mecanismo): ResultadoRevisionPago
    {
        // 1) Pago bloqueado y dentro del alcance; la guarda de estado es la primera defensa contra el doble clic.
        $pago = $this->bloquearPago($actor, $pagoId);

        if ($pago->estado === EstadoPago::CONFIRMADO) {
            return $this->resultadoEstable($pago, true);
        }

        if (! $pago->estado->puedeTransicionarA(EstadoPago::CONFIRMADO)) {
            throw new Rechazo(Rechazo::ESTADO_NO_REVISABLE, 'Solo se puede confirmar un pago sin revisar.', ['estado' => $pago->estado->value]);
        }

        // Falla cerrado: los saldos y la revision estructural solo existen con Cartera activa.
        if (! $this->fechaCorte->activa()) {
            throw new Rechazo(Rechazo::CARTERA_INACTIVA, 'Cartera todavia no esta activa (no hay fecha de corte).');
        }

        // 2) Aplicaciones activas (bloqueadas) e invariantes del pago.
        $aplicaciones = $this->aplicacionesActivasBloqueadas($pago->id);

        $suma = (int) $aplicaciones->sum(fn ($a) => (int) round(((float) $a->monto) * 100));

        if ($aplicaciones->isEmpty()
            || $suma !== (int) round(((float) $pago->monto) * 100)
            || $aplicaciones->contains(fn ($a) => $a->order_transaction_id !== null)) {
            throw new Rechazo(Rechazo::APLICACIONES_INCONSISTENTES, 'Las aplicaciones del pago no son consistentes con su monto o ya tienen una Transaction enlazada.');
        }

        // 3) Pedidos (id ascendente) y despues facturas (id ascendente).
        $ids = $aplicaciones->pluck('order_id')->map(fn ($id) => (int) $id)->all();

        $pedidos = DB::table('orders')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'customer_id', 'increment_id'])->keyBy('id');

        if ($pedidos->count() !== count($ids) || $pedidos->contains(fn ($o) => (int) $o->customer_id !== (int) $pago->customer_id)) {
            throw new Rechazo(Rechazo::APLICACIONES_INCONSISTENTES, 'Alguno de los pedidos no existe o no es del cliente del pago.');
        }

        $facturas = DB::table('invoices')->whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'order_id', 'state', 'base_grand_total']);
        $pagado = DB::table('order_transactions')->whereIn('invoice_id', $facturas->pluck('id'))->sharedLock()
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) as total')->pluck('total', 'invoice_id');

        // 4) Revalidar con el estado REAL de Cartera: en Cartera y sin revision pendiente (Refund, sobreaplicacion...).
        $saldos = $this->consulta->saldosDePedidos($ids, lecturaActual: true);

        foreach ($ids as $orderId) {
            $saldo = $saldos[$orderId] ?? null;

            if ($saldo === null || ! $saldo->enCartera || $saldo->requiereRevision) {
                throw new Rechazo(Rechazo::PEDIDO_NO_CONFIRMABLE, 'Un pedido del pago ya no admite confirmacion.', [
                    'pedido' => $pedidos[$orderId]->increment_id,
                    'motivo' => $saldo?->motivoFuera,
                    'motivos_revision' => $saldo?->motivosRevision ?? [],
                ]);
            }
        }

        // 5) Una Transaction nativa por aplicacion, enlazada a ella. Cualquier fallo → rollback de TODO.
        $creadas = [];

        foreach ($aplicaciones as $aplicacion) {
            $orderId = (int) $aplicacion->order_id;
            $monto = (float) $aplicacion->monto;
            $factura = $this->elegirFactura($facturas->where('order_id', $orderId), $pagado, $monto, (string) $pedidos[$orderId]->increment_id);
            $metodo = $this->metodoDePagoDelPedido($orderId, (string) $pedidos[$orderId]->increment_id);

            $transaccion = $this->transacciones->create([
                'transaction_id' => 'BYL-PAGO-'.$pago->id.'-AP-'.$aplicacion->id,
                'status' => 'paid',
                'type' => $pago->medio_cobro,
                'payment_method' => $metodo,
                'invoice_id' => $factura->id,
                'order_id' => $orderId,
                'amount' => $aplicacion->monto,
                'data' => json_encode([
                    'origen' => 'bylopez_ventas_asesor',
                    'pago_id' => (int) $pago->id,
                    'aplicacion_id' => (int) $aplicacion->id,
                    'medio_cobro' => $pago->medio_cobro,
                    'referencia' => $pago->referencia,
                    'registrado_por_admin_id' => (int) $pago->registrado_por_admin_id,
                    'confirmado_por_admin_id' => (int) $actor->id,
                ]),
            ]);

            // El vinculo es lo que impide contar dos veces: la Transaction deja de ser «ajena» a ByLopez.
            $aplicacion->forceFill(['order_transaction_id' => $transaccion->id, 'invoice_id' => $factura->id])->save();

            $pagado[$factura->id] = (float) ($pagado[$factura->id] ?? 0) + $monto;

            // Como el flujo nativo: factura cubierta → `paid`; el pedido recalcula su estado sin estado explicito.
            if ($pagado[$factura->id] >= (float) $factura->base_grand_total - self::TOLERANCIA) {
                $this->facturas->updateState(Invoice::query()->findOrFail($factura->id), Invoice::STATUS_PAID);
                $this->pedidos->updateOrderStatus(OrderProxy::modelClass()::query()->findOrFail($orderId));
            }

            $creadas[] = [
                'aplicacion_id' => (int) $aplicacion->id,
                'order_id' => $orderId,
                'order_transaction_id' => (int) $transaccion->id,
                'invoice_id' => (int) $factura->id,
                'monto' => $monto,
            ];
        }

        // 6) El pago pasa a confirmado; el evento deja actor, mecanismo y las Transactions creadas.
        $pago->forceFill([
            'estado' => EstadoPago::CONFIRMADO->value,
            'revisado_por_admin_id' => (int) $actor->id,
            'revisado_en' => now(),
        ])->save();

        PagoEvento::query()->create([
            'pago_id' => $pago->id,
            'tipo' => TipoEventoPago::CONFIRMADO->value,
            'actor_admin_id' => (int) $actor->id,
            'mecanismo' => $mecanismo,
            'motivo' => null,
            'datos' => ['transacciones' => $creadas],
        ]);

        // C6: un cobro hecho por el transportador (pago con guia) deja ese dinero EN CUSTODIA: nace el recaudo de la cuenta corriente
        // por lo COBRADO. No toca Cartera; si falla, se deshace todo el pago.
        if ($pago->guia_id !== null) {
            app(RecaudoDesdeCobro::class)->reconocer((int) $pago->id, $actor);
        }

        return new ResultadoRevisionPago((int) $pago->id, EstadoPago::CONFIRMADO->value, false, $creadas);
    }

    /**
     * Factura de menor id, abierta y con capacidad para TODA la aplicacion (sin partirla entre facturas).
     *
     * @param  Collection  $facturas  facturas del pedido
     * @param  Collection<int|string, mixed>  $pagado  suma de Transactions por factura
     */
    protected function elegirFactura($facturas, $pagado, float $monto, string $incrementId): object
    {
        $motivo = 'sin_factura';

        foreach ($facturas->sortBy('id') as $factura) {
            if ($factura->state === Invoice::STATUS_PAID) {
                $motivo = 'facturas_pagadas';

                continue;
            }

            $capacidad = (float) $factura->base_grand_total - (float) ($pagado[$factura->id] ?? 0);

            if ($capacidad + self::TOLERANCIA >= $monto) {
                return $factura;
            }

            $motivo = 'capacidad_insuficiente';
        }

        throw new Rechazo(
            Rechazo::SIN_FACTURA_DISPONIBLE,
            'El pedido no tiene una factura abierta con capacidad para este monto: la Transaction de Bagisto exige una factura.',
            ['pedido' => $incrementId, 'motivo' => $motivo, 'monto' => $monto],
        );
    }

    /** Metodo de pago del pedido, solo si Bagisto lo conoce (su accesor `payment_title` falla con codigos ajenos). */
    protected function metodoDePagoDelPedido(int $orderId, string $incrementId): string
    {
        $metodo = (string) DB::table('order_payment')->where('order_id', $orderId)->value('method');

        if ($metodo === '' || ! array_key_exists($metodo, (array) config('payment_methods'))) {
            throw new Rechazo(Rechazo::METODO_PAGO_PEDIDO_INVALIDO, 'El pedido no tiene un metodo de pago que Bagisto reconozca.', ['pedido' => $incrementId, 'metodo' => $metodo]);
        }

        return $metodo;
    }

    /** Resultado estable de un pago ya confirmado: las Transactions enlazadas a sus aplicaciones. */
    protected function resultadoEstable(Pago $pago, bool $yaProcesado): ResultadoRevisionPago
    {
        $transacciones = DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pago->id)->whereNotNull('order_transaction_id')
            ->orderBy('order_id')->get(['id', 'order_id', 'order_transaction_id', 'invoice_id', 'monto'])
            ->map(fn ($a) => [
                'aplicacion_id' => (int) $a->id,
                'order_id' => (int) $a->order_id,
                'order_transaction_id' => (int) $a->order_transaction_id,
                'invoice_id' => (int) $a->invoice_id,
                'monto' => (float) $a->monto,
            ])->all();

        return new ResultadoRevisionPago((int) $pago->id, $pago->estado->value, $yaProcesado, $transacciones);
    }
}
