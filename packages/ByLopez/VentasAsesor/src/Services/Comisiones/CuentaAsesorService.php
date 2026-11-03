<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionAbono;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionAplicacion;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Cuenta de la asesora: el saldo ACUMULADO (de todos los meses) y el historial de movimientos, separados de la «actividad del mes».
 * NO es una segunda cartera ni otra fuente de saldos: lee el libro que ya existe (liquidaciones, abonos, aplicaciones y ajustes) a
 * traves de LiquidacionesComisionService y AplicacionesComisionService, y la actividad del mes de PanelComisionesService.
 *
 * El acumulado NUNCA depende del filtro de mes: filtrar un mes no oculta una deuda anterior. Cada cifra dice a que se refiere:
 *  - saldo aprobado: lo que ByLopez aun debe pagar de liquidaciones aprobadas, separado en PAGABLE hoy y RETENIDO por incidencias;
 *  - habilitada sin liquidar: cumple todo y aun no esta en una liquidacion (con las que estan en revision aparte);
 *  - estimacion pendiente de condiciones: comision todavia bloqueada (se suma solo lo conocido; lo desconocido se cuenta aparte);
 *  - pagos realizados: abonos, reversos y compensaciones, SIEMPRE diferenciados.
 * No incluye prestamos, anticipos ni sobrepagos (no forman parte de esta aprobacion).
 */
class CuentaAsesorService
{
    public function __construct(
        protected ConsultaComisionesService $consulta,
        protected PanelComisionesService $panel,
        protected LiquidacionesComisionService $liquidaciones,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deAsesor(?Admin $actor, int $asesorId, string $mes): array
    {
        $propio = $this->consulta->alcance($actor);

        if ($propio !== null && $propio !== $asesorId) {
            throw new Invalida(Invalida::SIN_PERMISO, 'Solo puede ver su propia cuenta.');
        }

        $calculo = $this->consulta->veCostos($actor);

        return [
            'asesor_id' => $asesorId,
            'asesora' => (string) DB::table('bylopez_va_asesores')->where('admin_id', $asesorId)->value('nombre'),
            'mes' => $mes,
            'acumulado' => $this->acumulado($asesorId),
            'actividad' => $this->panel->deAsesor($asesorId, $mes, $calculo),
            'movimientos' => $this->movimientos($asesorId),
            've_calculo' => $calculo,
        ];
    }

    /**
     * Saldo acumulado de la cuenta, de TODOS los meses.
     *
     * @return array<string, mixed>
     */
    public function acumulado(int $asesorId): array
    {
        $saldo = $pagable = $retenido = BigDecimal::zero();

        foreach (ComisionLiquidacion::query()->with('abonos')->where('asesor_admin_id', $asesorId)->where('estado', ComisionLiquidacion::APROBADA)->get() as $liq) {
            $e = $this->aplicaciones->estado($liq);
            $saldo = $saldo->plus($this->liquidaciones->saldo($liq));
            $pagable = $pagable->plus(Dinero::de($e['pagable']));
            $retenido = $retenido->plus(Dinero::de($e['retenido']));
        }

        $libres = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)->whereNull('liquidacion_id');
        $enBorrador = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)
            ->whereIn('liquidacion_id', ComisionLiquidacion::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLiquidacion::BORRADOR)->select('id'));

        [$estimada, $sinCalcular] = $this->estimacionPendiente($asesorId);

        $abonado = Dinero::de(ComisionAbono::query()->where('asesor_admin_id', $asesorId)->where('tipo', ComisionAbono::ABONO)->sum('monto'));
        $reversado = Dinero::de(ComisionAbono::query()->where('asesor_admin_id', $asesorId)->where('tipo', ComisionAbono::REVERSO)->sum('monto'));
        $compensado = Dinero::de(ComisionAplicacion::query()->where('tipo', ComisionAplicacion::COMPENSACION)
            ->whereIn('liquidacion_id', ComisionLiquidacion::query()->where('asesor_admin_id', $asesorId)->select('id'))->sum('monto'));

        return [
            'saldo_aprobado' => Dinero::texto($saldo),
            'pagable' => Dinero::texto($pagable),
            'retenido' => Dinero::texto($retenido),
            'habilitada_sin_liquidar' => Dinero::texto(Dinero::de((clone $libres)->whereNull('alerta')->sum('monto'))),
            'habilitada_en_revision' => Dinero::texto(Dinero::de((clone $libres)->whereNotNull('alerta')->sum('monto'))),
            'habilitada_en_borrador' => Dinero::texto(Dinero::de($enBorrador->sum('monto'))),
            'estimacion_pendiente' => Dinero::texto($estimada),
            'estimacion_sin_calcular' => $sinCalcular,
            'pagos' => [
                'abonado' => Dinero::texto($abonado),
                'reversado' => Dinero::texto($reversado),
                'compensado' => Dinero::texto($compensado),
                'neto_pagado' => Dinero::texto($abonado->minus($reversado)),
            ],
        ];
    }

    /**
     * Historial de movimientos (abonos, reversos y compensaciones) con su fecha, medio, referencia, soporte y a que liquidacion y
     * pedidos se aplicaron. Del libro de aplicaciones guardado: un hecho posterior no lo reescribe.
     *
     * @return list<array<string, mixed>>
     */
    public function movimientos(int $asesorId): array
    {
        $abonos = ComisionAbono::query()->where('asesor_admin_id', $asesorId)->orderByDesc('fecha_pago')->orderByDesc('id')->get();
        $liquidaciones = ComisionLiquidacion::query()->where('asesor_admin_id', $asesorId)->get()->keyBy('id');
        $aplicadas = ComisionAplicacion::query()->whereIn('abono_id', $abonos->pluck('id')->all() ?: [0])->whereNotNull('linea_id')->get(['abono_id', 'linea_id', 'monto'])->groupBy('abono_id');
        $pedidos = DB::table('bylopez_va_comision_lineas as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('l.id', $aplicadas->flatten()->pluck('linea_id')->unique()->all() ?: [0])->pluck('o.increment_id', 'l.id');
        $porReverso = $abonos->where('tipo', ComisionAbono::REVERSO)->keyBy('revierte_id');

        $salida = [];

        foreach ($abonos as $a) {
            $incrementos = $aplicadas->get($a->id, collect())->map(fn ($x) => (string) ($pedidos[$x->linea_id] ?? $x->linea_id))->unique()->values()->all();

            $salida[] = [
                'tipo' => $a->tipo,
                'id' => (int) $a->id,
                'fecha' => $a->fecha_pago?->toDateString(),
                'monto' => Dinero::texto(Dinero::de($a->monto)),
                'medio' => $a->medio,
                'referencia' => $a->referencia,
                'observacion' => $a->observacion,
                'liquidacion' => $liquidaciones->get($a->liquidacion_id)?->numero(),
                'liquidacion_id' => (int) $a->liquidacion_id,
                'pedidos' => $incrementos,
                'soporte' => $a->comprobante_ruta !== null,
                'reversado' => $a->tipo === ComisionAbono::ABONO && $porReverso->has($a->id),
                'actor' => $a->actor_name_snapshot,
            ];
        }

        $ajustes = ComisionAjuste::query()->where('asesor_admin_id', $asesorId)->get()->keyBy('id');

        foreach (ComisionAplicacion::query()->where('tipo', ComisionAplicacion::COMPENSACION)->whereIn('liquidacion_id', $liquidaciones->keys()->all() ?: [0])->orderByDesc('id')->get() as $c) {
            $ajuste = $ajustes->get($c->compensa_ajuste_id);
            $salida[] = [
                'tipo' => 'compensacion',
                'id' => (int) $c->id,
                'fecha' => $c->created_at?->toDateString(),
                'monto' => Dinero::texto(Dinero::de($c->monto)),
                'medio' => null,
                'referencia' => null,
                'observacion' => $ajuste?->motivo,
                'liquidacion' => $liquidaciones->get($c->liquidacion_id)?->numero(),
                'liquidacion_id' => (int) $c->liquidacion_id,
                'pedidos' => [],
                'soporte' => false,
                'reversado' => false,
                'actor' => null,
            ];
        }

        usort($salida, fn ($x, $y) => [$y['fecha'], $y['id']] <=> [$x['fecha'], $x['id']]);

        return $salida;
    }

    /**
     * Estimacion pendiente de condiciones de todos los meses con comision aun bloqueada. Solo suma lo CONOCIDO; las lineas sin
     * calcular se cuentan aparte (nunca se tratan como $0).
     *
     * @return array{0: BigDecimal, 1: int}
     */
    protected function estimacionPendiente(int $asesorId): array
    {
        $meses = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::ESTIMADA)->distinct()->pluck('mes_comercial');
        $total = BigDecimal::zero();
        $sinCalcular = 0;

        foreach ($meses as $mes) {
            $p = $this->panel->deAsesor($asesorId, (string) $mes, false);
            $total = $total->plus(Dinero::de($p['etapas']['bloqueada']));
            $sinCalcular += $p['sin_calcular'];
        }

        return [$total, $sinCalcular];
    }
}
