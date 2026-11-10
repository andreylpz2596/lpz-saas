<?php

namespace ByLopez\VentasAsesor\Services\Asesores;

use ByLopez\VentasAsesor\DataObjects\SaldoPedido;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Pedidos\ConsultaPedidosAsesorService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;
use Webkul\User\Models\Admin;

/**
 * Lectura de «Ventas Asesor › Asesores» (ASESOR-01). Solo lectura y por lote: las metricas de una pagina de asesores salen
 * de consultas agrupadas por `advisor_id` (nunca una consulta por asesor). Cada cifra reutiliza el dominio que ya existe:
 * «ventas de hoy» con ConsultaPedidosAsesorService::soloVentasDeHoy(), la cartera con CarteraConsultaService y los pagos
 * sin revisar con las aplicaciones activas de pagos `sin_revisar` (misma base que la bandeja de PAG-E).
 *
 * Alcance: `ventas_asesor.asesores.view_all` = todos; `view_own` = solo el perfil propio (el del usuario en sesion).
 */
class ConsultaAsesoresService
{
    public const POR_PAGINA = 20;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $cartera,
        protected ConsultaPedidosAsesorService $pedidos,
        protected FechaCorteCartera $fechaCorte,
    ) {}

    public function alcance(?AdminContract $actor): AlcanceVisibilidad
    {
        return match (true) {
            $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_ASESORES_VER_TODOS) => AlcanceVisibilidad::TODOS,
            $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_ASESORES_VER_PROPIO) => AlcanceVisibilidad::PROPIOS,
            default => AlcanceVisibilidad::NINGUNO,
        };
    }

    /**
     * Listado (solo `view_all`) con sus metricas por lote.
     *
     * @param  array{q: ?string, estado: ?string}  $filtros
     */
    public function listar(array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $consulta = $this->base();

        if (($filtros['q'] ?? null) !== null) {
            $texto = '%'.addcslashes($filtros['q'], '%_\\').'%';

            $consulta->where(fn ($w) => $w->where('s.nombre', 'like', $texto)->orWhere('ad.name', 'like', $texto)
                ->orWhere('ad.email', 'like', $texto)->orWhere('s.telefono', 'like', $texto));
        }

        if (in_array($filtros['estado'] ?? null, ['activo', 'inactivo'], true)) {
            $consulta->where('s.activo', $filtros['estado'] === 'activo');
        }

        $paginador = $consulta->orderByDesc('s.activo')->orderBy('s.nombre')->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));
        $metricas = $this->metricas($paginador->getCollection()->pluck('admin_id')->map(fn ($id) => (int) $id)->all());

        $paginador->setCollection($paginador->getCollection()->map(function ($fila) use ($metricas) {
            $fila->metricas = $metricas[(int) $fila->admin_id] ?? $this->metricaVacia();

            return $fila;
        }));

        return $paginador;
    }

    /**
     * KPI de la pantalla (sobre todos los asesores, no sobre los filtros).
     *
     * @return array{activos: int, total: int, ventas_hoy: int, monto_hoy: float, cartera: float, sin_revisar: float, pagos_sin_revisar: int, monto_pagos_sin_revisar: float}
     */
    public function indicadores(): array
    {
        $asesores = DB::table('bylopez_va_asesores')->get(['admin_id', 'activo']);
        $metricas = collect($this->metricas($asesores->pluck('admin_id')->map(fn ($id) => (int) $id)->all()));

        return [
            'activos' => $asesores->where('activo', true)->count(),
            'total' => $asesores->count(),
            'ventas_hoy' => (int) $metricas->sum('ventas_hoy'),
            'monto_hoy' => round((float) $metricas->sum('monto_hoy'), 2),
            'cartera' => round((float) $metricas->sum('cartera_saldo'), 2),
            'sin_revisar' => round((float) $metricas->sum('cartera_sin_revisar'), 2),
            'pagos_sin_revisar' => (int) $metricas->sum('pagos_sin_revisar'),
            'monto_pagos_sin_revisar' => round((float) $metricas->sum('monto_pagos_sin_revisar'), 2),
        ];
    }

    /**
     * Ficha completa de un asesor: perfil, metricas, pedidos (los de Mis ventas), clientes atendidos y pagos recientes.
     * Null si no existe o el actor no puede verla (`view_own` solo ve la propia).
     */
    public function detalle(AdminContract $actor, int $asesorId): ?array
    {
        $alcance = $this->alcance($actor);
        $asesor = $this->base()->where('s.id', $asesorId)->first();

        if (! $asesor || $alcance === AlcanceVisibilidad::NINGUNO
            || ($alcance === AlcanceVisibilidad::PROPIOS && (int) $asesor->admin_id !== (int) $actor->id)) {
            return null;
        }

        $adminId = (int) $asesor->admin_id;

        // Pedidos: el MISMO listado de Mis ventas (misma semantica de estado, pago y despacho).
        $alcancePedidos = $this->autorizacion->alcanceDeVisibilidad($actor);
        $pedidos = $alcancePedidos === AlcanceVisibilidad::NINGUNO ? null : $this->pedidos->listar($alcancePedidos, $actor, [
            'q' => null, 'desde' => null, 'hasta' => null, 'estado' => null, 'entrega' => null, 'resumen' => null,
            'asesor' => $alcancePedidos === AlcanceVisibilidad::TODOS ? (string) $adminId : null,
        ]);

        return [
            'asesor' => $asesor,
            'metricas' => $this->metricas([$adminId])[$adminId] ?? $this->metricaVacia(),
            'pedidos' => $pedidos,
            'clientes' => $this->clientesAtendidos($adminId),
            'pagos' => $this->pagosRecientes($adminId),
        ];
    }

    /** Perfil propio del usuario (activo o no), o null si no tiene. */
    public function idDelPerfilDe(AdminContract $admin): ?int
    {
        $id = DB::table('bylopez_va_asesores')->where('admin_id', (int) $admin->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Usuarios elegibles para asociar: activos, con el permiso de asesor por ACL (`operar_como_asesor`) y sin perfil.
     * No se filtra por nombre de rol.
     *
     * @return list<array{id: int, nombre: string, email: string, rol: ?string}>
     */
    public function usuariosElegibles(): array
    {
        $asociados = DB::table('bylopez_va_asesores')->pluck('admin_id')->map(fn ($id) => (int) $id)->all();

        return Admin::query()->with('role')->where('status', 1)->whereNotIn('id', $asociados)->orderBy('name')->get()
            ->filter(fn (Admin $a) => $this->autorizacion->esAsesor($a))
            ->map(fn (Admin $a) => ['id' => (int) $a->id, 'nombre' => (string) $a->name, 'email' => (string) $a->email, 'rol' => $a->role?->name])
            ->values()->all();
    }

    private function base(): Builder
    {
        return DB::table('bylopez_va_asesores as s')
            ->join('admins as ad', 'ad.id', '=', 's.admin_id')
            ->leftJoin('roles as r', 'r.id', '=', 'ad.role_id')
            ->select(['s.*', 'ad.name as usuario', 'ad.email', 'ad.status as usuario_activo', 'r.name as rol']);
    }

    /**
     * Metricas por `advisor_id` (= admins.id del asesor), todas agrupadas por lote.
     *
     * @param  list<int>  $adminIds
     * @return array<int, array<string, mixed>>
     */
    private function metricas(array $adminIds): array
    {
        if ($adminIds === []) {
            return [];
        }

        $m = array_fill_keys($adminIds, $this->metricaVacia());
        $atribuidos = fn () => DB::table('orders as o')->join('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'o.id')->whereIn('atr.advisor_id', $adminIds);

        foreach ($this->pedidos->soloVentasDeHoy($atribuidos())->groupBy('atr.advisor_id')
            ->selectRaw('atr.advisor_id, COUNT(*) as cantidad, COALESCE(SUM(o.base_grand_total), 0) as total')->get() as $f) {
            $m[(int) $f->advisor_id]['ventas_hoy'] = (int) $f->cantidad;
            $m[(int) $f->advisor_id]['monto_hoy'] = (float) $f->total;
        }

        foreach ($atribuidos()->groupBy('atr.advisor_id')->selectRaw(
            'atr.advisor_id, MAX(o.created_at) as ultima, COUNT(DISTINCT o.customer_id) as clientes,
             SUM(CASE WHEN o.status IN (?, ?, ?) THEN 1 ELSE 0 END) as abiertos',
            ConsultaPedidosAsesorService::ESTADOS_ABIERTOS
        )->get() as $f) {
            $m[(int) $f->advisor_id]['ultima_venta'] = $f->ultima;
            $m[(int) $f->advisor_id]['clientes'] = (int) $f->clientes;
            $m[(int) $f->advisor_id]['pedidos_abiertos'] = (int) $f->abiertos;
        }

        // Cartera y pagos solo existen con Cartera activa (falla cerrado: sin corte no se tocan las tablas de pagos).
        if (! $this->fechaCorte->activa()) {
            return $m;
        }

        $duenos = $atribuidos()->where('o.created_at', '>=', $this->fechaCorte->valor())->pluck('atr.advisor_id', 'o.id');

        foreach ($this->cartera->saldosDePedidos($duenos->keys()->all()) as $orderId => $saldo) {
            if (! $saldo->enCartera) {
                continue;
            }

            $asesor = (int) $duenos[$orderId];

            // Igual que carteraDelCliente: un pedido con Refund se revisa aparte y no suma a la cartera ordinaria.
            if (! $saldo->tieneMotivo(SaldoPedido::REVISAR_REEMBOLSO)) {
                $m[$asesor]['cartera_saldo'] += (float) $saldo->saldo;
            }

            $m[$asesor]['cartera_sin_revisar'] += (float) $saldo->sinRevisar;
            // D10: dinero verificado REAL; la compensacion con credito del cliente satisface la deuda pero no es dinero recibido.
            $m[$asesor]['cartera_verificado'] += max(0.0, (float) $saldo->verificado - (float) $saldo->creditoAplicado);
        }

        foreach (DB::table('bylopez_va_aplicaciones_pago as a')
            ->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')
            ->join('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'a.order_id')
            ->whereIn('atr.advisor_id', $adminIds)->whereNull('a.anulada_en')->where('p.estado', 'sin_revisar')
            ->groupBy('atr.advisor_id')
            ->selectRaw('atr.advisor_id, COUNT(DISTINCT p.id) as pagos, COALESCE(SUM(a.monto), 0) as monto')->get() as $f) {
            $m[(int) $f->advisor_id]['pagos_sin_revisar'] = (int) $f->pagos;
            $m[(int) $f->advisor_id]['monto_pagos_sin_revisar'] = (float) $f->monto;
        }

        return $m;
    }

    private function metricaVacia(): array
    {
        return [
            'ventas_hoy' => 0, 'monto_hoy' => 0.0, 'ultima_venta' => null, 'clientes' => 0, 'pedidos_abiertos' => 0,
            'cartera_saldo' => 0.0, 'cartera_sin_revisar' => 0.0, 'cartera_verificado' => 0.0,
            'pagos_sin_revisar' => 0, 'monto_pagos_sin_revisar' => 0.0,
        ];
    }

    /** Clientes con pedidos atribuidos al asesor (no hay «dueño» permanente de un cliente). */
    private function clientesAtendidos(int $adminId): Collection
    {
        return DB::table('orders as o')
            ->join('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'o.id')
            ->where('atr.advisor_id', $adminId)->whereNotNull('o.customer_id')
            ->groupBy('o.customer_id')
            ->selectRaw("o.customer_id, MAX(CONCAT(COALESCE(o.customer_first_name, ''), ' ', COALESCE(o.customer_last_name, ''))) as nombre,
                COUNT(*) as pedidos, MAX(o.created_at) as ultimo, COALESCE(SUM(o.base_grand_total), 0) as total")
            ->orderByDesc('ultimo')->limit(20)->get();
    }

    /** Ultimos pagos que tocan pedidos del asesor (con Cartera activa). */
    private function pagosRecientes(int $adminId): Collection
    {
        if (! $this->fechaCorte->activa()) {
            return collect();
        }

        return DB::table('bylopez_va_pagos as p')
            ->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('bylopez_va_aplicaciones_pago as a')
                ->join('bylopez_va_order_attributions as atr', 'atr.order_id', '=', 'a.order_id')
                ->whereColumn('a.pago_id', 'p.id')->where('atr.advisor_id', $adminId))
            ->orderByDesc('p.created_at')->limit(10)
            ->get(['p.id', 'p.monto', 'p.estado', 'p.medio_cobro', 'p.created_at', 'c.first_name', 'c.last_name']);
    }
}
