@php
    use ByLopez\VentasAsesor\Support\Precios\FormatoPrecioGrupo as F;

    $hayFiltros = collect($filtros)->except(['grupo', 'qty'])->filter()->isNotEmpty() || $filtros['qty'] !== 1;
    $ocultos = fn (array $f) => collect($f)->filter(fn ($v) => $v !== null && $v !== false && $v !== '')->map(fn ($v) => $v === true ? '1' : $v);
    $operaciones = ['fijo', 'porcentaje', 'ajustar', 'completar', 'quitar'];
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
        @include('ventasasesor::admin.costos.precios.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.precios.titulo-pagina')
    </x-slot>

    @include('corteconfeccion::admin.partials.moneda')

    <div class="va-orders-page bylopez-scope" data-pantalla="precios-grupo">
        @include('ventasasesor::admin.costos.pestanas', ['activa' => 'precios'])

        @foreach (['success' => 'verde', 'error' => 'rojo'] as $tipo => $color)
            @if (session($tipo))
                <div class="va-panel mt-4 p-3 text-sm" data-flash="{{ $tipo }}" role="status"><span class="va-pill va-pill--{{ $color }}"><span>{{ session($tipo) }}</span></span></div>
            @endif
        @endforeach

        @if ($lote)
            @include('ventasasesor::admin.costos.precios.lote', ['lote' => $lote])
        @endif

        {{-- Filtros: el grupo primero (se trabaja con UN grupo existente; nunca se crea uno aqui). --}}
        <form method="GET" action="{{ route('admin.ventas_asesor.precios_grupo.index') }}" class="va-panel mt-4 p-4" data-form="filtros-precios">

            <div class="pg-grid">
                <div class="pg-c4 pg-ancho">
                    <label class="pg-label" for="pg-grupo">@lang('ventasasesor::app.precios.grupo')</label>
                    <input type="search" class="pg-input" placeholder="{{ trans('ventasasesor::app.precios.buscar-grupo') }}" data-buscar-grupo aria-label="{{ trans('ventasasesor::app.precios.buscar-grupo') }}" style="margin-bottom: 6px">
                    <select id="pg-grupo" name="grupo" class="pg-input" data-select-grupo required>
                        <option value="">@lang('ventasasesor::app.precios.elegir-grupo')</option>
                        @foreach ($grupos as $g)
                            <option value="{{ $g->id }}" @selected($filtros['grupo'] === (int) $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c2">
                    <label class="pg-label" for="pg-publico">@lang('ventasasesor::app.precios.publico')</label>
                    <select id="pg-publico" name="publico" class="pg-input">
                        <option value="">@lang('ventasasesor::app.precios.todos')</option>
                        @foreach ($opciones['publicos'] as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['publico'] === $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c3">
                    <label class="pg-label" for="pg-familia">@lang('ventasasesor::app.precios.referencia')</label>
                    <select id="pg-familia" name="familia" class="pg-input">
                        <option value="">@lang('ventasasesor::app.precios.todas')</option>
                        @foreach ($opciones['familias'] as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['familia'] === $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c3">
                    <label class="pg-label" for="pg-q">@lang('ventasasesor::app.precios.buscar')</label>
                    <input id="pg-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" class="pg-input" placeholder="{{ trans('ventasasesor::app.precios.buscar-ayuda') }}">
                </div>

                @if ($opciones['telas'] !== [])
                    <div class="pg-c2">
                        <label class="pg-label" for="pg-tela">@lang('ventasasesor::app.precios.tela')</label>
                        <select id="pg-tela" name="tela" class="pg-input">
                            <option value="">@lang('ventasasesor::app.precios.todas')</option>
                            @foreach ($opciones['telas'] as $id => $nombre)
                                <option value="{{ $id }}" @selected($filtros['tela'] === $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="pg-c2">
                    <label class="pg-label" for="pg-color">@lang('ventasasesor::app.precios.color')</label>
                    <select id="pg-color" name="color" class="pg-input">
                        <option value="">@lang('ventasasesor::app.precios.todos')</option>
                        @foreach ($opciones['colores'] as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['color'] === $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c2">
                    <label class="pg-label" for="pg-talla">@lang('ventasasesor::app.precios.talla')</label>
                    <select id="pg-talla" name="talla" class="pg-input">
                        <option value="">@lang('ventasasesor::app.precios.todas')</option>
                        @foreach ($opciones['tallas'] as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['talla'] === $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c2">
                    <label class="pg-label" for="pg-qty">@lang('ventasasesor::app.precios.cantidad-consulta')</label>
                    <input id="pg-qty" type="number" name="qty" min="1" max="9999" step="1" value="{{ $filtros['qty'] }}" class="pg-input" inputmode="numeric">
                </div>
                <div class="pg-c2">
                    <label class="pg-label" for="pg-regla">@lang('ventasasesor::app.precios.filtro-regla-titulo')</label>
                    <select id="pg-regla" name="regla" class="pg-input">
                        @foreach (trans('ventasasesor::app.precios.reglas-filtro') as $valor => $texto)
                            <option value="{{ $valor }}" @selected(($filtros['regla'] ?? '') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pg-c2 pg-ancho" style="display: flex; gap: 8px; justify-content: flex-end">
                    @if ($hayFiltros)
                        <a href="{{ route('admin.ventas_asesor.precios_grupo.index', array_filter(['grupo' => $filtros['grupo']])) }}" class="transparent-button">@lang('ventasasesor::app.precios.limpiar')</a>
                    @endif
                    <button type="submit" class="primary-button" data-ocupado="{{ trans('ventasasesor::app.precios.cargando') }}">@lang('ventasasesor::app.precios.consultar')</button>
                </div>
            </div>
        </form>

        <details class="va-panel mt-4 p-4 pg-ayuda" data-ayuda="calculo">
            <summary class="pg-fuerte" style="cursor: pointer">@lang('ventasasesor::app.precios.ayuda.titulo')</summary>
            <ul>
                @foreach (trans('ventasasesor::app.precios.ayuda.puntos') as $punto)
                    <li>{{ $punto }}</li>
                @endforeach
            </ul>
        </details>

        @if (! $grupo)
            <div class="va-panel mt-4 p-6 text-center text-gray-600 dark:text-gray-300" data-estado="sin-grupo">
                @lang('ventasasesor::app.precios.elija-grupo')
            </div>
        @else
            @php
                $refs = $listado['referencias'];
                $totalVariantes = $listado['total_variantes'];
            @endphp

            {{-- Barra fija: el grupo SIEMPRE visible, el alcance y la seleccion. --}}
            <div class="va-panel pg-barra mt-4" data-barra>
                <span class="pg-grupo" data-grupo-activo="{{ $grupo->id }}"><small>@lang('ventasasesor::app.precios.grupo')</small> {{ $grupo->name }}</span>
                <span class="pg-seleccion">
                    @lang('ventasasesor::app.precios.resultado', ['variantes' => $totalVariantes, 'referencias' => $refs->total(), 'qty' => $filtros['qty']])
                </span>
                @if ($puedeEditar)
                    <span class="pg-seleccion" data-contador-seleccion aria-live="polite">
                        @lang('ventasasesor::app.precios.seleccionadas', ['cantidad' => '<strong data-sel-total>0</strong>'])
                        <span data-sel-fuera class="pg-muted"></span>
                    </span>
                @endif
            </div>

            @if ($puedeEditar && $totalVariantes > 0)
                {{-- Edicion MASIVA: siempre pasa por la vista previa. Las casillas de las variantes se asocian con form="pg-masivo". --}}
                <form id="pg-masivo" method="POST" action="{{ route('admin.ventas_asesor.precios_grupo.previsualizar') }}" class="va-panel mt-4 p-4" data-form="edicion-masiva" data-grupo="{{ $grupo->id }}" data-firma-filtros="{{ md5(json_encode(collect($filtros)->except(['qty'])->filter(fn ($v) => $v !== null && $v !== '')->sortKeys()->all())) }}">
                    @csrf
                    @foreach ($ocultos($filtros) as $k => $v)
                        <input type="hidden" name="filtros[{{ $k }}]" value="{{ $v }}">
                    @endforeach

                    <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.precios.masiva-titulo')</p>
                    <p class="va-sub">@lang('ventasasesor::app.precios.masiva-ayuda')</p>

                    <div class="pg-grid mt-3" style="align-items: stretch">
                        <div class="pg-c3 pg-ancho">
                            <label class="pg-label" for="pg-op">@lang('ventasasesor::app.precios.accion')</label>
                            <select id="pg-op" name="operacion" class="pg-input" data-operacion required>
                                @foreach ($operaciones as $op)
                                    <option value="{{ $op }}" @selected(old('operacion', 'fijo') === $op)>@lang('ventasasesor::app.precios.operaciones.'.$op)</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="pg-c2">
                            <label class="pg-label" for="pg-esc">@lang('ventasasesor::app.precios.escalon')</label>
                            <input id="pg-esc" type="number" name="qty" min="1" max="9999" step="1" value="{{ old('qty', 1) }}" class="pg-input" required inputmode="numeric">
                        </div>
                        <div class="pg-c2" data-campo="tipo">
                            <label class="pg-label" for="pg-tipo">@lang('ventasasesor::app.precios.tipo')</label>
                            <select id="pg-tipo" name="tipo" class="pg-input">
                                <option value="fixed" @selected(old('tipo', 'fixed') === 'fixed')>@lang('ventasasesor::app.precios.tipos.fixed')</option>
                                <option value="discount" @selected(old('tipo') === 'discount')>@lang('ventasasesor::app.precios.tipos.discount')</option>
                            </select>
                        </div>
                        <div class="pg-c2" data-campo="modo">
                            <label class="pg-label" for="pg-modo">@lang('ventasasesor::app.precios.ajustar-por')</label>
                            <select id="pg-modo" name="modo_ajuste" class="pg-input">
                                <option value="porcentaje" @selected(old('modo_ajuste', 'porcentaje') === 'porcentaje')>@lang('ventasasesor::app.precios.ajuste-porcentaje')</option>
                                <option value="importe" @selected(old('modo_ajuste') === 'importe')>@lang('ventasasesor::app.precios.ajuste-importe')</option>
                            </select>
                        </div>
                        <div class="pg-c2" data-campo="valor">
                            <label class="pg-label" for="pg-valor" data-etiqueta-valor>@lang('ventasasesor::app.precios.valor')</label>
                            <input id="pg-valor" type="number" name="valor" step="0.01" value="{{ old('valor') }}" class="pg-input" inputmode="decimal">
                            <span class="va-sub" data-ayuda-valor></span>
                        </div>
                        <div class="pg-c2" data-campo="redondeo">
                            <label class="pg-label" for="pg-red">@lang('ventasasesor::app.precios.redondeo')</label>
                            <select id="pg-red" name="redondeo" class="pg-input">
                                @foreach ([1, 50, 100] as $r)
                                    <option value="{{ $r }}" @selected((int) old('redondeo', 1) === $r)>@lang('ventasasesor::app.precios.redondeos.'.$r)</option>
                                @endforeach
                            </select>
                            <span class="va-sub" data-ayuda-redondeo></span>
                        </div>
                        <div class="pg-c4 pg-ancho">
                            <label class="pg-label" for="pg-motivo">@lang('ventasasesor::app.precios.motivo')</label>
                            <input id="pg-motivo" type="text" name="motivo" maxlength="500" value="{{ old('motivo') }}" class="pg-input" placeholder="{{ trans('ventasasesor::app.precios.motivo-ayuda') }}">
                        </div>
                        <div class="pg-c6 pg-ancho">
                            <span class="pg-label">@lang('ventasasesor::app.precios.alcance')</span>
                            <label class="pg-check"><input type="radio" name="alcance" value="seleccion" checked data-alcance> @lang('ventasasesor::app.precios.alcance-seleccion')&nbsp;(<strong data-sel-total>0</strong>)</label>
                            <label class="pg-check"><input type="radio" name="alcance" value="filtro" data-alcance @disabled($totalVariantes > $maximo)> @lang('ventasasesor::app.precios.alcance-filtro', ['cantidad' => $totalVariantes])</label>
                            @if ($totalVariantes > $maximo)
                                <span class="va-sub">@lang('ventasasesor::app.precios.alcance-excesivo', ['maximo' => $maximo])</span>
                            @endif
                        </div>
                        <div class="pg-c3 pg-ancho" style="display: flex; align-items: flex-end">
                            <button type="submit" class="primary-button" style="width: 100%; justify-content: center; min-height: 44px" data-ocupado="{{ trans('ventasasesor::app.precios.cargando') }}">@lang('ventasasesor::app.precios.previsualizar')</button>
                        </div>
                    </div>
                </form>
            @endif

            <div class="pg-acciones-barra mt-4">
                @if ($puedeEditar && $totalVariantes > 0)
                    <label class="pg-check va-panel" style="padding: 0 12px"><input type="checkbox" data-sel-pagina> @lang('ventasasesor::app.precios.seleccionar-pagina')</label>
                    <button type="button" class="va-btn" data-sel-limpiar>@lang('ventasasesor::app.precios.limpiar-seleccion')</button>
                @endif
                <button type="button" class="va-btn" data-expandir="1">@lang('ventasasesor::app.precios.expandir')</button>
                <button type="button" class="va-btn" data-expandir="0">@lang('ventasasesor::app.precios.contraer')</button>
            </div>

            @forelse ($refs as $r)
                @include('ventasasesor::admin.costos.precios.referencia', ['r' => $r])
            @empty
                <div class="va-panel mt-4 p-6 text-center text-gray-500 dark:text-gray-400" data-estado="vacio">
                    @lang('ventasasesor::app.precios.vacio')
                </div>
            @endforelse

            @if ($refs->hasPages())
                <div class="mt-4 flex items-center justify-end gap-2" data-paginacion>
                    @if (! $refs->onFirstPage())
                        <a href="{{ $refs->appends(request()->except('page', 'lote'))->previousPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.anterior')</a>
                    @endif
                    <span class="text-sm text-slate-500 dark:text-gray-400">{{ $refs->currentPage() }} / {{ $refs->lastPage() }}</span>
                    @if ($refs->hasMorePages())
                        <a href="{{ $refs->appends(request()->except('page', 'lote'))->nextPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.siguiente')</a>
                    @endif
                </div>
            @endif
        @endif

        @if ($historial->isNotEmpty())
            <div class="va-panel mt-6 p-4" data-historial>
                <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.precios.historial')</p>
                <p class="va-sub" data-historial-nota>@lang('ventasasesor::app.precios.historial-nota')</p>
                <table class="pg-tabla mt-2">
                    <thead>
                        <tr>
                            <th>@lang('ventasasesor::app.precios.col-fecha')</th>
                            <th>@lang('ventasasesor::app.precios.grupo')</th>
                            <th>@lang('ventasasesor::app.precios.accion')</th>
                            <th class="pg-num">@lang('ventasasesor::app.precios.col-cambios')</th>
                            <th>@lang('ventasasesor::app.precios.col-actor')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historial as $h)
                            <tr>
                                <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-fecha') }}">
                                    <a class="text-blue-600 dark:text-blue-400" href="{{ route('admin.ventas_asesor.precios_grupo.index', ['grupo' => $h->customer_group_id, 'lote' => $h->lote]) }}">{{ $h->created_at?->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') }}</a>
                                </td>
                                <td data-etiqueta="{{ trans('ventasasesor::app.precios.grupo') }}">{{ $h->grupo_nombre_snapshot }}</td>
                                <td data-etiqueta="{{ trans('ventasasesor::app.precios.accion') }}">@lang('ventasasesor::app.precios.operaciones.'.$h->operacion) · @lang('ventasasesor::app.precios.desde-u', ['qty' => $h->parametros['qty'] ?? 1])</td>
                                <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-cambios') }}">{{ $h->creadas + $h->actualizadas + $h->quitadas }} / {{ $h->variantes }}</td>
                                <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-actor') }}">{{ $h->actor_nombre_snapshot ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @include('ventasasesor::admin.costos.precios.script')
</x-admin::layouts>
