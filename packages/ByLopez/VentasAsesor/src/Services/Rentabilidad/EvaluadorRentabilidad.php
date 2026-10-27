<?php

namespace ByLopez\VentasAsesor\Services\Rentabilidad;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use ByLopez\VentasAsesor\Models\CostoDirecto;
use ByLopez\VentasAsesor\Models\RentabilidadPolitica;
use ByLopez\VentasAsesor\Support\Comisiones\Dinero;
use Illuminate\Support\Collection;

/**
 * R1 — EVALUADOR CENTRAL de rentabilidad (Doc. 22 §3.4, opcion A aprobada para la comision). Funcion pura: no lee la base
 * de datos ni guarda nada; lo usan el borrador (advertencias), la confirmacion (bloqueo), el snapshot del pedido y el
 * diagnostico de listas. Sin `float`: todo con BigDecimal.
 *
 * Notacion por unidad (moneda base, SIN impuestos; P es `base_price` − descuento unitario, que en Bagisto no incluye IVA):
 *   P  precio neto de la mercancia       C  costo estandar congelado / vigente        r  tasa del asesor (fraccion)
 *   V  costos directos por unidad        f  cargos % cuya base REAL es P              G  ganancia minima por unidad
 *
 *   Comision      = r × max(0, P − C)                                (base A: P − C; los costos directos NO la reducen)
 *   Contribucion  = P − C − Comision − V − f·P
 *   Requerido     = max(G, m·P)      (m = margen minimo opcional)
 *   Cumple        ⇔ Contribucion ≥ Requerido                         (por LINEA: una referencia rentable no tapa otra)
 *   Precio minimo = [G + V + (1 − r)·C] ÷ (1 − r − f)                 (solo si 1 − r − f > 0; si no, «fuera de dominio»)
 *                 y con m: [V + (1 − r)·C] ÷ (1 − r − f − m); se toma el mayor y se redondea HACIA ARRIBA al escalon.
 *
 * Costos del PEDIDO (no son por unidad): fijos por pedido y % sobre otra base (total cobrado, valor del envio) forman F; el
 * pedido ademas exige Σ (Contribucion − Requerido) × cantidad ≥ F (holgura). Bloquear por esto es la decision P5 (pendiente).
 *
 * Tasa r: la del ACUERDO del asesor vigente al confirmar (tasa contractual, sin descontar el umbral mensual: evaluacion
 * CONSERVADORA, documentada para aprobacion); 0 en venta de empresa; la tasa de referencia (mayor % vigente) en venta sin
 * asesor. Sin tasa conocida la linea queda «incompleta»: la falta de datos nunca se convierte en aprobacion.
 *
 * Tramos y dominio (Doc. 23 §10.4): con P ≤ C la comision es 0 y la contribucion P − C − V − f·P es ≤ −V − f·P ≤ 0 < G
 * (G, V, f, C ≥ 0 por restriccion de la base), asi que ese tramo nunca cumple con G > 0 y el minimo siempre cae en P ≥ C:
 * P_min − C = (G + V + f·C) ÷ (1 − r − f) ≥ 0. La formula derivada para utilidad positiva es por eso valida en todo el
 * dominio. El cumplimiento se decide con valores EXACTOS (sin redondeos intermedios) y el minimo se redondea SIEMPRE hacia
 * arriba: vender exactamente al minimo publicado cumple.
 *
 * Estado del costo (cada uno con su significado): `sin_costo` → incompleta (nunca 0); `sin_estado` (nadie confirmo el
 * costo) y `vencido` (provisional con revision vencida) → incompleta, aunque se muestren las cifras; `provisional` vigente
 * → se evalua y se advierte; `confirmado` → se evalua.
 *
 * Politica: por referencia (familia) + tipo de tela; gana la mas especifica: familia+tela › familia › tela › defecto.
 */
class EvaluadorRentabilidad
{
    public const CUMPLE = 'cumple';

    public const INCUMPLE = 'incumple';

    public const INCOMPLETA = 'incompleta';

    public const SIN_POLITICA = 'sin_politica';

    public const NO_APLICA = 'no_aplica';

    /** Estados del costo que impiden decidir (la cifra existe pero nadie la respalda hoy). */
    public const COSTO_SIN_RESPALDO = ['sin_estado' => 'costo_sin_estado', 'vencido' => 'costo_vencido'];

    /** Clave de politica: «familia|tela», 0 = cualquiera. */
    public static function clavePolitica(?int $familia, ?int $tela): string
    {
        return (int) $familia.'|'.(int) $tela;
    }

    /** @param  array<string, RentabilidadPolitica>  $politicas */
    public static function politicaPara(array $politicas, ?int $familia, ?int $tela): ?RentabilidadPolitica
    {
        foreach ([[$familia, $tela], [$familia, null], [null, $tela], [null, null]] as [$f, $t]) {
            if ($p = $politicas[self::clavePolitica($f, $t)] ?? null) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param  list<array{clave: int|string, familia_id: ?int, tipo_tela_id?: ?int, referencia: ?string, sku: ?string, cantidad: int, precio_neto: string, costo: ?string, estado_costo: string}>  $lineas
     * @param  array<string, RentabilidadPolitica>  $politicas  por «familia|tela» (0 = cualquiera; «0|0» = defecto)
     * @param  Collection<int, CostoDirecto>  $costos
     * @param  array{tasa: ?string, origen_tasa: string, metodo_envio: ?string, metodo_pago: ?string, total_cobrado: string, valor_envio: string, aplica: bool}  $ctx
     * @return array{resultado: string, lineas: list<array<string, mixed>>, costo_pedido: string, holgura: ?string, motivos: list<string>, costos_aplicados: list<array<string, mixed>>, referencias_incumplen: list<string>}
     */
    public function evaluar(array $lineas, array $politicas, Collection $costos, array $ctx): array
    {
        if (! $ctx['aplica']) {
            return $this->noAplica($lineas);
        }

        $motivos = [];
        $tasa = Dinero::o($ctx['tasa']);
        $r = $tasa === null ? null : Dinero::fraccion($tasa);

        if ($r === null) {
            $motivos[] = 'sin_tasa_'.$ctx['origen_tasa'];
        }

        $aplicables = $costos->filter(fn (CostoDirecto $c) => $this->aplicaAlContexto($c, $ctx))->values();
        $familiasDelPedido = array_values(array_unique(array_map(fn ($l) => (int) ($l['familia_id'] ?? 0), $lineas)));

        // Costos del pedido: fijos y % sobre una base distinta del precio de la mercancia.
        $F = BigDecimal::zero();
        $aplicados = [];

        foreach ($aplicables as $c) {
            if ($c->familia_id !== null && ! in_array((int) $c->familia_id, $familiasDelPedido, true)) {
                continue;
            }

            $monto = match (true) {
                $c->tipo_calculo === 'por_pedido' => Dinero::de($c->valor),
                $c->tipo_calculo === 'porcentaje' && $c->base_porcentaje === 'total_cobrado' => Dinero::de($ctx['total_cobrado'])->multipliedBy(Dinero::fraccion($c->valor)),
                $c->tipo_calculo === 'porcentaje' && $c->base_porcentaje === 'valor_envio' => Dinero::de($ctx['valor_envio'])->multipliedBy(Dinero::fraccion($c->valor)),
                default => null,
            };

            if ($monto !== null) {
                $F = $F->plus($monto);
                $aplicados[] = ['id' => $c->id, 'codigo' => $c->codigo, 'version' => $c->version, 'nivel' => 'pedido', 'monto' => Dinero::texto($monto)];
            }
        }

        $salida = [];
        $holgura = BigDecimal::zero();
        $holguraConocida = true;
        $incumplen = [];

        foreach ($lineas as $l) {
            $fila = $this->linea($l, $politicas, $aplicables, $r, $aplicados);
            $salida[] = $fila;

            if ($fila['resultado'] === self::INCUMPLE && ($l['producto'] ?? $l['referencia']) !== null) {
                $incumplen[] = (string) ($l['producto'] ?? $l['referencia']);
            }

            if ($fila['holgura_total'] === null) {
                $holguraConocida = false;
            } else {
                $holgura = $holgura->plus(Dinero::de($fila['holgura_total']));
            }
        }

        $holguraPedido = $holguraConocida ? $holgura->minus($F) : null;

        if ($holguraPedido !== null && $holguraPedido->isNegative()) {
            $motivos[] = 'costos_del_pedido_sin_cubrir';
        }

        $resultados = array_column($salida, 'resultado');
        $resultado = match (true) {
            in_array(self::INCUMPLE, $resultados, true) => self::INCUMPLE,
            in_array(self::INCOMPLETA, $resultados, true) => self::INCOMPLETA,
            in_array(self::SIN_POLITICA, $resultados, true) => self::SIN_POLITICA,
            $holguraPedido !== null && $holguraPedido->isNegative() && (bool) config('ventasasesor.rentabilidad.bloquear_costos_pedido') => self::INCUMPLE,
            default => self::CUMPLE,
        };

        return [
            'resultado' => $resultado,
            'lineas' => $salida,
            'costo_pedido' => Dinero::texto($F),
            'holgura' => Dinero::texto($holguraPedido),
            'motivos' => array_values(array_unique($motivos)),
            'costos_aplicados' => $this->unicos($aplicados),
            'referencias_incumplen' => array_values(array_unique($incumplen)),
        ];
    }

    /** @param  list<array<string, mixed>>  $aplicados */
    protected function linea(array $l, array $politicas, Collection $aplicables, ?BigDecimal $r, array &$aplicados): array
    {
        $familia = (int) ($l['familia_id'] ?? 0);
        $tela = isset($l['tipo_tela_id']) ? (int) $l['tipo_tela_id'] : null;
        $politica = self::politicaPara($politicas, $familia ?: null, $tela ?: null);
        $P = Dinero::de($l['precio_neto']);
        $C = Dinero::o($l['costo']);
        $q = (int) $l['cantidad'];

        $V = BigDecimal::zero();
        $f = BigDecimal::zero();

        foreach ($aplicables as $c) {
            if ($c->familia_id !== null && (int) $c->familia_id !== $familia) {
                continue;
            }

            if ($c->tipo_calculo === 'por_unidad') {
                $V = $V->plus(Dinero::de($c->valor));
                $aplicados[] = ['id' => $c->id, 'codigo' => $c->codigo, 'version' => $c->version, 'nivel' => 'unidad'];
            } elseif ($c->tipo_calculo === 'porcentaje' && $c->base_porcentaje === 'precio_neto_mercancia') {
                $f = $f->plus(Dinero::fraccion($c->valor));
                $aplicados[] = ['id' => $c->id, 'codigo' => $c->codigo, 'version' => $c->version, 'nivel' => 'unidad'];
            }
        }

        $base = [
            'clave' => $l['clave'],
            'familia_id' => $l['familia_id'],
            'tipo_tela_id' => $tela ?: null,
            'referencia' => $l['referencia'],
            'producto' => $l['producto'] ?? null,
            'sku' => $l['sku'],
            'cantidad' => $q,
            'precio_neto' => Dinero::texto($P, 4),
            'costo' => Dinero::texto($C, 4),
            'estado_costo' => $l['estado_costo'],
            'politica_id' => $politica?->id,
            'variable' => Dinero::texto($V, 4),
            'cargo_pct' => (string) $f->multipliedBy(100)->toScale(4),
            'comision' => null,
            'contribucion' => null,
            'requerido' => null,
            'precio_minimo' => null,
            'faltante' => null,
            'holgura_total' => null,
            'dominio_valido' => null,
        ];

        if ($C === null) {
            return ['resultado' => self::INCOMPLETA, 'motivo' => 'sin_costo'] + $base;
        }

        // Valores EXACTOS (BigDecimal sin escala fija): el redondeo solo es de presentacion.
        $utilidad = $P->minus($C);
        $comision = $r === null ? null : Dinero::maximo($utilidad, BigDecimal::zero())->multipliedBy($r);
        $contribucion = $comision === null ? null : $utilidad->minus($comision)->minus($V)->minus($P->multipliedBy($f));

        $base['comision'] = Dinero::texto($comision, 4);
        $base['contribucion'] = Dinero::texto($contribucion, 4);

        if ($politica === null) {
            return ['resultado' => self::SIN_POLITICA, 'motivo' => 'sin_politica'] + $base;
        }

        if ($r === null) {
            return ['resultado' => self::INCOMPLETA, 'motivo' => 'sin_tasa'] + $base;
        }

        $G = Dinero::de($politica->ganancia_minima_unidad);
        $m = Dinero::o($politica->margen_minimo_pct);
        $mf = $m === null ? null : Dinero::fraccion($m);
        $requerido = $mf === null ? $G : Dinero::maximo($G, $P->multipliedBy($mf));

        [$minimo, $dominio] = $this->precioMinimo($G, $V, $C, $r, $f, $mf, $politica->redondeo_escalon);
        $cumple = $contribucion->isGreaterThanOrEqualTo($requerido);
        $diferencia = $contribucion->minus($requerido);
        $sinRespaldo = self::COSTO_SIN_RESPALDO[$l['estado_costo']] ?? null;

        return [
            // Un costo sin respaldo (sin estado o vencido) no permite decidir: las cifras se muestran, el resultado no aprueba.
            'resultado' => $sinRespaldo !== null ? self::INCOMPLETA : ($dominio && $cumple ? self::CUMPLE : self::INCUMPLE),
            'motivo' => $sinRespaldo ?? ($dominio ? ($cumple ? null : 'bajo_minimo') : 'fuera_de_dominio'),
            'requerido' => Dinero::texto($requerido, 4),
            'precio_minimo' => Dinero::texto($minimo),
            'faltante' => $cumple ? null : Dinero::texto($diferencia->negated()),
            'holgura_total' => Dinero::texto($diferencia->multipliedBy($q)),
            'dominio_valido' => $dominio,
        ] + $base;
    }

    /** @return array{0: ?BigDecimal, 1: bool} [precio minimo redondeado, dominio valido] */
    public function precioMinimo(BigDecimal $G, BigDecimal $V, BigDecimal $C, BigDecimal $r, BigDecimal $f, ?BigDecimal $m, ?int $escalon): array
    {
        $uno = BigDecimal::one();
        $den = $uno->minus($r)->minus($f);

        if (! $den->isPositive()) {
            return [null, false];
        }

        // Division redondeada HACIA ARRIBA (nunca HALF_UP): un minimo redondeado hacia abajo no cumpliria su propia regla.
        $num = $G->plus($V)->plus($uno->minus($r)->multipliedBy($C));
        $minimo = $num->dividedBy($den, 8, RoundingMode::CEILING);

        if ($m !== null) {
            $den2 = $den->minus($m);

            if (! $den2->isPositive()) {
                return [null, false];
            }

            $minimo = Dinero::maximo($minimo, $V->plus($uno->minus($r)->multipliedBy($C))->dividedBy($den2, 8, RoundingMode::CEILING));
        }

        return [Dinero::redondearArriba($minimo, $escalon), true];
    }

    /** Filtros de metodo de envio (prefijo del carrier, p. ej. `flatrate` cubre `flatrate_flatrate`) y de pago. */
    protected function aplicaAlContexto(CostoDirecto $c, array $ctx): bool
    {
        if ($c->metodo_envio !== null && ! str_starts_with((string) ($ctx['metodo_envio'] ?? ''), $c->metodo_envio)) {
            return false;
        }

        return $c->metodo_pago === null || $c->metodo_pago === ($ctx['metodo_pago'] ?? null);
    }

    protected function noAplica(array $lineas): array
    {
        return [
            'resultado' => self::NO_APLICA,
            'lineas' => array_map(fn ($l) => [
                'clave' => $l['clave'], 'familia_id' => $l['familia_id'], 'referencia' => $l['referencia'], 'sku' => $l['sku'],
                'cantidad' => (int) $l['cantidad'], 'precio_neto' => Dinero::texto(Dinero::de($l['precio_neto']), 4),
                'costo' => Dinero::texto(Dinero::o($l['costo']), 4), 'estado_costo' => $l['estado_costo'], 'politica_id' => null,
                'variable' => '0.0000', 'cargo_pct' => '0.0000', 'comision' => null, 'contribucion' => null, 'requerido' => null,
                'precio_minimo' => null, 'faltante' => null, 'holgura_total' => null, 'dominio_valido' => null,
                'resultado' => self::NO_APLICA, 'motivo' => 'operacion_no_comercial',
            ], $lineas),
            'costo_pedido' => '0.00',
            'holgura' => null,
            'motivos' => ['operacion_no_comercial'],
            'costos_aplicados' => [],
            'referencias_incumplen' => [],
        ];
    }

    /** @param  list<array<string, mixed>>  $aplicados */
    private function unicos(array $aplicados): array
    {
        $vistos = [];

        foreach ($aplicados as $a) {
            $clave = $a['id'].'|'.$a['nivel'];
            $vistos[$clave] ??= $a;
        }

        return array_values($vistos);
    }
}
