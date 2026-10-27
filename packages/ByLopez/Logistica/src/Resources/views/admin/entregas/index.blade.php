@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.entregas.titulo')
    </x-slot>

    <div class="lg-page" data-vista="entregas">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.entregas.titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.entregas.subtitulo')</p>
        </div>

        @include('logistica::admin.flash')

        <div class="mt-4 flex gap-2" data-pestanas>
            <a href="{{ route('admin.logistica.entregas.index') }}" class="lg-btn lg-btn--ok" data-pestana="entregas">@lang('logistica::app.entregas.titulo')</a>
            <a href="{{ route('admin.logistica.entregas.index', ['vista' => 'defectuosas']) }}" class="lg-btn" data-pestana="defectuosas">@lang('logistica::app.defectuosas.pestana')</a>
        </div>

        {{-- Movil: los indicadores se pueden compactar para llegar antes a los pedidos. Compactado siguen visibles los que piden accion
             (por recibir y por validar); el resto se muestra con «Ver todos». Sin JavaScript: checkbox + CSS (estado inicial: compacto). --}}
        <div class="lg-kpis-wrap mt-5">
            <input type="checkbox" id="lg-kpis-compacto" class="lg-kpis-toggle" checked>
            <label for="lg-kpis-compacto" class="lg-kpis-btn lg-btn" data-logistica="kpis-alternar">
                <span class="lg-kpis-btn-ver">@lang('logistica::app.entregas.kpi-ver-todos')</span>
                <span class="lg-kpis-btn-ocultar">@lang('logistica::app.entregas.kpi-compactar')</span>
            </label>
        <div class="lg-kpis lg-kpis--6">
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.entregas.kpi-envios-pendientes')</p><p class="lg-kpi-value" data-kpi="envios-pendientes">{{ $indicadores['envios_pendientes'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.entregas.kpi-unidades-pendientes')</p><p class="lg-kpi-value" data-kpi="unidades-pendientes">{{ $indicadores['unidades_pendientes'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.entregas.kpi-entregas-30d')</p><p class="lg-kpi-value" data-kpi="entregas-30d">{{ $indicadores['entregas_30d'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.entregas.kpi-retornos-30d')</p><p class="lg-kpi-value" data-kpi="retornos-30d">{{ $indicadores['retornos_30d'] }}</p></div>
            <div class="lg-panel lg-kpi lg-kpi--atencion"><p class="lg-kpi-label">@lang('logistica::app.drawer.lista.kpi_por_recibir')</p><p class="lg-kpi-value" data-kpi="devoluciones-por-recibir">{{ $indicadores['devoluciones_por_recibir'] }}</p></div>
            <a href="{{ route('admin.logistica.entregas.index', ['estado' => 'por_validar']) }}" class="lg-panel lg-kpi lg-kpi--atencion"><p class="lg-kpi-label">@lang('logistica::app.drawer.lista.kpi_por_validar')</p><p class="lg-kpi-value" data-kpi="por-validar">{{ $indicadores['por_validar'] }}</p></a>
        </div>
        </div>

        <p class="lg-nota-fina mt-3" data-aviso-despacho>@lang('logistica::app.entregas.aviso-despacho')</p>

        <form method="GET" action="{{ route('admin.logistica.entregas.index') }}" class="lg-panel lg-filters mt-3 p-4">
            <div>
                <label for="f-q" class="lg-caption">@lang('logistica::app.entregas.filtro-buscar')</label>
                <input id="f-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" placeholder="{{ trans('logistica::app.entregas.filtro-buscar-placeholder') }}" class="lg-input">
            </div>
            <div>
                <label for="f-estado" class="lg-caption">@lang('logistica::app.entregas.filtro-estado')</label>
                <select id="f-estado" name="estado" class="lg-input">
                    @foreach (['pendientes', 'sin_entrega', 'por_validar', 'entregados', 'retornados', 'todos'] as $e)
                        <option value="{{ $e }}" @selected($filtros['estado'] === $e)>@lang('logistica::app.entregas.estado-'.$e)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                @if ($filtros['pedido'])
                    <input type="hidden" name="pedido" value="{{ $filtros['pedido'] }}">
                @endif
                <button type="submit" class="lg-btn lg-btn--ok">@lang('logistica::app.entregas.filtrar')</button>
                <a href="{{ route('admin.logistica.entregas.index') }}" class="lg-btn">@lang('logistica::app.entregas.limpiar')</a>
            </div>
        </form>

        @if ($filtros['pedido'])
            <p class="mt-3"><span class="lg-pill lg-pill--azul">@lang('logistica::app.entregas.pedido-filtrado', ['pedido' => $filtros['pedido']])</span></p>
        @endif

        @if ($envios->isEmpty())
            <p class="lg-panel mt-4 p-6 text-center text-sm text-slate-500">@lang('logistica::app.entregas.sin-envios')</p>
        @else
            {{-- LISTADO = control operativo rapido: sin tallas, SKU, precios ni direccion. Todo eso esta en «Ver». --}}
            <div class="lg-panel lg-desktop mt-4 overflow-hidden">
                <table class="lg-table">
                    <thead>
                        <tr>
                            <th>@lang('logistica::app.entregas.col-pedido')</th>
                            <th>@lang('logistica::app.entregas.producto')</th>
                            <th>@lang('logistica::app.entregas.col-transportadora')</th>
                            <th>@lang('logistica::app.entregas.lbl-entrega') / @lang('logistica::app.entregas.lbl-validacion')</th>
                            <th style="min-width: 220px">@lang('logistica::app.entregas.col-despachadas') · @lang('logistica::app.entregas.col-entregadas') · @lang('logistica::app.entregas.col-retornadas') · @lang('logistica::app.entregas.col-pendientes')</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($envios as $e)
                            <tr data-envio="{{ $e->shipment_id }}">
                                <td>
                                    <p class="font-bold text-gray-900 dark:text-white">#{{ $e->increment_id }} <span class="lg-sub">· @lang('logistica::app.entregas.col-envio') #{{ $e->shipment_id }}</span></p>
                                    <p class="lg-sub">{{ $e->cliente }}</p>
                                </td>
                                <td>@include('logistica::admin.entregas.tipo-producto', ['tipo' => $e->tipo_producto])</td>
                                <td>
                                    @include('logistica::admin.entregas.transportadora', ['codigo' => $e->carrier_code, 'nombre' => $nombres[$e->carrier_code] ?? null, 'titulo' => $e->carrier_title])
                                    <p class="lg-sub">{{ $e->track_number ?: trans('logistica::app.entregas.sin-guia') }}</p>
                                </td>
                                <td>
                                    <div class="lg-estados">
                                        <div class="lg-estado">@include('logistica::admin.entregas.estado-entrega', ['estado' => $e->estado_entrega])</div>
                                        <div class="lg-estado">@include('logistica::admin.entregas.estado-validacion', ['estado' => $e->validacion])</div>
                                        @if ($e->devolucion)
                                            <div class="lg-estado">@include('logistica::admin.entregas.estado-devolucion', ['estado' => $e->devolucion])</div>
                                        @endif
                                        @include('logistica::admin.entregas.reporte-proveedor', ['r' => $e->reporte_proveedor ?? null])
                                    </div>
                                </td>
                                <td>@include('logistica::admin.entregas.cifras', ['despachadas' => $e->despachadas, 'entregadas' => $e->entregadas, 'retornadas' => $e->retornadas, 'pendientes' => $e->pendientes])</td>
                                <td>
                                    @include('logistica::admin.entregas.acciones', ['e' => $e])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Movil: una tarjeta por envio, en el orden pedido → cliente → transportadora → producto → estados → cifras → acciones. --}}
            <div class="lg-mobile mt-4">
                @foreach ($envios as $e)
                    <div class="lg-panel p-4" data-envio="{{ $e->shipment_id }}">
                        <p class="font-bold text-gray-900 dark:text-white">#{{ $e->increment_id }} · @lang('logistica::app.entregas.col-envio') #{{ $e->shipment_id }}</p>
                        <p class="lg-sub">{{ $e->cliente }}</p>

                        <p class="mt-2 text-sm">
                            @include('logistica::admin.entregas.transportadora', ['codigo' => $e->carrier_code, 'nombre' => $nombres[$e->carrier_code] ?? null, 'titulo' => $e->carrier_title])
                        </p>

                        <p class="mt-2 text-sm">@include('logistica::admin.entregas.tipo-producto', ['tipo' => $e->tipo_producto])</p>

                        <div class="lg-estados mt-3">
                            <div class="lg-estado"><span class="lg-estado-tit">@lang('logistica::app.entregas.lbl-entrega')</span>@include('logistica::admin.entregas.estado-entrega', ['estado' => $e->estado_entrega])</div>
                            <div class="lg-estado"><span class="lg-estado-tit">ByLopez</span>@include('logistica::admin.entregas.estado-validacion', ['estado' => $e->validacion])</div>
                            @if ($e->devolucion)
                                <div class="lg-estado"><span class="lg-estado-tit">@lang('logistica::app.entregas.lbl-devolucion')</span>@include('logistica::admin.entregas.estado-devolucion', ['estado' => $e->devolucion])</div>
                            @endif
                            @include('logistica::admin.entregas.reporte-proveedor', ['r' => $e->reporte_proveedor ?? null])
                        </div>

                        <div class="mt-3 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                            @include('logistica::admin.entregas.cifras', ['despachadas' => $e->despachadas, 'entregadas' => $e->entregadas, 'retornadas' => $e->retornadas, 'pendientes' => $e->pendientes])
                        </div>

                        <div class="mt-3">
                            @include('logistica::admin.entregas.acciones', ['e' => $e, 'movil' => true])
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $envios->links() }}</div>
        @endif
    </div>

    {{-- Drawer unico: Ver / Registrar entrega / Devolucion / Recibir devolucion sin salir de la bandeja. --}}
    @include('logistica::admin.entregas.componente')
</x-admin::layouts>