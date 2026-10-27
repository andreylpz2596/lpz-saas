<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ComisionAbono;
use ByLopez\VentasAsesor\Models\ComisionAjuste;
use ByLopez\VentasAsesor\Models\ComisionAplicacion;
use ByLopez\VentasAsesor\Models\ComisionIncidencia;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;

/**
 * Doc. 23 §10.3 — LIBRO DE APLICACIONES de una liquidacion al asesor. Antes la aplicacion de los abonos se derivaba (FIFO
 * sobre las lineas, sin ajustes): no cuadraba con ajustes negativos o positivos y un hecho posterior podia cambiar en
 * silencio a que se aplico un pago ya hecho. Ahora se GUARDA:
 *
 *  - Destinos: lineas (FIFO por habilitacion e id) y despues ajustes POSITIVOS (por id).
 *  - Compensacion: al APROBAR, cada ajuste NEGATIVO que quien liquido eligio expresamente se aplica contra los destinos
 *    (FIFO). Es la unica compensacion y la aprobo una persona; nada se compensa solo.
 *  - Abono: se reparte FIFO entre los destinos con pendiente que NO estan retenidos por una incidencia abierta.
 *  - Reverso: replica con signo − exactamente las aplicaciones del abono que revierte.
 *
 * Invariantes (se verifican antes de confirmar): Σ aplicaciones de un abono = su monto; ningun destino recibe mas que su
 * importe; Σ compensaciones de un ajuste negativo = |monto|.
 */
class AplicacionesComisionService
{
    /**
     * Estado de cada destino de la liquidacion y totales.
     *
     * @return array{destinos: list<array<string, mixed>>, pagado: string, compensado: string, pendiente: string, pagable: string, retenido: string}
     */
    public function estado(ComisionLiquidacion $liq): array
    {
        $apl = ComisionAplicacion::query()->where('liquidacion_id', $liq->id)
            ->selectRaw("linea_id, ajuste_id, SUM(CASE WHEN tipo = 'compensacion' THEN monto ELSE 0 END) AS comp, SUM(CASE WHEN tipo <> 'compensacion' THEN monto ELSE 0 END) AS pag")
            ->groupBy('linea_id', 'ajuste_id')->get();
        $retenidas = $this->lineasRetenidas($liq->id);

        $destinos = [];
        $tot = ['pagado' => BigDecimal::zero(), 'compensado' => BigDecimal::zero(), 'pendiente' => BigDecimal::zero(), 'pagable' => BigDecimal::zero(), 'retenido' => BigDecimal::zero()];

        foreach ($this->destinos($liq) as $d) {
            $fila = $apl->first(fn ($a) => $d['tipo'] === 'linea' ? (int) $a->linea_id === $d['id'] : (int) $a->ajuste_id === $d['id']);
            $pagado = Dinero::de($fila->pag ?? 0);
            $compensado = Dinero::de($fila->comp ?? 0);
            $pendiente = Dinero::maximo($d['monto']->minus($pagado)->minus($compensado), BigDecimal::zero());
            $retenido = isset($retenidas[$d['linea_ref'] ?? 0]);

            $destinos[] = $d + ['pagado' => $pagado, 'compensado' => $compensado, 'pendiente' => $pendiente, 'retenido' => $retenido];
            $tot['pagado'] = $tot['pagado']->plus($pagado);
            $tot['compensado'] = $tot['compensado']->plus($compensado);
            $tot['pendiente'] = $tot['pendiente']->plus($pendiente);
            $tot[$retenido ? 'retenido' : 'pagable'] = $tot[$retenido ? 'retenido' : 'pagable']->plus($pendiente);
        }

        return [
            'destinos' => array_map(fn ($d) => [
                'tipo' => $d['tipo'], 'id' => $d['id'], 'monto' => Dinero::texto($d['monto']), 'pagado' => Dinero::texto($d['pagado']),
                'compensado' => Dinero::texto($d['compensado']), 'pendiente' => Dinero::texto($d['pendiente']), 'retenido' => $d['retenido'],
            ], $destinos),
        ] + array_map(fn (BigDecimal $v) => Dinero::texto($v), $tot);
    }

    /** Lo efectivamente PAGADO (abonos − reversos) a una linea, en cualquier liquidacion. */
    public function pagadoDeLinea(int $lineaId): BigDecimal
    {
        return Dinero::de(ComisionAplicacion::query()->where('linea_id', $lineaId)->where('tipo', '!=', ComisionAplicacion::COMPENSACION)->sum('monto'));
    }

    /** Compensacion de los ajustes negativos elegidos, al aprobar. Llamar dentro de la transaccion, con la liquidacion bloqueada. */
    public function compensarAlAprobar(ComisionLiquidacion $liq): void
    {
        $negativos = ComisionAjuste::query()->where('liquidacion_id', $liq->id)->where('monto', '<', 0)->orderBy('id')->get();

        foreach ($negativos as $ajuste) {
            // Por liquidacion: si una liquidacion anulada libero el ajuste, la nueva lo vuelve a compensar en la suya.
            if (ComisionAplicacion::query()->where('liquidacion_id', $liq->id)->where('compensa_ajuste_id', $ajuste->id)->exists()) {
                continue;
            }

            $resto = $this->repartir($liq, Dinero::de($ajuste->monto)->abs(), ComisionAplicacion::COMPENSACION, ['compensa_ajuste_id' => $ajuste->id], false);

            if ($resto->isPositive()) {
                throw new Invalida(Invalida::DATOS_INVALIDOS, 'Los ajustes negativos superan lo que compensan: revise la liquidacion.');
            }
        }
    }

    /** Reparte un abono FIFO entre los destinos pagables. Llamar dentro de la transaccion, con la liquidacion bloqueada. */
    public function aplicarAbono(ComisionLiquidacion $liq, ComisionAbono $abono): void
    {
        $resto = $this->repartir($liq, Dinero::de($abono->monto), ComisionAplicacion::ABONO, ['abono_id' => $abono->id], true);

        if (! $resto->isZero()) {
            throw new Invalida(Invalida::MONTO_EXCEDE_SALDO, 'El abono supera lo que hoy se puede pagar de la liquidacion.');
        }

        $this->verificarAbono($abono);
    }

    /** El reverso devuelve exactamente lo que aplico el abono revertido. */
    public function aplicarReverso(ComisionAbono $reverso, ComisionAbono $original): void
    {
        foreach (ComisionAplicacion::query()->where('abono_id', $original->id)->orderBy('id')->get() as $a) {
            ComisionAplicacion::query()->create([
                'liquidacion_id' => $a->liquidacion_id, 'abono_id' => $reverso->id, 'linea_id' => $a->linea_id, 'ajuste_id' => $a->ajuste_id,
                'tipo' => ComisionAplicacion::REVERSO, 'monto' => (string) Dinero::de($a->monto)->negated(),
            ]);
        }

        $aplicado = Dinero::de(ComisionAplicacion::query()->where('abono_id', $reverso->id)->sum('monto'));

        if (! $aplicado->negated()->isEqualTo(Dinero::de($original->monto))) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El reverso no cuadra con las aplicaciones del abono.');
        }
    }

    /** Lineas de la liquidacion con una incidencia ABIERTA (lo no pagado queda retenido). @return array<int, true> */
    public function lineasRetenidas(int $liquidacionId): array
    {
        return array_fill_keys(ComisionIncidencia::query()->where('liquidacion_id', $liquidacionId)->where('estado', ComisionIncidencia::ABIERTA)
            ->pluck('linea_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /** Lo que hoy se puede abonar: pendiente de los destinos no retenidos. */
    public function pagable(ComisionLiquidacion $liq): BigDecimal
    {
        return Dinero::de($this->estado($liq)['pagable']);
    }

    /** @return BigDecimal lo que no se pudo repartir */
    protected function repartir(ComisionLiquidacion $liq, BigDecimal $monto, string $tipo, array $origen, bool $saltarRetenidas): BigDecimal
    {
        $estado = $this->estado($liq);
        $resto = $monto;

        foreach ($estado['destinos'] as $d) {
            if (! $resto->isPositive()) {
                break;
            }

            $pendiente = Dinero::de($d['pendiente']);

            if (! $pendiente->isPositive() || ($saltarRetenidas && $d['retenido'])) {
                continue;
            }

            $parte = $resto->isGreaterThan($pendiente) ? $pendiente : $resto;

            ComisionAplicacion::query()->create($origen + [
                'liquidacion_id' => $liq->id,
                'linea_id' => $d['tipo'] === 'linea' ? $d['id'] : null,
                'ajuste_id' => $d['tipo'] === 'ajuste' ? $d['id'] : null,
                'tipo' => $tipo,
                'monto' => (string) $parte,
            ]);

            $resto = $resto->minus($parte);
        }

        return $resto;
    }

    protected function verificarAbono(ComisionAbono $abono): void
    {
        $aplicado = Dinero::de(ComisionAplicacion::query()->where('abono_id', $abono->id)->sum('monto'));

        if (! $aplicado->isEqualTo(Dinero::de($abono->monto))) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, 'El abono no cuadra con sus aplicaciones.');
        }

        // Ningun destino puede quedar pagado por encima de su importe.
        foreach ($this->estado(ComisionLiquidacion::query()->findOrFail($abono->liquidacion_id))['destinos'] as $d) {
            if (Dinero::de($d['pagado'])->plus(Dinero::de($d['compensado']))->isGreaterThan(Dinero::de($d['monto']))) {
                throw new Invalida(Invalida::DATOS_INVALIDOS, 'Una linea quedaria pagada por encima de su comision.');
            }
        }
    }

    /**
     * Destinos en orden FIFO: lineas (habilitacion, id) y luego ajustes positivos (id).
     *
     * @return list<array{tipo: string, id: int, monto: BigDecimal, linea_ref: ?int}>
     */
    protected function destinos(ComisionLiquidacion $liq): array
    {
        $salida = [];

        foreach ($liq->lineas()->orderBy('habilitada_en')->orderBy('id')->get(['id', 'monto']) as $l) {
            $salida[] = ['tipo' => 'linea', 'id' => (int) $l->id, 'monto' => Dinero::de($l->monto), 'linea_ref' => (int) $l->id];
        }

        foreach ($liq->ajustes()->where('monto', '>', 0)->orderBy('id')->get(['id', 'monto', 'linea_id']) as $a) {
            $salida[] = ['tipo' => 'ajuste', 'id' => (int) $a->id, 'monto' => Dinero::de($a->monto), 'linea_ref' => $a->linea_id === null ? null : (int) $a->linea_id];
        }

        return $salida;
    }
}
