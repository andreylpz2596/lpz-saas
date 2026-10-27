<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Models\RentabilidadEvaluacion;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Lecturas de comisiones con AISLAMIENTO en el servicio: con `view_all` todo; con `view_own` solo lo del propio asesor
 * (lineas, liquidaciones, abonos, comprobantes); sin ninguna, nada. La vista del asesor NUNCA incluye costo, base ni
 * margen (COM-D-12): solo venta, unidades, estado y comision.
 */
class ConsultaComisionesService
{
    public function __construct(
        protected Autorizacion $autorizacion,
        protected UmbralMensualService $umbral,
        protected ElegibilidadComisionService $elegibilidad,
        protected LiquidacionesComisionService $liquidaciones,
        protected AplicacionesComisionService $aplicaciones,
    ) {}

    /** Asesor cuyos datos puede ver el actor: null = todos (view_all); id = solo el propio. */
    public function alcance(?Admin $actor): ?int
    {
        if ($this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_TODAS)) {
            return null;
        }

        if ($actor?->id && $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_PROPIAS)) {
            return (int) $actor->id;
        }

        throw new Invalida(Invalida::SIN_PERMISO, 'No tiene permiso para ver comisiones.');
    }

    public function veCostos(?Admin $actor): bool
    {
        return $this->autorizacion->tienePermiso($actor, Autorizacion::PERMISO_COMISIONES_VER_TODAS);
    }

    /**
     * Resumen del mes por asesor (avance del umbral y comision estimada / habilitada).
     *
     * @return list<array<string, mixed>>
     */
    public function resumenMes(?Admin $actor, string $mes, ?int $asesorFiltro = null): array
    {
        $propio = $this->alcance($actor);
        $asesor = $propio ?? $asesorFiltro;

        $asesores = ComisionLinea::query()->where('mes_comercial', $mes)->where('estado', '!=', ComisionLinea::ANULADA)
            ->when($asesor !== null, fn ($q) => $q->where('asesor_admin_id', $asesor))
            ->distinct()->pluck('asesor_admin_id');

        if ($asesor !== null && $asesores->isEmpty()) {
            $asesores = collect([$asesor]);
        }

        $nombres = DB::table('bylopez_va_asesores')->whereIn('admin_id', $asesores)->pluck('nombre', 'admin_id');
        $salida = [];

        foreach ($asesores as $id) {
            $d = $this->umbral->distribucion((int) $id, $mes);
            $habilitado = ComisionLinea::query()->where('asesor_admin_id', $id)->where('mes_comercial', $mes)
                ->where('estado', ComisionLinea::HABILITADA)->sum('monto');

            $salida[] = [
                'asesor_id' => (int) $id,
                'asesor' => $nombres[$id] ?? ('#'.$id),
                'umbral' => $d['umbral'],
                'estado_umbral' => $d['estado_umbral'],
                'computables' => $d['acumulado'],
                'faltante' => $d['faltante'],
                'avance' => $d['umbral'] ? min(100, (int) floor($d['acumulado'] * 100 / max(1, $d['umbral']))) : null,
                'comisionables' => $d['comisionables'],
                'estimada' => $d['monto_estimado'],
                'habilitada' => Dinero::texto(Dinero::de($habilitado)),
            ];
        }

        return $salida;
    }

    /**
     * Lineas del mes con su distribucion, agrupadas por pedido.
     *
     * @param  array{mes: string, asesor: ?int, estado: ?string}  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function pedidosDelMes(?Admin $actor, array $filtros): array
    {
        $propio = $this->alcance($actor);
        $asesor = $propio ?? $filtros['asesor'];
        $costos = $this->veCostos($actor);

        $lineas = ComisionLinea::query()->where('mes_comercial', $filtros['mes'])
            ->when($asesor !== null, fn ($q) => $q->where('asesor_admin_id', $asesor))
            ->when($filtros['estado'] !== null, fn ($q) => $q->where('estado', $filtros['estado']))
            ->orderBy('confirmado_en')->orderBy('order_id')->orderBy('order_item_id')->get();

        $dist = [];
        foreach ($lineas->pluck('asesor_admin_id')->unique() as $id) {
            $dist += $this->umbral->distribucion((int) $id, $filtros['mes'])['lineas'];
        }

        $incrementos = DB::table('orders')->whereIn('id', $lineas->pluck('order_id')->unique())->pluck('increment_id', 'id');
        $pedidos = [];

        foreach ($lineas as $l) {
            $pedidos[$l->order_id] ??= ['order_id' => $l->order_id, 'increment_id' => $incrementos[$l->order_id] ?? $l->order_id,
                'asesor' => $l->asesor_name_snapshot, 'confirmado_en' => $l->confirmado_en, 'lineas' => []];
            $pedidos[$l->order_id]['lineas'][] = $this->linea($l, $dist[$l->id] ?? null, $costos);
        }

        return array_values($pedidos);
    }

    /** Detalle de un pedido: lineas, historial, condiciones vivas y (solo admin) la evaluacion de rentabilidad. */
    public function detallePedido(?Admin $actor, int $orderId): ?array
    {
        $propio = $this->alcance($actor);
        $lineas = ComisionLinea::query()->with('eventos')->where('order_id', $orderId)->orderBy('order_item_id')->orderBy('id')->get();

        if ($propio !== null && $lineas->where('asesor_admin_id', $propio)->isEmpty()) {
            return null;
        }

        if ($propio !== null) {
            $lineas = $lineas->where('asesor_admin_id', $propio)->values();
        }

        $costos = $this->veCostos($actor);
        $dist = [];
        foreach ($lineas->where('estado', '!=', ComisionLinea::ANULADA)->map(fn ($l) => [$l->asesor_admin_id, $l->mes_comercial])->unique() as [$a, $m]) {
            $dist += $this->umbral->distribucion($a, $m)['lineas'];
        }

        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'increment_id', 'status', 'created_at']);
        $eleg = $this->elegibilidad->dePedido($orderId);

        return [
            'pedido' => $pedido,
            'mes' => MesComercial::de((string) $pedido->created_at),
            'lineas' => $lineas->map(fn (ComisionLinea $l) => $this->linea($l, $dist[$l->id] ?? null, $costos) + [
                'eventos' => $l->eventos->map(fn ($e) => ['tipo' => $e->tipo, 'detalle' => $costos ? $e->detalle : null, 'actor' => $e->actor_name_snapshot, 'en' => $e->created_at])->all(),
            ])->all(),
            'condiciones' => $eleg['motivos'],
            'evidencia' => $costos ? $eleg['evidencia'] : null,
            'evaluacion' => $costos ? RentabilidadEvaluacion::query()->with('lineas')->where('order_id', $orderId)->first() : null,
            've_costos' => $costos,
        ];
    }

    /**
     * Estado de cuenta del asesor: liquidaciones (con abonos y saldo), comision habilitada aun sin liquidar y ajustes.
     *
     * @return array<string, mixed>
     */
    public function cuenta(?Admin $actor, int $asesorId): array
    {
        $propio = $this->alcance($actor);

        if ($propio !== null && $propio !== $asesorId) {
            throw new Invalida(Invalida::SIN_PERMISO, 'Solo puede ver su propia cuenta.');
        }

        $liqs = ComisionLiquidacion::query()->with(['abonos', 'lineas'])->where('asesor_admin_id', $asesorId)->orderByDesc('consecutivo')->get();
        $porPagar = BigDecimal::zero();
        $filas = [];

        $incidencias = ComisionIncidencia::query()->whereIn('liquidacion_id', $liqs->pluck('id')->all() ?: [0])->orderByDesc('id')->get()->groupBy('liquidacion_id');

        foreach ($liqs as $liq) {
            $saldo = $this->liquidaciones->saldo($liq);
            $liq->estado === ComisionLiquidacion::APROBADA && $porPagar = $porPagar->plus($saldo);
            $estado = $this->aplicaciones->estado($liq);

            $filas[] = [
                'liquidacion' => $liq,
                'abonado' => Dinero::texto($this->liquidaciones->abonado($liq)),
                'saldo' => Dinero::texto($saldo),
                // Lo que hoy se puede abonar y lo retenido por incidencias abiertas (Doc. 23 §10.3).
                'pagable' => $liq->estado === ComisionLiquidacion::APROBADA ? $estado['pagable'] : '0.00',
                'retenido' => $estado['retenido'],
                'compensado' => $estado['compensado'],
                'aplicaciones' => $this->aplicaciones($liq, $estado),
                'incidencias' => $incidencias->get($liq->id, collect()),
            ];
        }

        $disponibles = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('estado', ComisionLinea::HABILITADA)->whereNull('liquidacion_id');

        return [
            'asesor_id' => $asesorId,
            'asesor' => DB::table('bylopez_va_asesores')->where('admin_id', $asesorId)->value('nombre'),
            'liquidaciones' => $filas,
            'por_pagar' => Dinero::texto($porPagar),
            'habilitado_sin_liquidar' => Dinero::texto(Dinero::de((clone $disponibles)->whereNull('alerta')->sum('monto'))),
            'habilitado_en_revision' => Dinero::texto(Dinero::de((clone $disponibles)->whereNotNull('alerta')->sum('monto'))),
            'ajustes' => ComisionAjuste::query()->where('asesor_admin_id', $asesorId)->orderByDesc('id')->get(),
        ];
    }

    /** Asesores con perfil (para filtros y formularios). @return array<int, string> */
    public function asesores(): array
    {
        return DB::table('bylopez_va_asesores')->orderBy('nombre')->pluck('nombre', 'admin_id')->all();
    }

    /**
     * Aplicacion GUARDADA de abonos, reversos y compensaciones a cada linea de la liquidacion (AplicacionesComisionService):
     * un hecho posterior no reescribe a que linea se aplico un pago ya hecho.
     *
     * @return array<int, array{pagado: string, compensado: string, pendiente: string, retenido: bool}> por linea
     */
    public function aplicaciones(ComisionLiquidacion $liq, ?array $estado = null): array
    {
        $estado ??= $this->aplicaciones->estado($liq);
        $salida = [];

        foreach ($estado['destinos'] as $d) {
            if ($d['tipo'] === 'linea') {
                $salida[$d['id']] = ['pagado' => $d['pagado'], 'compensado' => $d['compensado'], 'pendiente' => $d['pendiente'], 'retenido' => $d['retenido']];
            }
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    protected function linea(ComisionLinea $l, ?array $d, bool $costos): array
    {
        $fila = [
            'id' => $l->id,
            'order_id' => $l->order_id,
            'referencia' => $l->referencia,
            'sku' => $l->sku,
            'estado' => $l->estado,
            'alerta' => $l->alerta,
            'vendidas' => $l->cantidad_vendida,
            'computables' => $d['computables'] ?? null,
            'comisionables' => $l->estado === ComisionLinea::HABILITADA ? $l->unidades_comisionables : ($d['comisionables'] ?? null),
            'estimada' => $d['monto_estimado'] ?? null,
            'habilitada' => $l->estado === ComisionLinea::HABILITADA ? $l->monto : null,
            'marcas' => $d['marcas'] ?? [],
            'liquidacion_id' => $l->liquidacion_id,
            'precio_neto' => Dinero::texto(Dinero::de($l->precio_neto_unitario)),
            'motivo_anulacion' => $l->motivo_anulacion,
        ];

        // Solo administracion: costo, base comisionable y porcentaje.
        return $costos ? $fila + [
            'costo' => Dinero::texto(Dinero::o($l->costo_unitario)),
            'base' => Dinero::texto(Dinero::o($l->base_unitaria)),
            'porcentaje' => $l->porcentaje,
        ] : $fila;
    }
}
