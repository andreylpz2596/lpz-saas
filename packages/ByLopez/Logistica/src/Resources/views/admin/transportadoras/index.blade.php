@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.transportadoras.titulo')
    </x-slot>

    <div class="lg-page" data-vista="transportadoras">
        <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.transportadoras.titulo')</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.transportadoras.subtitulo')</p>

        @include('logistica::admin.flash')

        <p class="lg-panel mt-4 p-3 text-sm text-slate-700 dark:text-gray-300">@lang('logistica::app.transportadoras.despacho-ayuda')</p>

        <div class="lg-panel mt-4 overflow-x-auto">
            <table class="lg-table">
                <thead>
                    <tr>
                        <th>@lang('logistica::app.transportadoras.col-codigo')</th>
                        <th>@lang('logistica::app.transportadoras.col-nombre')</th>
                        <th>@lang('logistica::app.transportadoras.col-tipo')</th>
                        <th>@lang('logistica::app.transportadoras.col-guia')</th>
                        <th>@lang('logistica::app.transportadoras.col-capacidades')</th>
                        <th>@lang('logistica::app.transportadoras.col-estado')</th>
                        @if ($puedeGestionar)
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transportadoras as $t)
                        <tr data-transportadora="{{ $t->codigo }}">
                            <td class="font-mono font-semibold">{{ $t->codigo }}</td>
                            <td>{{ $t->nombre }}</td>
                            <td>@lang('logistica::app.tipos.'.$t->tipo->value)</td>
                            <td data-modo-guia="{{ $t->genera_guia_interna ? 'interna' : 'externa' }}">
                                <span class="lg-pill {{ $t->genera_guia_interna ? 'lg-pill--azul' : 'lg-pill--gris' }}">@lang('logistica::app.transportadoras.modo-'.($t->genera_guia_interna ? 'interna' : 'externa'))</span>
                            </td>
                            <td data-capacidades>
                                @if ($puedeGestionar)
                                    <form method="POST" action="{{ route('admin.logistica.transportadoras.capacidades', $t->id) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                        @csrf
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="liquida_cuenta_corriente" value="1" @checked($t->liquida_cuenta_corriente)> @lang('logistica::app.transportadoras.cap-cuenta')</label>
                                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1" role="radiogroup" aria-label="@lang('logistica::app.transportadoras.col-guia')">
                                            <label class="flex items-center gap-1 text-xs"><input type="radio" name="modo_guia" value="externa" @checked(! $t->genera_guia_interna)> @lang('logistica::app.transportadoras.modo-externa')</label>
                                            <label class="flex items-center gap-1 text-xs"><input type="radio" name="modo_guia" value="interna" @checked($t->genera_guia_interna)> @lang('logistica::app.transportadoras.modo-interna')</label>
                                        </span>
                                        <button type="submit" class="lg-btn">@lang('logistica::app.transportadoras.cap-guardar')</button>
                                    </form>
                                @else
                                    <span class="text-xs">{{ $t->liquida_cuenta_corriente ? trans('logistica::app.transportadoras.cap-cuenta') : '—' }}</span>
                                @endif
                            </td>                            <td><span class="lg-pill {{ $t->activo ? 'lg-pill--verde' : 'lg-pill--gris' }}">@lang('logistica::app.transportadoras.'.($t->activo ? 'activa' : 'inactiva'))</span></td>
                            @if ($puedeGestionar)
                                <td class="text-right">
                                    <form method="POST" action="{{ route('admin.logistica.transportadoras.estado', $t->id) }}">
                                        @csrf
                                        <input type="hidden" name="activo" value="{{ $t->activo ? 0 : 1 }}">
                                        <button type="submit" class="lg-btn">@lang('logistica::app.transportadoras.'.($t->activo ? 'desactivar' : 'activar'))</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($puedeGestionar)
            <form method="POST" action="{{ route('admin.logistica.transportadoras.store') }}" class="lg-panel mt-4 p-4">
                @csrf
                <p class="font-bold text-gray-900 dark:text-white">@lang('logistica::app.transportadoras.nueva')</p>
                <div class="lg-filters mt-3">
                    <div>
                        <label for="t-codigo" class="lg-caption">@lang('logistica::app.transportadoras.col-codigo')</label>
                        <input id="t-codigo" type="text" name="codigo" required maxlength="40" pattern="[A-Za-z0-9_]{2,40}" value="{{ old('codigo') }}" class="lg-input">
                        <p class="lg-sub mt-1">@lang('logistica::app.transportadoras.codigo-ayuda')</p>
                    </div>
                    <div>
                        <label for="t-nombre" class="lg-caption">@lang('logistica::app.transportadoras.col-nombre')</label>
                        <input id="t-nombre" type="text" name="nombre" required maxlength="120" value="{{ old('nombre') }}" class="lg-input">
                        <label for="t-tipo" class="lg-caption mt-2 block">@lang('logistica::app.transportadoras.col-tipo')</label>
                        <select id="t-tipo" name="tipo" class="lg-input">
                            @foreach ($tipos as $tipo)
                                <option value="{{ $tipo->value }}" @selected(old('tipo') === $tipo->value)>@lang('logistica::app.tipos.'.$tipo->value)</option>
                            @endforeach
                        </select>
                        <label class="mt-2 flex items-center gap-1 text-xs"><input type="checkbox" name="liquida_cuenta_corriente" value="1" @checked(old('liquida_cuenta_corriente'))> @lang('logistica::app.transportadoras.cap-cuenta')</label>
                        <p class="lg-caption mt-2">@lang('logistica::app.transportadoras.col-guia')</p>
                        <div role="radiogroup" aria-label="@lang('logistica::app.transportadoras.col-guia')">
                            <label class="flex items-center gap-1 text-xs"><input type="radio" name="modo_guia" value="externa" @checked(old('modo_guia', 'externa') !== 'interna')> @lang('logistica::app.transportadoras.modo-externa')</label>
                            <label class="flex items-center gap-1 text-xs"><input type="radio" name="modo_guia" value="interna" @checked(old('modo_guia') === 'interna')> @lang('logistica::app.transportadoras.modo-interna')</label>
                        </div>
                        <p class="lg-sub mt-1">@lang('logistica::app.transportadoras.modo-ayuda')</p>                    </div>
                    <div><button type="submit" class="lg-btn lg-btn--ok">@lang('logistica::app.transportadoras.crear')</button></div>
                </div>
            </form>
        @endif

        <h2 class="mt-6 text-base font-bold text-gray-900 dark:text-white">@lang('logistica::app.transportadoras.tarifas-titulo')</h2>
        <p class="lg-sub">@lang('logistica::app.transportadoras.tarifas-ayuda')</p>
        <p class="lg-sub">@lang('logistica::app.transportadoras.capacidades-ayuda')</p>
        <div class="lg-panel mt-2 overflow-x-auto">
            <table class="lg-table" data-tarifas>
                <thead>
                    <tr>
                        <th>@lang('logistica::app.transportadoras.col-nombre')</th>
                        <th>@lang('logistica::app.transportadoras.col-zona')</th>
                        <th class="lg-num">@lang('logistica::app.transportadoras.col-valor')</th>
                        <th>@lang('logistica::app.transportadoras.col-vigente-desde')</th>
                        <th>@lang('logistica::app.transportadoras.col-estado')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tarifas as $tf)
                        <tr data-tarifa="{{ $tf->id }}">
                            <td>{{ $tf->transportadora }}</td>
                            <td>{{ $tf->zona }}</td>
                            <td class="lg-num">{{ \ByLopez\Logistica\Support\Moneda::formatear($tf->valor) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($tf->vigente_desde)->format('d/m/Y') }}</td>
                            <td><span class="lg-pill {{ $tf->vigente ? 'lg-pill--verde' : 'lg-pill--gris' }}">@lang('logistica::app.transportadoras.'.($tf->vigente ? 'vigente' : 'historica'))</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="lg-sub">@lang('logistica::app.transportadoras.sin-tarifas')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($puedeGestionar && $zonas->isNotEmpty())
            <form method="POST" action="{{ route('admin.logistica.tarifas.store') }}" class="lg-panel mt-3 p-4" data-nueva-tarifa>
                @csrf
                <p class="font-bold text-gray-900 dark:text-white">@lang('logistica::app.transportadoras.nueva-tarifa')</p>
                <div class="lg-filters--4 mt-3">
                    <select name="transportadora_id" class="lg-input" aria-label="@lang('logistica::app.transportadoras.col-nombre')">
                        @foreach ($transportadoras->where('activo', true) as $t)
                            <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                        @endforeach
                    </select>
                    <select name="zona_id" class="lg-input" aria-label="@lang('logistica::app.transportadoras.col-zona')">
                        @foreach ($zonas as $z)
                            <option value="{{ $z->id }}">{{ $z->nombre }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="valor" min="0" step="1" required class="lg-input" placeholder="@lang('logistica::app.transportadoras.col-valor')">
                    <input type="date" name="vigente_desde" value="{{ now()->toDateString() }}" class="lg-input" aria-label="@lang('logistica::app.transportadoras.col-vigente-desde')">
                </div>
                <button type="submit" class="lg-btn lg-btn--ok mt-3">@lang('logistica::app.transportadoras.crear-tarifa')</button>
            </form>
        @endif

        <h2 class="mt-6 text-base font-bold text-gray-900 dark:text-white">@lang('logistica::app.transportadoras.historicos-titulo')</h2>
        <p class="lg-sub">@lang('logistica::app.transportadoras.historicos-ayuda')</p>
        <div class="lg-panel mt-2 overflow-x-auto">
            <table class="lg-table" data-historicos>
                <thead>
                    <tr>
                        <th>@lang('logistica::app.transportadoras.col-texto')</th>
                        <th class="lg-num">@lang('logistica::app.transportadoras.col-envios')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($historicos as $h)
                        <tr>
                            <td>{{ trim((string) $h->carrier_title) !== '' ? $h->carrier_title : trans('logistica::app.transportadoras.vacio') }}</td>
                            <td class="lg-num">{{ $h->envios }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
