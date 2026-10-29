<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * «Mis comisiones»: la vista de la ASESORA sobre su propia comision. Solo lectura y SOLO lo propio: el asesor nunca llega por
 * parametro (se toma del actor autenticado), de modo que no existe forma de pedir la cuenta de otra persona. Exige `view_own`
 * (el servicio lo vuelve a exigir aunque la ruta ya lo exija). Jamas incluye costo, base, margen ni evidencia interna.
 *
 * Estimada y habilitada NO son importes independientes: son etapas del MISMO importe. Para no sumarlas dos veces, cada peso de
 * cada linea del mes se reparte en UNA sola etapa (las etapas no se solapan y su suma es el total del mes):
 *
 *   bloqueada  = estimada que aun no cumple las condiciones (con el motivo comprensible de lo que falta)
 *   habilitada = cumple todo y aun no se paga (o esta en una liquidacion en borrador)
 *   por pagar  = liquidacion aprobada, saldo pendiente
 *   pagada     = abonos aplicados a la linea
 *
 * Las politicas pendientes (pedido mixto, desplazamiento del umbral, cambio de umbral en el mes, devolucion sin politica) se
 * muestran como bloqueo con su motivo; esta vista no decide ninguna politica ni cambia ningun calculo.
 */
class MisComisionesService
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected UmbralMensualService $umbral,
        protected ElegibilidadComisionService $elegibilidad,
        protected LiquidacionesComisionService $liquidaciones,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /** @return array<string, mixed> */
    public function resumen(?Admin $actor, string $mes): array
    {
        if (! $actor?->id || ! $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_PROPIAS)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para ver sus comisiones.');
        }

        $asesorId = (int) $actor->id;
        $d = $this->umbral->distribucion($asesorId, $mes);
        $lineas = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('mes_comercial', $mes)
            ->where('estado', '!=', ComisionLinea::ANULADA)->orderBy('confirmado_en')->orderBy('order_id')->orderBy('order_item_id')->get();

        $liquidaciones = ComisionLiquidacion::query()->where('asesor_admin_id', $asesorId)->whereIn('id', $lineas->pluck('liquidacion_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
        $aplicadas = [];

        foreach ($liquidaciones as $liq) {
            if ($liq->estado !== ComisionLiquidacion::APROBADA) {
                continue;
            }

            foreach ($this->aplicaciones->estado($liq)['destinos'] as $dest) {
                $dest['tipo'] === 'linea' && $aplicadas[$dest['id']] = $dest;
            }
        }

        $etapas = ['bloqueada' => BigDecimal::zero(), 'habilitada' => BigDecimal::zero(), 'por_pagar' => BigDecimal::zero(), 'pagada' => BigDecimal::zero()];
        $pedidos = [];
        $motivosPedido = [];
        $incrementos = DB::table('orders')->whereIn('id', $lineas->pluck('order_id')->unique())->pluck('increment_id', 'id');

        foreach ($lineas as $l) {
            $dl = $d['lineas'][$l->id] ?? null;
            $liq = $l->liquidacion_id ? $liquidaciones->get($l->liquidacion_id) : null;
            $partes = ['bloqueada' => BigDecimal::zero(), 'habilitada' => BigDecimal::zero(), 'por_pagar' => BigDecimal::zero(), 'pagada' => BigDecimal::zero()];
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
                $partes['bloqueada'] = Dinero::de($dl['monto_estimado'] ?? 0);
                $motivosPedido[$l->order_id] ??= $this->elegibilidad->dePedido((int) $l->order_id)['motivos'];
                array_push($motivos, ...$motivosPedido[$l->order_id], ...($dl['marcas'] ?? []), ...array_filter([$l->alerta]));

                if (($dl['comisionables'] ?? null) === 0 && $d['estado_umbral'] === 'definido') {
                    $motivos[] = 'dentro_del_umbral';
                }
            }

            foreach ($partes as $etapa => $valor) {
                $etapas[$etapa] = $etapas[$etapa]->plus($valor);
            }

            $pedidos[$l->order_id] ??= ['order_id' => (int) $l->order_id, 'increment_id' => $incrementos[$l->order_id] ?? $l->order_id, 'confirmado_en' => $l->confirmado_en, 'lineas' => []];
            $pedidos[$l->order_id]['lineas'][] = [
                'referencia' => $l->referencia,
                'sku' => $l->sku,
                'vendidas' => (int) $l->cantidad_vendida,
                'comisionables' => $l->estado === ComisionLinea::HABILITADA ? (int) $l->unidades_comisionables : ($dl['comisionables'] ?? null),
                'etapa' => $this->etapaPrincipal($partes),
                'importe' => Dinero::texto($partes['bloqueada']->plus($partes['habilitada'])->plus($partes['por_pagar'])->plus($partes['pagada'])),
                'pagado' => Dinero::texto($partes['pagada']),
                'por_pagar' => Dinero::texto($partes['por_pagar']),
                'motivos' => array_values(array_unique($motivos)),
            ];
        }

        $total = $etapas['bloqueada']->plus($etapas['habilitada'])->plus($etapas['por_pagar'])->plus($etapas['pagada']);
        $cuenta = $this->cuenta($asesorId);

        return [
            'mes' => $mes,
            'umbral' => ['valor' => $d['umbral'], 'estado' => $d['estado_umbral'], 'computables' => $d['acumulado'], 'faltante' => $d['faltante'], 'comisionables' => $d['comisionables'],
                'avance' => $d['umbral'] ? min(100, (int) floor($d['acumulado'] * 100 / max(1, $d['umbral']))) : null],
            'etapas' => array_map(fn (BigDecimal $v) => Dinero::texto($v), $etapas),
            'total_mes' => Dinero::texto($total),
            'pedidos' => array_values($pedidos),
            'liquidaciones' => $cuenta['liquidaciones'],
            'saldo_por_pagar' => $cuenta['por_pagar'],
            'habilitado_sin_liquidar' => $cuenta['habilitado_sin_liquidar'],
        ];
    }

    /**
     * Liquidaciones propias con sus abonos y saldo (de todos los meses; el saldo es lo que ByLopez aun debe pagar).
     *
     * @return array{liquidaciones: list<array<string, mixed>>, por_pagar: string, habilitado_sin_liquidar: string}
     */
    protected function cuenta(int $asesorId): array
    {
        $liqs = ComisionLiquidacion::query()->with('abonos')->where('asesor_admin_id', $asesorId)->where('estado', ComisionLiquidacion::APROBADA)->orderByDesc('consecutivo')->get();
        $porPagar = BigDecimal::zero();
        $filas = [];

        foreach ($liqs as $liq) {
            $saldo = $this->liquidaciones->saldo($liq);
            $porPagar = $porPagar->plus($saldo);
            $filas[] = [
                'numero' => $liq->numero(),
                'estado' => $liq->estado,
                'periodo' => $liq->periodo_desde?->toDateString().' – '.$liq->periodo_hasta?->toDateString(),
                'total' => Dinero::texto(Dinero::de($liq->total)),
                'abonado' => Dinero::texto($this->liquidaciones->abonado($liq)),
                'saldo' => Dinero::texto($saldo),
                'abonos' => $liq->abonos->sortBy('id')->map(fn ($a) => [
                    'fecha' => $a->fecha_pago?->toDateString(), 'tipo' => $a->tipo, 'monto' => Dinero::texto(Dinero::de($a->monto)), 'medio' => $a->medio, 'referencia' => $a->referencia,
                ])->values()->all(),
            ];
        }

        $libres = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)->whereNull('liquidacion_id');

        return ['liquidaciones' => $filas, 'por_pagar' => Dinero::texto($porPagar), 'habilitado_sin_liquidar' => Dinero::texto(Dinero::de($libres->sum('monto')))];
    }

    /** Etapa que se muestra como estado de la linea: la mas avanzada con importe (parcialmente pagada = por pagar); sin importe, bloqueada. */
    protected function etapaPrincipal(array $partes): string
    {
        foreach (['por_pagar', 'pagada', 'habilitada'] as $e) {
            if ($partes[$e]->isPositive()) {
                return $e;
            }
        }

        return 'bloqueada';
    }
}
