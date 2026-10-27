@php
    $dinero = fn (float|int|null $v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $iniciales = fn (string $nombre) => collect(explode(' ', trim($nombre)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: '#';
    $pillEstado = fn (bool $activo) => '<span class="va-pill va-pill--'.($activo ? 'verde' : 'gris').'"><span>'.e(trans('ventasasesor::app.asesores.'.($activo ? 'activo' : 'inactivo'))).'</span></span>';
    $actividad = fn (?string $fecha) => $fecha ? \Illuminate\Support\Carbon::parse($fecha)->locale(app()->getLocale())->diffForHumans() : trans('ventasasesor::app.asesores.sin-ventas');
    $urlFicha = fn (int $id) => route('admin.ventas_asesor.asesores.index', ['asesor' => $id]);
    $hayFiltros = collect($filtros)->filter()->isNotEmpty();
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.asesores.titulo')
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                    <span class="icon-customer-2"></span>
                </span>

                <div>
                    <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.titulo')</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.subtitulo')</p>
                </div>
            </div>

            @if ($puedeGestionar)
                @include('ventasasesor::admin.asesores.modal-crear')
            @endif
        </div>

        {{-- KPI del dominio real (ConsultaAsesoresService::indicadores): informativos, sobre todos los asesores. --}}
        <div class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-activos')</p>
                <p class="va-kpi-value" data-kpi="activos">{{ $indicadores['activos'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-activos-detalle', ['total' => $indicadores['total']])</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-ventas')</p>
                <p class="va-kpi-value" data-kpi="ventas-hoy">{{ $dinero($indicadores['monto_hoy']) }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-ventas-detalle', ['cantidad' => $indicadores['ventas_hoy']])</p>
            </div>
            <div class="va-panel va-kpi-box va-kpi-box--saldo">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-cartera')</p>
                <p class="va-kpi-value" data-kpi="cartera">{{ $dinero($indicadores['cartera']) }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-cartera-detalle', ['monto' => $dinero($indicadores['sin_revisar'])])</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-pagos')</p>
                <p class="va-kpi-value" data-kpi="pagos">{{ $indicadores['pagos_sin_revisar'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-pagos-detalle', ['monto' => $dinero($indicadores['monto_pagos_sin_revisar'])])</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.ventas_asesor.asesores.index') }}" class="va-panel mt-4 p-4">
            <div class="va-filters-layout">
                <div class="va-filter-search" style="grid-column: span 6 / span 6">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-q">@lang('ventasasesor::app.asesores.buscar')</label>
                    <input id="f-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                </div>

                <div class="va-filter-field" style="grid-column: span 3 / span 3">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-estado">@lang('ventasasesor::app.asesores.estado')</label>
                    <select id="f-estado" name="estado" class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <option value="">@lang('ventasasesor::app.asesores.todos')</option>
                        <option value="activo" @selected($filtros['estado'] === 'activo')>@lang('ventasasesor::app.asesores.activo')</option>
                        <option value="inactivo" @selected($filtros['estado'] === 'inactivo')>@lang('ventasasesor::app.asesores.inactivo')</option>
                    </select>
                </div>

                <div class="va-filter-actions" style="grid-column: span 3 / span 3">
                    @if ($hayFiltros)
                        <a href="{{ route('admin.ventas_asesor.asesores.index') }}" class="transparent-button">@lang('ventasasesor::app.asesores.limpiar')</a>
                    @endif
                    <button type="submit" class="secondary-button">@lang('ventasasesor::app.asesores.filtrar')</button>
                </div>
            </div>
        </form>

        {{-- Escritorio --}}
        <div class="va-panel va-desktop-table mt-4 overflow-hidden">
            <table class="va-orders-table text-left text-sm">
                <colgroup>
                    <col style="width: 26%">
                    <col style="width: 14%">
                    <col style="width: 13%">
                    <col style="width: 16%">
                    <col style="width: 13%">
                    <col style="width: 9%">
                    <col style="width: 9%">
                </colgroup>

                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        <th>@lang('ventasasesor::app.asesores.col-asesor')</th>
                        <th>@lang('ventasasesor::app.asesores.col-actividad')</th>
                        <th class="text-right">@lang('ventasasesor::app.asesores.col-ventas')</th>
                        <th class="text-right">@lang('ventasasesor::app.asesores.col-cartera')</th>
                        <th class="text-right">@lang('ventasasesor::app.asesores.col-pagos')</th>
                        <th>@lang('ventasasesor::app.asesores.col-estado')</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($asesores as $a)
                        @php($m = $a->metricas)
                        <tr data-asesor="{{ $a->id }}">
                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">{{ $iniciales($a->nombre) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-gray-900 dark:text-white">{{ $a->nombre }}</p>
                                        <p class="truncate text-xs text-slate-600 dark:text-gray-300">{{ $a->usuario }}@if ($a->rol) · {{ $a->rol }}@endif</p>
                                        <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $a->telefono ?: $a->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $actividad($m['ultima_venta']) }}</p>
                                @if ($m['ultima_venta'])
                                    <p class="va-sub">@lang('ventasasesor::app.asesores.ultima-venta')</p>
                                @endif
                            </td>
                            <td class="text-right">
                                <p class="va-money--strong text-gray-900 dark:text-white">{{ $dinero($m['monto_hoy']) }}</p>
                                <p class="va-sub">@lang('ventasasesor::app.asesores.pedidos', ['cantidad' => $m['ventas_hoy']])</p>
                            </td>
                            <td class="text-right">
                                <p class="va-money text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.pendiente', ['monto' => $dinero($m['cartera_saldo'])])</p>
                                @if ($m['cartera_sin_revisar'] > 0)
                                    <span class="va-pill va-pill--amarillo va-pill--sm"><span>@lang('ventasasesor::app.asesores.sin-revisar', ['monto' => $dinero($m['cartera_sin_revisar'])])</span></span>
                                @endif
                            </td>
                            <td class="text-right">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">@lang('ventasasesor::app.asesores.por-revisar', ['cantidad' => $m['pagos_sin_revisar']])</p>
                                <p class="va-sub">{{ $dinero($m['monto_pagos_sin_revisar']) }}</p>
                            </td>
                            <td>{!! $pillEstado((bool) $a->activo) !!}</td>
                            <td class="text-right">
                                <a href="{{ $urlFicha($a->id) }}" class="icon-view inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900" title="@lang('ventasasesor::app.asesores.ver')" aria-label="@lang('ventasasesor::app.asesores.ver') {{ $a->nombre }}"></a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                {{ $hayFiltros ? trans('ventasasesor::app.asesores.vacio-filtros') : trans('ventasasesor::app.asesores.vacio') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Tablet y movil --}}
        <div class="va-mobile-orders mt-4 gap-3">
            @forelse ($asesores as $a)
                @php($m = $a->metricas)
                <div class="va-panel p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">{{ $iniciales($a->nombre) }}</span>
                            <div class="min-w-0">
                                <p class="truncate font-bold text-gray-900 dark:text-white">{{ $a->nombre }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $a->usuario }}</p>
                            </div>
                        </div>
                        {!! $pillEstado((bool) $a->activo) !!}
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.asesores.col-ventas')</p>
                            <p class="va-money text-gray-900 dark:text-white">{{ $dinero($m['monto_hoy']) }}</p>
                            <p class="va-sub">@lang('ventasasesor::app.asesores.pedidos', ['cantidad' => $m['ventas_hoy']])</p>
                        </div>
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.asesores.col-cartera')</p>
                            <p class="va-money text-gray-900 dark:text-white">{{ $dinero($m['cartera_saldo']) }}</p>
                        </div>
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.asesores.col-pagos')</p>
                            <p class="va-money text-gray-900 dark:text-white">{{ $m['pagos_sin_revisar'] }}</p>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-3">
                        <p class="va-sub">{{ $actividad($m['ultima_venta']) }}</p>
                        <a href="{{ $urlFicha($a->id) }}" class="secondary-button shrink-0"><span class="icon-view text-xl"></span> @lang('ventasasesor::app.asesores.ver')</a>
                    </div>
                </div>
            @empty
                <div class="va-panel p-6 text-center text-gray-500 dark:text-gray-400">
                    {{ $hayFiltros ? trans('ventasasesor::app.asesores.vacio-filtros') : trans('ventasasesor::app.asesores.vacio') }}
                </div>
            @endforelse
        </div>

        @if ($asesores->hasPages())
            <div class="mt-4 flex items-center justify-end gap-2">
                @if (! $asesores->onFirstPage())
                    <a href="{{ $asesores->previousPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.anterior')</a>
                @endif
                <span class="text-sm text-slate-500 dark:text-gray-400">{{ $asesores->currentPage() }} / {{ $asesores->lastPage() }}</span>
                @if ($asesores->hasMorePages())
                    <a href="{{ $asesores->nextPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.siguiente')</a>
                @endif
            </div>
        @endif
    </div>
</x-admin::layouts>
