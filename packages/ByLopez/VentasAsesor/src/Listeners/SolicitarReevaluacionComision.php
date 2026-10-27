<?php

namespace ByLopez\VentasAsesor\Listeners;

use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\ClasificacionPedido;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Comisiones\ReevaluacionComisionesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Doc. 23 §10.2 — conecta los HECHOS reales con la reevaluacion automatica, REUTILIZANDO eventos que ya existen (sin tocar
 * Webkul ni Logistica): los eventos de Eloquent de los modelos que registran cada hecho y los eventos de Bagisto.
 *
 *  - Entrega: validacion y su anulacion (ValidacionEntrega), retornos de la transportadora y su anulacion (Retorno), envios.
 *  - Cobro: pagos registrados, confirmados, rechazados o revertidos (Pago) y sus aplicaciones a pedidos (AplicacionPago).
 *  - Dinero recibido: movimientos de cuenta de transportadora — recaudo liquidado, liquidacion de una diferencia (sigue la
 *    cadena hasta los pedidos de la liquidacion original) — y correcciones de guia (recaudo esperado).
 *  - Cancelaciones, cambios de estado, reembolsos y envios de Bagisto.
 *  - Atribucion y clasificacion comercial (reasignacion de asesor, tipo de operacion).
 *
 * Corre DENTRO de la transaccion del hecho y solo marca la bandeja (ReevaluacionComisionesService::solicitar, que nunca
 * lanza): el calculo va despues del commit. Si algo falla aqui, el hecho se confirma igual.
 */
class SolicitarReevaluacionComision
{
    /** Modelos de Logistica (por nombre: VentasAsesor no depende del paquete; si no esta instalado, nunca se disparan). */
    public const MODELOS_LOGISTICA = [
        'ByLopez\\Logistica\\Models\\ValidacionEntrega' => 'validacion_entrega',
        'ByLopez\\Logistica\\Models\\Retorno' => 'retorno_transportadora',
        'ByLopez\\Logistica\\Models\\Guia' => 'guia',
        'ByLopez\\Logistica\\Models\\MovimientoCuenta' => 'cuenta_transportadora',
        // C6: una conciliacion (efectivo / compensacion) cambia si el recaudo de la venta ya esta conciliado con ByLopez.
        'ByLopez\\Logistica\\Models\\ConciliacionMovimiento' => 'conciliacion_cuenta',
    ];

    public const EVENTOS_BAGISTO = [
        'sales.order.cancel.after' => 'cancelacion',
        'sales.order.update-status.after' => 'estado_pedido',
        'sales.refund.save.after' => 'reembolso',
        'sales.shipment.save.after' => 'envio',
    ];

    public function __construct(protected ReevaluacionComisionesService $reevaluacion) {}

    public static function registrar(): void
    {
        foreach (self::MODELOS_LOGISTICA as $clase => $origen) {
            Event::listen('eloquent.saved: '.$clase, fn ($modelo) => app(self::class)->deModelo($modelo, $origen));
        }

        foreach ([Pago::class => 'pago', AplicacionPago::class => 'aplicacion_pago', AtribucionPedido::class => 'atribucion', ClasificacionPedido::class => 'clasificacion'] as $clase => $origen) {
            Event::listen('eloquent.saved: '.$clase, fn ($modelo) => app(self::class)->deModelo($modelo, $origen));
        }

        foreach (self::EVENTOS_BAGISTO as $evento => $origen) {
            Event::listen($evento, fn ($entidad) => app(self::class)->deBagisto($entidad, $origen));
        }
    }

    public function deModelo(mixed $modelo, string $origen): void
    {
        try {
            $this->reevaluacion->solicitar($this->pedidosDe($modelo), $origen);
        } catch (Throwable) {
            // solicitar() ya registra sus fallos; aqui solo se protege el hecho de origen.
        }
    }

    public function deBagisto(mixed $entidad, string $origen): void
    {
        try {
            $id = $origen === 'cancelacion' || $origen === 'estado_pedido' ? ($entidad->id ?? null) : ($entidad->order_id ?? null);
            $this->reevaluacion->solicitar([$id], $origen);
        } catch (Throwable) {
            // Nunca interfiere con la operacion nativa.
        }
    }

    /** @return list<int> */
    protected function pedidosDe(mixed $modelo): array
    {
        if ($modelo instanceof Pago) {
            return DB::table('bylopez_va_aplicaciones_pago')->where('pago_id', $modelo->id)->pluck('order_id')->map(fn ($id) => (int) $id)->all();
        }

        // Conciliacion C6: el pedido es el del movimiento que concilia.
        if (isset($modelo->movimiento_id) && ! isset($modelo->order_id)) {
            $pedido = DB::table('bylopez_lg_cuenta_movimientos')->where('id', $modelo->movimiento_id)->value('order_id');

            return $pedido === null ? [] : [(int) $pedido];
        }

        $orderId = $modelo->order_id ?? null;

        if ($orderId !== null) {
            return [(int) $orderId];
        }

        // Diferencia de una liquidacion de transportadora (sin pedido): afecta a los pedidos de la liquidacion que la origino.
        $clave = (string) ($modelo->idempotency_key ?? '');

        if (preg_match('/^liquidacion:(\d+):diferencia:apertura$/', $clave, $m)) {
            return $this->pedidosDeLiquidacion((int) $m[1]);
        }

        return [];
    }

    /**
     * Pedidos de una liquidacion y, si incluyo la diferencia arrastrada de otra, tambien los de aquella (su conciliacion
     * depende de esta).
     *
     * @param  array<int, true>  $vistas
     * @return list<int>
     */
    protected function pedidosDeLiquidacion(int $liquidacionId, array $vistas = []): array
    {
        if (isset($vistas[$liquidacionId]) || count($vistas) > 50) {
            return [];
        }

        $movs = DB::table('bylopez_lg_cuenta_movimientos')->where('liquidacion_id', $liquidacionId)->get(['order_id', 'idempotency_key']);
        $ids = $movs->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($movs as $m) {
            if (preg_match('/^liquidacion:(\d+):diferencia:apertura$/', (string) $m->idempotency_key, $x)) {
                $ids = [...$ids, ...$this->pedidosDeLiquidacion((int) $x[1], $vistas + [$liquidacionId => true])];
            }
        }

        return array_values(array_unique($ids));
    }
}
