<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Services\Cuentas\ModoRecaudo;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * R3 — condiciones de HABILITACION de un pedido (A-04), TODAS a la vez y por PEDIDO COMPLETO:
 *
 *  1. Fecha de inicio configurada y pedido confirmado desde ella (sin fecha: nada se habilita; nada retroactivo).
 *  2. Pedido no cancelado/cerrado/fraude.
 *  3. Entrega: toda unidad vendida no cancelada esta despachada, y CADA envio del pedido tiene validacion de entrega vigente
 *     (la validacion exige 0 pendientes en el envio). Se distinguen tres cosas: CANCELADAS antes de despachar (dejan de ser
 *     exigibles), NO ENTREGADAS (rechazo/retorno de la transportadora: restan unidades en el libro del umbral; el envio se
 *     valida igual) y DEVUELTAS DESPUES de entregar o REEMBOLSADAS (reembolso de Bagisto): no hay politica aprobada, asi que
 *     bloquean el pedido con `devolucion_sin_politica` hasta decidirla (nunca se aprueban solas).
 *  4. Cobro confirmado en Cartera: en cartera, saldo 0, sin pagos sin revisar ni «requiere revision» (reembolsos incluidos).
 *     Un cobro parcial no habilita nada proporcional.
 *  5. Dinero RECIBIDO por ByLopez. La prueba depende de quien tiene el dinero:
 *     - contra entrega con transportadora o domiciliario con cuenta: el recaudo de CADA guia esta LIQUIDADO, es decir, entro
 *       en una liquidacion cerrada por administracion con el valor recibido (o compensado contra fletes) y, si cerro con
 *       diferencia, esa diferencia ya se liquido en otra que a su vez no dejo diferencia abierta (se sigue la cadena).
 *       «Liquidado» no basta por el nombre: lo que acredita es la liquidacion CERRADA y CONCILIADA;
 *     - guia con recaudo que no liquida por cuenta corriente: no hay registro que pruebe la recepcion → bloquea;
 *     - transferencia directa o recogida en tienda: el pago CONFIRMADO en Cartera (revisado contra el soporte) es la prueba;
 *       un cobro hecho por el domiciliario (medio de custodia) sin recaudo de guia que lo respalde NO basta.
 *
 * `completed` de Bagisto, una factura `paid` o una liquidacion creada NO se usan como prueba por si solos.
 * Lectura pura; no escribe nada.
 */
class ElegibilidadComisionService
{
    private const ESTADOS_SIN_COMISION = ['canceled', 'closed', 'fraud'];

    public function __construct(protected CarteraConsultaService $cartera) {}

    /** @return array{habilitable: bool, motivos: list<string>, evidencia: array<string, mixed>} */
    public function dePedido(int $orderId): array
    {
        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'status', 'created_at']);
        $motivos = [];
        $evidencia = [];

        if ($pedido === null) {
            return ['habilitable' => false, 'motivos' => ['pedido_inexistente'], 'evidencia' => []];
        }

        $inicio = config('ventasasesor.comisiones.fecha_inicio');

        if (empty($inicio)) {
            $motivos[] = 'sin_fecha_inicio';
        } elseif (MesComercial::instante((string) $pedido->created_at)->lt(CarbonImmutable::parse((string) $inicio, MesComercial::zona()))) {
            $motivos[] = 'anterior_a_inicio';
        }

        if (in_array($pedido->status, self::ESTADOS_SIN_COMISION, true)) {
            $motivos[] = 'pedido_'.$pedido->status;
        }

        array_push($motivos, ...$this->entrega($orderId, $evidencia));
        array_push($motivos, ...$this->cobro($orderId, $evidencia));
        array_push($motivos, ...$this->dinero($orderId, $evidencia));

        return ['habilitable' => $motivos === [], 'motivos' => array_values(array_unique($motivos)), 'evidencia' => $evidencia];
    }

    /** @return list<string> */
    protected function entrega(int $orderId, array &$evidencia): array
    {
        $items = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->get(['id', 'qty_ordered', 'qty_canceled']);
        $despachadas = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->where('s.order_id', $orderId)->groupBy('si.order_item_id')->selectRaw('si.order_item_id, SUM(si.qty) AS q')->pluck('q', 'si.order_item_id');
        $envios = DB::table('shipments')->where('order_id', $orderId)->pluck('id')->map(fn ($id) => (int) $id);
        $validaciones = DB::table('bylopez_lg_validaciones_entrega')->where('order_id', $orderId)->where('estado', 'confirmado')
            ->get(['id', 'shipment_id', 'created_at', 'entregadas', 'devueltas'])->keyBy('shipment_id');

        $motivos = [];

        foreach ($items as $i) {
            if ((int) ($despachadas[$i->id] ?? 0) < (int) $i->qty_ordered - (int) $i->qty_canceled) {
                $motivos[] = 'unidades_sin_despachar';
            }
        }

        if ($envios->isEmpty() && $items->sum(fn ($i) => (int) $i->qty_ordered - (int) $i->qty_canceled) > 0) {
            $motivos[] = 'sin_envios';
        }

        if ($envios->contains(fn (int $id) => ! $validaciones->has($id))) {
            $motivos[] = 'envio_sin_validar';
        }

        // Devolucion posterior a la entrega o reembolso (Bagisto): sin politica aprobada, queda en revision.
        $reembolsado = DB::table('order_items')->where('order_id', $orderId)->whereNull('parent_id')->sum('qty_refunded');

        // C5: mercancia que Bodega ya recibio devuelta (descuenta el saldo exigible, pero la comision de esas unidades sigue sin politica).
        $devueltaRecibida = DB::table('bylopez_lg_recepciones_devolucion')->where('order_id', $orderId)->where('estado', 'confirmado')->exists();

        if ((int) $reembolsado > 0 || $devueltaRecibida || DB::table('refunds')->where('order_id', $orderId)->exists()) {
            $motivos[] = 'devolucion_sin_politica';
        }

        $evidencia['validaciones'] = $validaciones->map(fn ($v) => ['id' => (int) $v->id, 'shipment_id' => (int) $v->shipment_id, 'en' => (string) $v->created_at, 'entregadas' => (int) $v->entregadas, 'devueltas' => (int) $v->devueltas])->values()->all();

        return $motivos;
    }

    /** @return list<string> */
    protected function cobro(int $orderId, array &$evidencia): array
    {
        $s = $this->cartera->saldosDePedidos([$orderId])[$orderId] ?? null;

        if ($s === null) {
            return ['cartera_sin_datos'];
        }

        $evidencia['cartera'] = ['total' => $s->total, 'verificado' => $s->verificado, 'saldo' => $s->saldo, 'sin_revisar' => $s->sinRevisar];

        return array_values(array_filter([
            ! $s->enCartera ? 'fuera_de_cartera' : null,
            $s->requiereRevision ? 'cartera_requiere_revision' : null,
            $s->enCartera && ($s->saldo ?? 1) > 0.004 ? 'saldo_pendiente' : null,
            $s->enCartera && ($s->sinRevisar ?? 0) > 0.004 ? 'pagos_sin_revisar' : null,
        ]));
    }

    /** @return list<string> */
    protected function dinero(int $orderId, array &$evidencia): array
    {
        $motivos = [];
        $guias = DB::table('bylopez_lg_guias')->where('order_id', $orderId)->where('recaudo_esperado', '>', 0)
            ->get(['id', 'shipment_id', 'recaudo_esperado', 'liquida_cuenta_corriente']);
        $evidencia['recaudos'] = [];
        $c6 = $this->guiasC6($guias->pluck('id')->map(fn ($id) => (int) $id)->all());

        foreach ($guias as $g) {
            // C6: si el recaudo de la guia nacio de un COBRO real, la prueba es el recaudo CONCILIADO frente a ByLopez (efectivo + compensacion
            // valida), no la liquidacion completa ni el efectivo solo. Una diferencia bloquea solo los recaudos que quedaron sin conciliar.
            $cobros = DB::table('bylopez_lg_cuenta_movimientos')->where('guia_id', $g->id)->where('concepto', 'recaudo')->where('origen_tipo', 'cobro')
                ->get(['id', 'estado', 'importe']);

            if ($cobros->isNotEmpty()) {
                $cobrado = round((float) $cobros->sum('importe'), 2);
                $conciliado = round((float) DB::table('bylopez_lg_conciliaciones_movimiento')->whereIn('movimiento_id', $cobros->pluck('id')->all())->sum(DB::raw('efectivo + compensado')), 2);
                $evidencia['recaudos'][] = ['guia_id' => (int) $g->id, 'modelo' => 'c6', 'cobrado' => $cobrado, 'conciliado' => $conciliado];

                if ($cobros->contains(fn ($m) => $m->estado !== 'liquidado')) {
                    $motivos[] = 'recaudo_en_custodia';
                } elseif ($conciliado + 0.004 < $cobrado) {
                    $motivos[] = 'recaudo_pendiente_de_conciliar';
                }

                continue;
            }

            // Guia C6 sin cobro del transportador: el pago (transferencia, anticipo) lo cubre Cartera; no hay custodia que conciliar.
            // PERO si hay un cobro CONFIRMADO ligado a esta guia y aun asi no existe el movimiento de recaudo, el transportador no lleva
            // cuenta corriente con ByLopez: no hay registro que pruebe que el dinero llego, y NO se da por recibido (jornada 2026-10-28).
            if (isset($c6[(int) $g->id])) {
                $motivo = $this->cobroSinLibro($orderId, $g);

                if ($motivo !== null) {
                    $motivos[] = $motivo;
                }

                continue;
            }

            $movs = DB::table('bylopez_lg_cuenta_movimientos')->where('guia_id', $g->id)->where('concepto', 'recaudo')->get(['id', 'estado', 'liquidacion_id', 'importe']);
            $revertidos = DB::table('bylopez_lg_cuenta_movimientos')->whereIn('revierte_id', $movs->pluck('id')->all() ?: [0])->pluck('revierte_id')->flip();
            $vivos = $movs->reject(fn ($m) => $revertidos->has($m->id));

            if ($vivos->isEmpty()) {
                $motivos[] = $g->liquida_cuenta_corriente ? 'recaudo_sin_movimiento' : 'recaudo_sin_cuenta_corriente';

                continue;
            }

            if ($vivos->contains(fn ($m) => $m->estado !== 'liquidado')) {
                $motivos[] = 'recaudo_en_custodia';

                continue;
            }

            foreach ($vivos->pluck('liquidacion_id')->unique() as $liqId) {
                $liq = DB::table('bylopez_lg_liquidaciones_transportadora')->where('id', $liqId)
                    ->first(['id', 'consecutivo', 'estado', 'sentido', 'valor_liquidado', 'diferencia', 'cerrada_en']);

                if ($liq === null || $liq->estado !== 'cerrada' || $liq->cerrada_en === null) {
                    $motivos[] = 'recaudo_sin_liquidacion_cerrada';

                    continue;
                }

                $conciliada = $this->conciliada((int) $liq->id);

                if (! $conciliada) {
                    $motivos[] = 'liquidacion_con_diferencia_sin_conciliar';
                }

                $evidencia['recaudos'][] = ['guia_id' => (int) $g->id, 'liquidacion_id' => (int) $liq->id, 'consecutivo' => (int) $liq->consecutivo,
                    'cerrada_en' => (string) $liq->cerrada_en, 'sentido' => (string) $liq->sentido, 'valor_liquidado' => (string) $liq->valor_liquidado, 'conciliada' => $conciliada];
            }
        }

        // Cobro hecho por el domiciliario (medio de custodia o cobro registrado al validar) sin recaudo de guia que lo respalde.
        if ($guias->isEmpty()) {
            $custodia = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
                ->where('a.order_id', $orderId)->whereNull('a.anulada_en')->where('p.estado', 'confirmado')
                ->where(fn ($q) => $q->whereIn('p.medio_cobro', (array) config('ventasasesor.comisiones.medios_custodia_tercero', []))
                    ->orWhere('p.observacion', (string) config('ventasasesor.comisiones.observacion_cobro_en_entrega')))
                ->exists();

            if ($custodia) {
                $motivos[] = 'cobro_en_custodia_sin_recaudo';
            }
        }

        return $motivos;
    }

    /**
     * Guia C6 con recaudo esperado y SIN movimiento de recaudo en la cuenta: ¿hay un cobro confirmado que dijo ser del transportador?
     * En una guia que liquida por cuenta corriente el cobro viene ligado a ella (`guia_id`); en una que no, el cobro llega por un medio de
     * custodia (o por la observacion del cobro en entrega) y NADA lo concilia: no hay prueba de que el dinero llego a ByLopez.
     */
    protected function cobroSinLibro(int $orderId, object $guia): ?string
    {
        $ligado = fn ($q) => $q->where('p.guia_id', $guia->id);

        if (! $guia->liquida_cuenta_corriente) {
            $ligado = fn ($q) => $q->where('p.guia_id', $guia->id)
                ->orWhereIn('p.medio_cobro', (array) config('ventasasesor.comisiones.medios_custodia_tercero', []))
                ->orWhere('p.observacion', (string) config('ventasasesor.comisiones.observacion_cobro_en_entrega'));
        }

        $hay = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.order_id', $orderId)->whereNull('a.anulada_en')->where('p.estado', 'confirmado')->where($ligado)->exists();

        return $hay ? ($guia->liquida_cuenta_corriente ? 'recaudo_sin_movimiento' : 'recaudo_sin_cuenta_corriente') : null;
    }

    /** @param  list<int>  $guiaIds @return array<int, true> */
    protected function guiasC6(array $guiaIds): array
    {
        if ($guiaIds === []) {
            return [];
        }

        return app(ModoRecaudo::class)->guiasC6(Guia::query()->whereIn('id', $guiaIds)->get());
    }

    /**
     * Una liquidacion esta CONCILIADA si cerro sin diferencia, o si su diferencia se liquido en otra liquidacion que a su vez
     * esta conciliada (una diferencia arrastrada a la siguiente liquidacion con otra diferencia sigue sin cobrarse).
     *
     * @param  array<int, true>  $vistas
     */
    protected function conciliada(int $liquidacionId, array $vistas = []): bool
    {
        if (isset($vistas[$liquidacionId]) || count($vistas) > 50) {
            return false;
        }

        $liq = DB::table('bylopez_lg_liquidaciones_transportadora')->where('id', $liquidacionId)->first(['id', 'estado', 'diferencia']);

        if ($liq === null || $liq->estado !== 'cerrada') {
            return false;
        }

        if (abs((float) $liq->diferencia) < 0.005) {
            return true;
        }

        $apertura = DB::table('bylopez_lg_cuenta_movimientos')->where('idempotency_key', 'liquidacion:'.$liq->id.':diferencia:apertura')
            ->first(['estado', 'liquidacion_id']);

        return $apertura !== null && $apertura->estado === 'liquidado' && $apertura->liquidacion_id !== null
            && $this->conciliada((int) $apertura->liquidacion_id, $vistas + [$liquidacionId => true]);
    }
}
