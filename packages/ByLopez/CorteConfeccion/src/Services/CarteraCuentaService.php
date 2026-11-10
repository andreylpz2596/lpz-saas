<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Models\CarteraAplicacion;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use ByLopez\CorteConfeccion\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta DERIVADA de un prestador. Nada de lo que devuelve se guarda: cada importe se calcula en el momento y se cuenta
 * una sola vez.
 *
 * Por orden o:  R = trabajo reconocido (recibido por Bodega) · P = trabajo pendiente de recibir (estimado)
 *               A = pagos aplicados + descuentos de prestamo aplicados a esa orden
 *   saldo por pagar de lo recibido  = max(R - A, 0)
 *   anticipo ligado a pendiente     = min(max(A - R, 0), P)       (se pago antes de recibir; sigue «pendiente de recibir»)
 *   credito de la orden             = max(A - R - P, 0)           (se acepto menos de lo estimado; no se traslada solo)
 * Cuenta:
 *   Faltaria por pagar (lo recibido) = deuda previa pendiente + Σ saldo recibido - anticipo disponible - Σ credito
 *   Faltaria por pagar (estimado)    = lo anterior + Σ (P - anticipo ligado a pendiente)
 * Resultado negativo = saldo a favor de ByLopez. El total abonado por trabajo es solo dinero real entregado: usar un
 * anticipo anterior o descontar un prestamo NO lo aumenta.
 */
class CarteraCuentaService
{
    public function __construct(protected CarteraTrabajoService $trabajo) {}

    /**
     * @return array<string, mixed>
     */
    public function cuenta(Taller|Cortador $prestador): array
    {
        $arranque = CarteraConfig::fechaArranque();
        $col = $prestador instanceof Taller ? 'taller_id' : 'cortador_id';
        $colOrden = $prestador instanceof Taller ? 'orden_confeccion_id' : 'orden_corte_id';

        $movimientos = DB::table('bylopez_cc_cartera_movimientos')->where($col, $prestador->id)->orderBy('fecha_efectiva')->orderBy('id')->get();
        $vigentes = $movimientos->where('estado', 'registrado');

        $aplicaciones = DB::table('bylopez_cc_cartera_aplicaciones as a')
            ->join('bylopez_cc_cartera_movimientos as m', 'm.id', '=', 'a.movimiento_id')
            ->where("m.{$col}", $prestador->id)
            ->where('m.estado', 'registrado')
            ->whereNull('a.anulada_en')
            ->get(['a.*']);

        $pagos = $aplicaciones->where('concepto', CarteraAplicacion::PAGO_TRABAJO);
        $descuentos = $aplicaciones->where('concepto', CarteraAplicacion::DESCUENTO_PRESTAMO);

        // ---- dinero por trabajo ----
        $abonos = $vigentes->where('tipo', 'abono');
        $totalAbonado = Dinero::sumar(...$abonos->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $anticipoPrevio = Dinero::sumar(...$vigentes->where('tipo', 'saldo_inicial_anticipo')->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $dineroPorTrabajo = $vigentes->whereIn('tipo', ['abono', 'saldo_inicial_anticipo']);

        $anticipoDisponible = Dinero::cero();
        $detalleAnticipos = [];

        foreach ($dineroPorTrabajo as $mov) {
            $aplicado = Dinero::sumar(...$pagos->where('movimiento_id', $mov->id)->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
            $libre = Dinero::restar((string) $mov->valor, $aplicado);
            $anticipoDisponible = Dinero::sumar($anticipoDisponible, $libre);
            $detalleAnticipos[$mov->id] = ['aplicado' => $aplicado, 'disponible' => $libre];
        }

        // ---- deuda previa por trabajo ----
        $deudaPrevia = Dinero::sumar(...$vigentes->where('tipo', 'saldo_inicial_trabajo')->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $pagadoDeudaPrevia = Dinero::sumar(...$aplicaciones->whereNotNull('saldo_inicial_id')->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $deudaPreviaPendiente = Dinero::maxCero(Dinero::restar($deudaPrevia, $pagadoDeudaPrevia));

        // ---- ordenes ----
        $ordenes = $this->trabajo->ordenes($prestador, $arranque)->map(function (array $o) use ($pagos, $descuentos, $colOrden) {
            $pagosOrden = Dinero::sumar(...$pagos->where($colOrden, $o['orden_id'])->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
            $descOrden = Dinero::sumar(...$descuentos->where($colOrden, $o['orden_id'])->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);

            return $this->estadoDePago($o, $pagosOrden, $descOrden);
        });

        // Ordenes que solo tienen pagos/descuentos (p. ej. su trabajo quedo cubierto por el saldo inicial) tambien se listan.
        $conAplicacion = $pagos->merge($descuentos)->pluck($colOrden)->filter()->unique()->diff($ordenes->pluck('orden_id'));

        if ($conAplicacion->isNotEmpty()) {
            $tabla = $prestador instanceof Taller ? 'bylopez_cc_ordenes_confeccion' : 'bylopez_cc_ordenes_corte';

            foreach (DB::table($tabla)->whereIn('id', $conAplicacion)->get(['id', 'codigo', 'estado']) as $extra) {
                $o = [
                    'tipo' => $prestador instanceof Taller ? 'confeccion' : 'corte', 'orden_id' => (int) $extra->id, 'codigo' => $extra->codigo,
                    'estado_produccion' => $extra->estado, 'cantidad_reconocida' => 0, 'cantidad_pendiente' => 0,
                    'valor_reconocido' => Dinero::cero(), 'valor_pendiente' => Dinero::cero(), 'sin_tarifa' => [], 'lineas' => [],
                ];
                $pagosOrden = Dinero::sumar(...$pagos->where($colOrden, $extra->id)->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
                $descOrden = Dinero::sumar(...$descuentos->where($colOrden, $extra->id)->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
                $ordenes->push($this->estadoDePago($o, $pagosOrden, $descOrden));
            }
        }

        $reconocido = Dinero::sumar(...$ordenes->pluck('valor_reconocido')->all() ?: ['0']);
        $pendiente = Dinero::sumar(...$ordenes->pluck('valor_pendiente')->all() ?: ['0']);
        $saldoRecibidoOrdenes = Dinero::sumar(...$ordenes->pluck('saldo_recibido')->all() ?: ['0']);
        $anticipoLigado = Dinero::sumar(...$ordenes->pluck('anticipo_ligado_pendiente')->all() ?: ['0']);
        $creditos = Dinero::sumar(...$ordenes->pluck('credito')->all() ?: ['0']);
        $pagosAplicados = Dinero::sumar(...$pagos->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $descuentoTotal = Dinero::sumar(...$descuentos->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);

        $porRecibido = Dinero::restar(Dinero::restar(Dinero::sumar($deudaPreviaPendiente, $saldoRecibidoOrdenes), $anticipoDisponible), $creditos);
        $pendienteSinAnticipo = Dinero::restar($pendiente, $anticipoLigado);
        $porEstimado = Dinero::sumar($porRecibido, $pendienteSinAnticipo);

        // ---- prestamos ----
        $prestamos = $vigentes->whereIn('tipo', ['prestamo', 'saldo_inicial_prestamo']);
        $devoluciones = $vigentes->where('tipo', 'devolucion');
        $totalPrestado = Dinero::sumar(...$prestamos->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $devuelto = Dinero::sumar(...$devoluciones->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
        $detallePrestamos = [];

        foreach ($prestamos as $p) {
            $dev = Dinero::sumar(...$devoluciones->where('prestamo_id', $p->id)->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
            $desc = Dinero::sumar(...$descuentos->where('movimiento_id', $p->id)->pluck('valor')->map(fn ($v) => (string) $v)->all() ?: ['0']);
            $detallePrestamos[$p->id] = ['codigo' => $p->codigo, 'valor' => (string) $p->valor, 'devuelto' => $dev, 'descontado' => $desc, 'pendiente' => Dinero::restar(Dinero::restar((string) $p->valor, $dev), $desc)];
        }

        $prestamoPendiente = Dinero::sumar(...collect($detallePrestamos)->pluck('pendiente')->all() ?: ['0']);

        return [
            'prestador_id' => (int) $prestador->id,
            'iniciada' => $arranque !== null,
            'fecha_arranque' => $arranque?->toDateString(),
            'tipo' => $prestador instanceof Taller ? 'taller' : 'cortador',
            // Trabajo
            'trabajo_reconocido' => $reconocido,
            'trabajo_pendiente' => $pendiente,
            'trabajo_estimado' => Dinero::sumar($reconocido, $pendiente),
            'cantidad_reconocida' => (int) $ordenes->sum('cantidad_reconocida'),
            'cantidad_pendiente' => (int) $ordenes->sum('cantidad_pendiente'),
            // Abonos
            'total_abonado' => $totalAbonado,
            'anticipo_previo' => $anticipoPrevio,
            'pagos_aplicados' => $pagosAplicados,
            'anticipo_disponible' => $anticipoDisponible,
            'anticipo_ligado_pendiente' => $anticipoLigado,
            'detalle_anticipos' => $detalleAnticipos,
            // Resultado
            'deuda_previa' => $deudaPrevia,
            'deuda_previa_pendiente' => $deudaPreviaPendiente,
            'descuento_prestamo' => $descuentoTotal,
            'creditos' => $creditos,
            'por_pagar_recibido' => $porRecibido,
            'por_pagar_estimado' => $porEstimado,
            'a_favor_bylopez' => Dinero::comparar($porEstimado, Dinero::cero()) < 0,
            // Prestamos
            'total_prestado' => $totalPrestado,
            'devuelto' => $devuelto,
            'descontado' => $descuentoTotal,
            'prestamo_pendiente' => $prestamoPendiente,
            'prestamos' => $detallePrestamos,
            // Ordenes
            'ordenes' => $ordenes->values()->all(),
            'sin_tarifa' => $ordenes->pluck('sin_tarifa')->flatten()->unique()->values()->all(),
            // Tope para descontar un prestamo en un corte: solo lo ya recibido y sin pagar (nunca el estimado).
            'saldo_elegible_descuento' => Dinero::maxCero(Dinero::sumar($deudaPreviaPendiente, $saldoRecibidoOrdenes)),
            'movimientos' => $movimientos,
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    protected function estadoDePago(array $o, string $pagosOrden, string $descOrden): array
    {
        $aplicado = Dinero::sumar($pagosOrden, $descOrden);
        $r = $o['valor_reconocido'];
        $p = $o['valor_pendiente'];

        $saldoRecibido = Dinero::maxCero(Dinero::restar($r, $aplicado));
        $excedente = Dinero::maxCero(Dinero::restar($aplicado, $r));
        $anticipoLigado = Dinero::menor($excedente, $p);
        $credito = Dinero::maxCero(Dinero::restar($excedente, $p));
        $saldoEstimado = Dinero::maxCero(Dinero::restar(Dinero::sumar($r, $p), $aplicado));

        $cerradaProduccion = in_array($o['estado_produccion'], ['cerrada', 'cerrado'], true);
        $sinTarifa = ! empty($o['sin_tarifa']);
        $completa = Dinero::esPositivo($r) && Dinero::esCero($p) && Dinero::esCero($saldoRecibido) && ! $sinTarifa && (int) $o['cantidad_pendiente'] === 0;

        $estadoPago = match (true) {
            $sinTarifa && Dinero::esCero($r) && Dinero::esCero($p) => 'sin_tarifa',
            $completa => 'pagada',
            Dinero::esPositivo($aplicado) => 'abonada',
            default => 'pendiente',
        };

        return $o + [
            'pagos_aplicados' => $pagosOrden,
            'descuentos_aplicados' => $descOrden,
            'aplicado' => $aplicado,
            'saldo_recibido' => $saldoRecibido,
            'anticipo_ligado_pendiente' => $anticipoLigado,
            'credito' => $credito,
            'saldo_estimado' => $saldoEstimado,
            'estado_pago' => $estadoPago,
            'cerrada_y_pagada' => $cerradaProduccion && $estadoPago === 'pagada',
        ];
    }

    /** Cuenta de una sola orden: lineas con tarifa, aplicaciones y cortes que la cubrieron. */
    public function cuentaDeOrden(Taller|Cortador $prestador, string $tipoOrden, int $ordenId): ?array
    {
        $cuenta = $this->cuenta($prestador);
        $orden = collect($cuenta['ordenes'])->first(fn ($o) => $o['tipo'] === $tipoOrden && $o['orden_id'] === $ordenId);

        if (! $orden) {
            return null;
        }

        $col = $tipoOrden === 'confeccion' ? 'orden_confeccion_id' : 'orden_corte_id';

        $aplicaciones = DB::table('bylopez_cc_cartera_aplicaciones as a')
            ->join('bylopez_cc_cartera_movimientos as m', 'm.id', '=', 'a.movimiento_id')
            ->leftJoin('bylopez_cc_cartera_liquidaciones as l', 'l.id', '=', 'a.liquidacion_id')
            ->where("a.{$col}", $ordenId)
            ->whereNull('a.anulada_en')
            ->where('m.estado', 'registrado')
            ->orderBy('a.id')
            ->get(['a.concepto', 'a.valor', 'a.liquidacion_id', 'm.codigo as movimiento', 'm.id as movimiento_id', 'm.fecha_efectiva', 'm.tipo', 'l.codigo as liquidacion', 'l.fecha as liquidacion_fecha']);

        return $orden + ['aplicaciones' => $aplicaciones->all(), 'arranque' => $cuenta['fecha_arranque']];
    }
}
