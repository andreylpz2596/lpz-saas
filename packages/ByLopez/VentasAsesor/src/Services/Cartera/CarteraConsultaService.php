<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\DataObjects\CarteraCliente;
use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\DistribucionFifo;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\Order;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Consulta de Cartera (PAG-B, D-59, Doc. 05 §42.3): FUENTE UNICA del saldo comercial de un pedido, de su
 * composicion de pago y de la cartera de un cliente. SOLO LECTURA: no registra, confirma ni rechaza pagos, no
 * crea Transactions y no guarda ningun resultado (P-03: todo es derivado al consultar).
 *
 * Formula (moneda base, redondeo a 2 decimales), por pedido en Cartera:
 *
 *   Ap  = suma de aplicaciones ByLopez ACTIVAS (anulada_en NULL) de pagos sin_revisar o confirmado
 *   Nv  = suma de `order_transactions` del pedido que NO estan vinculadas a ninguna aplicacion
 *         (`aplicaciones.order_transaction_id`): las «ajenas» a ByLopez
 *   aplicado_total = Ap + Nv ;  verificado = Ap_confirmado + Nv ;  sin_revisar = Ap_sin_revisar
 *   Dv  = valor historico de lo RECIBIDO en bodega por devolucion (C5): Σ `valor_devuelto` de las recepciones
 *         CONFIRMADAS del pedido (aptas + defectuosas; los faltantes no). Anular la recepcion lo revierte.
 *   saldo = max(0, orders.base_grand_total - Dv - aplicado_total)        «saldo real por cobrar»
 *   saldo_a_favor = min(Dv, max(0, aplicado_total - (total - Dv)))       identificado, nunca reembolsado aqui (D10) *
 * SIN DOBLE CONTEO: la Transaction que nace al confirmar un pago queda vinculada a su aplicacion y por eso
 * sale de `Nv`: confirmar no mueve el saldo. Es lo que impide el -100.000 del ejemplo 500.000 / 300.000.
 *
 * Historico y Cartera inactiva: un pedido solo entra en Cartera si hay fecha de corte y `created_at` es igual o
 * posterior (FechaCorteCartera, falla cerrado). `Invoice paid` NO cuenta como pago: sin Transaction ni
 * aplicacion se marca `requiere_revision`, nunca como pagado.
 *
 * Dos capas, a proposito:
 *  - `saldosDePedidos()`: por lote y SIN actor; el llamador ya acoto el alcance (p. ej. Mis ventas).
 *  - `saldoDePedido()` / `carteraDelCliente()`: con actor; el servicio aplica el alcance de
 *    `ventas_asesor.cartera.view_own|view_all` (I-10) y nunca depende de ocultar botones.
 *
 * Rendimiento: cinco consultas agrupadas por lote (pedidos, reembolsos, aplicaciones, Transactions ajenas y
 * facturas pagadas), sin consulta por pedido.
 */
class CarteraConsultaService
{
    /** Pedidos que no generan saldo: cancelado, cerrado (reembolsado por completo) y fraude. */
    private const ESTADOS_SIN_SALDO = [Order::STATUS_CANCELED, Order::STATUS_CLOSED, Order::STATUS_FRAUD];

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected FechaCorteCartera $fechaCorte,
    ) {}

    /**
     * Saldo y composicion de varios pedidos. NO valida alcance: usar solo con ids ya acotados por el llamador.
     * Los ids que no existen se omiten; el orden de entrada se conserva.
     *
     * LECTURA ACTUAL (F3/F4, auditoria 2026-10-21): quien VALIDA dinero dentro de una transaccion (registrar, confirmar,
     * abonar) debe pasar `$lecturaActual = true`. En REPEATABLE READ la instantanea de la transaccion nace con su PRIMERA
     * lectura no bloqueante; si esa lectura ocurrio antes de obtener el bloqueo del pedido (p. ej. el listener de
     * reevaluacion de comisiones al guardar el Pago), un SELECT normal posterior no ve lo que otra transaccion confirmo
     * mientras se esperaba el bloqueo y se sobreaplicaba el saldo. Con `true` cada consulta es una lectura BLOQUEANTE
     * (`LOCK IN SHARE MODE`), que siempre lee lo ultimo confirmado, sin depender de que no haya lecturas anteriores ni
     * cambiar el aislamiento global. Fuera de una transaccion no aporta nada: las pantallas usan el valor por defecto.
     *
     * @param  list<int|string>  $orderIds
     * @return array<int, SaldoPedido> indexado por order_id
     */
    public function saldosDePedidos(array $orderIds, bool $lecturaActual = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            return [];
        }

        $leer = fn ($consulta) => $lecturaActual ? $consulta->sharedLock() : $consulta;

        $pedidos = $leer(DB::table('orders')->whereIn('id', $ids))
            ->get(['id', 'increment_id', 'customer_id', 'status', 'created_at', 'base_grand_total', 'base_grand_total_refunded'])
            ->keyBy('id');

        // Cartera inactiva (sin fecha de corte): ningun pedido entra, asi que no hay nada que agregar. No se tocan
        // las tablas de pagos: un entorno sin migrar (o con Cartera apagada) sigue funcionando y sin costo.
        if (! $this->fechaCorte->activa()) {
            $resultado = [];

            foreach ($ids as $id) {
                if ($pedidos->has($id)) {
                    $resultado[$id] = $this->componer($pedidos->get($id), [], 0.0, false, false);
                }
            }

            return $resultado;
        }

        $conReembolso = $leer(DB::table('refunds')->whereIn('order_id', $ids))->distinct()->pluck('order_id')->flip();

        // Aplicaciones ACTIVAS agrupadas por pedido y estado del pago.
        $aplicado = [];

        foreach ($leer(DB::table('bylopez_va_aplicaciones_pago as a')
            ->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereIn('a.order_id', $ids)->whereNull('a.anulada_en'))
            ->groupBy('a.order_id', 'p.estado')
            ->selectRaw('a.order_id, p.estado, SUM(a.monto) AS total')
            ->get() as $fila) {
            $aplicado[(int) $fila->order_id][(string) $fila->estado] = (float) $fila->total;
        }

        // Transactions nativas NO vinculadas a ninguna aplicacion: ajenas a ByLopez.
        $ajenas = $leer(DB::table('order_transactions as t')
            ->leftJoin('bylopez_va_aplicaciones_pago as a', 'a.order_transaction_id', '=', 't.id')
            ->whereIn('t.order_id', $ids)->whereNull('a.id'))
            ->groupBy('t.order_id')
            ->selectRaw('t.order_id, SUM(t.amount) AS total')
            ->pluck('total', 'order_id');

        // C5: lo devuelto y RECIBIDO en bodega. Se deriva de las recepciones confirmadas (una consulta agrupada por lote).
        $devuelto = $this->devueltoDePedidos($ids, $lecturaActual);

        $facturaPagada = $leer(DB::table('invoices')->whereIn('order_id', $ids)->where('state', 'paid'))->distinct()->pluck('order_id')->flip();

        $resultado = [];

        foreach ($ids as $id) {
            if (! $pedidos->has($id)) {
                continue;
            }

            $resultado[$id] = $this->componer(
                $pedidos->get($id),
                $aplicado[$id] ?? [],
                (float) ($ajenas[$id] ?? 0),
                $conReembolso->has($id),
                $facturaPagada->has($id),
                (float) ($devuelto[$id]['valor'] ?? 0),
                (int) ($devuelto[$id]['unidades'] ?? 0),
            );
        }

        return $resultado;
    }

    /**
     * Saldo de un pedido para un actor. Null si el pedido no existe o el actor no puede verlo (no se revela
     * si existe). Sin permiso de cartera se lanza AuthorizationException.
     *
     * @throws AuthorizationException
     */
    public function saldoDePedido(int $orderId, AdminContract $actor): ?SaldoPedido
    {
        $alcance = $this->alcanceOExcepcion($actor);

        if (! $this->pedidosVisibles($alcance, $actor)->where('o.id', $orderId)->exists()) {
            return null;
        }

        return $this->saldosDePedidos([$orderId])[$orderId] ?? null;
    }

    /**
     * Cartera de un cliente para un actor: solo cuentan los pedidos que el actor puede ver.
     *
     * @throws AuthorizationException
     */
    public function carteraDelCliente(int $customerId, AdminContract $actor): CarteraCliente
    {
        $alcance = $this->alcanceOExcepcion($actor);

        // Del mas antiguo al mas nuevo: el orden de la propuesta FIFO (P-07).
        $ids = $this->pedidosVisibles($alcance, $actor)
            ->where('o.customer_id', $customerId)
            ->orderBy('o.created_at')->orderBy('o.id')
            ->pluck('o.id')->map(fn ($id) => (int) $id)->all();

        $enCartera = [];
        $fuera = 0;
        $cartera = 0.0;
        $porRevisar = 0.0;

        foreach ($this->saldosDePedidos($ids) as $saldo) {
            if (! $saldo->enCartera) {
                $fuera++;

                continue;
            }

            $enCartera[] = $saldo;

            // P-10: un pedido con Refund se muestra aparte (por revisar) y no entra en la cartera ordinaria.
            if ($saldo->tieneMotivo(SaldoPedido::REVISAR_REEMBOLSO)) {
                $porRevisar += (float) $saldo->saldo;
            } else {
                $cartera += (float) $saldo->saldo;
            }
        }

        return new CarteraCliente(
            customerId: $customerId,
            carteraActiva: $this->fechaCorte->activa(),
            fechaCorte: $this->fechaCorte->valor()?->toDateTimeString(),
            carteraTotal: round($cartera, 2),
            porRevisarTotal: round($porRevisar, 2),
            pedidos: $enCartera,
            fueraDeCartera: $fuera,
        );
    }

    /**
     * Todo lo que necesita la pantalla «Cartera del cliente» (PAG-D), SOLO LECTURA y acotado al actor: datos del
     * cliente, la cartera derivada y los pedidos elegibles para recibir un abono (en Cartera, con saldo, sin revision
     * pendiente), del mas antiguo al mas nuevo (propuesta FIFO). Null si el cliente no existe o, con `view_own`, si el
     * actor no tiene ningun pedido de ese cliente (no se revela su ficha).
     *
     * `$pagoId` opcional: resumen del abono recien registrado (solo si es de ese cliente y el actor puede verlo).
     *
     * @return array{cliente: array, cartera: CarteraCliente, elegibles: list<array>, deuda_elegible: float, abono: ?array, fechas: array<int, string>}|null
     *
     * @throws AuthorizationException
     */
    public function fichaDelCliente(int $customerId, AdminContract $actor, ?int $pagoId = null): ?array
    {
        $alcance = $this->alcanceOExcepcion($actor);

        $cliente = DB::table('customers as c')
            ->leftJoin('customer_groups as g', 'g.id', '=', 'c.customer_group_id')
            ->where('c.id', $customerId)
            ->first(['c.id', 'c.first_name', 'c.last_name', 'c.phone', 'c.email', 'g.name as grupo']);

        if (! $cliente) {
            return null;
        }

        $cartera = $this->carteraDelCliente($customerId, $actor);

        if ($alcance === AlcanceVisibilidad::PROPIOS && $cartera->pedidos === [] && $cartera->fueraDeCartera === 0) {
            return null;
        }

        $elegibles = [];

        foreach ($cartera->pedidos as $pedido) {
            if (DistribucionFifo::esElegible($pedido)) {
                $elegibles[] = [
                    'id' => $pedido->orderId,
                    'increment_id' => $pedido->incrementId,
                    'total' => $pedido->total,
                    'saldo' => $pedido->saldo,
                ];
            }
        }

        $abono = null;

        if ($pagoId !== null && $this->fechaCorte->activa()) {
            $pago = DB::table('bylopez_va_pagos')->where('id', $pagoId)->where('customer_id', $customerId)->first(['id', 'monto', 'estado', 'registrado_por_admin_id']);

            if ($pago && ($alcance === AlcanceVisibilidad::TODOS || (int) $pago->registrado_por_admin_id === (int) $actor->id)) {
                $abono = [
                    'id' => (int) $pago->id,
                    'monto' => (float) $pago->monto,
                    'estado' => (string) $pago->estado,
                    'pedidos' => DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $pagoId)->whereNull('anulada_en')->count(),
                ];
            }
        }

        return [
            'cliente' => [
                'id' => (int) $cliente->id,
                'nombre' => trim($cliente->first_name.' '.$cliente->last_name),
                'telefono' => $cliente->phone,
                'email' => $cliente->email,
                'grupo' => $cliente->grupo,
            ],
            'cartera' => $cartera,
            'elegibles' => $elegibles,
            'deuda_elegible' => DistribucionFifo::deudaElegible($cartera->pedidos),
            'abono' => $abono,
            'fechas' => DB::table('orders')->whereIn('id', array_map(fn (SaldoPedido $p) => $p->orderId, $cartera->pedidos))->pluck('created_at', 'id')->all(),
        ];
    }

    /**
     * @param  array<string, float>  $aplicadoPorEstado  aplicaciones activas del pedido por estado del pago
     */
    /**
     * Valor y unidades devueltos y RECIBIDOS por pedido (recepciones confirmadas de Logistica).
     *
     * @param  list<int>  $ids
     * @return array<int, array{valor: float, unidades: int}>
     */
    private function devueltoDePedidos(array $ids, bool $lecturaActual = false): array
    {
        $salida = [];
        $consulta = DB::table('bylopez_lg_recepcion_devolucion_items as i')
            ->join('bylopez_lg_recepciones_devolucion as r', 'r.id', '=', 'i.recepcion_id')
            ->whereIn('r.order_id', $ids)->where('r.estado', 'confirmado')->whereNotNull('i.valor_devuelto');

        foreach (($lecturaActual ? $consulta->sharedLock() : $consulta)
            ->groupBy('r.order_id')
            ->selectRaw('r.order_id, SUM(i.valor_devuelto) AS valor, SUM(i.recibidas) AS unidades')
            ->get() as $fila) {
            $salida[(int) $fila->order_id] = ['valor' => round((float) $fila->valor, 2), 'unidades' => (int) $fila->unidades];
        }

        return $salida;
    }

    private function componer(object $pedido, array $aplicadoPorEstado, float $ajenas, bool $tieneRefund, bool $tieneFacturaPagada, float $devuelto = 0.0, int $unidadesDevueltas = 0): SaldoPedido
    {
        $id = (int) $pedido->id;
        $total = round((float) $pedido->base_grand_total, 2);
        $cliente = $pedido->customer_id === null ? null : (int) $pedido->customer_id;
        $incremento = (string) $pedido->increment_id;
        $estado = (string) $pedido->status;

        // Fuera de Cartera: no se calcula saldo (Doc. 05 §42.8).
        if (! $this->fechaCorte->activa()) {
            return SaldoPedido::fuera($id, $cliente, $incremento, $estado, $total, SaldoPedido::FUERA_CARTERA_INACTIVA);
        }

        if (in_array($estado, self::ESTADOS_SIN_SALDO, true)) {
            return SaldoPedido::fuera($id, $cliente, $incremento, $estado, $total, SaldoPedido::FUERA_ESTADO_NO_APLICA);
        }

        if (! $this->fechaCorte->incluye(CarbonImmutable::parse((string) $pedido->created_at, config('app.timezone')))) {
            return SaldoPedido::fuera($id, $cliente, $incremento, $estado, $total, SaldoPedido::FUERA_ANTERIOR_A_CORTE);
        }

        $sinRevisar = $aplicadoPorEstado[EstadoPago::SIN_REVISAR->value] ?? 0.0;
        $confirmado = $aplicadoPorEstado[EstadoPago::CONFIRMADO->value] ?? 0.0;

        // Aplicacion activa de un pago ya cerrado: invariante I-3 violada. No se cuenta; se senala.
        $enPagoCerrado = 0.0;

        foreach ($aplicadoPorEstado as $estadoPago => $monto) {
            $conocido = EstadoPago::tryFrom($estadoPago);

            if ($conocido === null || ! $conocido->cuentaParaSaldo()) {
                $enPagoCerrado += $monto;
            }
        }

        $verificado = round($confirmado + $ajenas, 2);
        $sinRevisar = round($sinRevisar, 2);
        $aplicadoTotal = round($verificado + $sinRevisar, 2);
        // C5: lo recibido en bodega deja de ser exigible; el total original no se toca.
        $devuelto = min(max(0.0, round($devuelto, 2)), $total);
        $totalOperativo = round($total - $devuelto, 2);
        $saldo = max(0.0, round($totalOperativo - $aplicadoTotal, 2));
        $saldoAFavor = $devuelto > 0 ? round(min($devuelto, max(0.0, $aplicadoTotal - $totalOperativo)), 2) : 0.0;
        $motivos = [];

        if ($tieneRefund || (float) $pedido->base_grand_total_refunded > 0) {
            $motivos[] = SaldoPedido::REVISAR_REEMBOLSO;
        }

        // Factura `paid` sin ninguna evidencia de dinero (D-48/historico): NO es pago, se revisa.
        if ($tieneFacturaPagada && $aplicadoTotal <= 0) {
            $motivos[] = SaldoPedido::REVISAR_FACTURA_PAGADA_SIN_EVIDENCIA;
        }

        if ($aplicadoTotal > $total) {
            $motivos[] = SaldoPedido::REVISAR_SOBREAPLICADO;
        }

        if ($enPagoCerrado > 0) {
            $motivos[] = SaldoPedido::REVISAR_APLICACION_EN_PAGO_CERRADO;
        }

        return new SaldoPedido(
            orderId: $id,
            customerId: $cliente,
            incrementId: $incremento,
            estadoPedido: $estado,
            total: $total,
            enCartera: true,
            motivoFuera: null,
            aplicadoTotal: $aplicadoTotal,
            verificado: $verificado,
            sinRevisar: $sinRevisar,
            transaccionesAjenas: round($ajenas, 2),
            saldo: $saldo,
            requiereRevision: $motivos !== [],
            motivosRevision: $motivos,
            devuelto: $devuelto,
            unidadesDevueltas: $unidadesDevueltas,
            totalOperativo: $totalOperativo,
            saldoAFavor: $saldoAFavor,
        );
    }

    /**
     * Pedidos que el actor puede ver segun su alcance de cartera: TODOS = todos; PROPIOS = solo los atribuidos a
     * el (misma regla que Mis ventas; sin atribucion valida se niega, RA-6).
     */
    private function pedidosVisibles(AlcanceVisibilidad $alcance, AdminContract $actor): Builder
    {
        $consulta = DB::table('orders as o');

        return match ($alcance) {
            AlcanceVisibilidad::TODOS => $consulta,
            AlcanceVisibilidad::PROPIOS => $consulta
                ->join('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'o.id')
                ->where('atr.advisor_id', (int) $actor->id),
            AlcanceVisibilidad::NINGUNO => $consulta->whereRaw('1 = 0'),
        };
    }

    private function alcanceOExcepcion(AdminContract $actor): AlcanceVisibilidad
    {
        $alcance = $this->autorizacion->alcanceDeCartera($actor);

        if ($alcance === AlcanceVisibilidad::NINGUNO) {
            throw new AuthorizationException('No tienes permiso para consultar la cartera.');
        }

        return $alcance;
    }
}
