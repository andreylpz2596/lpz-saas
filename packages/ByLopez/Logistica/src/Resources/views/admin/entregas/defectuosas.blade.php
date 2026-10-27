@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.defectuosas.titulo')
    </x-slot>

    <div class="lg-page" data-vista="defectuosas">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.defectuosas.titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.defectuosas.subtitulo')</p>
        </div>

        <div class="mt-4 flex gap-2" data-pestanas>
            <a href="{{ route('admin.logistica.entregas.index') }}" class="lg-btn" data-pestana="entregas">@lang('logistica::app.entregas.titulo')</a>
            <a href="{{ route('admin.logistica.entregas.index', ['vista' => 'defectuosas']) }}" class="lg-btn lg-btn--ok" data-pestana="defectuosas">@lang('logistica::app.defectuosas.pestana')</a>
        </div>

        <div class="lg-kpis mt-5">
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.defectuosas.kpi-pendientes')</p><p class="lg-kpi-value" data-kpi="defectuosas-pendientes">{{ $resumen['pendientes'] }}</p></div>
            @foreach ($resumen['por_motivo'] as $nombre => $n)
                <div class="lg-panel lg-kpi"><p class="lg-kpi-label">{{ $nombre }}</p><p class="lg-kpi-value">{{ $n }}</p></div>
            @endforeach
        </div>

        <p class="lg-nota-fina mt-3" data-aviso-no-vendible>@lang('logistica::app.defectuosas.aviso')</p>

        <form method="GET" action="{{ route('admin.logistica.entregas.index') }}" class="lg-panel lg-filters mt-3 p-4">
            <input type="hidden" name="vista" value="defectuosas">
            <div>
                <label for="d-desde" class="lg-caption">@lang('logistica::app.defectuosas.desde')</label>
                <input id="d-desde" type="date" name="desde" value="{{ $filtros['desde'] }}" class="lg-input">
            </div>
            <div>
                <label for="d-hasta" class="lg-caption">@lang('logistica::app.defectuosas.hasta')</label>
                <input id="d-hasta" type="date" name="hasta" value="{{ $filtros['hasta'] }}" class="lg-input">
            </div>
            <div>
                <label for="d-motivo" class="lg-caption">@lang('logistica::app.defectuosas.motivo')</label>
                <select id="d-motivo" name="motivo" class="lg-input">
                    <option value="">@lang('logistica::app.defectuosas.todos')</option>
                    @foreach ($catalogos['motivos'] as $m)
                        <option value="{{ $m['codigo'] }}" @selected($filtros['motivo'] === $m['codigo'])>{{ $m['nombre'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="d-origen" class="lg-caption">@lang('logistica::app.defectuosas.origen')</label>
                <select id="d-origen" name="origen" class="lg-input">
                    <option value="">@lang('logistica::app.defectuosas.todos')</option>
                    @foreach ($catalogos['origenes'] as $o)
                        <option value="{{ $o['codigo'] }}" @selected($filtros['origen'] === $o['codigo'])>{{ $o['nombre'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="d-estado" class="lg-caption">@lang('logistica::app.defectuosas.estado')</label>
                <select id="d-estado" name="estado" class="lg-input">
                    <option value="">@lang('logistica::app.defectuosas.todos')</option>
                    @foreach (['pendiente_revision', 'recuperada', 'perdida'] as $e)
                        <option value="{{ $e }}" @selected($filtros['estado'] === $e)>@lang('logistica::app.defectuosas.estados.'.$e)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="lg-btn lg-btn--ok">@lang('logistica::app.entregas.filtrar')</button>
                <a href="{{ route('admin.logistica.entregas.index', ['vista' => 'defectuosas']) }}" class="lg-btn">@lang('logistica::app.entregas.limpiar')</a>
            </div>
        </form>

        @if ($unidades->isEmpty())
            <p class="lg-panel mt-4 p-6 text-center text-sm text-slate-500" data-sin-defectuosas>@lang('logistica::app.defectuosas.vacio')</p>
        @else
            <div class="lg-panel mt-4 overflow-x-auto">
                <table class="lg-table" data-tabla-defectuosas>
                    <thead>
                        <tr>
                            <th>@lang('logistica::app.defectuosas.col-fecha')</th>
                            <th>@lang('logistica::app.defectuosas.col-pedido')</th>
                            <th>@lang('logistica::app.defectuosas.col-producto')</th>
                            <th class="text-right">@lang('logistica::app.defectuosas.col-cantidad')</th>
                            <th>@lang('logistica::app.defectuosas.col-motivo')</th>
                            <th>@lang('logistica::app.defectuosas.col-origen')</th>
                            <th>@lang('logistica::app.defectuosas.col-estado')</th>
                            <th>@lang('logistica::app.defectuosas.col-recibio')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unidades as $u)
                            <tr data-defectuosa="{{ $u->id }}">
                                <td>{{ \Illuminate\Support\Carbon::parse($u->created_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('admin.logistica.entregas.index', ['envio' => $u->shipment_id]) }}" class="text-blue-600 hover:underline">{{ $u->increment_id }}</a>
                                    <p class="lg-sub">@lang('logistica::app.defectuosas.devolucion-recepcion', ['retorno' => $u->retorno_id, 'recepcion' => $u->recepcion_id])</p>
                                </td>
                                <td>
                                    {{ $u->producto }}
                                    <p class="lg-sub">{{ $u->sku_variante }}@if ($u->color) &middot; {{ $u->color }}@endif @if ($u->talla) &middot; {{ $u->talla }}@endif</p>
                                </td>
                                <td class="text-right font-semibold">{{ $u->cantidad }}</td>
                                <td>
                                    {{ $u->motivo }}
                                    @if ($u->detalle)<p class="lg-sub">{{ $u->detalle }}</p>@endif
                                    @if ($u->observacion)<p class="lg-sub">{{ $u->observacion }}</p>@endif
                                </td>
                                <td>{{ $u->origen }}</td>
                                <td><span class="lg-pill lg-pill--amarillo">@lang('logistica::app.defectuosas.estados.'.$u->estado)</span></td>
                                <td>{{ $u->actor_name_snapshot }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $unidades->links() }}</div>
        @endif
    </div>
</x-admin::layouts>