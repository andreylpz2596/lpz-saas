<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Support\Cartera\AlcanceDePagos;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Lectura de la BANDEJA CENTRAL de pagos (PAG-E). Solo lectura y acotada al alcance del actor (AlcanceDePagos, la misma
 * regla que usa la revision). Los KPI y los filtros `?resumen=` usan el MISMO criterio (resumen()): cifra y tabla nunca
 * divergen. Agrupa por lote (sin consulta por pago): una para la pagina de pagos y otra para sus aplicaciones.
 */
class BandejaPagosService
{
    public const POR_PAGINA = 20;

    public const RESUMEN_SIN_REVISAR = 'sin_revisar';

    public const RESUMEN_CONFIRMADOS_HOY = 'confirmados_hoy';

    public const RESUMEN_RECHAZADOS = 'rechazados';

    public const RESUMENES = [self::RESUMEN_SIN_REVISAR, self::RESUMEN_CONFIRMADOS_HOY, self::RESUMEN_RECHAZADOS];

    public function __construct(
        protected AlcanceDePagos $alcance,
        protected CarteraConsultaService $consulta,
        protected MediosCobro $medios,
    ) {}

    /**
     * @param  array{q?: ?string, estado?: ?string, medio?: ?string, desde?: ?string, hasta?: ?string, registrador?: ?string, resumen?: ?string}  $filtros
     */
    public function listar(AdminContract $actor, array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $consulta = $this->base($actor)
            ->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('admins as ad', 'ad.id', '=', 'p.registrado_por_admin_id')
            ->select(['p.id', 'p.customer_id', 'p.monto', 'p.medio_cobro', 'p.plataforma', 'p.grupo_pago', 'p.fecha_pago', 'p.referencia', 'p.estado', 'p.created_at', 'p.revisado_en',
                'c.first_name', 'c.last_name', 'c.phone', 'ad.name as registrado_por']);

        $this->aplicarFiltros($consulta, $actor, $filtros);

        $paginador = $consulta->orderByDesc('p.created_at')->orderByDesc('p.id')->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));

        $ids = $paginador->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $aplicaciones = $this->aplicacionesDe($ids);

        $paginador->setCollection($paginador->getCollection()->map(function ($fila) use ($aplicaciones) {
            $fila->aplicaciones = $aplicaciones[(int) $fila->id] ?? [];
            $fila->medio_etiqueta = $this->etiquetaMedio((string) $fila->medio_cobro, $fila->plataforma);

            return $fila;
        }));

        return $paginador;
    }

    /**
     * KPI de la bandeja, sobre el alcance (no sobre los filtros).
     *
     * @return array{sin_revisar: int, monto_sin_revisar: float, confirmados_hoy: int, rechazados: int}
     */
    public function indicadores(AdminContract $actor): array
    {
        $sinRevisar = $this->resumen($this->base($actor), self::RESUMEN_SIN_REVISAR)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(p.monto), 0) as monto')->first();

        return [
            'sin_revisar' => (int) ($sinRevisar->cantidad ?? 0),
            'monto_sin_revisar' => (float) ($sinRevisar->monto ?? 0),
            'confirmados_hoy' => $this->resumen($this->base($actor), self::RESUMEN_CONFIRMADOS_HOY)->count(),
            'rechazados' => $this->resumen($this->base($actor), self::RESUMEN_RECHAZADOS)->count(),
        ];
    }

    /** Medios de cobro presentes (para el filtro) y administradores que registraron pagos (solo view_all). */
    public function opcionesDeFiltro(AdminContract $actor): array
    {
        $registradores = $this->alcance->alcance($actor) === AlcanceVisibilidad::TODOS
            ? $this->base($actor)->join('admins as ad', 'ad.id', '=', 'p.registrado_por_admin_id')
                ->groupBy('ad.id')->selectRaw('ad.id as id, MAX(ad.name) as nombre')->orderBy('nombre')->get()
                ->map(fn ($a) => ['id' => (int) $a->id, 'nombre' => (string) $a->nombre])->all()
            : [];

        return [
            'medios' => collect($this->medios->todos())->map(fn ($m) => ['codigo' => $m['codigo'], 'etiqueta' => $this->medios->etiqueta($m['codigo'])])->values()->all(),
            'registradores' => $registradores,
        ];
    }

    /**
     * Detalle de un pago con sus aplicaciones (saldo antes/despues segun el evento `registrado`, estado ACTUAL del pedido),
     * las Transactions enlazadas y el historial append-only. Null si no existe o esta fuera de alcance.
     *
     * @return array<string, mixed>|null
     */
    public function detalle(AdminContract $actor, int $pagoId): ?array
    {
        $pago = $this->base($actor)->where('p.id', $pagoId)
            ->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('admins as ad', 'ad.id', '=', 'p.registrado_por_admin_id')
            ->leftJoin('admins as rev', 'rev.id', '=', 'p.revisado_por_admin_id')
            ->first(['p.*', 'c.first_name', 'c.last_name', 'c.phone', 'ad.name as registrado_por', 'rev.name as revisado_por']);

        if (! $pago) {
            return null;
        }

        $eventos = DB::table('bylopez_va_pagos_eventos as e')
            ->leftJoin('admins as ad', 'ad.id', '=', 'e.actor_admin_id')
            ->where('e.pago_id', $pagoId)->orderBy('e.id')
            ->get(['e.id', 'e.tipo', 'e.motivo', 'e.datos', 'e.mecanismo', 'e.created_at', 'ad.name as actor'])
            ->map(function ($e) {
                $e->datos = $e->datos === null ? [] : (json_decode((string) $e->datos, true) ?: []);

                return $e;
            });

        // Saldo antes/despues por pedido: lo que dejo escrito el evento `registrado` (PAG-C: un pedido; PAG-D: distribucion).
        $registrado = $eventos->firstWhere('tipo', 'registrado')->datos ?? [];
        $saldos = [];

        if (isset($registrado['order_id'])) {
            $saldos[(int) $registrado['order_id']] = [$registrado['saldo_antes'] ?? null, $registrado['saldo_despues'] ?? null];
        }

        foreach ($registrado['distribucion'] ?? [] as $linea) {
            $saldos[(int) $linea['order_id']] = [$linea['saldo_antes'] ?? null, $linea['saldo_despues'] ?? null];
        }

        $aplicaciones = DB::table('bylopez_va_aplicaciones_pago as a')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->leftJoin('order_transactions as t', 't.id', '=', 'a.order_transaction_id')
            ->where('a.pago_id', $pagoId)->orderBy('a.order_id')
            ->get(['a.id', 'a.order_id', 'a.monto', 'a.anulada_en', 'a.order_transaction_id', 'a.invoice_id', 'o.increment_id', 'o.status', 'o.base_grand_total', 'o.created_at as pedido_creado', 't.transaction_id']);

        $actuales = $this->consulta->saldosDePedidos($aplicaciones->pluck('order_id')->all());

        $aplicaciones = $aplicaciones->map(function ($a) use ($saldos, $actuales) {
            $a->saldo_antes = $saldos[(int) $a->order_id][0] ?? null;
            $a->saldo_despues = $saldos[(int) $a->order_id][1] ?? null;
            $a->cartera = $actuales[(int) $a->order_id] ?? null;

            return $a;
        });

        return [
            'pago' => $pago,
            'medio_etiqueta' => $this->etiquetaMedio((string) $pago->medio_cobro, $pago->plataforma),
            'aplicaciones' => $aplicaciones,
            'eventos' => $eventos,
        ];
    }

    /** Consulta base acotada al alcance del actor. */
    private function base(AdminContract $actor): Builder
    {
        return $this->alcance->restringir(DB::table('bylopez_va_pagos as p'), $actor);
    }

    /**
     * Criterio UNICO de cada tarjeta KPI y de su filtro `?resumen=`.
     *  - sin_revisar: pagos en estado `sin_revisar`.
     *  - confirmados_hoy: pagos `confirmado` cuya revision fue desde las 00:00.
     *  - rechazados: pagos `rechazado`.
     */
    private function resumen(Builder $consulta, string $resumen): Builder
    {
        return match ($resumen) {
            self::RESUMEN_SIN_REVISAR => $consulta->where('p.estado', EstadoPago::SIN_REVISAR->value),
            self::RESUMEN_CONFIRMADOS_HOY => $consulta->where('p.estado', EstadoPago::CONFIRMADO->value)->where('p.revisado_en', '>=', now()->startOfDay()),
            self::RESUMEN_RECHAZADOS => $consulta->where('p.estado', EstadoPago::RECHAZADO->value),
            default => $consulta,
        };
    }

    private function aplicarFiltros(Builder $consulta, AdminContract $actor, array $filtros): void
    {
        if (in_array($filtros['resumen'] ?? null, self::RESUMENES, true)) {
            $this->resumen($consulta, $filtros['resumen']);
        }

        if (($filtros['q'] ?? null) !== null) {
            $texto = '%'.addcslashes($filtros['q'], '%_\\').'%';
            $digitos = preg_replace('/\D+/', '', $filtros['q']);
            $referencia = MediosCobro::normalizarReferencia($filtros['q']);

            $consulta->where(function ($w) use ($texto, $digitos, $referencia) {
                $w->whereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) LIKE ?", [$texto])
                    ->orWhere('p.referencia', 'like', $texto);

                if ($referencia !== null) {
                    $w->orWhere('p.referencia_normalizada', 'like', '%'.$referencia.'%');
                }

                if (strlen($digitos) >= 4) {
                    $w->orWhere('c.phone', 'like', '%'.$digitos.'%');
                }
            });
        }

        if (in_array($filtros['estado'] ?? null, EstadoPago::valores(), true)) {
            $consulta->where('p.estado', $filtros['estado']);
        }

        if (($filtros['medio'] ?? null) !== null) {
            $consulta->where('p.medio_cobro', $filtros['medio']);
        }

        if (($filtros['desde'] ?? null) !== null) {
            $consulta->where('p.created_at', '>=', $filtros['desde'].' 00:00:00');
        }

        if (($filtros['hasta'] ?? null) !== null) {
            $consulta->where('p.created_at', '<=', $filtros['hasta'].' 23:59:59');
        }

        // El filtro por quien registro solo existe con view_all (con view_own se ignora, nunca amplia el alcance).
        if (($filtros['registrador'] ?? null) !== null && $this->alcance->alcance($actor) === AlcanceVisibilidad::TODOS) {
            $consulta->where('p.registrado_por_admin_id', (int) $filtros['registrador']);
        }
    }

    /**
     * @param  list<int>  $pagoIds
     * @return array<int, list<array{order_id: int, increment_id: string, monto: float, anulada: bool}>>
     */
    private function aplicacionesDe(array $pagoIds): array
    {
        if ($pagoIds === []) {
            return [];
        }

        $mapa = [];

        foreach (DB::table('bylopez_va_aplicaciones_pago as a')->join('orders as o', 'o.id', '=', 'a.order_id')
            ->whereIn('a.pago_id', $pagoIds)->orderBy('a.order_id')
            ->get(['a.pago_id', 'a.order_id', 'o.increment_id', 'a.monto', 'a.anulada_en']) as $f) {
            $mapa[(int) $f->pago_id][] = [
                'order_id' => (int) $f->order_id,
                'increment_id' => (string) $f->increment_id,
                'monto' => (float) $f->monto,
                'anulada' => $f->anulada_en !== null,
            ];
        }

        return $mapa;
    }

    /** «Transferencia · Nequi»: la plataforma solo existe en transferencias nuevas; las historicas no la tienen. */
    private function etiquetaMedio(string $codigo, ?string $plataforma = null): string
    {
        $etiqueta = $this->medios->existe($codigo) ? $this->medios->etiqueta($codigo) : $codigo;
        $plataforma = $this->medios->etiquetaPlataforma($plataforma);

        return $plataforma === null ? $etiqueta : $etiqueta.' · '.$plataforma;
    }
}
