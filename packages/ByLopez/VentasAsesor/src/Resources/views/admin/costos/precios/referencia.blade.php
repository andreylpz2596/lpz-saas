{{-- Una referencia (padre + color) con sus tallas. $r viene de ConsultaPreciosGrupoService::referencias(). --}}
@php
    use ByLopez\VentasAsesor\Support\Precios\FormatoPrecioGrupo as F;

    $res = $r['resumen'];
    $ocultosFiltros = collect($filtros)->filter(fn ($v) => $v !== null && $v !== false && $v !== '')->map(fn ($v) => $v === true ? '1' : $v);
@endphp

<details class="va-panel pg-ref" data-referencia="{{ $r['id'] }}">
    <summary>
        @if ($puedeEditar)
            <input type="checkbox" data-sel-ref="{{ $r['id'] }}" aria-label="{{ trans('ventasasesor::app.precios.seleccionar-ref', ['nombre' => $r['nombre']]) }}">
        @else
            <span></span>
        @endif

        <span style="min-width: 0">
            <span class="pg-ref-nombre">{{ $r['nombre'] }}@if ($r['color']) <span class="pg-muted">· {{ $r['color'] }}</span>@endif</span>
            <span class="pg-chips">
                @foreach ($r['publicos'] as $p)<span class="pg-chip">{{ $p }}</span>@endforeach
                @if ($r['familia'])<span class="pg-chip">{{ $r['familia'] }}</span>@endif
                @if ($r['tela'])<span class="pg-chip">{{ $r['tela'] }}</span>@endif
                @if ($r['sku'])<span class="pg-chip">{{ $r['sku'] }}</span>@endif
                <span class="pg-chip">{{ trans_choice('ventasasesor::app.precios.n-tallas', count($r['variantes']), ['n' => count($r['variantes'])]) }}</span>
            </span>
        </span>

        <span class="pg-resumen">
            <span class="pg-fuerte" data-resumen-precio>
                {{ $res['variado'] ? trans('ventasasesor::app.precios.precios-variados') : F::dinero($res['precio_min']) }}
            </span>
            <span class="va-sub" style="display: block">
                @if ($res['variado'])
                    {{ F::dinero($res['precio_min']) }} – {{ F::dinero($res['precio_max']) }}
                @elseif ($res['sin_regla'] === count($r['variantes']))
                    @lang('ventasasesor::app.precios.sin-precio-especifico')
                @elseif ($res['reglas_iguales'])
                    {{ collect($res['reglas'])->map(fn ($x) => trans('ventasasesor::app.precios.desde-u', ['qty' => $x['qty']]).': '.F::regla($x))->implode(' · ') }}
                @else
                    @lang('ventasasesor::app.precios.reglas-variadas')
                @endif
            </span>
            @if ($res['sin_regla'] > 0 && $res['sin_regla'] < count($r['variantes']))
                <span class="va-pill va-pill--sm va-pill--amarillo"><span>{{ trans('ventasasesor::app.precios.n-sin-regla', ['n' => $res['sin_regla']]) }}</span></span>
            @endif
        </span>

        <span class="pg-flecha" aria-hidden="true">▶</span>
    </summary>

    <table class="pg-tabla">
        <thead>
            <tr>
                @if ($puedeEditar)<th style="width: 36px"><span class="pg-sr">@lang('ventasasesor::app.precios.seleccionar')</span></th>@endif
                <th>@lang('ventasasesor::app.precios.col-variante')</th>
                <th class="pg-num">@lang('ventasasesor::app.precios.col-base')</th>
                <th>@lang('ventasasesor::app.precios.col-regla')</th>
                <th class="pg-num">{{ trans('ventasasesor::app.precios.col-efectivo', ['qty' => $filtros['qty']]) }}</th>
                @if ($conCostos)
                    <th class="pg-num">@lang('ventasasesor::app.precios.col-costo')</th>
                    <th class="pg-num">@lang('ventasasesor::app.precios.col-diferencia')</th>
                @endif
                <th class="pg-num">@lang('ventasasesor::app.precios.col-accion')</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r['variantes'] as $v)
                @php $e = $v['eval']; @endphp
                <tr data-variante="{{ $v['id'] }}">
                    @if ($puedeEditar)
                        <td>
                            <input type="checkbox" form="pg-masivo" name="ids[]" value="{{ $v['id'] }}" data-sel-var data-ref="{{ $r['id'] }}" aria-label="{{ trans('ventasasesor::app.precios.seleccionar-var', ['sku' => $v['sku']]) }}">
                        </td>
                    @endif
                    <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-variante') }}">
                        <span class="pg-fuerte">{{ $v['talla'] ?? '—' }}</span>
                        <span class="va-sub" style="display: block">{{ $v['sku'] }}</span>
                        @unless ($v['activa'])
                            <span class="va-pill va-pill--sm va-pill--gris"><span>@lang('ventasasesor::app.precios.deshabilitada')</span></span>
                        @endunless
                    </td>
                    <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-base') }}">{{ F::dinero($v['base']) }}</td>
                    <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-regla') }}">
                        @if ($v['reglas'] === [])
                            <span class="pg-muted" data-sin-regla>@lang('ventasasesor::app.precios.sin-precio-especifico')</span>
                        @else
                            <ul class="pg-escalones">
                                @foreach ($v['reglas'] as $x)
                                    <li @class(['pg-tachado' => $x['sin_efecto']]) data-escalon="{{ $x['qty'] }}" @if ($x['sin_efecto']) title="{{ trans('ventasasesor::app.precios.sin-efecto-ayuda') }}" @endif>
                                        <span class="pg-muted">@lang('ventasasesor::app.precios.desde-u', ['qty' => $x['qty']]):</span>
                                        {{ F::regla($x, $x['tipo'] === 'discount' ? $v['base'] : null) }}
                                        @if ($x['sin_efecto'])<span class="va-pill va-pill--sm va-pill--amarillo"><span>@lang('ventasasesor::app.precios.sin-efecto')</span></span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @foreach ($v['reglas_todos'] as $x)
                            <span class="va-sub" style="display: block">@lang('ventasasesor::app.precios.todos-los-grupos'): @lang('ventasasesor::app.precios.desde-u', ['qty' => $x['qty']]) · {{ F::regla($x) }}</span>
                        @endforeach
                    </td>
                    <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-efectivo', ['qty' => $filtros['qty']]) }}">
                        <span class="pg-fuerte" data-precio-efectivo>{{ F::dinero($e->final) }}</span>
                        <span style="display: block"><span class="va-pill va-pill--sm va-pill--{{ F::colorOrigen($e) }}" data-origen="{{ $e->origen }}"><span>{{ F::origen($e) }}</span></span></span>
                        @if ($v['reglas'] === [] || $e->origen !== 'grupo')
                            <span class="va-sub" style="display: block">@lang('ventasasesor::app.precios.alternativo-'.($e->origen === 'grupo' ? 'todos' : $e->origen))</span>
                        @endif
                        @if ($v['indice_desactualizado'])
                            <span class="va-pill va-pill--sm va-pill--rojo" title="{{ trans('ventasasesor::app.precios.indice-ayuda') }}"><span>@lang('ventasasesor::app.precios.indice-desactualizado')</span></span>
                        @endif
                    </td>
                    @if ($conCostos)
                        <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-costo') }}">
                            {{ $v['costo'] === null ? trans('ventasasesor::app.precios.costo-desconocido') : F::dinero($v['costo']) }}
                        </td>
                        <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-diferencia') }}">
                            @if ($v['diferencia'] === null)
                                <span class="pg-muted">—</span>
                            @else
                                <span @class(['pg-fuerte', 'pg-rojo' => $v['diferencia'] < 0])>{{ \ByLopez\Logistica\Support\Moneda::formatear($v['diferencia'], true) }}</span>
                            @endif
                        </td>
                    @endif
                    <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-accion') }}">
                        <button type="button" class="va-btn" data-editar="{{ $v['id'] }}" aria-expanded="false" aria-controls="pg-editor-{{ $v['id'] }}">
                            {{ $puedeEditar ? trans('ventasasesor::app.precios.editar') : trans('ventasasesor::app.precios.ver-detalle') }}
                        </button>
                    </td>
                </tr>

                <tr class="pg-editor pg-oculto" id="pg-editor-{{ $v['id'] }}" data-editor="{{ $v['id'] }}">
                    <td colspan="{{ 5 + ($puedeEditar ? 1 : 0) + ($conCostos ? 2 : 0) }}">
                        <div class="pg-editor-caja">
                            <div>
                                <p class="pg-fuerte">{{ trans('ventasasesor::app.precios.editor-titulo', ['sku' => $v['sku'], 'grupo' => $grupo->name]) }}</p>

                                @if ($v['reglas'] === [])
                                    <p class="va-sub">@lang('ventasasesor::app.precios.editor-sin-reglas')</p>
                                @else
                                    <ul class="pg-escalones mt-2">
                                        @foreach ($v['reglas'] as $x)
                                            <li style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 4px 0">
                                                <span>@lang('ventasasesor::app.precios.desde-u', ['qty' => $x['qty']]): <strong>{{ F::regla($x, $x['tipo'] === 'discount' ? $v['base'] : null) }}</strong> → {{ F::dinero($x['precio']) }}</span>
                                                @if ($puedeEditar)
                                                    <button type="button" class="va-btn" data-precargar="{{ $v['id'] }}" data-qty="{{ $x['qty'] }}" data-tipo="{{ $x['tipo'] }}" data-valor="{{ number_format($x['valor'], 2, '.', '') }}">@lang('ventasasesor::app.precios.cambiar')</button>
                                                    <form method="POST" action="{{ route('admin.ventas_asesor.precios_grupo.previsualizar') }}" style="display: inline">
                                                        @csrf
                                                        @foreach ($ocultosFiltros as $k => $val)<input type="hidden" name="filtros[{{ $k }}]" value="{{ $val }}">@endforeach
                                                        <input type="hidden" name="ids[]" value="{{ $v['id'] }}">
                                                        <input type="hidden" name="alcance" value="seleccion">
                                                        <input type="hidden" name="operacion" value="quitar">
                                                        <input type="hidden" name="qty" value="{{ $x['qty'] }}">
                                                        <button type="submit" class="va-btn va-btn--no" data-ocupado="{{ trans('ventasasesor::app.precios.cargando') }}">@lang('ventasasesor::app.precios.quitar')</button>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($puedeEditar)
                                    <form method="POST" action="{{ route('admin.ventas_asesor.precios_grupo.previsualizar') }}" class="pg-form-linea mt-3" data-form="individual" data-variante="{{ $v['id'] }}">
                                        @csrf
                                        @foreach ($ocultosFiltros as $k => $val)<input type="hidden" name="filtros[{{ $k }}]" value="{{ $val }}">@endforeach
                                        <input type="hidden" name="ids[]" value="{{ $v['id'] }}">
                                        <input type="hidden" name="alcance" value="seleccion">
                                        <label>
                                            <span class="pg-label">@lang('ventasasesor::app.precios.escalon')</span>
                                            <input type="number" name="qty" min="1" max="9999" step="1" value="1" class="pg-input" required inputmode="numeric" data-campo-qty>
                                        </label>
                                        <label>
                                            <span class="pg-label">@lang('ventasasesor::app.precios.tipo')</span>
                                            <select name="operacion" class="pg-input" data-campo-op>
                                                <option value="fijo">@lang('ventasasesor::app.precios.tipos.fixed')</option>
                                                <option value="porcentaje">@lang('ventasasesor::app.precios.tipos.discount')</option>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="pg-label">@lang('ventasasesor::app.precios.valor')</span>
                                            <input type="number" name="valor" min="0.01" step="0.01" class="pg-input" required inputmode="decimal" data-campo-valor>
                                        </label>
                                        <button type="submit" class="primary-button" data-ocupado="{{ trans('ventasasesor::app.precios.cargando') }}">@lang('ventasasesor::app.precios.previsualizar')</button>
                                    </form>
                                    <p class="va-sub mt-1">@lang('ventasasesor::app.precios.editor-ayuda')</p>
                                    <p class="va-sub" data-maximo-base>{{ trans('ventasasesor::app.precios.maximo-base', ['base' => F::dinero($v['base'])]) }}</p>
                                @endif
                            </div>

                            <div>
                                <p class="pg-label">{{ trans('ventasasesor::app.precios.otros-grupos', ['qty' => $filtros['qty']]) }}</p>
                                {{-- Comparacion secundaria: se carga al abrir el editor (sin JavaScript, el enlace abre el fragmento). --}}
                                <div data-comparar="{{ route('admin.ventas_asesor.precios_grupo.index', ['grupo' => $grupo->id, 'qty' => $filtros['qty'], 'comparar' => $v['id']]) }}">
                                    <a class="text-blue-600 dark:text-blue-400" href="{{ route('admin.ventas_asesor.precios_grupo.index', ['grupo' => $grupo->id, 'qty' => $filtros['qty'], 'comparar' => $v['id']]) }}">@lang('ventasasesor::app.precios.ver-otros-grupos')</a>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</details>
