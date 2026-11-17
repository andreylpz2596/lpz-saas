<?php

namespace ByLopez\VentasAsesor\Services\Rentabilidad;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Services\Comisiones\UmbralMensualService;
use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;

/**
 * R4 — PANEL MENSUAL de rentabilidad. Cada cifra declara su fecha y su fuente, y NUNCA se suman categorias distintas:
 *
 *  - Ventas netas, costo vendido, comisiones y costos directos: por MES CALENDARIO de la confirmacion de la venta (empresa).
 *    El periodo de comision de cada asesora (dia de ingreso) solo decide su umbral, no el mes de este panel.
 *    Una comision pagada en otro mes NO se vuelve a reconocer: el gasto pertenece al mes de la venta.
 *  - Comision: UNA vez por linea (habilitada = monto congelado; resto = estimada con el umbral). El abono al asesor no es
 *    un gasto nuevo.
 *  - Costo vendido: el costo ESTANDAR congelado al confirmar (COM-01). No es el costo real completo: tela, corte y
 *    confeccion por orden existen en Corte y Confeccion pero no se concilian con el estandar; transporte productivo,
 *    empaque consumido y gastos generales no tienen registro (Doc. 23 §10.5). Sin costo = fuera de la cifra, nunca 0.
 *  - Recaudo recibido: por fecha de RECEPCION del dinero. Contra entrega: por la liquidacion de la transportadora (bruto,
 *    servicios/fletes, ajustes, diferencia y neto pagado o compensado). Directo: pagos confirmados sin custodia. Los
 *    cobros en manos de terceros se muestran aparte: no se suman (su dinero llega por la liquidacion).
 *  - Gastos generales: no existe fuente en el sistema (ningun registro de arriendo, nomina, servicios): se muestra
 *    «incompleto», nunca 0. Por eso el resultado operativo queda «no disponible» y nunca se llama utilidad neta.
 *  - Lineas sin costo congelado: se excluyen de costo y contribucion y se cuentan aparte (cifra «parcial»).
 */
class PanelMensualService
{
    private const ESTADOS_EXCLUIDOS = ['canceled', 'closed', 'fraud'];

    public function __construct(protected UmbralMensualService $umbral) {}

    /** @return array<string, mixed> */
    public function mes(string $mes): array
    {
        [$inicio, $fin] = MesComercial::rango($mes);
        // orders.created_at esta en la zona de la aplicacion: se convierten los limites de Bogota a esa zona.
        $tz = (string) config('app.timezone');
        $desde = $inicio->setTimezone($tz)->toDateTimeString();
        $hasta = $fin->setTimezone($tz)->toDateTimeString();

        $items = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('bylopez_va_costos_venta as c', fn ($j) => $j->on('c.order_item_id', '=', 'i.id')->where('c.autoridad_comision', 1))
            ->whereNull('i.parent_id')->where('o.created_at', '>=', $desde)->where('o.created_at', '<', $hasta)
            ->whereNotIn('o.status', self::ESTADOS_EXCLUIDOS)
            ->get(['i.order_id', 'i.qty_ordered', 'i.qty_canceled', 'i.base_price', 'i.base_discount_amount', 'c.costo_unitario']);

        $ventas = BigDecimal::zero();
        $ventasConCosto = BigDecimal::zero();
        $costo = BigDecimal::zero();
        $sinCosto = 0;

        foreach ($items as $i) {
            $q = max(0, (int) $i->qty_ordered - (int) $i->qty_canceled);
            $neto = Dinero::de($i->base_price)->multipliedBy($q)
                ->minus((int) $i->qty_ordered > 0 ? Dinero::dividir(Dinero::de($i->base_discount_amount)->multipliedBy($q), BigDecimal::of((int) $i->qty_ordered)) : BigDecimal::zero());
            $ventas = $ventas->plus($neto);

            if ($i->costo_unitario === null) {
                $q > 0 && $sinCosto++;

                continue;
            }

            $ventasConCosto = $ventasConCosto->plus($neto);
            $costo = $costo->plus(Dinero::de($i->costo_unitario)->multipliedBy($q));
        }

        $pedidos = $items->pluck('order_id')->unique()->values()->all();

        // Comision de las ventas CONFIRMADAS en este mes calendario, UNA sola vez por linea: si esta habilitada cuenta su monto
        // CONGELADO; si no, su estimacion con el umbral. Nunca estimada + habilitada del mismo pedido. Los abonos al asesor no son
        // gasto aqui (pagar en otro mes no vuelve a reconocer la comision). Cada asesora tiene su PERIODO (dia de ingreso): la
        // estimacion se toma del reparto de SU periodo (asesora + mes_comercial), aunque ese periodo cruce dos meses calendario.
        $habilitada = BigDecimal::zero();
        $estimadaResto = BigDecimal::zero();
        $lineasMes = ComisionLinea::query()->where('confirmado_en', '>=', $desde)->where('confirmado_en', '<', $hasta)
            ->where('estado', '!=', ComisionLinea::ANULADA)->get(['id', 'asesor_admin_id', 'mes_comercial', 'estado', 'monto']);
        foreach ($lineasMes->groupBy(fn (ComisionLinea $l) => $l->asesor_admin_id.'|'.$l->mes_comercial) as $grupo) {
            $dist = $this->umbral->distribucion((int) $grupo->first()->asesor_admin_id, (string) $grupo->first()->mes_comercial)['lineas'];

            foreach ($grupo as $l) {
                $l->estado === ComisionLinea::HABILITADA
                    ? $habilitada = $habilitada->plus(Dinero::de($l->monto))
                    : $estimadaResto = $estimadaResto->plus(Dinero::de($dist[$l->id]['monto_estimado'] ?? 0));
            }
        }
        $estimada = $habilitada->plus($estimadaResto);

        // Costos directos ESTIMADOS (snapshot de cada venta) y REALES (servicios y devoluciones en cuentas de transportadora).
        $directosEst = BigDecimal::zero();
        foreach (DB::table('bylopez_va_rentabilidad_evaluaciones as e')->whereIn('e.order_id', $pedidos ?: [0])->get(['e.id', 'e.costo_pedido_estimado']) as $e) {
            $directosEst = $directosEst->plus(Dinero::de($e->costo_pedido_estimado));
        }
        foreach (DB::table('bylopez_va_rentabilidad_evaluacion_lineas as l')->join('bylopez_va_rentabilidad_evaluaciones as e', 'e.id', '=', 'l.evaluacion_id')
            ->whereIn('e.order_id', $pedidos ?: [0])->get(['l.variable_unitario', 'l.cargo_pct', 'l.precio_neto_unitario', 'l.cantidad']) as $l) {
            $unidad = Dinero::de($l->variable_unitario)->plus(Dinero::de($l->precio_neto_unitario)->multipliedBy(Dinero::fraccion($l->cargo_pct)));
            $directosEst = $directosEst->plus($unidad->multipliedBy((int) $l->cantidad));
        }
        $conEvaluacion = DB::table('bylopez_va_rentabilidad_evaluaciones')->whereIn('order_id', $pedidos ?: [0])->count();
        $directosReal = Dinero::de(DB::table('bylopez_lg_cuenta_movimientos')->whereIn('order_id', $pedidos ?: [0])
            ->whereIn('concepto', ['servicio', 'costo_devolucion'])->sum('importe'))->negated();

        // Dinero recibido por fecha de RECEPCION, sin duplicar: el contra entrega entra UNA vez, por la liquidacion de la
        // transportadora (bruto − fletes/servicios ± ajustes = saldo; lo pagado o compensado = neto; lo que falto = diferencia).
        $liqs = DB::table('bylopez_lg_liquidaciones_transportadora')->where('estado', 'cerrada')
            ->where('cerrada_en', '>=', $desde)->where('cerrada_en', '<', $hasta)
            ->get(['sentido', 'total_recaudos', 'total_servicios', 'total_ajustes', 'valor_liquidado', 'diferencia']);
        $recaudo = Dinero::de($liqs->sum('total_recaudos'));
        $servicios = Dinero::de($liqs->sum('total_servicios'));
        $ajustesLiq = Dinero::de($liqs->sum('total_ajustes'));
        $diferencias = Dinero::de($liqs->sum('diferencia'));
        $neto = $liqs->reduce(fn (BigDecimal $t, $q) => $q->sentido === 'transportador_entrega' ? $t->plus(Dinero::de($q->valor_liquidado))
            : ($q->sentido === 'bylopez_paga' ? $t->minus(Dinero::de($q->valor_liquidado)) : $t), BigDecimal::zero());

        // Pagos confirmados en Cartera. Los de custodia de un tercero (domiciliario/transportadora) o registrados como cobro al
        // validar la entrega NO son dinero en ByLopez todavia: se reconocen por la liquidacion de arriba, o quedan aparte.
        $custodia = (array) config('ventasasesor.comisiones.medios_custodia_tercero', []);
        $obsEntrega = (string) config('ventasasesor.comisiones.observacion_cobro_en_entrega');
        // D10: el credito del cliente (`credito_cliente`) satisface una obligacion pero NO es dinero recibido: nunca entra en el recaudo.
        $pagosMes = DB::table('bylopez_va_pagos')->where('estado', 'confirmado')->where('revisado_en', '>=', $desde)->where('revisado_en', '<', $hasta);
        $creditoAplicado = Dinero::de((clone $pagosMes)->where('medio_cobro', CreditoClienteService::MEDIO)->sum('monto'));
        $pagos = (clone $pagosMes)->where('medio_cobro', '!=', CreditoClienteService::MEDIO);
        $directo = Dinero::de((clone $pagos)->whereNotIn('medio_cobro', $custodia)
            ->where(fn ($q) => $q->whereNull('observacion')->orWhere('observacion', '!=', $obsEntrega))->sum('monto'));
        $enTerceros = Dinero::de((clone $pagos)->where(fn ($q) => $q->whereIn('medio_cobro', $custodia ?: ['__ninguno__'])->orWhere('observacion', $obsEntrega))->sum('monto'));

        $contribucion = $ventasConCosto->minus($costo)->minus($estimada)->minus($directosEst);

        return [
            'mes' => $mes,
            'pedidos' => count($pedidos),
            'ventas_netas' => Dinero::texto($ventas),
            'costo_vendido' => Dinero::texto($costo),
            'lineas_sin_costo' => $sinCosto,
            'comision_estimada' => Dinero::texto($estimada),
            'comision_habilitada' => Dinero::texto($habilitada),
            'costos_directos_estimados' => Dinero::texto($directosEst),
            'costos_directos_reales' => Dinero::texto($directosReal),
            'pedidos_con_evaluacion' => $conEvaluacion,
            'contribucion_estimada' => Dinero::texto($contribucion),
            'contribucion_parcial' => $sinCosto > 0 || $conEvaluacion < count($pedidos),
            'gastos_generales' => null,
            'resultado_operativo' => null,
            'recaudo_transportadoras' => Dinero::texto($recaudo),
            'servicios_transportadoras' => Dinero::texto($servicios),
            'ajustes_transportadoras' => Dinero::texto($ajustesLiq),
            'diferencias_transportadoras' => Dinero::texto($diferencias),
            'neto_transportadoras' => Dinero::texto($neto),
            'liquidaciones' => $liqs->count(),
            'recaudo_directo' => Dinero::texto($directo),
            'cobros_en_terceros' => Dinero::texto($enTerceros),
            'credito_aplicado' => Dinero::texto($creditoAplicado),
            // El costo es el ESTANDAR congelado al vender (COM-01), no el costo real completo de produccion.
            'costo_es_estandar' => true,
        ];
    }
}
