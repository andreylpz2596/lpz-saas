@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

@php
    use ByLopez\Logistica\Support\Moneda;

    // Situacion en palabras + color de apoyo (nunca solo el color).
    $colores = ['debe_entregar' => 'amarillo', 'bylopez_paga' => 'azul', 'al_dia' => 'verde'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.cuentas.titulo')
    </x-slot>

    <div class="lg-page" data-vista="cuentas">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.cuentas.titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.cuentas.subtitulo')</p>
        </div>

        @include('logistica::admin.flash')

        <div class="lg-kpis mt-5">
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.cuentas.kpi-con-saldo')</p><p class="lg-kpi-value" data-kpi="con-saldo">{{ $kpis['con_saldo'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.cuentas.kpi-por-entregar')</p><p class="lg-kpi-value" data-kpi="por-entregar">{{ Moneda::formatear($kpis['por_entregar']) }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.cuentas.kpi-por-pagar')</p><p class="lg-kpi-value" data-kpi="por-pagar">{{ Moneda::formatear($kpis['por_pagar']) }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.cuentas.kpi-pendientes')</p><p class="lg-kpi-value" data-kpi="pendientes">{{ $kpis['pendientes'] }}</p></div>
        </div>

        <div data-kpi-revision>
            @if ($kpis['revision'] > 0)
                <div class="lge-banner lge-banner--aviso mt-3">
                    <span class="icon-information"></span>
                    <p class="lge-banner-tit">@lang('logistica::app.cuentas.aviso-revision', ['n' => $kpis['revision']])</p>
                </div>
            @endif
        </div>

        <p class="lg-nota-fina mt-3">@lang('logistica::app.cuentas.convencion')</p>

        @if ($cuentas === [])
            <p class="lg-panel mt-4 p-6 text-center text-sm text-slate-500">@lang('logistica::app.cuentas.sin-cuentas')</p>
        @else
            <div class="lg-panel lg-desktop mt-4 overflow-hidden">
                <table class="lg-table">
                    <thead>
                        <tr>
                            <th>@lang('logistica::app.cuentas.col-transportadora')</th>
                            <th>@lang('logistica::app.cuentas.col-ultimo')</th>
                            <th class="lg-num">@lang('logistica::app.cuentas.col-recaudado')</th>
                            <th class="lg-num">@lang('logistica::app.cuentas.col-servicios')</th>
                            <th class="lg-num">@lang('logistica::app.cuentas.col-saldo')</th>
                            <th>@lang('logistica::app.cuentas.col-situacion')</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cuentas as $c)
                            <tr data-cuenta="{{ $c['id'] }}">
                                <td>
                                    <p class="lgc-nombre">{{ $c['nombre'] }}</p>
                                    @if (! $c['liquida'])
                                        <p class="lg-sub">@lang('logistica::app.cuentas.ya-no-liquida')</p>
                                    @endif
                                </td>
                                <td class="lg-sub">{{ $c['ultimo'] ? \Carbon\Carbon::parse($c['ultimo'])->locale(app()->getLocale())->translatedFormat('j M Y') : trans('logistica::app.cuentas.sin-movimiento') }}</td>
                                <td class="lg-num">{{ Moneda::formatear($c['recaudado']) }}</td>
                                <td class="lg-num">{{ Moneda::formatear($c['servicios']) }}</td>
                                <td class="lg-num"><span class="lgc-monto" data-saldo="{{ $c['saldo']['valor'] }}">{{ $c['saldo']['valor_texto'] }}</span></td>
                                <td>
                                    <div class="lg-estados">
                                        <span class="lg-pill lg-pill--punto lg-pill--{{ $colores[$c['saldo']['sentido']] }}" data-sentido="{{ $c['saldo']['sentido'] }}">{{ $c['saldo']['situacion'] }}</span>
                                        @if ($c['revision'] > 0)
                                            <span class="lg-pill lg-pill--rojo">@lang('logistica::app.cuentas.revision-n', ['n' => $c['revision']])</span>
                                        @endif
                                        @if ($c['pendientes'] > 0)
                                            <span class="lg-sub">@lang('logistica::app.cuentas.pendientes', ['n' => $c['pendientes']])</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right">
                                    <a href="#" class="lg-btn" data-logistica-cuenta data-cuenta-id="{{ $c['id'] }}"><span class="icon-view"></span>@lang('logistica::app.cuentas.ver-cuenta')</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Movil: una tarjeta por transportador (situacion en palabras → saldo grande → pendientes → accion). Sin tablas horizontales. --}}
            <div class="lg-mobile mt-4">
                @foreach ($cuentas as $c)
                    <div class="lg-panel lgc-card" data-cuenta="{{ $c['id'] }}">
                        <p class="lgc-nombre text-base">{{ $c['nombre'] }}</p>
                        <p class="lg-sub" data-sentido="{{ $c['saldo']['sentido'] }}">{{ $c['saldo']['situacion'] }}</p>
                        <p class="lgc-monto lgc-monto--grande">{{ $c['saldo']['valor_texto'] }}</p>
                        <p class="lg-sub">
                            @lang('logistica::app.cuentas.pendientes', ['n' => $c['pendientes']])
                            @if ($c['revision'] > 0)
                                &middot; <span class="lg-pill lg-pill--rojo">@lang('logistica::app.cuentas.revision-n', ['n' => $c['revision']])</span>
                            @endif
                        </p>
                        <a href="#" class="lg-btn lge-principal mt-1" data-logistica-cuenta data-cuenta-id="{{ $c['id'] }}"><span class="icon-view"></span>@lang('logistica::app.cuentas.ver-cuenta')</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Drawer de la cuenta: cuenta actual, revision, liquidar, ajuste e historico sin salir del listado. --}}
    @include('corteconfeccion::admin.partials.moneda')
    @include('logistica::admin.cuentas.componente')
</x-admin::layouts>
