<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * UNICA fuente del panel de comisiones de UNA asesora en UN mes (la leen «Mis comisiones», Comisiones, Asesores y la tarjeta de
 * Pedidos): no recalcula reglas, solo ordena lo que ya decidieron UmbralMensualService (distribucion), ElegibilidadComisionService
 * (condiciones) y AplicacionesComisionService (lo pagado).
 *
 * Cada peso de cada linea esta en UNA sola etapa, asi que las etapas se suman sin contar dos veces:
 *   bloqueada  = estimada que aun no cumple las condiciones
 *   habilitada = cumple todo y aun no se paga (o esta en una liquidacion en borrador)
 *   por pagar  = liquidacion aprobada con saldo
 *   pagada     = abonos y compensaciones aplicados
 *
 * Un importe DESCONOCIDO (sin costo con autoridad, sin porcentaje o umbral sin definir) es null, nunca $0: se cuenta aparte en
 * `sin_calcular` y el total queda marcado como parcial.
 *
 * `$conCalculo` solo lo pide administracion autorizada: agrega precio neto, costo historico, base por unidad y porcentaje. La
 * vista de la asesora nunca los recibe (no viajan en el arreglo: no basta con esconderlos en la vista).
 */
class PanelComisionesService
{
    /** Motivos que describen a TODA la cuenta del mes (se avisan una vez, no junto a cada pedido). */
    public const GENERALES = ['sin_fecha_inicio', 'sin_acuerdo', 'umbral_sin_definir', 'politica_pendiente_umbral_cambio_mes'];

    private const ORDEN = ['bloqueada' => 0, 'habilitada' => 1, 'por_pagar' => 2, 'pagada' => 3];

    public function __construct(
        protected UmbralMensualService $umbral,
        protected ElegibilidadComisionService $elegibilidad,
        protected LiquidacionesComisionService $liquidaciones,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /** @return array<string, mixed> */
    public function deAsesor(int $asesorId, string $mes, bool $conCalculo = false): array
    {
        $d = $this->umbral->distribucion($asesorId, $mes);
        $lineas = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('mes_comercial', $mes)
            ->where('estado', '!=', ComisionLinea::ANULADA)->orderBy('confirmado_en')->orderBy('order_id')->orderBy('order_item_id')->get();

        $liquidaciones = ComisionLiquidacion::query()->where('asesor_admin_id', $asesorId)
            ->whereIn('id', $lineas->pluck('liquidacion_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
        $aplicadas = $this->aplicadasPorLinea($liquidaciones->all());

        $cero = fn () => ['bloqueada' => BigDecimal::zero(), 'habilitada' => BigDecimal::zero(), 'por_pagar' => BigDecimal::zero(), 'pagada' => BigDecimal::zero()];
        $etapas = $cero();
        $sinCalcular = 0;
        $generales = [];
        $pedidos = [];
        $elegibles = [];
        $incrementos = DB::table('orders')->whereIn('id', $lineas->pluck('order_id')->unique()->all() ?: [0])->pluck('increment_id', 'id');

        if ($d['estado_umbral'] !== 'definido') {
            $generales[] = $d['estado_umbral'];
        }

        foreach ($lineas as $l) {
            $dl = $d['lineas'][$l->id] ?? null;
            $liq = $l->liquidacion_id ? $liquidaciones->get($l->liquidacion_id) : null;
            $partes = $cero();
            $desconocida = false;
            $motivos = [];

            if ($l->estado === ComisionLinea::HABILITADA) {
                $monto = Dinero::de($l->monto);

                if ($liq?->estado === ComisionLiquidacion::APROBADA) {
                    $a = $aplicadas[$l->id] ?? null;
                    $pagado = $a ? Dinero::de($a['pagado'])->plus(Dinero::de($a['compensado'])) : BigDecimal::zero();
                    $partes['pagada'] = $pagado;
                    $partes['por_pagar'] = Dinero::maximo($monto->minus($pagado), BigDecimal::zero());
                    ! empty($a['retenido']) && $motivos[] = 'perdio_condiciones';
                } else {
                    $partes['habilitada'] = $monto;
                }

                array_push($motivos, ...($dl['marcas'] ?? []));
            } else {
                $estimado = $dl['monto_estimado'] ?? null;
                $desconocida = $estimado === null;
                $partes['bloqueada'] = $desconocida ? BigDecimal::zero() : Dinero::de($estimado);
                $elegibles[$l->order_id] ??= $this->elegibilidad->dePedido((int) $l->order_id)['motivos'];
                array_push($motivos, ...$elegibles[$l->order_id], ...($dl['marcas'] ?? []), ...array_filter([$l->alerta]));

                if ($l->costo_unitario === null || $l->porcentaje === null) {
                    $motivos[] = $l->costo_unitario === null ? 'costo_sin_autoridad' : 'sin_acuerdo_al_confirmar';
                }

                if (($dl['comisionables'] ?? null) === 0 && $d['estado_umbral'] === 'definido') {
                    $motivos[] = 'dentro_del_umbral';
                }
            }

            $desconocida && $sinCalcular++;

            foreach ($partes as $etapa => $valor) {
                $etapas[$etapa] = $etapas[$etapa]->plus($valor);
            }

            $propios = [];

            foreach (array_unique($motivos) as $m) {
                in_array($m, self::GENERALES, true) ? $generales[] = $m : $propios[] = $m;
            }

            $comisionables = $l->estado === ComisionLinea::HABILITADA ? (int) $l->unidades_comisionables : ($dl['comisionables'] ?? null);
            $importe = $desconocida ? null : $partes['bloqueada']->plus($partes['habilitada'])->plus($partes['por_pagar'])->plus($partes['pagada']);

            $pedidos[$l->order_id] ??= ['order_id' => (int) $l->order_id, 'increment_id' => (string) ($incrementos[$l->order_id] ?? $l->order_id), 'confirmado_en' => $l->confirmado_en, 'lineas' => []];
            $pedidos[$l->order_id]['lineas'][] = [
                'id' => (int) $l->id,
                'referencia' => $l->referencia,
                'sku' => $l->sku,
                'vendidas' => (int) $l->cantidad_vendida,
                'computables' => $dl['computables'] ?? null,
                'comisionables' => $comisionables,
                'etapa' => $this->etapaPrincipal($partes, $desconocida),
                'importe' => $importe === null ? null : Dinero::texto($importe),
                'pagado' => Dinero::texto($partes['pagada']),
                'por_pagar' => Dinero::texto($partes['por_pagar']),
                'motivos' => array_values($propios),
            ] + ($conCalculo ? [
                'calculo' => [
                    'precio_neto' => Dinero::texto(Dinero::de($l->precio_neto_unitario)),
                    'costo' => $l->costo_unitario === null ? null : Dinero::texto(Dinero::de($l->costo_unitario)),
                    'base' => $l->base_unitaria === null ? null : Dinero::texto(Dinero::de($l->base_unitaria)),
                    'porcentaje' => $l->porcentaje === null ? null : rtrim(rtrim((string) $l->porcentaje, '0'), '.'),
                ],
            ] : []);
        }

        $salida = [];

        foreach ($pedidos as $p) {
            $salida[] = $this->cerrarPedido($p);
        }

        $total = $etapas['bloqueada']->plus($etapas['habilitada'])->plus($etapas['por_pagar'])->plus($etapas['pagada']);
        $activas = ! empty(config('ventasasesor.comisiones.fecha_inicio'));

        if (! $activas) {
            $generales[] = 'sin_fecha_inicio';
        }

        return [
            'asesor_id' => $asesorId,
            'mes' => $mes,
            'comisiones_activas' => $activas,
            'avisos' => array_values(array_unique($generales)),
            'umbral' => [
                'valor' => $d['umbral'], 'estado' => $d['estado_umbral'], 'computables' => $d['acumulado'], 'faltante' => $d['faltante'], 'comisionables' => $d['comisionables'],
                'avance' => $d['umbral'] ? min(100, (int) floor($d['acumulado'] * 100 / max(1, $d['umbral']))) : null,
            ],
            'etapas' => array_map(fn (BigDecimal $v) => Dinero::texto($v), $etapas),
            'total_mes' => Dinero::texto($total),
            'total_parcial' => $sinCalcular > 0,
            'sin_calcular' => $sinCalcular,
            'pedidos' => $salida,
        ];
    }

    /**
     * Cierra un pedido: unidades, importe (null si alguna linea es desconocida), situacion y motivos UNA vez por pedido.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    protected function cerrarPedido(array $p): array
    {
        $lineas = $p['lineas'];
        $comisionables = array_sum(array_map(fn ($l) => (int) ($l['comisionables'] ?? 0), $lineas));
        $conocidas = array_filter($lineas, fn ($l) => $l['importe'] !== null);
        $importe = BigDecimal::zero();

        foreach ($conocidas as $l) {
            $importe = $importe->plus(Dinero::de($l['importe']));
        }

        $motivos = array_values(array_unique(array_merge(...array_map(fn ($l) => $l['motivos'], $lineas))));
        $desconocidas = count($conocidas) < count($lineas);
        $sinUnidades = ! $desconocidas && $comisionables === 0 && in_array('dentro_del_umbral', $motivos, true);

        // Un pedido que aun no comisiona (dentro del umbral) solo explica eso: sus condiciones de cobro no cambian nada todavia.
        if ($sinUnidades) {
            $motivos = array_values(array_filter($motivos, fn ($m) => str_starts_with($m, 'politica_pendiente') || $m === 'dentro_del_umbral'));
        }

        $etapas = array_map(fn ($l) => $l['etapa'], $lineas);
        $situacion = match (true) {
            $sinUnidades => 'dentro_del_umbral',
            in_array('sin_calcular', $etapas, true) => 'sin_calcular',
            default => array_reduce($etapas, fn (?string $min, string $e) => $min === null || self::ORDEN[$e] < self::ORDEN[$min] ? $e : $min),
        };

        return [
            'order_id' => $p['order_id'],
            'increment_id' => $p['increment_id'],
            'confirmado_en' => $p['confirmado_en'],
            'vendidas' => array_sum(array_map(fn ($l) => $l['vendidas'], $lineas)),
            'comisionables' => $comisionables,
            'importe' => $desconocidas ? null : Dinero::texto($importe),
            'importe_parcial' => $desconocidas && $conocidas !== [] ? Dinero::texto($importe) : null,
            'situacion' => $situacion,
            'motivos' => $motivos,
            'lineas' => $lineas,
        ];
    }

    /** Etapa de la linea: la mas avanzada con importe (parcialmente pagada = por pagar); sin importe, bloqueada; desconocida, sin_calcular. */
    protected function etapaPrincipal(array $partes, bool $desconocida): string
    {
        if ($desconocida) {
            return 'sin_calcular';
        }

        foreach (['por_pagar', 'pagada', 'habilitada'] as $e) {
            if ($partes[$e]->isPositive()) {
                return $e;
            }
        }

        return 'bloqueada';
    }

    /**
     * Lo aplicado a cada linea de las liquidaciones APROBADAS (el libro guardado: un hecho posterior no lo reescribe).
     *
     * @param  array<int, ComisionLiquidacion>  $liquidaciones
     * @return array<int, array<string, mixed>>
     */
    protected function aplicadasPorLinea(array $liquidaciones): array
    {
        $aplicadas = [];

        foreach ($liquidaciones as $liq) {
            if ($liq->estado !== ComisionLiquidacion::APROBADA) {
                continue;
            }

            foreach ($this->aplicaciones->estado($liq)['destinos'] as $dest) {
                $dest['tipo'] === 'linea' && $aplicadas[$dest['id']] = $dest;
            }
        }

        return $aplicadas;
    }
}
