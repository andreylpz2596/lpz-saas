<?php

namespace ByLopez\VentasAsesor\Services\Comisiones;

use Brick\Math\BigDecimal;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Models\ComisionMes;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Collection;

/**
 * R3 — UMBRAL MENSUAL por asesor (aprobado): las primeras N unidades del mes no comisionan; desde la N+1 si. No es
 * retroactivo, se reinicia cada mes (America/Bogota, instante real de confirmacion) y N sale del acuerdo del asesor.
 *
 * DISTRIBUCION DETERMINISTA (derivada, nunca un contador mutable): las lineas vivas del asesor y mes se ordenan por
 * (confirmado_en, order_id, order_item_id); el acumulado se recorre POR PEDIDO con las unidades COMPUTABLES (libro: venta −
 * canceladas − no entregadas). Ejemplo: acumulado 950, pedido de 100 con umbral 1.000 → 50 completan el umbral y 50 comisionan.
 * Dos pedidos simultaneos no consumen el mismo tramo: el orden es estable y la habilitacion se serializa con el cerrojo del
 * mes (ComisionMes FOR UPDATE).
 *
 * PROPUESTAS NO APROBADAS (se calculan y se muestran, pero la linea afectada NO se habilita hasta aprobar la politica):
 *  - pedido MIXTO que cruza el umbral con lineas de distinta base: reparto PROPORCIONAL a las unidades computables (resto
 *    mayor; empate por order_item_id). Si todas las lineas tienen la misma base el reparto no cambia el monto y no se marca.
 *  - umbral que CAMBIA dentro del mes: se previsualiza con el umbral vigente al inicio del mes (o el primero del mes).
 *  - cancelacion o devolucion que DESPLAZA el tramo de pedidos posteriores ya habilitados: la linea congelada se marca.
 */
class UmbralMensualService
{
    public const POLITICA_PEDIDO_MIXTO = 'politica_pendiente_pedido_mixto';

    public const POLITICA_UMBRAL_CAMBIO_MES = 'politica_pendiente_umbral_cambio_mes';

    public const POLITICA_DESPLAZAMIENTO = 'politica_pendiente_desplazamiento';

    public const SIN_ACUERDO = 'sin_acuerdo';

    public const UMBRAL_SIN_DEFINIR = 'umbral_sin_definir';

    public function __construct(
        protected AcuerdosComisionService $acuerdos,
        protected LineasComisionService $lineas,
    ) {}

    /**
     * @return array{asesor_id: int, mes: string, umbral: ?int, acuerdo_umbral_id: ?int, estado_umbral: string, acumulado: int, faltante: ?int, comisionables: int, monto_estimado: string, lineas: array<int, array<string, mixed>>}
     */
    public function distribucion(int $asesorId, string $mes): array
    {
        [$umbral, $acuerdoId, $estado] = $this->umbralDelMes($asesorId, $mes);

        /** @var Collection<int, ComisionLinea> $lineas */
        $lineas = ComisionLinea::query()->where('asesor_admin_id', $asesorId)->where('mes_comercial', $mes)
            ->where('estado', '!=', ComisionLinea::ANULADA)
            ->orderBy('confirmado_en')->orderBy('order_id')->orderBy('order_item_id')->get();

        $computables = $this->lineas->computables($lineas->pluck('id')->all());
        $acumulado = 0;
        $comisionablesMes = 0;
        $montoMes = BigDecimal::zero();
        $salida = [];

        foreach ($lineas->groupBy('order_id', true) as $delPedido) {
            $unidades = $delPedido->sum(fn (ComisionLinea $l) => $computables[$l->id] ?? 0);
            $libres = $umbral === null ? null : max(0, $umbral - $acumulado);
            $comisionablesPedido = $libres === null ? 0 : max(0, $unidades - $libres);
            $acumuladoPrevio = $acumulado;
            $acumulado += $unidades;

            $reparto = $this->repartir($delPedido, $computables, $comisionablesPedido);
            $cruza = $comisionablesPedido > 0 && $comisionablesPedido < $unidades && $delPedido->count() > 1;
            $basesDistintas = $delPedido->filter(fn (ComisionLinea $l) => ($computables[$l->id] ?? 0) > 0)
                ->map(fn (ComisionLinea $l) => (string) $l->base_unitaria)->unique()->count() > 1;

            foreach ($delPedido as $l) {
                $c = $umbral === null ? null : $reparto[$l->id];
                $monto = $this->monto($l, $c);
                $marcas = [];

                if ($estado !== 'definido') {
                    $marcas[] = $estado;
                }

                if ($cruza && $basesDistintas) {
                    $marcas[] = self::POLITICA_PEDIDO_MIXTO;
                }

                // D5 (aprobada): una linea habilitada que AUN NO esta en una liquidacion se recalcula sola (con evento y traza). Solo la que
                // ya esta en una liquidacion conserva lo congelado y se trata por D6 (incidencia + ajuste por el exceso pagado).
                if ($l->estado === ComisionLinea::HABILITADA && $l->liquidacion_id !== null && $c !== null && $l->unidades_comisionables !== $c) {
                    $marcas[] = self::POLITICA_DESPLAZAMIENTO;
                }

                $salida[$l->id] = [
                    'computables' => $computables[$l->id] ?? 0,
                    'comisionables' => $c,
                    'monto_estimado' => Dinero::texto($monto),
                    'cruza_umbral' => $cruza,
                    // Traza del calculo (D5): lo que se uso para decidir cuantas unidades comisionan.
                    'umbral' => $umbral,
                    'acumulado_previo' => $acumuladoPrevio,
                    'unidades_pedido' => $unidades,
                    'marcas' => $marcas,
                ];

                $comisionablesMes += (int) $c;
                $montoMes = $montoMes->plus($monto ?? BigDecimal::zero());
            }
        }

        return [
            'asesor_id' => $asesorId,
            'mes' => $mes,
            'umbral' => $umbral,
            'acuerdo_umbral_id' => $acuerdoId,
            'estado_umbral' => $estado,
            'acumulado' => $acumulado,
            'faltante' => $umbral === null ? null : max(0, $umbral - $acumulado),
            'comisionables' => $comisionablesMes,
            'monto_estimado' => Dinero::texto($montoMes),
            'lineas' => $salida,
        ];
    }

    /**
     * Umbral que rige el mes: `definido` si un solo valor rige todo el tramo con acuerdo; si no, la marca que lo impide.
     * Si el mes ya fijo su umbral al habilitar la primera linea (ComisionMes), se respeta ese valor.
     *
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    public function umbralDelMes(int $asesorId, string $mes): array
    {
        $fijado = ComisionMes::query()->where('asesor_admin_id', $asesorId)->where('mes', $mes)->first();
        [$inicio, $fin] = MesComercial::rango($mes);
        $acuerdos = $this->acuerdos->vigentesEntre($asesorId, $inicio, $fin);

        if ($acuerdos->isEmpty()) {
            return [$fijado?->umbral_aplicado, $fijado?->acuerdo_umbral_id, $fijado?->umbral_aplicado === null ? self::SIN_ACUERDO : 'definido'];
        }

        $referencia = $acuerdos->first(fn (ComisionAcuerdo $a) => $a->vigenteEn($inicio)) ?? $acuerdos->first();
        $valores = $acuerdos->map(fn (ComisionAcuerdo $a) => $a->umbral_mensual_unidades)->unique();
        $cubreTodoElMes = $acuerdos->first(fn (ComisionAcuerdo $a) => $a->vigenteEn($inicio)) !== null;

        $estado = match (true) {
            $valores->contains(null) => self::UMBRAL_SIN_DEFINIR,
            $valores->count() > 1 || ! $cubreTodoElMes => self::POLITICA_UMBRAL_CAMBIO_MES,
            default => 'definido',
        };

        if ($fijado?->umbral_aplicado !== null) {
            return [$fijado->umbral_aplicado, $fijado->acuerdo_umbral_id, $estado === self::UMBRAL_SIN_DEFINIR ? $estado : ($fijado->umbral_aplicado === $referencia->umbral_mensual_unidades ? $estado : self::POLITICA_UMBRAL_CAMBIO_MES)];
        }

        return [$referencia->umbral_mensual_unidades, (int) $referencia->id, $estado];
    }

    /** Fila de control del mes, BLOQUEADA (FOR UPDATE). Llamar dentro de una transaccion. */
    public function bloquearMes(int $asesorId, string $mes): ComisionMes
    {
        ComisionMes::query()->insertOrIgnore(['asesor_admin_id' => $asesorId, 'mes' => $mes, 'created_at' => now(), 'updated_at' => now()]);

        return ComisionMes::query()->where('asesor_admin_id', $asesorId)->where('mes', $mes)->lockForUpdate()->firstOrFail();
    }

    /**
     * Reparto de las unidades comisionables del pedido entre sus lineas: proporcional a las computables, resto mayor y
     * desempate por order_item_id (PROPUESTA). Con una sola linea, o sin cruce, es exacto.
     *
     * @return array<int, int>
     */
    protected function repartir(Collection $lineas, array $computables, int $comisionables): array
    {
        $total = $lineas->sum(fn (ComisionLinea $l) => $computables[$l->id] ?? 0);
        $reparto = [];

        if ($total === 0 || $comisionables === 0) {
            return $lineas->mapWithKeys(fn (ComisionLinea $l) => [$l->id => 0])->all();
        }

        $restos = [];

        foreach ($lineas as $l) {
            $u = $computables[$l->id] ?? 0;
            $exacto = $u * $comisionables;
            $reparto[$l->id] = intdiv($exacto, $total);
            $restos[] = ['id' => $l->id, 'item' => $l->order_item_id, 'resto' => $exacto % $total];
        }

        $faltan = $comisionables - array_sum($reparto);
        usort($restos, fn ($a, $b) => [$b['resto'], $a['item']] <=> [$a['resto'], $b['item']]);

        foreach (array_slice($restos, 0, $faltan) as $r) {
            $reparto[$r['id']]++;
        }

        return $reparto;
    }

    /** Monto = unidades comisionables × base (P − C) × %; HALF_UP a 2. Null si falta base o porcentaje. */
    public function monto(ComisionLinea $l, ?int $comisionables): ?BigDecimal
    {
        if ($comisionables === null || $l->base_unitaria === null || $l->porcentaje === null) {
            return null;
        }

        return Dinero::importe(Dinero::de($l->base_unitaria)->multipliedBy($comisionables)->multipliedBy(Dinero::fraccion($l->porcentaje)));
    }
}
