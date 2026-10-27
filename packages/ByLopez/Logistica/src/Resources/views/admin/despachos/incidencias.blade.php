{{--
    Pestana «Incidencias de stock» de Logistica › Despachos. Variables: $incidencias (paginador), $filtrosIncidencias.
    «Revisar» abre el MISMO drawer del pedido: alli BODEGA ve que esta pendiente y el Administrador regulariza o rechaza.
--}}
@php
    $tono = ['solicitada' => 'amarillo', 'regularizada' => 'verde', 'rechazada' => 'rojo'];
@endphp

<div class="mt-4 flex flex-wrap items-center gap-2" data-incidencias-filtros>
    <a href="{{ route('admin.logistica.despachos.index', ['vista' => 'incidencias']) }}" class="lg-btn {{ $filtrosIncidencias['estado'] === 'abiertas' ? 'lg-btn--ok' : '' }}">@lang('logistica::app.despachos.incidencias.abiertas')</a>
    <a href="{{ route('admin.logistica.despachos.index', ['vista' => 'incidencias', 'estado' => 'todas']) }}" class="lg-btn {{ $filtrosIncidencias['estado'] === 'todas' ? 'lg-btn--ok' : '' }}">@lang('logistica::app.despachos.incidencias.historial')</a>
</div>

@if ($incidencias->isEmpty())
    <p class="lg-panel mt-4 p-6 text-center text-sm text-slate-500" data-incidencias-vacio>@lang('logistica::app.despachos.incidencias.vacio')</p>
@else
    <div class="lg-panel mt-4 overflow-hidden">
        <table class="lg-table" data-incidencias>
            <thead>
                <tr>
                    <th>@lang('logistica::app.despachos.incidencias.col-pedido')</th>
                    <th>@lang('logistica::app.despachos.incidencias.col-solicitud')</th>
                    <th>@lang('logistica::app.despachos.incidencias.col-lineas')</th>
                    <th>@lang('logistica::app.despachos.incidencias.col-estado')</th>
                    <th class="lg-num">@lang('logistica::app.despachos.col-accion')</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($incidencias as $i)
                    <tr data-pedido="{{ $i->order_id }}" data-incidencia="{{ $i->id }}">
                        <td>
                            <p class="font-bold text-gray-900 dark:text-white">#{{ $i->increment_id }}</p>
                            <p class="text-sm text-gray-800 dark:text-gray-200">{{ $i->cliente ?: '-' }}</p>
                        </td>
                        <td>
                            <p class="text-sm">@lang('logistica::app.despachos.incidencias.por', ['quien' => $i->solicitante_name_snapshot]) &middot; {{ $i->created_at?->format('Y-m-d H:i') }}</p>
                            <p class="lg-sub lg-clamp" title="{{ $i->nota_solicitud }}">{{ $i->nota_solicitud }}</p>
                        </td>
                        <td>
                            @foreach ($i->lineas as $l)
                                <p class="text-sm"><strong>{{ $l->sku_snapshot }}</strong> <span class="lg-sub">@lang('logistica::app.despachos.incidencias.sistema-contado', ['sistema' => $l->stock_sistema_solicitud, 'contado' => $l->conteo_declarado])</span></p>
                            @endforeach
                        </td>
                        <td>
                            <span class="lg-pill lg-pill--{{ $tono[$i->estado->value] }}" data-estado="{{ $i->estado->value }}">@lang('logistica::app.despachos.incidencias.estados.'.$i->estado->value)</span>
                            @if (! $i->abiertaAun())
                                <p class="lg-sub">@lang('logistica::app.despachos.incidencias.por', ['quien' => $i->resuelta_por_name_snapshot]) &middot; {{ $i->resuelta_en?->format('Y-m-d H:i') }}</p>
                                <p class="lg-sub lg-clamp" title="{{ $i->motivo_resolucion }}">{{ $i->motivo_resolucion }}</p>
                            @endif
                        </td>
                        <td>
                            <div class="flex justify-end">
                                <button type="button" class="{{ $i->abiertaAun() ? 'primary-button' : 'secondary-button' }}" data-logistica-despacho data-pedido="{{ $i->order_id }}" @if (! $i->abiertaAun()) data-modo="ver" @endif data-accion="incidencia">
                                    @lang($i->abiertaAun() ? 'logistica::app.despachos.incidencias.revisar' : 'logistica::app.despachos.incidencias.ver')
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $incidencias->links() }}</div>
@endif
