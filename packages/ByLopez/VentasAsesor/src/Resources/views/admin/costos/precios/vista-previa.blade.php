@php
    use ByLopez\VentasAsesor\DataObjects\VistaPreviaPreciosGrupo as Vista;
    use ByLopez\VentasAsesor\Support\Precios\FormatoPrecioGrupo as F;

    $s = $vista->solicitud;
    $colores = [Vista::CREAR => 'verde', Vista::ACTUALIZAR => 'cian', Vista::QUITAR => 'rojo', Vista::SIN_CAMBIO => 'gris', Vista::ERROR => 'rojo'];
    $ocultos = collect($filtros)->filter(fn ($v) => $v !== null && $v !== false && $v !== '')->map(fn ($v) => $v === true ? '1' : $v);
    $volver = route('admin.ventas_asesor.precios_grupo.index', $ocultos->all());

    $descripcion = match ($s->operacion) {
        'fijo' => trans('ventasasesor::app.precios.desc.fijo', ['valor' => F::dinero((float) $s->valor), 'qty' => $s->qty]),
        'porcentaje' => trans('ventasasesor::app.precios.desc.porcentaje', ['valor' => F::porcentaje((float) $s->valor), 'qty' => $s->qty]),
        'completar' => trans('ventasasesor::app.precios.desc.completar', ['regla' => F::regla(['tipo' => $s->tipo, 'valor' => (float) $s->valor]), 'qty' => $s->qty]),
        'ajustar' => trans('ventasasesor::app.precios.desc.ajustar', [
            'valor' => $s->modoAjuste === 'porcentaje' ? ((float) $s->valor > 0 ? '+' : '').F::porcentaje((float) $s->valor) : \ByLopez\Logistica\Support\Moneda::formatear((float) $s->valor, true),
            'qty' => $s->qty,
            'redondeo' => trans('ventasasesor::app.precios.redondeos.'.$s->redondeo),
        ]),
        'quitar' => trans('ventasasesor::app.precios.desc.quitar', ['qty' => $s->qty]),
    };
    $reglaTexto = fn (?array $r, float $base) => $r === null ? trans('ventasasesor::app.precios.sin-regla-escalon') : F::regla($r, $r['tipo'] === 'discount' ? $base : null);
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
        @include('ventasasesor::admin.costos.precios.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.precios.vista-previa-titulo')
    </x-slot>

    <div class="va-orders-page bylopez-scope" data-pantalla="precios-vista-previa">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">@lang('ventasasesor::app.precios.vista-previa-titulo')</h1>
        <p class="va-sub">@lang('ventasasesor::app.precios.vista-previa-ayuda')</p>

        @isset($aviso)
            <div class="pg-aviso pg-aviso--rojo mt-4" role="alert" data-aviso="{{ $avisoCodigo }}">{{ $aviso }}</div>
        @endisset

        <section class="va-panel mt-4 p-4" data-resumen-operacion>
            <div class="pg-grid" style="align-items: start">
                <div class="pg-c4 pg-ancho">
                    <p class="pg-label">@lang('ventasasesor::app.precios.grupo')</p>
                    <p class="pg-grupo" data-vista-grupo>{{ $vista->grupo['nombre'] }}</p>
                </div>
                <div class="pg-c4 pg-ancho">
                    <p class="pg-label">@lang('ventasasesor::app.precios.accion')</p>
                    <p class="pg-fuerte">{{ $descripcion }}</p>
                    @if ($s->operacion === 'ajustar')
                        <p class="va-sub" data-explicacion-redondeo>{{ trans('ventasasesor::app.precios.redondeo-explicacion.'.$s->redondeo) }}</p>
                    @endif
                </div>
                <div class="pg-c4 pg-ancho">
                    <p class="pg-label">@lang('ventasasesor::app.precios.alcance')</p>
                    <p class="pg-fuerte" data-vista-alcance="{{ $s->alcance }}">
                        {{ $s->alcance === 'filtro'
                            ? trans('ventasasesor::app.precios.alcance-filtro', ['cantidad' => count($s->ids)])
                            : trans('ventasasesor::app.precios.alcance-seleccion-n', ['cantidad' => count($s->ids)]) }}
                    </p>
                    @if ($s->filtros !== [])
                        <p class="va-sub">
                            @php
                                $listas = ['publico' => 'publicos', 'familia' => 'familias', 'tela' => 'telas', 'color' => 'colores', 'talla' => 'tallas'];
                                $nombreFiltro = fn ($k, $val) => is_bool($val) ? '✓' : (isset($listas[$k]) ? ($opciones[$listas[$k]][$val] ?? $val) : $val);
                            @endphp
                            @foreach (collect($s->filtros)->except(['grupo']) as $k => $val)
                                <span class="pg-chip" data-filtro="{{ $k }}">{{ trans('ventasasesor::app.precios.filtro-'.$k) }}: {{ $nombreFiltro($k, $val) }}</span>
                            @endforeach
                        </p>
                    @endif
                </div>
                @if ($s->motivo)
                    <div class="pg-c12 pg-ancho"><p class="va-sub">@lang('ventasasesor::app.precios.motivo'): {{ $s->motivo }}</p></div>
                @endif
            </div>
        </section>

        <div class="pg-kpis mt-4">
            @foreach ([Vista::CREAR, Vista::ACTUALIZAR, Vista::QUITAR, Vista::SIN_CAMBIO, Vista::ERROR] as $k)
                <div class="va-panel va-kpi-box">
                    <p class="va-kpi-label">@lang('ventasasesor::app.precios.conteo.'.$k)</p>
                    <p @class(['va-kpi-value', 'pg-rojo' => $k === Vista::ERROR && $vista->conteo[$k] > 0]) data-conteo="{{ $k }}">{{ $vista->conteo[$k] }}</p>
                </div>
            @endforeach
        </div>

        @if ($vista->borradoresAbiertos > 0)
            <p class="pg-aviso mt-4" data-aviso="borradores">{{ trans('ventasasesor::app.precios.aviso-borradores', ['n' => $vista->borradoresAbiertos]) }}</p>
        @endif
        @if ($vista->conteo[Vista::ERROR] > 0)
            <p class="pg-aviso pg-aviso--rojo mt-4" data-aviso="errores">@lang('ventasasesor::app.precios.aviso-errores')</p>
        @elseif ($vista->cambios() === 0)
            <p class="pg-aviso mt-4" data-aviso="sin-cambios">@lang('ventasasesor::app.precios.aviso-sin-cambios')</p>
        @endif

        <div class="va-panel mt-4" style="overflow-x: auto">
            <table class="pg-tabla">
                <thead>
                    <tr>
                        <th>@lang('ventasasesor::app.precios.col-variante')</th>
                        <th>{{ trans('ventasasesor::app.precios.col-anterior-esc', ['qty' => $s->qty]) }}</th>
                        <th>@lang('ventasasesor::app.precios.col-nueva')</th>
                        <th class="pg-num">{{ trans('ventasasesor::app.precios.col-resultante-qty', ['qty' => $s->qty]) }}</th>
                        @if ($vista->conCostos)
                            <th class="pg-num">@lang('ventasasesor::app.precios.col-costo')</th>
                        @endif
                        <th>@lang('ventasasesor::app.precios.col-estado')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vista->filas as $f)
                        <tr data-fila="{{ $f['id'] }}" data-accion="{{ $f['accion'] }}">
                            <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-variante') }}">
                                <span class="pg-fuerte">{{ $f['referencia'] }}</span>
                                <span class="va-sub" style="display: block">{{ collect([$f['color'], $f['talla'], $f['sku']])->filter()->implode(' · ') }}</span>
                            </td>
                            <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-anterior-esc', ['qty' => $s->qty]) }}">{{ $reglaTexto($f['anterior'], $f['base']) }}</td>
                            <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-nueva') }}">
                                @if ($f['accion'] === Vista::QUITAR)
                                    <span class="pg-rojo">@lang('ventasasesor::app.precios.se-quita')</span>
                                @else
                                    {{ $f['nueva'] === null ? '—' : $reglaTexto($f['nueva'], $f['base']) }}
                                    @if ($f['valor_calculado'] !== null && abs($f['valor_calculado'] - ($f['nueva']['valor'] ?? 0)) > 0.004)
                                        <span class="va-sub" style="display: block" data-redondeo>{{ trans('ventasasesor::app.precios.calculado-redondeado', ['calculado' => F::dinero($f['valor_calculado']), 'redondeado' => F::dinero($f['nueva']['valor'])]) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-resultante-qty', ['qty' => $s->qty]) }}">
                                @if (abs($f['precio_anterior'] - $f['precio_nuevo']) > 0.00005)
                                    <span class="pg-muted pg-tachado">{{ F::dinero($f['precio_anterior']) }}</span>&nbsp;
                                @endif
                                <span class="pg-fuerte" data-precio-nuevo>{{ F::dinero($f['precio_nuevo']) }}</span>
                                <span style="display: block"><span class="va-pill va-pill--sm va-pill--{{ F::colorOrigen($f['origen_nuevo']) }}"><span>{{ F::origen($f['origen_nuevo']) }}</span></span></span>
                            </td>
                            @if ($vista->conCostos)
                                <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-costo') }}">{{ $f['costo'] === null ? trans('ventasasesor::app.precios.costo-desconocido') : F::dinero($f['costo']) }}</td>
                            @endif
                            <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-estado') }}">
                                <span class="va-pill va-pill--sm va-pill--{{ $colores[$f['accion']] }}"><span>@lang('ventasasesor::app.precios.acciones.'.$f['accion'])</span></span>
                                @if ($f['motivo'])
                                    <span class="va-sub" style="display: block">{{ F::motivo($f['motivo'], ['base' => F::dinero($f['base'])]) }}</span>
                                @endif
                                @foreach ($f['advertencias'] as $a)
                                    <span class="va-sub pg-rojo" style="display: block" data-advertencia="{{ $a['codigo'] }}">⚠ {{ F::advertencia($a) }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.ventas_asesor.precios_grupo.confirmar') }}" class="va-panel pg-sticky-pie mt-4" data-form="confirmar-precios">
            @csrf
            <input type="hidden" name="token" value="{{ $vista->token }}">
            @foreach ($s->comoFormulario() as $k => $val)
                @if ($val !== null && $k !== 'grupo')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
            @endforeach
            @foreach ($s->ids as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
            @foreach ($ocultos as $k => $val)<input type="hidden" name="filtros[{{ $k }}]" value="{{ $val }}">@endforeach

            <a href="{{ $volver }}" class="secondary-button" data-cancelar>@lang('ventasasesor::app.precios.cancelar')</a>
            <button type="submit" class="primary-button" data-confirmar data-ocupado="{{ trans('ventasasesor::app.precios.guardando') }}" @disabled(! $vista->confirmable())>
                {{ trans('ventasasesor::app.precios.confirmar', ['n' => $vista->cambios()]) }}
            </button>
        </form>
    </div>

    @pushOnce('scripts')
        <script>
            // Evita el doble envio por doble clic (el servidor ademas es idempotente por token). Delegado en `document`: Bagisto
            // re-crea el DOM al montar Vue.
            document.addEventListener('submit', (e) => {
                if (! e.target.matches('[data-form="confirmar-precios"]')) return;
                const boton = e.target.querySelector('[data-confirmar]');
                if (boton.dataset.enviado) { e.preventDefault(); return; }
                boton.dataset.enviado = '1';
                boton.setAttribute('aria-busy', 'true');
                setTimeout(() => { boton.textContent = boton.dataset.ocupado; boton.disabled = true; }, 0);
            });
        </script>
    @endPushOnce
</x-admin::layouts>
