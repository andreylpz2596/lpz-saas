<?php

namespace ByLopez\VentasAsesor\Services\Cupo;

use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\DataObjects\EvaluacionCupo;
use ByLopez\VentasAsesor\Exceptions\PedidoRechazadoPorCercoException as Cerco;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cupo / limite de cartera por cliente (Doc. 35). FUENTE UNICA de: cupo autorizado, exposicion vigente, disponible, utilizacion, exceso,
 * estado, detalle por pedido y «¿se puede aprobar una obligacion nueva de $X?». Controladores, Blade, JS y el cerco de creacion de
 * pedidos SOLO presentan lo que este servicio devuelve; ninguno repite la formula.
 *
 *   exposicion_pedido  = max(0, totalOperativo − verificado)      (componentes de CarteraConsultaService; ver Doc. 35 §2)
 *   exposicion_cliente = Σ exposicion_pedido de sus pedidos que estan en Cartera
 *   disponible         = max(0, autorizado − exposicion)           exceso = max(0, exposicion − autorizado)
 *
 * No hay formula de deuda paralela: se reutiliza `CarteraConsultaService::saldosDePedidos()`. La UNICA decision propia es que un pago
 * `sin_revisar` NO libera cupo (Cartera lo resta del saldo; el cupo no, porque un comprobante sin verificar no es dinero recibido).
 *
 * Solo lectura. Lo unico persistido es la politica (tabla `bylopez_va_clientes_cupo_cartera`); la exposicion se deriva cada vez.
 * El bloqueo de ventas esta en CercoCupoCartera y solo actua con `ventasasesor.cupo.activo`.
 */
class CupoCarteraClienteService
{
    public function __construct(
        protected CarteraConsultaService $cartera,
        protected FechaCorteCartera $fechaCorte,
    ) {}

    /** ¿El cupo bloquea ventas? Apagado por defecto: consultar y administrar el cupo no depende de esto. */
    public function bloqueoActivo(): bool
    {
        return (bool) config('ventasasesor.cupo.activo', false);
    }

    /** Utilizacion (%) desde la que el estado pasa a «cerca del limite». Acotado a 1..100. */
    public function umbralAlertaPct(): int
    {
        return max(1, min(100, (int) config('ventasasesor.cupo.umbral_alerta_pct', 80)));
    }

    public function delCliente(int $customerId, bool $conDetalle = false): CupoCartera
    {
        return $this->deClientes([$customerId], $conDetalle)[$customerId];
    }

    /**
     * Cupo de varios clientes por LOTE: la politica de todos en una consulta y la exposicion de todos con UNA llamada a Cartera (sin
     * consultas por cliente ni por pedido). Un cliente inexistente devuelve igualmente su cupo vacio (sin politica, exposicion 0).
     *
     * @param  list<int|string>  $customerIds
     * @return array<int, CupoCartera> indexado por customer_id
     */
    public function deClientes(array $customerIds, bool $conDetalle = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds), fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $politicas = DB::table('bylopez_va_clientes_cupo_cartera as p')
            ->leftJoin('admins as a', 'a.id', '=', 'p.actualizado_por')
            ->whereIn('p.customer_id', $ids)
            ->get(['p.customer_id', 'p.cupo_autorizado', 'p.estado', 'p.vigencia_desde', 'p.motivo', 'p.updated_at', 'a.name as actualizado_por_nombre'])
            ->keyBy(fn ($fila) => (int) $fila->customer_id);

        // Autorizacion de exceso vigente (a lo sumo una por cliente: UNIQUE en base). Una sola consulta para todo el lote.
        $excesos = DB::table('bylopez_va_clientes_cupo_cartera_excesos')
            ->whereIn('customer_id', $ids)->where('estado', 'vigente')->where('vence_en', '>', now())
            ->get(['id', 'customer_id', 'exceso_autorizado', 'vence_en', 'autorizado_nombre', 'motivo'])
            ->keyBy(fn ($fila) => (int) $fila->customer_id);

        $carteraActiva = $this->fechaCorte->activa();
        $exposiciones = $carteraActiva ? $this->exposiciones($ids, $conDetalle) : [];
        $salida = [];

        foreach ($ids as $id) {
            $politica = $politicas->get($id);
            $e = $exposiciones[$id] ?? null;
            $autorizado = $politica === null ? 0.0 : round((float) $politica->cupo_autorizado, 2);
            $estadoPolitica = $politica?->estado;
            $exposicion = $e === null ? 0.0 : round($e['exposicion'], 2);

            [$estado, $disponible, $exceso, $utilizacion] = $this->derivar($politica !== null, $estadoPolitica, $autorizado, $exposicion);

            $salida[$id] = new CupoCartera(
                customerId: $id,
                tienePolitica: $politica !== null,
                autorizado: $autorizado,
                estadoPolitica: $estadoPolitica,
                carteraActiva: $carteraActiva,
                exposicion: $exposicion,
                disponible: $disponible,
                exceso: $exceso,
                utilizacionPct: $utilizacion,
                estado: $estado,
                pedidosQueConsumen: $e['consumen'] ?? 0,
                pedidosEnRevision: $e['revision'] ?? 0,
                diasPedidoMasAntiguo: ($e['mas_antiguo'] ?? null) === null ? null : $this->diasDesde($e['mas_antiguo']),
                vigenciaDesde: $politica?->vigencia_desde,
                motivo: $politica?->motivo,
                actualizadoEn: $politica?->updated_at,
                actualizadoPorNombre: $politica?->actualizado_por_nombre,
                pedidos: $conDetalle ? ($e['pedidos'] ?? []) : null,
                excesoVigente: ($x = $excesos->get($id)) === null ? null : [
                    'id' => (int) $x->id, 'exceso_autorizado' => round((float) $x->exceso_autorizado, 2), 'vence_en' => $x->vence_en,
                    'autorizado_por' => $x->autorizado_nombre, 'motivo' => $x->motivo,
                ],
            );
        }

        return $salida;
    }

    /**
     * Alertas de cupo para LISTADOS (pedidos, panel): solo un cliente CON politica puede estar en alerta, asi que primero se filtran los
     * ids por una consulta barata y solo esos pasan por Cartera. Devuelve unicamente los clientes en alerta (cerca, agotado, excedido o
     * bloqueado), indexados por customer_id.
     *
     * @param  list<int|string>  $customerIds
     * @return array<int, CupoCartera>
     */
    public function alertasDeClientes(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds), fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $conPolitica = DB::table('bylopez_va_clientes_cupo_cartera')->whereIn('customer_id', $ids)->pluck('customer_id')->map(fn ($id) => (int) $id)->all();

        return array_filter(
            $this->deClientes($conPolitica),
            fn (CupoCartera $c) => in_array($c->estado, [CupoCartera::CERCA, CupoCartera::AGOTADO, CupoCartera::EXCEDIDO, CupoCartera::BLOQUEADO], true),
        );
    }

    /**
     * ¿Se puede aprobar una obligacion nueva de `$nuevaObligacion` para este cupo? Una obligacion de 0 (muestra, garantia) nunca se
     * rechaza: no hay nada que limitar. El orden de los motivos es fijo: bloqueado > no verificable > sin cupo > excedido.
     */
    public function evaluar(CupoCartera $cupo, float $nuevaObligacion): EvaluacionCupo
    {
        $nueva = $this->centavos(max(0.0, $nuevaObligacion));
        $autorizado = $this->centavos($cupo->autorizado);
        $exposicion = $this->centavos($cupo->exposicion);

        $codigo = match (true) {
            $nueva <= 0 => null,
            $cupo->bloqueado() => Cerco::CUPO_BLOQUEADO,
            ! $cupo->carteraActiva => Cerco::CUPO_NO_VERIFICABLE,
            ! $cupo->tienePolitica || $autorizado <= 0 => Cerco::CUPO_SIN_ASIGNAR,
            $exposicion + $nueva > $autorizado => Cerco::CUPO_EXCEDIDO,
            default => null,
        };

        $exceso = max(0, $exposicion + $nueva - $autorizado);
        // Informativo: una autorizacion de exceso vigente que alcance cubriria un rechazo por monto (nunca un bloqueo ni «no verificable»).
        $cubierta = in_array($codigo, [Cerco::CUPO_EXCEDIDO, Cerco::CUPO_SIN_ASIGNAR], true)
            && $cupo->excesoVigente !== null && $this->centavos((float) $cupo->excesoVigente['exceso_autorizado']) >= $exceso;

        return new EvaluacionCupo(
            permitido: $codigo === null,
            codigo: $codigo,
            nuevaObligacion: $nueva / 100,
            autorizado: $autorizado / 100,
            exposicionActual: $exposicion / 100,
            disponibleAntes: max(0, $autorizado - $exposicion) / 100,
            disponibleDespues: max(0, $autorizado - $exposicion - $nueva) / 100,
            exceso: $exceso / 100,
            cubiertaPorExceso: $cubierta,
        );
    }

    /** Atajo: cupo del cliente + decision para una obligacion nueva. */
    public function puedeAprobar(int $customerId, float $nuevaObligacion): EvaluacionCupo
    {
        return $this->evaluar($this->delCliente($customerId), $nuevaObligacion);
    }

    /**
     * Estado y cifras derivadas. Precedencia: bloqueado > excedido > agotado > cerca > activo > sin_cupo.
     *
     * @return array{0: string, 1: float, 2: float, 3: ?float} [estado, disponible, exceso, utilizacion %]
     */
    public function derivar(bool $tienePolitica, ?string $estadoPolitica, float $autorizado, float $exposicion): array
    {
        $a = $this->centavos($autorizado);
        $e = $this->centavos($exposicion);
        $disponible = max(0, $a - $e) / 100;
        $exceso = max(0, $e - $a) / 100;
        $utilizacion = $a > 0 ? round($e / $a * 100, 1) : null;

        $estado = match (true) {
            $estadoPolitica === 'bloqueado' => CupoCartera::BLOQUEADO,
            ! $tienePolitica => CupoCartera::SIN_CUPO,
            $e > $a => CupoCartera::EXCEDIDO,
            $a === 0 => CupoCartera::SIN_CUPO,
            $e === $a => CupoCartera::AGOTADO,
            $e * 100 >= $a * $this->umbralAlertaPct() => CupoCartera::CERCA,
            default => CupoCartera::ACTIVO,
        };

        return [$estado, $disponible, $exceso, $utilizacion];
    }

    /**
     * Exposicion de cada cliente: los pedidos desde la fecha de corte (superconjunto barato; Cartera decide la pertenencia exacta con
     * `enCartera`) pasan UNA vez por `saldosDePedidos()`.
     *
     * @param  list<int>  $ids
     * @return array<int, array{exposicion: float, consumen: int, revision: int, mas_antiguo: ?CarbonImmutable, pedidos: list<array<string, mixed>>}>
     */
    protected function exposiciones(array $ids, bool $conDetalle): array
    {
        $corte = $this->fechaCorte->valor();

        $pedidos = DB::table('orders')
            ->whereIn('customer_id', $ids)
            ->where('created_at', '>=', $corte->subDay()->toDateTimeString())
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'customer_id', 'created_at']);

        if ($pedidos->isEmpty()) {
            return [];
        }

        $saldos = $this->cartera->saldosDePedidos($pedidos->pluck('id')->all());
        $salida = [];

        foreach ($pedidos as $pedido) {
            $saldo = $saldos[(int) $pedido->id] ?? null;

            if ($saldo === null || ! $saldo->enCartera) {
                continue; // historico, cancelado, cerrado, fraude: no consume
            }

            // La unica decision propia: se resta lo VERIFICADO, no lo «sin revisar».
            $exposicion = round(max(0.0, (float) $saldo->totalOperativo - (float) $saldo->verificado), 2);

            if ($exposicion <= 0) {
                continue;
            }

            $cliente = (int) $pedido->customer_id;
            $creado = CarbonImmutable::parse((string) $pedido->created_at, config('app.timezone'));
            $salida[$cliente] ??= ['exposicion' => 0.0, 'consumen' => 0, 'revision' => 0, 'mas_antiguo' => null, 'pedidos' => []];
            $salida[$cliente]['exposicion'] = round($salida[$cliente]['exposicion'] + $exposicion, 2);
            $salida[$cliente]['consumen']++;
            $salida[$cliente]['revision'] += $saldo->requiereRevision ? 1 : 0;
            $salida[$cliente]['mas_antiguo'] ??= $creado; // vienen ordenados del mas antiguo al mas nuevo

            if ($conDetalle) {
                $salida[$cliente]['pedidos'][] = [
                    'order_id' => (int) $pedido->id,
                    'increment_id' => $saldo->incrementId,
                    'estado_pedido' => $saldo->estadoPedido,
                    'creado' => $creado->toDateTimeString(),
                    'dias' => $this->diasDesde($creado),
                    'total' => $saldo->total,
                    'total_operativo' => (float) $saldo->totalOperativo,
                    'verificado' => (float) $saldo->verificado,
                    'sin_revisar' => (float) $saldo->sinRevisar,
                    'exposicion' => $exposicion,
                    'requiere_revision' => $saldo->requiereRevision,
                    'motivos_revision' => $saldo->motivosRevision,
                ];
            }
        }

        return $salida;
    }

    protected function diasDesde(CarbonImmutable $fecha): int
    {
        return max(0, (int) $fecha->startOfDay()->diffInDays(CarbonImmutable::now(config('app.timezone'))->startOfDay()));
    }

    /** Dinero a centavos enteros: las comparaciones de limite no dependen de decimales binarios. */
    protected function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }
}
