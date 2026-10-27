<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\ConciliacionMovimiento;
use ByLopez\Logistica\Models\LiquidacionTransportadora as Liquidacion;
use ByLopez\Logistica\Models\MovimientoCuenta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * C6 — CONCILIACION de una liquidacion: como se satisfizo cada movimiento (efectivo / compensado) y cuanto sigue pendiente del
 * TRANSPORTADOR. No es un libro paralelo: el libro (cuenta_movimientos + liquidaciones) no cambia; esto solo ATRIBUYE.
 *
 * Vocabulario (todo en centavos enteros internamente):
 *   obligacion  = movimiento positivo (recaudo, ajuste a favor): el transportador debe a ByLopez.
 *   credito     = movimiento negativo (servicio, costo de devolucion, ajuste en contra): ByLopez debe al transportador.
 *   hoja        = el movimiento que de verdad se concilia. Un `diferencia:apertura` se EXPANDE a las hojas de la liquidacion que lo
 *                 origino que siguen pendientes (asi la diferencia queda ligada a sus guias/pedidos) solo si la suma cuadra; si no
 *                 (liquidacion anterior a C6 o entrega de mas) la apertura es su propia hoja.
 *   restante    = |importe| − Σ conciliado en liquidaciones anteriores (la suma historica nunca supera |importe|).
 *
 * Propuesta automatica (el Administrador solo confirma):
 *   1. COMPENSACION: cada credito se salda contra obligaciones; primero la misma guia, despues FIFO (fecha_operativa, id). Total
 *      compensado = min(Σ obligaciones, Σ creditos). Compensar no mueve caja.
 *   2. EFECTIVO: lo realmente entregado (o pagado por ByLopez) se asigna FIFO a lo que falte, sin exceder el pendiente.
 *   3. PENDIENTE DEL TRANSPORTADOR = lo que aun falta de las obligaciones. FIFO solo decide a que recaudos queda atribuido; NUNCA
 *      reabre Cartera ni convierte la diferencia en deuda de un cliente.
 * El Administrador puede reasignar ANTES de confirmar (motivo obligatorio, mismos totales); luego no se edita.
 */
class ConciliacionCuenta
{
    /** @var list<string> conceptos que se concilian directamente */
    private const CONCEPTOS_HOJA = ['recaudo', 'servicio', 'costo_devolucion', 'ajuste'];

    /**
     * Propuesta SIN escribir. `$movimientos`: los abiertos que se liquidan; `$valor`: efectivo entregado (transportador) o pagado (ByLopez).
     *
     * @param  Collection<int, MovimientoCuenta>  $movimientos
     * @param  list<array{movimiento_id: mixed, efectivo?: mixed, compensado?: mixed}>|null  $atribucion
     * @return array{hojas: list<array<string, mixed>>, totales: array<string, float>, manual: bool}
     */
    public function propuesta(Collection $movimientos, float $valor, ?array $atribucion = null, bool $bloquear = false): array
    {
        $hojas = $this->hojas($movimientos, $bloquear);
        $centavos = (int) round($valor * 100);

        $obl = array_values(array_filter($hojas, fn ($h) => $h['signo'] > 0));
        $cre = array_values(array_filter($hojas, fn ($h) => $h['signo'] < 0));
        $orden = fn ($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']];
        usort($obl, $orden);
        usort($cre, $orden);

        // 1. Compensacion: misma guia primero, despues FIFO.
        foreach ([true, false] as $soloMismaGuia) {
            foreach ($cre as &$c) {
                foreach ($obl as &$o) {
                    if ($soloMismaGuia && ($c['guia_id'] === null || $c['guia_id'] !== $o['guia_id'])) {
                        continue;
                    }

                    $x = min($c['restante'] - $c['comp'], $o['restante'] - $o['comp']);

                    if ($x > 0) {
                        $c['comp'] += $x;
                        $o['comp'] += $x;
                    }
                }
                unset($o);
            }
            unset($c);
        }

        // 2. Efectivo: a lo que falte del lado que corresponda (el transportador entrega, o ByLopez paga sus creditos).
        $faltaObl = array_sum(array_map(fn ($o) => $o['restante'] - $o['comp'], $obl));
        $faltaCre = array_sum(array_map(fn ($c) => $c['restante'] - $c['comp'], $cre));
        $lado = $faltaObl > 0 ? 'obl' : 'cre';
        $efectivoAsignable = min($centavos, $lado === 'obl' ? $faltaObl : $faltaCre);
        $resto = $efectivoAsignable;

        foreach ($lado === 'obl' ? $obl : $cre as $i => $h) {
            $x = min($resto, $h['restante'] - $h['comp']);

            if ($lado === 'obl') {
                $obl[$i]['efe'] = max(0, $x);
            } else {
                $cre[$i]['efe'] = max(0, $x);
            }

            $resto -= max(0, $x);
        }

        $manual = false;

        if ($atribucion !== null) {
            [$obl, $cre] = $this->reasignar($obl, $cre, $atribucion, $efectivoAsignable);
            $manual = true;
        }

        $filas = array_merge($obl, $cre);
        usort($filas, $orden);

        $suma = fn (array $xs, string $k) => array_sum(array_column($xs, $k));
        $compObl = $suma($obl, 'comp');
        $efeObl = $suma($obl, 'efe');
        $recaudos = array_filter($obl, fn ($h) => $h['concepto'] === 'recaudo');

        return [
            'hojas' => array_map(fn ($h) => $h + ['pendiente' => $h['restante'] - $h['comp'] - $h['efe']], $filas),
            'manual' => $manual,
            'totales' => [
                'cobrado_en_custodia' => $suma($recaudos, 'restante') / 100,
                'servicios_a_compensar' => $suma($cre, 'restante') / 100,
                'compensado' => $compObl / 100,
                'efectivo_sugerido' => max(0, $suma($obl, 'restante') - $suma($cre, 'restante')) / 100,
                'efectivo_a_pagar_bylopez' => max(0, $suma($cre, 'restante') - $suma($obl, 'restante')) / 100,
                'efectivo' => ($lado === 'obl' ? $efeObl : $suma($cre, 'efe')) / 100,
                'conciliado' => ($compObl + $efeObl) / 100,
                'pendiente_transportador' => ($suma($obl, 'restante') - $compObl - $efeObl) / 100,
                'pendiente_bylopez' => ($suma($cre, 'restante') - $suma($cre, 'comp') - $suma($cre, 'efe')) / 100,
            ],
        ];
    }

    /**
     * Escribe la conciliacion de una liquidacion DENTRO de su transaccion (los movimientos incluidos ya estan bloqueados). Recalcula
     * todo bajo bloqueo, aplica la reasignacion manual si viene (con motivo) y verifica que la suma historica no supere |importe|.
     *
     * @param  Collection<int, MovimientoCuenta>  $movimientos
     * @param  list<array{movimiento_id: mixed, efectivo?: mixed, compensado?: mixed}>|null  $atribucion
     * @return array{totales: array<string, float>, manual: bool, filas: int}
     */
    public function registrar(Liquidacion $liquidacion, Collection $movimientos, float $valor, ?array $atribucion, ?string $motivo, int $actorId): array
    {
        if ($atribucion !== null && ($motivo === null || trim($motivo) === '')) {
            throw new Invalida(Invalida::OBSERVACION_REQUERIDA, ['motivo' => 'atribucion']);
        }

        $p = $this->propuesta($movimientos, $valor, $atribucion, true);
        $origen = $p['manual'] ? ConciliacionMovimiento::ORIGEN_MANUAL : ConciliacionMovimiento::ORIGEN_PROPUESTA;
        $filas = 0;

        foreach ($p['hojas'] as $h) {
            if ($h['comp'] + $h['efe'] <= 0) {
                continue;
            }

            ConciliacionMovimiento::query()->create([
                'liquidacion_id' => (int) $liquidacion->id,
                'movimiento_id' => $h['id'],
                'efectivo' => $h['efe'] / 100,
                'compensado' => $h['comp'] / 100,
                'origen' => $origen,
                'motivo' => $p['manual'] ? mb_substr(trim((string) $motivo), 0, 500) : null,
                'actor_admin_id' => $actorId,
            ]);
            $filas++;
        }

        // Invariante: la suma historica conciliada de cada movimiento tocado nunca supera |importe|.
        $ids = array_column($p['hojas'], 'id');
        $excede = DB::table('bylopez_lg_conciliaciones_movimiento as c')->join('bylopez_lg_cuenta_movimientos as m', 'm.id', '=', 'c.movimiento_id')
            ->whereIn('c.movimiento_id', $ids ?: [0])->groupBy('c.movimiento_id', 'm.importe')
            ->havingRaw('SUM(c.efectivo + c.compensado) > ABS(m.importe) + 0.004')->exists();

        if ($excede) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'conciliacion_excede_importe']);
        }

        return ['totales' => $p['totales'], 'manual' => $p['manual'], 'filas' => $filas];
    }

    /**
     * Hojas conciliables (con `restante` > 0) de los movimientos incluidos.
     *
     * @param  Collection<int, MovimientoCuenta>  $movimientos
     * @return list<array<string, mixed>>
     */
    public function hojas(Collection $movimientos, bool $bloquear = false): array
    {
        $acumulado = [];

        foreach ($movimientos as $m) {
            foreach ($this->expandir($m->getAttributes(), $bloquear, []) as $hoja) {
                $acumulado[$hoja['id']] = $hoja;
            }
        }

        $hojas = array_values($acumulado);

        if ($hojas !== []) {
            $guias = DB::table('bylopez_lg_guias')->whereIn('id', array_filter(array_column($hojas, 'guia_id')) ?: [0])->pluck('numero_guia', 'id');
            $pedidos = DB::table('orders')->whereIn('id', array_filter(array_column($hojas, 'order_id')) ?: [0])->pluck('increment_id', 'id');

            foreach ($hojas as &$h) {
                $h['guia'] = $h['guia_id'] === null ? null : ($guias[$h['guia_id']] ?? null);
                $h['pedido'] = $h['order_id'] === null ? null : ($pedidos[$h['order_id']] ?? null);
            }
            unset($h);
        }

        return $hojas;
    }

    /** @return list<array<string, mixed>> */
    private function expandir(array $m, bool $bloquear, array $vistas): array
    {
        if ($m['concepto'] === Concepto::DIFERENCIA_LIQUIDACION->value
            && preg_match('/^liquidacion:(\d+):diferencia:apertura$/', (string) $m['idempotency_key'], $x)
            && ! isset($vistas[(int) $x[1]])) {
            $origen = (int) $x[1];

            if (DB::table('bylopez_lg_conciliaciones_movimiento')->where('liquidacion_id', $origen)->exists()) {
                $consulta = DB::table('bylopez_lg_cuenta_movimientos')->where('liquidacion_id', $origen)->orderBy('id');
                $hijos = ($bloquear ? $consulta->lockForUpdate() : $consulta)->get();
                $hojas = [];

                foreach ($hijos as $h) {
                    foreach ($this->expandir((array) $h, $bloquear, $vistas + [$origen => true]) as $hoja) {
                        $hojas[$hoja['id']] = $hoja;
                    }
                }

                $signado = array_sum(array_map(fn ($h) => $h['signo'] * $h['restante'], $hojas));

                // Solo se expande si lo pendiente de la liquidacion de origen cuadra con la apertura (si no, es su propia hoja).
                if ($hojas !== [] && $signado === (int) round((float) $m['importe'] * 100)) {
                    return array_values($hojas);
                }
            }
        }

        if (! in_array($m['concepto'], self::CONCEPTOS_HOJA, true) && $m['concepto'] !== Concepto::DIFERENCIA_LIQUIDACION->value) {
            return [];
        }

        $conciliado = (int) round((float) DB::table('bylopez_lg_conciliaciones_movimiento')->where('movimiento_id', $m['id'])->selectRaw('COALESCE(SUM(efectivo + compensado), 0) AS s')->value('s') * 100);
        $restante = (int) round(abs((float) $m['importe']) * 100) - $conciliado;

        // Un movimiento cuyo concepto es diferencia de CIERRE (liquidado al nacer) o un pago de liquidacion nunca es hoja.
        if ($restante <= 0 || ! in_array($m['estado'], ['abierto', 'liquidado'], true)) {
            return [];
        }

        if ($m['concepto'] === Concepto::DIFERENCIA_LIQUIDACION->value && ! str_ends_with((string) $m['idempotency_key'], ':diferencia:apertura')) {
            return [];
        }

        return [[
            'id' => (int) $m['id'],
            'concepto' => (string) $m['concepto'],
            'signo' => (float) $m['importe'] > 0 ? 1 : -1,
            'restante' => $restante,
            'guia_id' => $m['guia_id'] === null ? null : (int) $m['guia_id'],
            'order_id' => $m['order_id'] === null ? null : (int) $m['order_id'],
            'fecha' => substr((string) $m['fecha_operativa'], 0, 10),
            'comp' => 0,
            'efe' => 0,
        ]];
    }

    /**
     * Reasignacion manual ANTES de confirmar: mismos totales que la propuesta (nunca crea ni destruye dinero).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function reasignar(array $obl, array $cre, array $atribucion, int $efectivoAsignable): array
    {
        $porId = [];

        foreach ($atribucion as $a) {
            $id = (int) ($a['movimiento_id'] ?? 0);
            $porId[$id] = [$this->centavos($a['efectivo'] ?? 0), $this->centavos($a['compensado'] ?? 0)];
        }

        $totalPropuestoComp = array_sum(array_column($obl, 'comp'));
        $lado = array_sum(array_map(fn ($o) => $o['restante'] - $o['comp'], $obl)) > 0 ? 'obl' : 'cre';
        $conocidos = array_column(array_merge($obl, $cre), 'id');

        if (array_diff(array_keys($porId), $conocidos) !== []) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'atribucion_movimiento_desconocido']);
        }

        $aplicar = function (array $grupo) use ($porId): array {
            foreach ($grupo as $i => $h) {
                [$efe, $comp] = $porId[$h['id']] ?? [0, 0];

                if ($efe < 0 || $comp < 0 || $efe + $comp > $h['restante']) {
                    throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'atribucion_excede_restante']);
                }

                $grupo[$i]['efe'] = $efe;
                $grupo[$i]['comp'] = $comp;
            }

            return $grupo;
        };
        $obl = $aplicar($obl);
        $cre = $aplicar($cre);

        $efeTotal = array_sum(array_column($lado === 'obl' ? $obl : $cre, 'efe'));
        $efeOtroLado = array_sum(array_column($lado === 'obl' ? $cre : $obl, 'efe'));

        if ($efeTotal !== $efectivoAsignable || $efeOtroLado !== 0
            || array_sum(array_column($obl, 'comp')) !== $totalPropuestoComp || array_sum(array_column($cre, 'comp')) !== $totalPropuestoComp) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'atribucion_no_conserva_totales']);
        }

        return [$obl, $cre];
    }

    private function centavos(mixed $v): int
    {
        $t = trim((string) $v);

        if ($t === '') {
            return 0;
        }

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $t)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['motivo' => 'atribucion_valor_invalido']);
        }

        return (int) round((float) $t * 100);
    }
}
