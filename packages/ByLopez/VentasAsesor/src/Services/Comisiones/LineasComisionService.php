<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use ByLopez\VentasAsesor\Enums\TipoOperacionPedido;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Models\ComisionEvento;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\RentabilidadEvaluacion;
use ByLopez\VentasAsesor\Models\UmbralMovimiento;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * R3 — LINEAS de comision y su libro de unidades (umbral mensual).
 *
 * `asegurar()` crea, para un pedido de ASESOR con venta normal y con snapshot de rentabilidad (frontera: solo pedidos
 * confirmados desde esta version; nada retroactivo), una linea `estimada` por item padre con las fuentes historicas:
 * asesor, acuerdo y % vigentes AL CONFIRMAR (A-02), mes comercial (Bogota), precio neto y costo con autoridad. Registra el
 * movimiento `venta` del umbral (idempotente por linea).
 *
 * `sincronizar()` lleva al libro, como movimientos UNICOS por origen, las cancelaciones (`qty_canceled`) y las unidades no
 * entregadas (retornos vigentes); y aplica una reasignacion de asesor SOLO sobre lineas no habilitadas: anula la linea
 * (con motivo e historial), revierte sus unidades y crea la del nuevo asesor. Una linea habilitada nunca cambia de asesor
 * aqui (AsignacionAsesorService lo impide).
 *
 * Debe correr dentro de una transaccion (la del pedido o la de EvaluarComisionesService).
 */
class LineasComisionService
{
    public function __construct(protected AcuerdosComisionService $acuerdos) {}

    /** @return Collection<int, ComisionLinea> lineas vivas del pedido */
    public function asegurar(int $orderId): Collection
    {
        $evaluacion = RentabilidadEvaluacion::query()->with('lineas')->where('order_id', $orderId)->first();
        $atribucion = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->first(['commercial_owner_type', 'advisor_id', 'advisor_name_snapshot']);
        $tipo = DB::table('bylopez_va_clasificaciones_pedido')->where('order_id', $orderId)->value('tipo_operacion');

        $vivas = ComisionLinea::query()->where('order_id', $orderId)->where('estado', '!=', ComisionLinea::ANULADA)->get();

        $esDeAsesor = $evaluacion !== null
            && $atribucion?->commercial_owner_type === TipoPropiedadComercial::ASESOR->value
            && $atribucion->advisor_id !== null
            && $tipo === TipoOperacionPedido::VENTA_NORMAL->value;

        if (! $esDeAsesor || $vivas->isNotEmpty()) {
            return $vivas;
        }

        $pedido = DB::table('orders')->where('id', $orderId)->first(['id', 'created_at']);
        $confirmado = CarbonImmutable::parse($pedido->created_at, (string) config('app.timezone'));
        $asesorId = (int) $atribucion->advisor_id;
        $acuerdo = $this->acuerdos->vigenteEn($asesorId, $confirmado);
        $cantidades = DB::table('order_items')->whereIn('id', $evaluacion->lineas->pluck('order_item_id'))->pluck('qty_ordered', 'id');

        foreach ($evaluacion->lineas->sortBy('order_item_id') as $l) {
            $P = Dinero::de($l->precio_neto_unitario);
            $C = Dinero::o($l->costo_unitario);
            $vendida = (int) ($cantidades[$l->order_item_id] ?? $l->cantidad);

            $linea = ComisionLinea::query()->create([
                'order_id' => $orderId,
                'order_item_id' => $l->order_item_id,
                'asesor_admin_id' => $asesorId,
                'asesor_name_snapshot' => $atribucion->advisor_name_snapshot,
                'acuerdo_id' => $acuerdo?->id,
                'porcentaje' => $acuerdo?->porcentaje,
                'mes_comercial' => MesComercial::de($confirmado),
                'confirmado_en' => $confirmado,
                'familia_id' => $l->familia_id,
                'referencia' => $l->referencia,
                'sku' => $l->sku,
                'cantidad_vendida' => $vendida,
                'precio_neto_unitario' => (string) $P,
                'costo_unitario' => $C === null ? null : (string) $C,
                // Base A (aprobada): P − C por unidad, llevada a 0 si es negativa. Sin costo con autoridad: desconocida.
                'base_unitaria' => $C === null ? null : (string) Dinero::escala(Dinero::maximo($P->minus($C), Dinero::de(0))),
                'estado' => ComisionLinea::ESTIMADA,
            ]);

            $this->evento($linea, 'creada', ['acuerdo_id' => $acuerdo?->id, 'sin_acuerdo' => $acuerdo === null]);

            if ($vendida > 0) {
                $this->movimiento($linea, UmbralMovimiento::VENTA, $vendida, 'venta:'.$linea->id);
            }
        }

        return ComisionLinea::query()->where('order_id', $orderId)->where('estado', '!=', ComisionLinea::ANULADA)->get();
    }

    /**
     * Cancelaciones, no entregadas y reasignacion. Devuelve las lineas vivas ya sincronizadas.
     *
     * @return Collection<int, ComisionLinea>
     */
    public function sincronizar(int $orderId, ?int $actorId = null): Collection
    {
        $atribucion = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->first(['commercial_owner_type', 'advisor_id']);
        $tipo = DB::table('bylopez_va_clasificaciones_pedido')->where('order_id', $orderId)->value('tipo_operacion');
        $asesorVigente = $atribucion?->commercial_owner_type === TipoPropiedadComercial::ASESOR->value && $tipo === TipoOperacionPedido::VENTA_NORMAL->value
            ? (int) $atribucion->advisor_id : null;

        foreach (ComisionLinea::query()->where('order_id', $orderId)->where('estado', ComisionLinea::ESTIMADA)->get() as $linea) {
            if ($linea->asesor_admin_id !== $asesorVigente) {
                $this->anular($linea, $asesorVigente === null ? 'pedido_sin_asesor_o_no_comercial' : 'reasignacion_de_asesor', $actorId);
            }
        }

        $vivas = $this->asegurar($orderId);
        $items = DB::table('order_items')->where('order_id', $orderId)->get(['id', 'parent_id', 'qty_canceled'])->keyBy('id');
        $noEntregadas = $this->noEntregadas($orderId, $items);
        // Bagisto guarda el envio en el item PADRE (la variante solo actualiza qty_shipped): se cuenta una sola vez.
        $despachadas = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')
            ->where('s.order_id', $orderId)->whereIn('si.order_item_id', $vivas->pluck('order_item_id')->all() ?: [0])
            ->groupBy('si.order_item_id')->selectRaw('si.order_item_id, SUM(si.qty) AS q')->pluck('q', 'si.order_item_id');

        foreach ($vivas as $linea) {
            $cancelada = (int) ($items->get($linea->order_item_id)->qty_canceled ?? 0);
            $retornada = (int) ($noEntregadas[$linea->order_item_id] ?? 0);
            // Bagisto permite cancelar unidades despachadas y no facturadas (qty_to_cancel = pedidas − canceladas − facturadas):
            // si una unidad devuelta por la transportadora ademas se cancela, se resta UNA vez (como cancelada).
            $sinDespachar = max(0, $linea->cantidad_vendida - (int) ($despachadas[$linea->order_item_id] ?? 0));
            $solape = max(0, min($retornada, $cancelada - $sinDespachar));

            $this->ajustarHasta($linea, UmbralMovimiento::CANCELACION, $cancelada);
            $this->ajustarHasta($linea, UmbralMovimiento::NO_ENTREGADA, $retornada - $solape);
        }

        return $vivas;
    }

    /** Unidades computables actuales de cada linea (Σ de su libro). @return array<int, int> */
    public function computables(array $lineaIds): array
    {
        return UmbralMovimiento::query()->whereIn('linea_id', $lineaIds ?: [0])->groupBy('linea_id')
            ->selectRaw('linea_id, SUM(unidades) AS u')->pluck('u', 'linea_id')->map(fn ($u) => max(0, (int) $u))->all();
    }

    public function anular(ComisionLinea $linea, string $motivo, ?int $actorId): void
    {
        $total = (int) UmbralMovimiento::query()->where('linea_id', $linea->id)->sum('unidades');

        if ($total > 0) {
            $this->movimiento($linea, UmbralMovimiento::REVERSO_REASIGNACION, -$total, 'reverso:'.$linea->id);
        }

        $linea->update(['estado' => ComisionLinea::ANULADA, 'anulada_en' => now(), 'motivo_anulacion' => $motivo]);
        $this->evento($linea, 'anulada', ['motivo' => $motivo], $actorId);
    }

    public function evento(ComisionLinea $linea, string $tipo, array $detalle = [], ?int $actorId = null): void
    {
        ComisionEvento::query()->create([
            'linea_id' => $linea->id,
            'tipo' => $tipo,
            'detalle' => $detalle,
            'actor_admin_id' => $actorId,
            'actor_name_snapshot' => $actorId === null ? null : DB::table('admins')->where('id', $actorId)->value('name'),
        ]);
    }

    /**
     * Lleva el total registrado de un tipo (cancelacion / no_entregada, en negativo) hasta `$objetivo` unidades, con un
     * movimiento por cada cambio. La clave incluye el total alcanzado: reintentar es un no-op; una reversion (p. ej. un
     * retorno anulado) entra como `correccion` positiva.
     */
    protected function ajustarHasta(ComisionLinea $linea, string $tipo, int $objetivo): void
    {
        $registrado = -(int) UmbralMovimiento::query()->where('linea_id', $linea->id)
            ->where('idempotency_key', 'like', $tipo.':'.$linea->id.':%')->sum('unidades');
        $objetivo = max(0, min($objetivo, $linea->cantidad_vendida));
        $delta = $objetivo - $registrado;

        if ($delta === 0) {
            return;
        }

        $n = UmbralMovimiento::query()->where('linea_id', $linea->id)->where('idempotency_key', 'like', $tipo.':'.$linea->id.':%')->count() + 1;

        $this->movimiento($linea, $delta > 0 ? $tipo : UmbralMovimiento::CORRECCION, -$delta, $tipo.':'.$linea->id.':'.$n.':'.$objetivo);
    }

    protected function movimiento(ComisionLinea $linea, string $tipo, int $unidades, string $clave): void
    {
        UmbralMovimiento::query()->insertOrIgnore([
            'asesor_admin_id' => $linea->asesor_admin_id,
            'mes' => $linea->mes_comercial,
            'order_id' => $linea->order_id,
            'order_item_id' => $linea->order_item_id,
            'linea_id' => $linea->id,
            'tipo' => $tipo,
            'unidades' => $unidades,
            'confirmado_en' => $linea->confirmado_en,
            'idempotency_key' => $clave,
            'created_at' => now(),
        ]);
    }

    /**
     * Unidades devueltas por la transportadora (retornos vigentes) por item padre.
     *
     * @return array<int, int>
     */
    protected function noEntregadas(int $orderId, Collection $items): array
    {
        $porItem = [];

        foreach (DB::table('bylopez_lg_retorno_items as ri')->join('bylopez_lg_retornos as r', 'r.id', '=', 'ri.retorno_id')
            ->where('r.order_id', $orderId)->where('r.estado', 'confirmado')
            ->groupBy('ri.order_item_id')->selectRaw('ri.order_item_id, SUM(ri.cantidad) AS u')->get() as $f) {
            $id = (int) $f->order_item_id;
            $padre = $items->get($id)?->parent_id;
            $clave = $padre === null ? $id : (int) $padre;
            $porItem[$clave] = ($porItem[$clave] ?? 0) + (int) $f->u;
        }

        return $porItem;
    }
}
