@php
    $tonoEstado = ['sin_despachar' => 'amarillo', 'parcial' => 'azul', 'despachado' => 'verde'];
    $nombreCliente = fn (object $p) => trim($p->customer_first_name.' '.$p->customer_last_name);
    $moneda = fn (float $v) => \ByLopez\Logistica\Support\Moneda::formatear($v);

    // Primera guia del pedido (suficiente para operar) y cuantas mas hay.
    $guiaDe = fn (object $p) => $p->guias[0] ?? null;
@endphp

@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.despachos.titulo')
    </x-slot>

    <div class="lg-page" data-vista="despachos">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.despachos.titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.despachos.subtitulo')</p>
        </div>

        @include('logistica::admin.flash')

        @if ($puedeVerIncidencias)
            {{-- Pestanas: bandeja de despachos / incidencias de stock (con el contador de abiertas). --}}
            <div class="mt-4 flex gap-2" data-pestanas>
                <a href="{{ route('admin.logistica.despachos.index') }}" class="lg-btn {{ $vista === 'pedidos' ? 'lg-btn--ok' : '' }}" data-pestana="pedidos">@lang('logistica::app.despachos.titulo')</a>
                <a href="{{ route('admin.logistica.despachos.index', ['vista' => 'incidencias']) }}" class="lg-btn {{ $vista === 'incidencias' ? 'lg-btn--ok' : '' }}" data-pestana="incidencias">
                    @lang('logistica::app.despachos.incidencias.pestana')
                    @if ($incidenciasAbiertas > 0)
                        <span class="lg-pill lg-pill--amarillo" data-kpi="incidencias-abiertas">{{ $incidenciasAbiertas }}</span>
                    @endif
                </a>
            </div>
        @endif

        @if ($vista === 'incidencias')
            @include('logistica::admin.despachos.incidencias')
        @else

        <div class="lg-kpis lg-kpis--3 mt-5">
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.despachos.kpi-sin-despachar')</p><p class="lg-kpi-value" data-kpi="sin-despachar">{{ $indicadores['sin_despachar'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.despachos.kpi-parcial')</p><p class="lg-kpi-value" data-kpi="parcial">{{ $indicadores['parcial'] }}</p></div>
            <div class="lg-panel lg-kpi"><p class="lg-kpi-label">@lang('logistica::app.despachos.kpi-hoy')</p><p class="lg-kpi-value" data-kpi="hoy">{{ $indicadores['despachados_hoy'] }}</p></div>
        </div>

        <form method="GET" action="{{ route('admin.logistica.despachos.index') }}" class="lg-panel lg-filters--4 mt-4 p-4" data-filtros>
            <div>
                <label for="d-q" class="lg-caption">@lang('logistica::app.despachos.filtro-buscar')</label>
                <input id="d-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" placeholder="{{ trans('logistica::app.despachos.filtro-buscar-ph') }}" class="lg-input">
            </div>
            <div>
                <label for="d-estado" class="lg-caption">@lang('logistica::app.despachos.filtro-estado')</label>
                <select id="d-estado" name="estado" class="lg-input">
                    @foreach (\ByLopez\Logistica\Services\Despachos\ConsultaDespachoService::ESTADOS as $e)
                        <option value="{{ $e }}" @selected($filtros['estado'] === $e)>@lang('logistica::app.despachos.estados.'.$e)</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="d-tipo" class="lg-caption">@lang('logistica::app.despachos.filtro-tipo')</label>
                <select id="d-tipo" name="tipo" class="lg-input">
                    @foreach (\ByLopez\Logistica\Services\Despachos\ConsultaDespachoService::TIPOS as $t)
                        <option value="{{ $t }}" @selected($filtros['tipo'] === $t)>@lang('logistica::app.despachos.tipos.'.$t)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="lg-btn lg-btn--ok">@lang('logistica::app.despachos.filtrar')</button>
                <a href="{{ route('admin.logistica.despachos.index') }}" class="lg-btn">@lang('logistica::app.despachos.limpiar')</a>
            </div>
        </form>

        @if ($pedidos->isEmpty())
            <p class="lg-panel mt-4 p-6 text-center text-sm text-slate-500">@lang('logistica::app.despachos.sin-resultados')</p>
        @else
            {{-- Escritorio: Pedido/Cliente · Destino · Tipo de entrega · Estado · Transportadora/Guia · Accion --}}
            <div class="lg-panel lg-desktop mt-4 overflow-hidden">
                <table class="lg-table">
                    <thead>
                        <tr>
                            <th>@lang('logistica::app.despachos.col-pedido')</th>
                            <th>@lang('logistica::app.despachos.col-destino')</th>
                            <th>@lang('logistica::app.despachos.col-entrega')</th>
                            <th>@lang('logistica::app.despachos.col-estado')</th>
                            <th>@lang('logistica::app.despachos.col-guia')</th>
                            <th class="lg-num">@lang('logistica::app.despachos.col-accion')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidos as $p)
                            @php($g = $guiaDe($p))
                            <tr data-pedido="{{ $p->id }}">
                                <td>
                                    <p class="font-bold text-gray-900 dark:text-white">#{{ $p->increment_id }}</p>
                                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $nombreCliente($p) ?: '-' }}</p>
                                    <p class="lg-sub">{{ $p->telefono ?: '—' }}</p>
                                </td>
                                <td>
                                    <p class="font-medium text-gray-800 dark:text-gray-200">{{ $p->ciudad }}</p>
                                    <p class="lg-sub lg-clamp" title="{{ $p->direccion }}">{{ $p->direccion }}</p>
                                </td>
                                <td>
                                    <p class="text-sm">{{ $p->shipping_title ?: $p->shipping_method }}</p>
                                    @if ($p->es_contraentrega)
                                        <span class="lg-pill lg-pill--amarillo" data-contraentrega>@lang('logistica::app.despachos.contraentrega')</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="lg-pill lg-pill--{{ $tonoEstado[$p->estado_despacho] }}" data-estado="{{ $p->estado_despacho }}">@lang('logistica::app.despachos.estados.'.$p->estado_despacho)</span>
                                    @if ($p->estado_despacho !== 'sin_despachar')
                                        <p class="lg-sub">@lang('logistica::app.despachos.entregadas', ['entregadas' => $p->entrega['entregadas'], 'despachadas' => $p->entrega['despachadas']])</p>
                                    @endif
                                </td>
                                <td>
                                    @if ($g)
                                        <p class="font-semibold text-gray-900 dark:text-white" data-guia>{{ $g['transportadora'] }} &middot; {{ $g['numero_guia'] }}</p>
                                        @if (count($p->guias) > 1)
                                            <p class="lg-sub">+{{ count($p->guias) - 1 }}</p>
                                        @endif
                                        @if ($g['recaudo_esperado'] > 0)
                                            <p class="lg-sub" data-recaudo>@lang('logistica::app.despachos.por-recaudar', ['monto' => $moneda($g['recaudo_esperado'])])</p>
                                        @endif
                                    @elseif ($p->es_recogida && $p->estado_despacho === 'despachado')
                                        <span class="lg-sub">@lang('logistica::app.despachos.tipos.recogida')</span>
                                    @else
                                        <span class="lg-sub">@lang('logistica::app.despachos.sin-guia')</span>
                                    @endif
                                </td>
                                <td>@include('logistica::admin.despachos.acciones')</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Tablet y movil: tarjetas con lo mismo y la accion al pie --}}
            <div class="lg-mobile mt-4">
                @foreach ($pedidos as $p)
                    @php($g = $guiaDe($p))
                    <div class="lg-panel p-4" data-pedido="{{ $p->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-bold text-gray-900 dark:text-white">#{{ $p->increment_id }}</p>
                                <p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $nombreCliente($p) ?: '-' }}</p>
                                <p class="lg-sub">{{ $p->telefono ?: '—' }}</p>
                            </div>
                            <span class="lg-pill lg-pill--{{ $tonoEstado[$p->estado_despacho] }}" data-estado="{{ $p->estado_despacho }}">@lang('logistica::app.despachos.estados.'.$p->estado_despacho)</span>
                        </div>

                        <div class="mt-3 grid gap-1 rounded-lg bg-slate-50 p-3 text-sm dark:bg-gray-950">
                            <p><span class="lg-caption">@lang('logistica::app.despachos.col-destino')</span><br>{{ $p->ciudad }} &middot; {{ $p->direccion }}</p>
                            <p><span class="lg-caption">@lang('logistica::app.despachos.col-entrega')</span><br>{{ $p->shipping_title ?: $p->shipping_method }}
                                @if ($p->es_contraentrega)
                                    <span class="lg-pill lg-pill--amarillo">@lang('logistica::app.despachos.contraentrega')</span>
                                @endif
                            </p>
                            <p><span class="lg-caption">@lang('logistica::app.despachos.col-guia')</span><br>
                                @if ($g)
                                    <strong>{{ $g['transportadora'] }} &middot; {{ $g['numero_guia'] }}</strong>
                                    @if ($g['recaudo_esperado'] > 0)
                                        <br><span class="lg-sub">@lang('logistica::app.despachos.por-recaudar', ['monto' => $moneda($g['recaudo_esperado'])])</span>
                                    @endif
                                @else
                                    <span class="lg-sub">@lang('logistica::app.despachos.sin-guia')</span>
                                @endif
                            </p>
                        </div>

                        <div class="mt-3">@include('logistica::admin.despachos.acciones')</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $pedidos->links() }}</div>
        @endif
        @endif

        {{-- El MISMO componente que usa el listado de Pedidos del Administrador. --}}
        @include('logistica::admin.despachos.componente')

        {{-- Mensaje de WhatsApp del pedido (mismo contrato y componente en Pedidos y Entregas). --}}
        @include('logistica::admin.mensajes.whatsapp', [
            'urlOpciones' => route('admin.logistica.despachos.mensajes.opciones', ['pedido' => '__ID__']),
            'urlMensaje' => route('admin.logistica.despachos.mensajes.mensaje', ['pedido' => '__ID__', 'clave' => '__CLAVE__']),
        ])
    </div>
</x-admin::layouts>
