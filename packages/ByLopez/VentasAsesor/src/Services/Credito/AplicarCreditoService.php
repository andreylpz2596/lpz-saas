<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento as Mov;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * D10 V1 — APLICAR el saldo a favor de un pedido ORIGEN a la deuda de otro pedido DESTINO del MISMO cliente. Explicito (nunca
 * automatico), atomico, idempotente y auditable.
 *
 * Que crea (todo o nada): un Pago `credito_cliente` NACIDO `confirmado`, su AplicacionPago al destino, sus eventos
 * (registrado + confirmado, con el origen), y UNA fila del ledger (origen → destino → monto). Que NO crea: Transaction de Bagisto,
 * Refund, recaudo/custodia/movimiento de C6 ni caja. El credito satisface la obligacion; NO es dinero nuevo.
 *
 * Orden de bloqueos: pedidos origen y destino `FOR UPDATE` por ID ascendente; DESPUES, todo se valida con lecturas BLOQUEANTES.
 * Validaciones (todas bajo el bloqueo): mismo cliente, credito disponible (solo dinero verificado y RECIBIDO), destino en Cartera
 * sin revision y con saldo, y C1: la suma de recaudos comprometidos en guias del destino no puede superar lo exigible tras aplicar.
 */
class AplicarCreditoService extends OperacionCredito
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
     * @return array{movimiento_id: int, pago_id: int, monto: float, duplicado: bool}
     *
     * @throws Rechazo
     */
    public function aplicar(AdminContract $actor, int $origenId, int $destinoId, mixed $monto, string $idempotencyKey, ?string $motivo = null): array
    {
        $this->exigir($actor, AutorizacionVentasAsesor::PERMISO_CREDITO_APLICAR);

        if ($origenId === $destinoId) {
            throw new Rechazo(Rechazo::MISMO_PEDIDO, 'El credito no se puede aplicar al mismo pedido que lo origina.');
        }

        $clave = 'apl:'.$this->clave($idempotencyKey);
        $monto = $this->monto($monto);
        $motivo = $this->motivo($motivo, false);
        $huella = $this->huella(['o' => $origenId, 'd' => $destinoId, 'm' => $monto]);

        if ($previo = $this->repeticion($clave, $huella)) {
            return $this->estable($previo);
        }

        try {
            return DB::transaction(fn () => $this->bajoBloqueo($actor, $origenId, $destinoId, $monto, $clave, $huella, $motivo), self::INTENTOS);
        } catch (QueryException $e) {
            // Carrera: otra peticion con la misma clave confirmo entre la lectura y el INSERT.
            if (str_contains($e->getMessage(), 'Duplicate entry') && ($previo = $this->repeticion($clave, $huella))) {
                return $this->estable($previo);
            }

            throw $e;
        }
    }

    protected function bajoBloqueo(AdminContract $actor, int $origenId, int $destinoId, string $monto, string $clave, string $huella, ?string $motivo): array
    {
        // 1) Bloqueo (id ascendente). A partir de aqui nadie mas consume ese credito ni aplica dinero al destino.
        $pedidos = $this->bloquearPedidos([$origenId, $destinoId]);
        $customerId = (int) $pedidos[$origenId]->customer_id;

        if ($previo = $this->repeticion($clave, $huella, true)) {
            return $this->estable($previo);
        }

        // 2) Validar con lecturas ACTUALES (bloqueantes), nunca con lo que vio la pantalla ni una lectura previa.
        $valor = (float) $monto;
        $destino = $this->cartera->saldosDePedidos([$destinoId], true)[$destinoId] ?? null;

        if ($destino === null || ! $destino->enCartera) {
            throw new Rechazo(Rechazo::DESTINO_NO_ADMITE, 'El pedido destino no esta en Cartera o no admite aplicar credito.', ['motivo' => $destino?->motivoFuera]);
        }

        if ($destino->requiereRevision) {
            throw new Rechazo(Rechazo::DESTINO_NO_ADMITE, 'El pedido destino requiere revision antes de aplicarle credito.', ['motivos' => $destino->motivosRevision]);
        }

        if ($valor > $destino->saldo + 0.000001) {
            throw new Rechazo(Rechazo::MONTO_EXCEDE_SALDO, 'El monto supera el saldo pendiente del pedido destino.', ['saldo' => $destino->saldo, 'monto' => $valor]);
        }

        $c = $this->credito->dePedido($origenId, true);

        if ($valor > $c['disponible'] + 0.000001) {
            $this->rechazarCreditoNoDisponible($c, $valor);
        }

        $this->exigirRecaudoCompatible($destinoId, $destino->saldo, $valor);

        // 3) El pago nace CONFIRMADO: es una compensacion ya verificada contra dinero real recibido; no pasa por la revision de dinero.
        $pago = Pago::query()->create([
            'customer_id' => $customerId, 'monto' => $monto, 'medio_cobro' => CreditoClienteService::MEDIO, 'guia_id' => null,
            'fecha_pago' => now()->toDateString(),
            'observacion' => mb_substr('Credito del pedido '.$pedidos[$origenId]->increment_id.($motivo ? ' · '.$motivo : ''), 0, 500),
            'estado' => EstadoPago::CONFIRMADO->value, 'mecanismo' => 'ventas_asesor',
            'idempotency_key' => $clave, 'huella_solicitud' => $huella,
            'registrado_por_admin_id' => (int) $actor->id, 'revisado_por_admin_id' => (int) $actor->id, 'revisado_en' => now(),
        ]);

        $aplicacion = AplicacionPago::query()->create(['pago_id' => $pago->id, 'order_id' => $destinoId, 'monto' => $monto, 'posicion' => 1]);

        $datos = ['compensacion' => true, 'order_origen_id' => $origenId, 'order_destino_id' => $destinoId, 'monto' => $valor,
            'saldo_destino_antes' => $destino->saldo, 'credito_disponible_antes' => $c['disponible']];

        foreach ([TipoEventoPago::REGISTRADO, TipoEventoPago::CONFIRMADO] as $tipo) {
            PagoEvento::query()->create(['pago_id' => $pago->id, 'tipo' => $tipo->value, 'actor_admin_id' => (int) $actor->id, 'mecanismo' => 'ventas_asesor', 'motivo' => null, 'datos' => $datos]);
        }

        $mov = $this->registrarMovimiento([
            'customer_id' => $customerId, 'tipo' => Mov::APLICACION, 'order_origen_id' => $origenId, 'order_destino_id' => $destinoId, 'monto' => $monto,
            'pago_id' => $pago->id, 'aplicacion_pago_id' => $aplicacion->id, 'recepcion_origen_id' => $this->recepcionInformativa($origenId),
            'motivo' => $motivo, 'actor_admin_id' => (int) $actor->id, 'actor_nombre' => $this->nombre($actor),
            'idempotency_key' => $clave, 'huella_solicitud' => $huella,
            'datos' => $datos + ['credito_generado' => $c['generado'], 'credito_usado_antes' => $c['usado']],
        ]);

        // 4) La factura del destino queda «satisfecha» si dinero real + compensacion cubren su total (sin crear Transaction).
        $this->facturas->sincronizar($destinoId);

        return ['movimiento_id' => (int) $mov->id, 'pago_id' => (int) $pago->id, 'monto' => $valor, 'duplicado' => false];
    }

    /** Mensaje correcto segun por que el credito economico no es utilizable (custodia, sin recepcion o simplemente insuficiente). */
    protected function rechazarCreditoNoDisponible(array $c, float $valor): never
    {
        $economicoLibre = round($c['economico'] - $c['usado'], 2);

        if ($valor <= $economicoLibre + 0.000001) {
            if ($c['en_custodia'] > 0) {
                throw new Rechazo(Rechazo::CREDITO_EN_CUSTODIA, 'El transportador aun tiene el dinero que origina este credito; primero debe liquidarse y conciliarse.', ['en_custodia' => $c['en_custodia'], 'disponible' => $c['disponible']]);
            }

            throw new Rechazo(Rechazo::CREDITO_ORIGEN_SIN_RECEPCION, 'El dinero que origina este credito aun no esta verificado y recibido por ByLopez.', ['sin_recibir' => $c['sin_recibir'], 'disponible' => $c['disponible']]);
        }

        throw new Rechazo(Rechazo::CREDITO_INSUFICIENTE, 'El credito disponible no alcanza para ese monto.', ['disponible' => $c['disponible'], 'monto' => $valor]);
    }

    /**
     * C1: tras aplicar, lo EXIGIBLE del destino baja. Si las guias que ya tiene comprometen un recaudo mayor, el transportador podria
     * cobrar mas de lo debido: se BLOQUEA antes de crear nada. Misma cuenta que RecaudoPorGuia / SugiereRecaudoDesdeCartera
     * (exigible = saldo + lo ya cobrado por transportadores; comprometido = Σ recaudo_esperado − devuelto recibido por envio), pero
     * con lecturas bloqueantes. No se ajusta ninguna guia historica.
     */
    protected function exigirRecaudoCompatible(int $destinoId, float $saldoActual, float $aplicar): void
    {
        if (! Schema::hasTable('bylopez_lg_guias')) {
            return;
        }

        $guias = DB::table('bylopez_lg_guias')->where('order_id', $destinoId)->sharedLock()->get(['shipment_id', 'recaudo_esperado']);

        if ($guias->isEmpty()) {
            return;
        }

        $devuelto = DB::table('bylopez_lg_recepcion_devolucion_items as i')->join('bylopez_lg_recepciones_devolucion as r', 'r.id', '=', 'i.recepcion_id')
            ->where('r.order_id', $destinoId)->where('r.estado', 'confirmado')->whereNotNull('i.valor_devuelto')->sharedLock()
            ->groupBy('r.shipment_id')->selectRaw('r.shipment_id, SUM(i.valor_devuelto) AS valor')->pluck('valor', 'shipment_id');
        $comprometido = round((float) $guias->sum(fn ($g) => max(0.0, (float) $g->recaudo_esperado - (float) ($devuelto[$g->shipment_id] ?? 0))), 2);

        if ($comprometido <= 0) {
            return;
        }

        $cobrado = (float) DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.order_id', $destinoId)->whereNull('a.anulada_en')
            ->where(fn ($q) => $q->whereIn('p.medio_cobro', (array) config('ventasasesor.comisiones.medios_custodia_tercero', []))->orWhereNotNull('p.guia_id'))
            ->whereIn('p.estado', [EstadoPago::SIN_REVISAR->value, EstadoPago::CONFIRMADO->value])->sharedLock()->sum('a.monto');

        $exigibleDespues = round($saldoActual - $aplicar + $cobrado, 2);

        if ($comprometido > $exigibleDespues + 0.004) {
            throw new Rechazo(
                Rechazo::RECAUDO_COMPROMETIDO,
                'El pedido destino ya tiene guias con recaudo comprometido por '.number_format($comprometido, 0, ',', '.').' y, tras aplicar el credito, solo seran exigibles '.number_format(max(0, $exigibleDespues), 0, ',', '.').'. El transportador cobraria de mas: ajusta primero el recaudo de la guia.',
                ['comprometido' => $comprometido, 'exigible_despues' => $exigibleDespues],
            );
        }
    }

    /** Ultima recepcion C5 confirmada del origen (solo informativa: el credito es agregado por pedido). */
    protected function recepcionInformativa(int $origenId): ?int
    {
        if (! Schema::hasTable('bylopez_lg_recepciones_devolucion')) {
            return null;
        }

        $id = DB::table('bylopez_lg_recepciones_devolucion')->where('order_id', $origenId)->where('estado', 'confirmado')->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{movimiento_id: int, pago_id: int, monto: float, duplicado: bool} */
    protected function estable(object $m): array
    {
        return ['movimiento_id' => (int) $m->id, 'pago_id' => (int) $m->pago_id, 'monto' => (float) $m->monto, 'duplicado' => true];
    }
}
