@php
    use ByLopez\VentasAsesor\Enums\EstadoControlCosto as Estado;

    $dinero = fn (?float $v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear($v);
    $colores = [
        Estado::REVISAR->value => 'rojo',
        Estado::PARCIAL->value => 'cian',
        Estado::SIN_DATOS_PRODUCCION->value => 'gris',
        Estado::SIN_COSTO_COMISIONABLE->value => 'amarillo',
        Estado::OK->value => 'verde',
        Estado::FAVORABLE->value => 'verde',
    ];
    $pill = fn (Estado $e) => '<span class="va-pill va-pill--sm va-pill--'.$colores[$e->value].'"><span>'.e(trans('ventasasesor::app.costos.estados.'.$e->value)).'</span></span>';
    $diferencia = function ($f) use ($dinero) {
        if ($f->diferencia === null) {
            return '<span class="text-slate-400">—</span>';
        }
        $clase = $f->diferencia > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300';
        $signo = $f->diferencia > 0 ? '+' : '';

        return '<span class="font-semibold '.$clase.'">'.$signo.e($dinero($f->diferencia)).'</span><span class="va-sub block">'.$signo.e(number_format($f->diferenciaPorcentaje, 1, ',', '.')).' %</span>';
    };
    $fecha = fn (?string $f) => $f ? \Illuminate\Support\Carbon::parse($f)->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') : trans('ventasasesor::app.costos.sin-cambios-registrados');
    $hayFiltros = collect($filtros)->filter()->isNotEmpty();
    $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.costos.titulo')
    </x-slot>

    {{-- Mascara monetaria visual compartida (data-moneda): el valor que se envia no cambia. --}}
    @include('corteconfeccion::admin.partials.moneda')

    <div class="va-orders-page bylopez-scope">
        {{-- Doc. 32: encabezado y pestañas «Costos» / «Precios por grupo». --}}
        @include('ventasasesor::admin.costos.pestanas', ['activa' => 'costos'])

        @foreach (['success' => 'verde', 'error' => 'rojo'] as $tipo => $color)
            @if (session($tipo))
                <div class="va-panel mt-4 p-3 text-sm" data-flash="{{ $tipo }}"><span class="va-pill va-pill--{{ $color }}"><span>{{ session($tipo) }}</span></span></div>
            @endif
        @endforeach

        <div class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.kpi-con-costo')</p>
                <p class="va-kpi-value" data-kpi="con-costo">{{ $indicadores['con_costo'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.costos.kpi-con-costo-detalle', ['total' => $indicadores['variantes']])</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.kpi-sin-costo')</p>
                <p class="va-kpi-value" data-kpi="sin-costo">{{ $indicadores['sin_costo'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.costos.kpi-sin-costo-detalle')</p>
            </div>
            <a href="{{ route('admin.ventas_asesor.costos.index', ['estado' => Estado::REVISAR->value]) }}" class="va-panel va-kpi-box block">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.kpi-revisar')</p>
                <p class="va-kpi-value {{ $indicadores['revisar'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-kpi="revisar">{{ $indicadores['revisar'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.costos.kpi-revisar-detalle')</p>
            </a>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.kpi-cambios')</p>
                <p class="va-kpi-value" data-kpi="cambios">{{ $indicadores['cambios_recientes'] }}</p>
                <p class="va-sub">@lang('ventasasesor::app.costos.kpi-cambios-detalle', ['lotes' => $indicadores['lotes_recientes'], 'dias' => \ByLopez\VentasAsesor\Services\Costos\ControlCostosService::DIAS_RECIENTES])</p>
            </div>
        </div>

        <p class="va-panel mt-4 p-3 text-xs leading-5 text-slate-600 dark:text-gray-300" data-aviso="produccion">
            {{ $indicadores['produccion_disponible'] ? trans('ventasasesor::app.costos.aviso-parcial') : trans('ventasasesor::app.costos.aviso-sin-produccion') }}
        </p>

        <form method="GET" action="{{ route('admin.ventas_asesor.costos.index') }}" class="va-panel mt-4 p-4">
            <div class="va-filters-layout">
                <div class="va-filter-search" style="grid-column: span 4 / span 4">
                    <label class="{{ $label }}" for="f-q">@lang('ventasasesor::app.costos.buscar')</label>
                    <input id="f-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" class="{{ $input }}">
                </div>
                <div class="va-filter-field" style="grid-column: span 3 / span 3">
                    <label class="{{ $label }}" for="f-familia">@lang('ventasasesor::app.costos.familia')</label>
                    <select id="f-familia" name="familia" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::app.costos.todas')</option>
                        @foreach ($familias as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['familia'] === $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-filter-field" style="grid-column: span 2 / span 2">
                    <label class="{{ $label }}" for="f-estado">@lang('ventasasesor::app.costos.estado')</label>
                    <select id="f-estado" name="estado" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::app.costos.todos')</option>
                        @foreach (Estado::cases() as $e)
                            <option value="{{ $e->value }}" @selected($filtros['estado'] === $e->value)>@lang('ventasasesor::app.costos.estados.'.$e->value)</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-filter-actions" style="grid-column: span 3 / span 3">
                    @if ($hayFiltros)
                        <a href="{{ route('admin.ventas_asesor.costos.index') }}" class="transparent-button">@lang('ventasasesor::app.costos.limpiar')</a>
                    @endif
                    <button type="submit" class="secondary-button">@lang('ventasasesor::app.costos.filtrar')</button>
                </div>
            </div>
        </form>

        @if ($puedeActualizar)
            {{-- Actualizacion masiva: SIEMPRE pasa por la vista previa (D-COST-05). Las casillas de la tabla y de las
                 tarjetas se asocian a este formulario con el atributo `form`. --}}
            <form id="va-costos-form" method="POST" action="{{ route('admin.ventas_asesor.costos.previsualizar') }}" class="va-panel mt-4 p-4" data-form="actualizar-costos">
                @csrf
                <input type="hidden" name="q" value="{{ $filtros['q'] }}">
                <input type="hidden" name="familia" value="{{ $filtros['familia'] }}">
                <input type="hidden" name="estado" value="{{ $filtros['estado'] }}">

                <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.costos.actualizar-titulo')</p>
                <p class="va-sub mt-0.5">@lang('ventasasesor::app.costos.actualizar-ayuda')</p>

                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12">
                    <div class="md:col-span-3">
                        <label class="{{ $label }}" for="f-nuevo">@lang('ventasasesor::app.costos.nuevo-costo')</label>
                        <input id="f-nuevo" type="number" name="nuevo_costo" min="0.01" step="0.01" required data-moneda class="{{ $input }}">
                    </div>
                    <div class="md:col-span-4">
                        <label class="{{ $label }}" for="f-motivo">@lang('ventasasesor::app.costos.motivo')</label>
                        <input id="f-motivo" type="text" name="motivo" maxlength="500" class="{{ $input }}">
                    </div>
                    <div class="flex flex-col justify-end gap-1.5 text-sm text-gray-700 dark:text-gray-300 md:col-span-3">
                        <label class="flex items-center gap-2"><input type="radio" name="alcance" value="seleccion" checked> @lang('ventasasesor::app.costos.alcance-seleccion')</label>
                        <label class="flex items-center gap-2"><input type="radio" name="alcance" value="filtro"> @lang('ventasasesor::app.costos.alcance-filtro', ['cantidad' => $filas->total()])</label>
                    </div>
                    <div class="flex items-end md:col-span-2">
                        <button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::app.costos.vista-previa')</button>
                    </div>
                </div>
            </form>
        @endif

        {{-- Escritorio --}}
        <div class="va-panel va-desktop-table mt-4 overflow-hidden">
            <table class="va-orders-table text-left text-sm">
                <colgroup>
                    @if ($puedeActualizar)<col style="width: 4%">@endif
                    <col style="width: 27%">
                    <col style="width: 12%">
                    <col style="width: 12%">
                    <col style="width: 14%">
                    <col style="width: 11%">
                    <col style="width: 10%">
                    <col style="width: 10%">
                </colgroup>
                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        @if ($puedeActualizar)
                            <th><input type="checkbox" data-seleccionar-todo="desktop" aria-label="{{ trans('ventasasesor::app.costos.alcance-seleccion') }}"></th>
                        @endif
                        <th>@lang('ventasasesor::app.costos.col-producto')</th>
                        <th>@lang('ventasasesor::app.costos.col-familia')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-comisionable')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-productivo')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-diferencia')</th>
                        <th>@lang('ventasasesor::app.costos.col-estado')</th>
                        <th>@lang('ventasasesor::app.costos.col-ultimo')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $f)
                        <tr data-variante="{{ $f->productId }}" data-estado="{{ $f->estado->value }}">
                            @if ($puedeActualizar)
                                <td><input type="checkbox" form="va-costos-form" name="ids[]" value="{{ $f->productId }}" data-grupo="desktop" aria-label="{{ trans('ventasasesor::app.costos.seleccionar', ['sku' => $f->sku]) }}"></td>
                            @endif
                            <td>
                                <p class="truncate font-bold text-gray-900 dark:text-white">{{ $f->producto ?? $f->nombre }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $f->sku }}</p>
                            </td>
                            <td class="text-xs text-slate-600 dark:text-gray-300">{{ $f->familia ?? '—' }}</td>
                            <td class="text-right"><span class="va-money--strong text-gray-900 dark:text-white">{{ $dinero($f->costoComisionable) }}</span></td>
                            <td class="text-right">
                                <span class="va-money text-gray-900 dark:text-white">{{ $dinero($f->costoProductivo) }}</span>
                                @if ($f->costoProductivo !== null && ! $f->productivoCompleto)
                                    <span class="va-sub block">@lang('ventasasesor::app.costos.solo-tela', ['piezas' => $f->piezasProducidas])</span>
                                @endif
                            </td>
                            <td class="text-right">{!! $diferencia($f) !!}</td>
                            <td>{!! $pill($f->estado) !!}</td>
                            <td class="text-xs text-slate-500 dark:text-gray-400">{{ $fecha($f->ultimoCambio) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $puedeActualizar ? 8 : 7 }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                {{ $hayFiltros ? trans('ventasasesor::app.costos.vacio-filtros') : trans('ventasasesor::app.costos.vacio') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Tablet (2 columnas) y movil (1 columna) --}}
        <div class="va-mobile-orders mt-4 gap-3">
            @forelse ($filas as $f)
                <div class="va-panel p-4" data-variante-card="{{ $f->productId }}">
                    <div class="flex items-start justify-between gap-3">
                        <label class="flex min-w-0 items-start gap-2.5">
                            @if ($puedeActualizar)
                                <input type="checkbox" form="va-costos-form" name="ids[]" value="{{ $f->productId }}" data-grupo="mobile" class="mt-1" aria-label="{{ trans('ventasasesor::app.costos.seleccionar', ['sku' => $f->sku]) }}">
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate font-bold text-gray-900 dark:text-white">{{ $f->sku }}</span>
                                <span class="block truncate text-xs text-slate-500 dark:text-gray-400">{{ $f->producto ?? $f->nombre }}</span>
                            </span>
                        </label>
                        {!! $pill($f->estado) !!}
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.costos.col-comisionable')</p>
                            <p class="va-money text-gray-900 dark:text-white">{{ $dinero($f->costoComisionable) }}</p>
                        </div>
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.costos.col-productivo')</p>
                            <p class="va-money text-gray-900 dark:text-white">{{ $dinero($f->costoProductivo) }}</p>
                        </div>
                        <div>
                            <p class="va-caption">@lang('ventasasesor::app.costos.col-diferencia')</p>
                            <p class="text-sm">{!! $diferencia($f) !!}</p>
                        </div>
                    </div>

                    @if ($f->costoProductivo !== null && ! $f->productivoCompleto)
                        <p class="va-sub mt-2">@lang('ventasasesor::app.costos.solo-tela', ['piezas' => $f->piezasProducidas])</p>
                    @endif
                </div>
            @empty
                <div class="va-panel p-6 text-center text-gray-500 dark:text-gray-400">
                    {{ $hayFiltros ? trans('ventasasesor::app.costos.vacio-filtros') : trans('ventasasesor::app.costos.vacio') }}
                </div>
            @endforelse
        </div>

        @if ($filas->hasPages())
            <div class="mt-4 flex items-center justify-end gap-2">
                @if (! $filas->onFirstPage())
                    <a href="{{ $filas->previousPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.anterior')</a>
                @endif
                <span class="text-sm text-slate-500 dark:text-gray-400">{{ $filas->currentPage() }} / {{ $filas->lastPage() }}</span>
                @if ($filas->hasMorePages())
                    <a href="{{ $filas->nextPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.siguiente')</a>
                @endif
            </div>
        @endif
    </div>

    @if ($puedeActualizar)
        @pushOnce('scripts')
            <script>
                // Marca o desmarca las casillas visibles de la pagina actual (tabla o tarjetas, nunca las dos).
                document.querySelectorAll('[data-seleccionar-todo]').forEach((maestra) => {
                    maestra.addEventListener('change', () => {
                        document.querySelectorAll('input[data-grupo="' + maestra.dataset.seleccionarTodo + '"]')
                            .forEach((casilla) => { casilla.checked = maestra.checked; });
                    });
                });
            </script>
        @endPushOnce
    @endif
</x-admin::layouts>
