@php
    $dinero = fn (float|int|null $v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $m = $metricas;
    $iniciales = collect(explode(' ', trim($asesor->nombre)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: '#';
    $fecha = fn ($f, string $formato = 'd/m/Y') => $f ? core()->formatDate($f, $formato) : '—';

    // Mismos pills y semantica que «Mis ventas» (estado del pedido, pago, despacho son dominios distintos).
    $pill = fn (string $tono, string $texto, string $extra = '') => '<span class="va-pill va-pill--'.$tono.' '.$extra.'"><span>'.e($texto).'</span></span>';
    $tonoEstado = ['pending' => 'amarillo', 'pending_payment' => 'amarillo', 'processing' => 'cian', 'completed' => 'verde', 'closed' => 'indigo', 'canceled' => 'rojo', 'fraud' => 'rojo'];
    $tonoPago = ['pagado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'revisar' => 'indigo', 'no_aplica' => 'gris', 'devuelto' => 'gris'];
    $tonoDespacho = ['despachado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'no_aplica' => 'gris'];
    $tonoPagoCartera = ['sin_revisar' => 'amarillo', 'confirmado' => 'verde', 'rechazado' => 'rojo', 'reversado' => 'gris'];
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        {{ $asesor->nombre }}
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        @if ($verTodos)
            <a href="{{ route('admin.ventas_asesor.asesores.index') }}" class="text-sm text-blue-600 hover:underline">← @lang('ventasasesor::app.asesores.volver')</a>
        @endif

        {{-- Cabecera --}}
        <div class="va-panel mt-2 flex flex-wrap items-start justify-between gap-4 p-4" data-asesor-ficha="{{ $asesor->id }}">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">{{ $iniciales }}</span>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold leading-8 text-gray-900 dark:text-white">{{ $asesor->nombre }}</h1>
                        {!! $pill($asesor->activo ? 'verde' : 'gris', trans('ventasasesor::app.asesores.'.($asesor->activo ? 'activo' : 'inactivo'))) !!}
                    </div>

                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                        <span class="va-caption">@lang('ventasasesor::app.asesores.usuario'):</span>
                        <span class="font-semibold">{{ $asesor->usuario }}</span>
                        @if ($asesor->rol) · @lang('ventasasesor::app.asesores.rol'): {{ $asesor->rol }} @endif
                        @if (! $asesor->usuario_activo)
                            {!! $pill('rojo', trans('ventasasesor::app.asesores.usuario-inactivo'), 'va-pill--sm') !!}
                        @endif
                    </p>
                    <p class="text-sm text-slate-500 dark:text-gray-400">{{ $asesor->email }}@if ($asesor->telefono) · {{ $asesor->telefono }}@endif</p>
                </div>
            </div>

            @if ($puedeGestionar)
                <div class="flex flex-wrap items-center gap-2">
                    @include('ventasasesor::admin.asesores.modal-editar')

                    <form method="POST" action="{{ route('admin.ventas_asesor.asesores.estado', ['asesor' => $asesor->id]) }}">
                        @csrf
                        <input type="hidden" name="activo" value="{{ $asesor->activo ? 0 : 1 }}">
                        <button type="submit" class="va-btn {{ $asesor->activo ? 'va-btn--no' : 'va-btn--ok' }}">
                            @lang('ventasasesor::app.asesores.'.($asesor->activo ? 'inactivar' : 'activar'))
                        </button>
                    </form>
                </div>
            @endif
        </div>

        {{-- KPI del asesor mostrado (ConsultaAsesoresService::metricas) --}}
        <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-ventas')</p>
                <p class="va-kpi-value" data-kpi="ventas-hoy">{{ $dinero($m['monto_hoy']) }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-ventas-detalle', ['cantidad' => $m['ventas_hoy']])</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-abiertos')</p>
                <p class="va-kpi-value" data-kpi="abiertos">{{ $m['pedidos_abiertos'] }}</p>
            </div>
            <div class="va-panel va-kpi-box va-kpi-box--saldo">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-cartera')</p>
                <p class="va-kpi-value" data-kpi="cartera">{{ $dinero($m['cartera_saldo']) }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-cartera-detalle', ['monto' => $dinero($m['cartera_sin_revisar'])])</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.asesores.kpi-pagos')</p>
                <p class="va-kpi-value" data-kpi="pagos">{{ $m['pagos_sin_revisar'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.asesores.kpi-pagos-detalle', ['monto' => $dinero($m['monto_pagos_sin_revisar'])])</p>
            </div>
        </div>

        {{-- Resumen --}}
        <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.resumen')</p>
        <div class="va-panel mt-2 p-4">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-4">
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.nombre')</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ $asesor->nombre }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.usuario')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $asesor->usuario }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.correo')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $asesor->email }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.telefono')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $asesor->telefono ?: '—' }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.fecha-ingreso')</dt><dd class="text-gray-800 dark:text-gray-200" data-fecha-ingreso>{{ $fecha($asesor->fecha_ingreso) }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.periodo-comision')</dt><dd class="text-gray-800 dark:text-gray-200" data-periodo-comision="{{ $periodoComision['actual']['clave'] }}">{{ ucfirst($periodoComision['actual']['etiqueta']) }}
                    @if ($periodoComision['cambio'])<br><small class="va-sub">@lang('ventasasesor::app.asesores.periodo-cambio-programado', ['dia' => $periodoComision['cambio']['dia'], 'desde' => $fecha($periodoComision['cambio']['desde'])])</small>@endif
                </dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.alta')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $fecha($asesor->created_at) }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.ultima-venta')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $fecha($m['ultima_venta'], 'd/m/Y h:i A') }}</dd></div>
                <div><dt class="va-caption">@lang('ventasasesor::app.asesores.clientes-atendidos')</dt><dd class="text-gray-800 dark:text-gray-200">{{ $m['clientes'] }}</dd></div>
            </dl>
        </div>

        {{-- Pedidos: el mismo listado y semantica de Mis ventas --}}
        @if ($pedidos !== null)
            <div class="mt-5 flex items-center justify-between gap-3">
                <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.seccion-pedidos')</p>
                <a href="{{ route('admin.ventas_asesor.pedidos.index', $verTodos ? ['asesor' => $asesor->admin_id] : []) }}" class="text-sm text-blue-600 hover:underline">@lang('ventasasesor::app.asesores.ver-todos-pedidos')</a>
            </div>

            <div class="va-panel mt-2 overflow-x-auto">
                <table class="va-orders-table text-left text-sm" style="table-layout:auto;min-width:720px">
                    <thead class="bg-slate-50 dark:bg-gray-950">
                        <tr>
                            <th>@lang('ventasasesor::app.pedidos.columnas.pedido-fecha')</th>
                            <th>@lang('ventasasesor::app.pedidos.columnas.cliente')</th>
                            <th class="text-right">@lang('ventasasesor::app.pedidos.columnas.unidades-total')</th>
                            <th>@lang('ventasasesor::app.pedidos.columnas.estado')</th>
                            <th>@lang('ventasasesor::app.pedidos.columnas.pago')</th>
                            <th>@lang('ventasasesor::app.pedidos.columnas.despacho')</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pedidos as $p)
                            <tr data-pedido="{{ $p->id }}">
                                <td>
                                    <p class="va-order-id text-gray-900 dark:text-white">#{{ $p->increment_id }}</p>
                                    <p class="va-sub">{{ $fecha($p->created_at, 'd/m/Y h:i A') }}</p>
                                </td>
                                <td class="text-gray-800 dark:text-gray-200">{{ trim($p->customer_first_name.' '.$p->customer_last_name) ?: '—' }}</td>
                                <td class="text-right">
                                    <p class="va-money--strong text-gray-900 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($p->grand_total) }}</p>
                                    <p class="va-sub">{{ trans('ventasasesor::app.pedidos.unidades', ['cantidad' => (int) $p->total_qty_ordered]) }}</p>
                                </td>
                                <td>{!! $pill($tonoEstado[$p->status] ?? 'gris', trans('admin::app.sales.orders.index.datagrid.'.str_replace('_', '-', $p->status))) !!}</td>
                                <td>{!! $pill($tonoPago[$p->estado_pago] ?? 'gris', trans('ventasasesor::app.pedidos.pago.'.$p->estado_pago)) !!}</td>
                                <td>{!! $pill($tonoDespacho[$p->estado_despacho] ?? 'gris', trans('ventasasesor::app.pedidos.despacho.'.$p->estado_despacho)) !!}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.ventas_asesor.pedidos.index', ['pedido' => $p->id]) }}" class="icon-view inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900" title="@lang('ventasasesor::app.pedidos.ver-detalle')"></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.sin-pedidos')</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Clientes atendidos: clientes con pedidos atribuidos (no hay «dueño» permanente de un cliente) --}}
        <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.seccion-clientes')</p>
        <div class="va-panel mt-2 overflow-x-auto">
            <table class="va-orders-table text-left text-sm" style="table-layout:auto;min-width:560px">
                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        <th>@lang('ventasasesor::app.asesores.cliente')</th>
                        <th class="text-right">@lang('ventasasesor::app.asesores.seccion-pedidos')</th>
                        <th>@lang('ventasasesor::app.asesores.ultimo-pedido')</th>
                        <th class="text-right">@lang('ventasasesor::app.asesores.total-comprado')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clientes as $c)
                        <tr>
                            <td class="font-semibold text-gray-900 dark:text-white">{{ trim($c->nombre) ?: '#'.$c->customer_id }}</td>
                            <td class="text-right text-gray-800 dark:text-gray-200">{{ $c->pedidos }}</td>
                            <td class="text-gray-800 dark:text-gray-200">{{ $fecha($c->ultimo) }}</td>
                            <td class="va-money text-right text-gray-900 dark:text-white">{{ $dinero($c->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.sin-clientes')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Cartera / Pagos (derivado de Cartera; sin acciones de revision aqui) --}}
        <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.asesores.seccion-cartera')</p>
        <div class="mt-2 grid grid-cols-3 gap-3">
            <div class="va-panel va-kpi-box va-kpi-box--saldo"><p class="va-kpi-label">@lang('ventasasesor::app.asesores.saldo')</p><p class="va-kpi-value">{{ $dinero($m['cartera_saldo']) }}</p></div>
            <div class="va-panel va-kpi-box"><p class="va-kpi-label">@lang('ventasasesor::app.cartera.cliente.sin-revisar')</p><p class="va-kpi-value">{{ $dinero($m['cartera_sin_revisar']) }}</p></div>
            <div class="va-panel va-kpi-box"><p class="va-kpi-label">@lang('ventasasesor::app.asesores.verificado')</p><p class="va-kpi-value">{{ $dinero($m['cartera_verificado']) }}</p></div>
        </div>

        <div class="va-panel mt-3 p-4">
            <p class="va-caption">@lang('ventasasesor::app.asesores.pagos-recientes')</p>
            @forelse ($pagos as $pg)
                <a href="{{ route('admin.ventas_asesor.cartera.index', ['pago' => $pg->id]) }}" class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2 text-sm hover:underline dark:border-gray-800">
                    <span class="font-semibold text-gray-900 dark:text-white">#{{ $pg->id }} · {{ trim($pg->first_name.' '.$pg->last_name) }}</span>
                    <span class="flex items-center gap-2">
                        <span class="va-money text-gray-900 dark:text-white">{{ $dinero($pg->monto) }}</span>
                        {!! $pill($tonoPagoCartera[$pg->estado] ?? 'gris', trans('ventasasesor::app.cartera.pagos.estado-'.$pg->estado), 'va-pill--sm') !!}
                        <span class="va-sub">{{ $fecha($pg->created_at) }}</span>
                    </span>
                </a>
            @empty
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.sin-pagos')</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
