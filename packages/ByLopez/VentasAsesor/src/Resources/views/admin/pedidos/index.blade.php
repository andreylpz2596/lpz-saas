@php
    $titulo = $verTodos ? trans('ventasasesor::app.pedidos.title-todos') : trans('ventasasesor::app.pedidos.title');

    $estadosPedido = ['pending', 'pending_payment', 'processing', 'completed', 'canceled', 'closed', 'fraud'];
    $etiquetaEstado = fn (string $estado) => trans('admin::app.sales.orders.index.datagrid.'.str_replace('_', '-', $estado));

    /*
     * Semantica de color UNICA (la misma paleta que los label-* de Bagisto: pending/pending_payment amarillo,
     * processing cian, completed verde, closed indigo = requiere revision, canceled/fraud rojo, info gris):
     *   verde = completado / pagado / despachado · amarillo = pendiente / sin despachar · cian = procesando / parcial
     *   indigo = revisar · rojo = cancelado / fraude · gris = no aplica.
     */
    $tonoEstado = ['pending' => 'amarillo', 'pending_payment' => 'amarillo', 'processing' => 'cian', 'completed' => 'verde', 'closed' => 'indigo', 'canceled' => 'rojo', 'fraud' => 'rojo'];
    $iconoEstado = ['pending' => 'reloj', 'pending_payment' => 'reloj', 'processing' => 'punto', 'completed' => 'check', 'closed' => 'menos', 'canceled' => 'x', 'fraud' => 'alerta'];

    $tonoPago = ['pagado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'revisar' => 'indigo', 'no_aplica' => 'gris', 'devuelto' => 'gris'];
    $iconoPago = ['pagado' => 'check', 'parcial' => 'moneda', 'pendiente' => 'reloj', 'revisar' => 'alerta', 'no_aplica' => 'menos', 'devuelto' => 'menos'];

    $tonoDespacho = ['despachado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'no_aplica' => 'gris'];
    $iconoDespacho = ['despachado' => 'check', 'parcial' => 'caja', 'pendiente' => 'caja', 'no_aplica' => 'menos'];

    $iconos = [
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'alerta' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'reloj' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'caja' => '<path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7L12 12l8.7-5M12 22V12"/>',
        'moneda' => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'menos' => '<line x1="6" y1="12" x2="18" y2="12"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'punto' => '<circle cx="12" cy="12" r="5" fill="currentColor"/>',
    ];

    // Pill de estado (HTML ya escapado): tono semantico + icono + texto.
    $pill = function (string $tono, string $texto, string $icono, string $extra = '') use ($iconos): string {
        return '<span class="va-pill va-pill--'.$tono.' '.$extra.'"><svg viewBox="0 0 24 24" aria-hidden="true">'.($iconos[$icono] ?? '').'</svg><span>'.e($texto).'</span></span>';
    };

    $pillEstado = fn (object $p) => $pill($tonoEstado[$p->status] ?? 'gris', $etiquetaEstado($p->status), $iconoEstado[$p->status] ?? 'menos');
    $pillPago = fn (object $p) => $pill($tonoPago[$p->estado_pago] ?? 'gris', trans('ventasasesor::app.pedidos.pago.'.$p->estado_pago), $iconoPago[$p->estado_pago] ?? 'menos');
    $pillDespacho = fn (object $p) => $pill($tonoDespacho[$p->estado_despacho] ?? 'gris', trans('ventasasesor::app.pedidos.despacho.'.$p->estado_despacho), $iconoDespacho[$p->estado_despacho] ?? 'menos');

    // Segunda senal del pago (solo dentro de Cartera): dinero sin revisar vs verificado. Nunca se llama «confirmado».
    $pillRevision = function (object $p) use ($pill): string {
        if (($p->pago_sin_revisar ?? 0) > 0) {
            return $pill('amarillo', trans('ventasasesor::app.cartera.bloque.sin-revisar'), 'alerta', 'va-pill--sm');
        }

        return ($p->pago_verificado ?? 0) > 0 ? $pill('verde', trans('ventasasesor::app.cartera.bloque.verificado'), 'check', 'va-pill--sm') : '';
    };

    // Cuanto falta por cobrar: solo cuando hay saldo y el pago esta pendiente o parcial.
    $saldoVisible = fn (object $p) => in_array($p->estado_pago, ['pendiente', 'parcial'], true) && ($p->saldo ?? 0) > 0;

    // Tarjetas KPI clicables: filtran con ?resumen=. Conservan busqueda, entrega y asesor; reemplazan estado y
    // fechas (que contradirian al indicador). Pulsar la tarjeta activa vuelve a todos los pedidos.
    $resumenActivo = $filtros['resumen'] ?? null;
    $conservados = array_filter(['q' => $filtros['q'], 'entrega' => $filtros['entrega'], 'asesor' => $filtros['asesor']]);
    $urlResumen = fn (string $r) => route('admin.ventas_asesor.pedidos.index', $resumenActivo === $r ? $conservados : $conservados + ['resumen' => $r]);

    $hayFiltros = collect($filtros)->filter()->isNotEmpty();
    $columnas = 8;

    $nombreCliente = fn (object $pedido) => trim($pedido->customer_first_name.' '.$pedido->customer_last_name);

    $inicialesCliente = function (object $pedido) use ($nombreCliente): string {
        $nombre = $nombreCliente($pedido);

        if ($nombre === '') {
            return '#';
        }

        return collect(explode(' ', $nombre))
            ->filter()
            ->take(2)
            ->map(fn (string $parte) => mb_strtoupper(mb_substr($parte, 0, 1)))
            ->implode('');
    };

    $entregaLegible = function (?string $titulo): string {
        $titulo = trim((string) $titulo);

        if ($titulo === '') {
            return '-';
        }

        $partes = collect(preg_split('/\s+-\s+/', $titulo) ?: [])
            ->map(fn (string $parte) => trim($parte))
            ->filter();

        if ($partes->count() <= 1) {
            return $titulo;
        }

        $unicas = $partes
            ->unique(fn (string $parte) => mb_strtolower($parte))
            ->values();

        return $unicas->implode(' - ');
    };
@endphp

@once
    @push('styles')
        <style>
            .va-orders-page {
                margin: -16px;
                min-height: calc(100vh - 64px);
                background: #f8fafc;
                padding: 16px;
            }

            .dark .va-orders-page {
                background: #030712;
            }

            .va-panel {
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #fff;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }

            .dark .va-panel {
                border-color: #1f2937;
                background: #111827;
            }

            /* ---------- Tarjetas KPI clicables ---------- */
            .va-kpi {
                position: relative;
                display: block;
                cursor: pointer;
                text-decoration: none;
                transition: box-shadow .16s ease, border-color .16s ease, background-color .16s ease;
            }

            .va-kpi:hover {
                box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
            }

            .va-kpi:focus-visible {
                outline: 2px solid #2563eb;
                outline-offset: 2px;
            }

            .va-kpi-check {
                display: none;
                position: absolute;
                top: 10px;
                right: 10px;
                width: 20px;
                height: 20px;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                color: #fff;
            }

            .va-kpi-check svg {
                width: 12px;
                height: 12px;
                fill: none;
                stroke: currentColor;
                stroke-width: 3;
                stroke-linecap: round;
                stroke-linejoin: round;
            }

            .va-kpi.is-active {
                border-width: 2px;
                padding: 15px;
            }

            .va-kpi.is-active .va-kpi-check {
                display: inline-flex;
            }

            .va-kpi--azul.is-active { border-color: #2563eb; background: #dbeafe !important; }
            .va-kpi--azul.is-active .va-kpi-check { background: #2563eb; }
            .va-kpi--verde.is-active { border-color: #059669; background: #d1fae5 !important; }
            .va-kpi--verde.is-active .va-kpi-check { background: #059669; }
            .va-kpi--ambar.is-active { border-color: #d97706; background: #fef3c7 !important; }
            .va-kpi--ambar.is-active .va-kpi-check { background: #d97706; }
            .va-kpi--violeta.is-active { border-color: #7c3aed; background: #ede9fe !important; }
            .va-kpi--violeta.is-active .va-kpi-check { background: #7c3aed; }

            .dark .va-kpi--azul.is-active { background: #1e3a8a !important; }
            .dark .va-kpi--verde.is-active { background: #064e3b !important; }
            .dark .va-kpi--ambar.is-active { background: #78350f !important; }
            .dark .va-kpi--violeta.is-active { background: #4c1d95 !important; }

            .va-resumen-activo {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
                padding: 10px 14px;
            }

            /* ---------- Pills de estado ---------- */
            .va-pill {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                max-width: 100%;
                padding: 3px 10px 3px 8px;
                border: 1px solid;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 700;
                line-height: 1.2;
            }

            .va-pill svg {
                flex: none;
                width: 13px;
                height: 13px;
                fill: none;
                stroke: currentColor;
                stroke-width: 2.4;
                stroke-linecap: round;
                stroke-linejoin: round;
            }

            .va-pill--sm {
                padding: 1px 8px 1px 6px;
                font-size: 11px;
                font-weight: 600;
            }

            .va-pill--sm svg {
                width: 11px;
                height: 11px;
            }

            .va-pill--verde { background: #dcfce7; border-color: #86efac; color: #166534; }
            .va-pill--amarillo { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
            .va-pill--cian { background: #cffafe; border-color: #67e8f9; color: #155e75; }
            .va-pill--indigo { background: #e0e7ff; border-color: #a5b4fc; color: #3730a3; }
            .va-pill--rojo { background: #ffe4e6; border-color: #fda4af; color: #9f1239; }
            .va-pill--gris { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }

            .dark .va-pill--verde { background: #052e16; border-color: #166534; color: #86efac; }
            .dark .va-pill--amarillo { background: #451a03; border-color: #92400e; color: #fcd34d; }
            .dark .va-pill--cian { background: #083344; border-color: #155e75; color: #67e8f9; }
            .dark .va-pill--indigo { background: #1e1b4b; border-color: #4338ca; color: #a5b4fc; }
            .dark .va-pill--rojo { background: #4c0519; border-color: #9f1239; color: #fda4af; }
            .dark .va-pill--gris { background: #1f2937; border-color: #374151; color: #d1d5db; }

            /* ---------- Tabla ---------- */
            .va-filters-layout {
                display: grid;
                grid-template-columns: repeat(12, minmax(0, 1fr));
                gap: 10px;
                align-items: end;
            }

            .va-filter-search {
                grid-column: span 4 / span 4;
            }

            .va-filter-fields {
                display: contents;
            }

            .va-filter-field {
                grid-column: span 2 / span 2;
            }

            .va-filter-actions {
                grid-column: span 2 / span 2;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }

            .va-filter-toggle,
            .va-mobile-filter-button {
                display: none;
            }

            .va-desktop-table {
                display: block;
            }

            .va-mobile-orders {
                display: none;
            }

            .va-orders-table {
                width: 100%;
                table-layout: fixed;
                border-collapse: collapse;
            }

            .va-orders-table th,
            .va-orders-table td {
                padding: 14px 10px;
                vertical-align: middle;
            }

            .va-orders-table th {
                color: #475569;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: .02em;
                text-transform: uppercase;
            }

            .va-orders-table tbody tr {
                border-top: 1px solid #f1f5f9;
                transition: background-color .16s ease;
            }

            .va-orders-table tbody tr:hover {
                background: rgba(239, 246, 255, .62);
            }

            .va-order-id {
                font-size: 15px;
                font-weight: 800;
                line-height: 1.2;
            }

            .va-sub {
                margin-top: 2px;
                font-size: 12px;
                color: #64748b;
            }

            .va-total {
                font-size: 15px;
                font-weight: 800;
                line-height: 1.2;
            }

            .va-units {
                margin-top: 2px;
                font-size: 12px;
                color: #64748b;
            }

            .va-stack {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .va-saldo {
                font-size: 11px;
                font-weight: 600;
                color: #92400e;
            }

            .va-caption {
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .04em;
                text-transform: uppercase;
                color: #94a3b8;
            }

            .va-clamp-2 {
                display: -webkit-box;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
            }

            .dark .va-orders-table th {
                color: #d1d5db;
            }

            .dark .va-orders-table tbody tr {
                border-color: #1f2937;
            }

            .dark .va-orders-table tbody tr:hover {
                background: #030712;
            }

            .dark .va-sub,
            .dark .va-units {
                color: #9ca3af;
            }

            .dark .va-saldo {
                color: #fcd34d;
            }

            /* Tablet y movil: tarjetas (la tabla de 8 columnas no cabe bajo 1180px). */
            @media (max-width: 1179px) {
                .va-desktop-table {
                    display: none;
                }

                .va-mobile-orders {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767px) {
                .va-orders-page {
                    margin: -12px;
                    padding: 12px;
                }

                .va-mobile-orders {
                    grid-template-columns: minmax(0, 1fr);
                }

                .va-filters-layout {
                    display: block;
                }

                .va-filter-search {
                    display: block;
                }

                .va-mobile-filter-button {
                    display: inline-flex;
                    margin-top: 12px;
                    width: 100%;
                    justify-content: center;
                }

                .va-filter-fields {
                    display: none;
                    grid-template-columns: minmax(0, 1fr);
                    gap: 10px;
                    margin-top: 12px;
                }

                .va-filter-toggle:checked ~ .va-filter-fields {
                    display: grid;
                }

                .va-filter-actions {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .va-filter-actions > * {
                    justify-content: center;
                    width: 100%;
                }
            }
        </style>
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        {{ $titulo }}
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                    <span class="icon-orders"></span>
                </span>

                <div>
                    <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">
                        {{ $titulo }}
                    </h1>

                    <p class="mt-1 text-sm text-slate-500 dark:text-gray-400" data-alcance="{{ $verTodos ? 'todos' : 'propios' }}">
                        {{ $verTodos ? trans('ventasasesor::app.pedidos.alcance-todos') : trans('ventasasesor::app.pedidos.alcance-propios') }}
                    </p>
                </div>
            </div>

            @if (bouncer()->hasPermission('ventas_asesor.pedidos.create'))
                <a
                    href="{{ route('admin.ventas_asesor.nueva_venta.pantalla') }}"
                    class="primary-button whitespace-nowrap"
                >
                    + @lang('ventasasesor::app.pedidos.nueva-venta')
                </a>
            @endif
        </div>

        @if (! empty($avance))
            @include('ventasasesor::admin.pedidos.avance')
        @endif

        {{-- KPI clicables: cada tarjeta es un enlace ?resumen=… con el MISMO criterio que su cifra (resumen() en el servicio). --}}
        <div class="mt-7 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <a
                href="{{ $urlResumen('hoy') }}"
                class="va-kpi va-kpi--azul rounded-xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950 {{ $resumenActivo === 'hoy' ? 'is-active' : '' }}"
                data-resumen-link="hoy"
                title="{{ trans('ventasasesor::app.pedidos.resumen.'.($resumenActivo === 'hoy' ? 'ayuda-quitar' : 'ayuda')) }}"
                @if ($resumenActivo === 'hoy') aria-current="true" @endif
            >
                <span class="va-kpi-check"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>

                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xl text-blue-600 dark:bg-blue-900 dark:text-blue-300">
                        <span class="icon-cart"></span>
                    </span>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">@lang('ventasasesor::app.pedidos.kpis.hoy')</p>
                        <p class="mt-1 text-2xl font-bold leading-7 text-gray-900 dark:text-white" data-kpi="hoy-total">
                            {{ \ByLopez\Logistica\Support\Moneda::formatear($indicadores['hoy_total']) }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400" data-kpi="hoy-cantidad">
                            @lang('ventasasesor::app.pedidos.kpis.hoy-detalle', ['cantidad' => $indicadores['hoy_cantidad']])
                        </p>
                    </div>
                </div>
            </a>

            <a
                href="{{ $urlResumen('abiertos') }}"
                class="va-kpi va-kpi--verde rounded-xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950 {{ $resumenActivo === 'abiertos' ? 'is-active' : '' }}"
                data-resumen-link="abiertos"
                title="{{ trans('ventasasesor::app.pedidos.resumen.'.($resumenActivo === 'abiertos' ? 'ayuda-quitar' : 'ayuda')) }}"
                @if ($resumenActivo === 'abiertos') aria-current="true" @endif
            >
                <span class="va-kpi-check"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>

                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl text-emerald-600 dark:bg-emerald-900 dark:text-emerald-300">
                        <span class="icon-list"></span>
                    </span>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">@lang('ventasasesor::app.pedidos.kpis.abiertos')</p>
                        <p class="mt-1 text-2xl font-bold leading-7 text-gray-900 dark:text-white" data-kpi="abiertos">
                            {{ $indicadores['abiertos'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.kpis.abiertos-detalle')</p>
                    </div>
                </div>
            </a>

            <a
                href="{{ $urlResumen('por_despachar') }}"
                class="va-kpi va-kpi--ambar rounded-xl border border-amber-100 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950 {{ $resumenActivo === 'por_despachar' ? 'is-active' : '' }}"
                data-resumen-link="por_despachar"
                title="{{ trans('ventasasesor::app.pedidos.resumen.'.($resumenActivo === 'por_despachar' ? 'ayuda-quitar' : 'ayuda')) }}"
                @if ($resumenActivo === 'por_despachar') aria-current="true" @endif
            >
                <span class="va-kpi-check"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>

                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xl text-amber-600 dark:bg-amber-900 dark:text-amber-300">
                        <span class="icon-store"></span>
                    </span>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">@lang('ventasasesor::app.pedidos.kpis.por-despachar')</p>
                        <p class="mt-1 text-2xl font-bold leading-7 text-gray-900 dark:text-white" data-kpi="por-despachar">
                            {{ $indicadores['por_despachar'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.kpis.por-despachar-detalle')</p>
                    </div>
                </div>
            </a>

            <a
                href="{{ $urlResumen('pendientes_pago') }}"
                class="va-kpi va-kpi--violeta rounded-xl border border-violet-100 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950 {{ $resumenActivo === 'pendientes_pago' ? 'is-active' : '' }}"
                data-resumen-link="pendientes_pago"
                title="{{ trans('ventasasesor::app.pedidos.resumen.'.($resumenActivo === 'pendientes_pago' ? 'ayuda-quitar' : 'ayuda')) }}"
                @if ($resumenActivo === 'pendientes_pago') aria-current="true" @endif
            >
                <span class="va-kpi-check"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>

                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xl text-violet-600 dark:bg-violet-900 dark:text-violet-300">
                        <span class="icon-attribute"></span>
                    </span>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">@lang('ventasasesor::app.pedidos.kpis.pendientes-pago')</p>
                        <p class="mt-1 text-2xl font-bold leading-7 text-gray-900 dark:text-white" data-kpi="pendientes-pago">
                            {{ $indicadores['pendientes_pago'] }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.kpis.pendientes-pago-detalle')</p>
                    </div>
                </div>
            </a>
        </div>

        @if ($resumenActivo)
            <div class="va-panel va-resumen-activo mt-4" data-resumen-activo="{{ $resumenActivo }}">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    @lang('ventasasesor::app.pedidos.resumen.mostrando'):
                    <span class="font-bold text-gray-900 dark:text-white">@lang('ventasasesor::app.pedidos.resumen.'.$resumenActivo)</span>
                    &middot; @lang('ventasasesor::app.pedidos.resumen.pedidos', ['cantidad' => $pedidos->total()])
                </p>

                <a
                    href="{{ route('admin.ventas_asesor.pedidos.index', array_filter(request()->except(['resumen', 'pedido', 'page']))) }}"
                    class="transparent-button"
                >
                    ✕ @lang('ventasasesor::app.pedidos.resumen.quitar')
                </a>
            </div>
        @endif

        <form
            method="GET"
            action="{{ route('admin.ventas_asesor.pedidos.index') }}"
            class="va-panel mt-5 p-4"
        >
            @if ($resumenActivo)
                <input type="hidden" name="resumen" value="{{ $resumenActivo }}">
            @endif

            <div class="mb-4 flex min-h-10 items-center justify-between gap-3 max-sm:flex-wrap">
                <div class="flex items-center gap-2.5">
                    <span class="icon-filter text-xl text-blue-600"></span>

                    <p class="text-base font-semibold text-gray-900 dark:text-white">
                        Filtros de b&uacute;squeda
                    </p>
                </div>

                @if ($hayFiltros)
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                        Activos
                    </span>
                @endif
            </div>

            <div class="va-filters-layout">
                <div class="va-filter-search">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-q">
                        @lang('ventasasesor::app.pedidos.filtros.q')
                    </label>

                    <div class="relative">
                        <input
                            id="f-q"
                            type="search"
                            name="q"
                            value="{{ $filtros['q'] }}"
                            maxlength="100"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2.5 pr-10 text-sm text-gray-700 transition-all hover:border-slate-300 focus:border-blue-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            placeholder="#, nombre, 300..."
                        >

                        <span class="icon-search pointer-events-none absolute top-2 flex items-center text-2xl text-gray-400 ltr:right-3 rtl:left-3"></span>
                    </div>
                </div>

                <input
                    id="va-filter-toggle"
                    type="checkbox"
                    class="va-filter-toggle"
                    @checked($hayFiltros)
                >

                <label for="va-filter-toggle" class="va-mobile-filter-button secondary-button">
                    <span class="icon-filter text-xl"></span>
                    Filtros
                </label>

                <div class="va-filter-fields">
                    <div class="va-filter-field">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-desde">
                            @lang('ventasasesor::app.pedidos.filtros.desde')
                        </label>

                        <input
                            id="f-desde"
                            type="date"
                            name="desde"
                            value="{{ $filtros['desde'] }}"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        >
                    </div>

                    <div class="va-filter-field">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-hasta">
                            @lang('ventasasesor::app.pedidos.filtros.hasta')
                        </label>

                        <input
                            id="f-hasta"
                            type="date"
                            name="hasta"
                            value="{{ $filtros['hasta'] }}"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        >
                    </div>

                    <div class="va-filter-field">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-estado">
                            @lang('ventasasesor::app.pedidos.filtros.estado')
                        </label>

                        <select
                            id="f-estado"
                            name="estado"
                            class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        >
                            <option value="">@lang('ventasasesor::app.pedidos.filtros.todos')</option>

                            @foreach ($estadosPedido as $estado)
                                <option value="{{ $estado }}" @selected($filtros['estado'] === $estado)>{{ $etiquetaEstado($estado) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="va-filter-field">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-entrega">
                            @lang('ventasasesor::app.pedidos.filtros.entrega')
                        </label>

                        <select
                            id="f-entrega"
                            name="entrega"
                            class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        >
                            <option value="">@lang('ventasasesor::app.pedidos.filtros.todos')</option>

                            @foreach ($metodosEntrega as $metodo)
                                <option value="{{ $metodo['codigo'] }}" @selected($filtros['entrega'] === $metodo['codigo'])>{{ $entregaLegible($metodo['titulo']) }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($verTodos)
                        <div class="va-filter-field">
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-asesor">
                                @lang('ventasasesor::app.pedidos.filtros.asesor')
                            </label>

                            <select
                                id="f-asesor"
                                name="asesor"
                                class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            >
                                <option value="">@lang('ventasasesor::app.pedidos.filtros.todos')</option>

                                @foreach ($asesores as $asesor)
                                    <option value="{{ $asesor['id'] }}" @selected($filtros['asesor'] === (string) $asesor['id'])>{{ $asesor['nombre'] }}</option>
                                @endforeach

                                <option value="empresa" @selected($filtros['asesor'] === 'empresa')>@lang('ventasasesor::app.pedidos.filtros.empresa')</option>
                                <option value="sin_asignar" @selected($filtros['asesor'] === 'sin_asignar')>@lang('ventasasesor::app.pedidos.filtros.sin-asignar')</option>
                                <option value="sin_atribucion" @selected($filtros['asesor'] === 'sin_atribucion')>@lang('ventasasesor::app.pedidos.filtros.sin-atribucion')</option>
                            </select>
                        </div>
                    @endif

                    <div class="va-filter-actions">
                        @if ($hayFiltros)
                            <a
                                href="{{ route('admin.ventas_asesor.pedidos.index') }}"
                                class="transparent-button"
                            >
                                @lang('ventasasesor::app.pedidos.filtros.limpiar')
                            </a>
                        @endif

                        <button type="submit" class="secondary-button">
                            @lang('ventasasesor::app.pedidos.filtros.aplicar')
                        </button>
                    </div>
                </div>
            </div>
        </form>

        {{-- Escritorio: 8 columnas. Estado (pedido), Pago (financiero) y Despacho (logistico) son dominios distintos. --}}
        <div class="va-panel va-desktop-table mt-5 overflow-hidden">
            <table class="va-orders-table text-left text-sm">
                <colgroup>
                    <col style="width: 12%">
                    <col style="width: 16%">
                    <col style="width: 17%">
                    <col style="width: 10%">
                    <col style="width: 10%">
                    <col style="width: 11%">
                    <col style="width: 10%">
                    <col style="width: 14%">
                </colgroup>

                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        <th>@lang('ventasasesor::app.pedidos.columnas.pedido-fecha')</th>
                        <th>@lang('ventasasesor::app.pedidos.columnas.cliente')</th>
                        <th>@lang('ventasasesor::app.pedidos.columnas.asesor-despacho')</th>
                        <th class="text-right">@lang('ventasasesor::app.pedidos.columnas.unidades-total')</th>
                        <th>@lang('ventasasesor::app.pedidos.columnas.estado')</th>
                        <th>@lang('ventasasesor::app.pedidos.columnas.pago')</th>
                        <th>@lang('ventasasesor::app.pedidos.columnas.entrega')</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($pedidos as $pedido)
                        <tr data-pedido="{{ $pedido->id }}">
                            <td>
                                <p class="va-order-id text-gray-900 dark:text-white">#{{ $pedido->increment_id }}</p>
                                <p class="va-sub">{{ core()->formatDate($pedido->created_at, 'd/m/Y') }} &middot; {{ core()->formatDate($pedido->created_at, 'h:i A') }}</p>
                            </td>

                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                        {{ $inicialesCliente($pedido) }}
                                    </span>

                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $nombreCliente($pedido) ?: '-' }}</p>
                                        <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $pedido->telefono ?: trans('ventasasesor::app.pedidos.sin-telefono') }}</p>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="va-stack">
                                    <span class="va-clamp-2 text-sm font-medium text-gray-800 dark:text-gray-200" data-propietario>{{ $pedido->propietario }}</span>
                                    {!! $pillDespacho($pedido) !!}
                                    @include('ventasasesor::admin.pedidos.guia-resumen')
                                </div>
                            </td>

                            <td class="text-right">
                                <p class="va-total text-gray-900 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->grand_total) }}</p>
                                <p class="va-units">{{ trans('ventasasesor::app.pedidos.unidades', ['cantidad' => (int) $pedido->total_qty_ordered]) }}</p>
                            </td>

                            <td>
                                {!! $pillEstado($pedido) !!}
                            </td>

                            <td>
                                <div class="va-stack">
                                    {!! $pillPago($pedido) !!}
                                    {!! $pillRevision($pedido) !!}

                                    @if ($saldoVisible($pedido))
                                        <span class="va-saldo">@lang('ventasasesor::app.pedidos.resumen.saldo') {{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->saldo) }}</span>
                                    @endif
                                </div>
                            </td>

                            <td class="text-sm text-gray-700 dark:text-gray-300">
                                <span class="va-clamp-2">{{ $entregaLegible($pedido->shipping_title) }}</span>
                            </td>

                            <td class="text-right">
                                <div class="flex flex-col items-end gap-1.5">
                                    @include('ventasasesor::admin.pedidos.acciones-envio')

                                    <a
                                        href="{{ route('admin.ventas_asesor.pedidos.index', array_merge(request()->query(), ['pedido' => $pedido->id])) }}"
                                        class="icon-view inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
                                        title="@lang('ventasasesor::app.pedidos.ver-detalle')"
                                        aria-label="@lang('ventasasesor::app.pedidos.ver-detalle') #{{ $pedido->increment_id }}"
                                    ></a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnas }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                {{ $hayFiltros ? trans('ventasasesor::app.pedidos.vacio-filtros') : trans('ventasasesor::app.pedidos.vacio') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Tablet y movil: tarjetas apiladas con la misma informacion, priorizando Pedido, Cliente, Total, Pago, Despacho y Accion. --}}
        <div class="va-mobile-orders mt-5 gap-3">
            @forelse ($pedidos as $pedido)
                <div class="va-panel p-4" data-pedido="{{ $pedido->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="va-order-id text-gray-900 dark:text-white">#{{ $pedido->increment_id }}</p>
                            <p class="va-sub">{{ core()->formatDate($pedido->created_at, 'd/m/Y') }} &middot; {{ core()->formatDate($pedido->created_at, 'h:i A') }}</p>
                        </div>

                        <div class="va-stack items-end">
                            <span class="va-caption">@lang('ventasasesor::app.pedidos.columnas.estado')</span>
                            {!! $pillEstado($pedido) !!}
                        </div>
                    </div>

                    <div class="mt-3 flex min-w-0 items-center gap-2.5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                            {{ $inicialesCliente($pedido) }}
                        </span>

                        <div class="min-w-0">
                            <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $nombreCliente($pedido) ?: '-' }}</p>
                            <p class="truncate text-sm text-slate-500 dark:text-gray-400">{{ $pedido->telefono ?: trans('ventasasesor::app.pedidos.sin-telefono') }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.pedidos.columnas.total')</p>
                            <p class="va-total text-gray-900 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->grand_total) }}</p>
                            <p class="va-units">{{ trans('ventasasesor::app.pedidos.unidades', ['cantidad' => (int) $pedido->total_qty_ordered]) }}</p>
                        </div>

                        <div class="text-right">
                            <p class="va-caption">@lang('ventasasesor::app.pedidos.columnas.asesor')</p>
                            <p class="mt-0.5 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $pedido->propietario }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="va-stack">
                            <span class="va-caption">@lang('ventasasesor::app.pedidos.columnas.pago')</span>
                            {!! $pillPago($pedido) !!}
                            {!! $pillRevision($pedido) !!}

                            @if ($saldoVisible($pedido))
                                <span class="va-saldo">@lang('ventasasesor::app.pedidos.resumen.saldo') {{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->saldo) }}</span>
                            @endif
                        </div>

                        <div class="va-stack">
                            <span class="va-caption">@lang('ventasasesor::app.pedidos.columnas.despacho')</span>
                            {!! $pillDespacho($pedido) !!}
                            @include('ventasasesor::admin.pedidos.guia-resumen')
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p class="min-w-0 truncate text-xs text-slate-500 dark:text-gray-400">
                            {{ $entregaLegible($pedido->shipping_title) }}
                        </p>

                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            @include('ventasasesor::admin.pedidos.acciones-envio')

                            <a
                                href="{{ route('admin.ventas_asesor.pedidos.index', array_merge(request()->query(), ['pedido' => $pedido->id])) }}"
                                class="secondary-button shrink-0"
                            >
                                <span class="icon-view text-xl"></span>
                                @lang('ventasasesor::app.pedidos.ver-detalle')
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="va-panel p-6 text-center text-gray-500 dark:text-gray-400">
                    {{ $hayFiltros ? trans('ventasasesor::app.pedidos.vacio-filtros') : trans('ventasasesor::app.pedidos.vacio') }}
                </div>
            @endforelse
        </div>

        @if ($pedidos->total() > 0)
            <div class="mt-4 flex items-center justify-between gap-2.5 max-sm:flex-wrap">
                <p class="text-sm text-slate-500 dark:text-gray-400">
                    @lang('ventasasesor::app.pedidos.resultados', ['desde' => $pedidos->firstItem(), 'hasta' => $pedidos->lastItem(), 'total' => $pedidos->total()])
                </p>

                @if ($pedidos->hasPages())
                    <div class="flex items-center gap-2">
                        @if ($pedidos->onFirstPage())
                            <span class="secondary-button cursor-not-allowed opacity-50">@lang('ventasasesor::app.pedidos.anterior')</span>
                        @else
                            <a href="{{ $pedidos->previousPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.anterior')</a>
                        @endif

                        <span class="text-sm text-slate-500 dark:text-gray-400">{{ $pedidos->currentPage() }} / {{ $pedidos->lastPage() }}</span>

                        @if ($pedidos->hasMorePages())
                            <a href="{{ $pedidos->nextPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.siguiente')</a>
                        @else
                            <span class="secondary-button cursor-not-allowed opacity-50">@lang('ventasasesor::app.pedidos.siguiente')</span>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Despacho V1: el MISMO drawer «Despachar» de Logistica (misma interfaz, mismos endpoints y servicios). Solo se
             monta para quien puede despachar o ver el envio; la autorizacion real la valida el backend. --}}
        @if ($puedeDespachar || $puedeVerEnvio)
            @include('logistica::admin.despachos.componente')
        @endif
    </div>
</x-admin::layouts>
