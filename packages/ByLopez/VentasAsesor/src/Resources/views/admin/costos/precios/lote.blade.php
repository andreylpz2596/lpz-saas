{{-- Resultado de un lote confirmado (bitacora). Nunca dice «exito» si la reindexacion fallo. --}}
@php
    use ByLopez\VentasAsesor\Models\LotePrecioGrupo;
    use ByLopez\VentasAsesor\Support\Precios\FormatoPrecioGrupo as F;

    $reglaTexto = fn (?string $tipo, $valor) => $tipo === null ? '—' : F::regla(['tipo' => $tipo, 'valor' => (float) $valor]);
@endphp

<section class="va-panel mt-4 p-4" data-lote="{{ $lote->lote }}" data-indice="{{ $lote->indice_estado }}">
    <div class="va-encab">
        <div class="va-encab__texto">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.precios.lote-titulo')</p>
            <p class="va-sub">
                {{ $lote->created_at?->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') }} · {{ $lote->actor_nombre_snapshot ?? '—' }} ·
                <strong>{{ $lote->grupo_nombre_snapshot }}</strong> ·
                @lang('ventasasesor::app.precios.operaciones.'.$lote->operacion) · @lang('ventasasesor::app.precios.desde-u', ['qty' => $lote->parametros['qty'] ?? 1])
            </p>
            @if ($lote->motivo)
                <p class="va-sub">@lang('ventasasesor::app.precios.motivo'): {{ $lote->motivo }}</p>
            @endif
        </div>
    </div>

    <div class="pg-kpis mt-3">
        @foreach (['creadas', 'actualizadas', 'quitadas', 'sin_cambio'] as $k)
            <div class="va-panel va-kpi-box"><p class="va-kpi-label">@lang('ventasasesor::app.precios.lote-'.$k)</p><p class="va-kpi-value" data-lote-{{ $k }}>{{ $lote->{$k} }}</p></div>
        @endforeach
        <div class="va-panel va-kpi-box"><p class="va-kpi-label">@lang('ventasasesor::app.precios.lote-variantes')</p><p class="va-kpi-value">{{ $lote->variantes }}</p></div>
    </div>

    @if ($lote->indice_estado === LotePrecioGrupo::INDICE_ACTUALIZADO)
        <p class="mt-3 text-sm"><span class="va-pill va-pill--verde"><span>@lang('ventasasesor::app.precios.indice-ok')</span></span></p>
    @else
        <p class="pg-aviso pg-aviso--rojo mt-3" role="alert">
            {{ trans('ventasasesor::app.precios.indice-fallo', ['error' => $lote->indice_error ?? trans('ventasasesor::app.precios.indice-pendiente')]) }}
            <code>php artisan indexer:index --type=price</code>
        </p>
        @if ($puedeEditar ?? false)
            <form method="POST" action="{{ route('admin.ventas_asesor.precios_grupo.reindexar', $lote->lote) }}" class="mt-2" data-form="reindexar">
                @csrf
                <button type="submit" class="primary-button" data-ocupado="{{ trans('ventasasesor::app.precios.guardando') }}">@lang('ventasasesor::app.precios.reintentar-indice')</button>
            </form>
        @endif
    @endif

    <details class="mt-3">
        <summary class="pg-fuerte" style="cursor: pointer">{{ trans('ventasasesor::app.precios.lote-detalle', ['n' => $lote->cambios->count()]) }}</summary>
        <table class="pg-tabla mt-2">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>@lang('ventasasesor::app.precios.col-estado')</th>
                    <th>@lang('ventasasesor::app.precios.col-anterior')</th>
                    <th>@lang('ventasasesor::app.precios.col-nueva')</th>
                    <th class="pg-num">@lang('ventasasesor::app.precios.col-resultante')</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lote->cambios as $c)
                    <tr>
                        <td data-etiqueta="SKU">{{ $c->sku_snapshot }}</td>
                        <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-estado') }}">@lang('ventasasesor::app.precios.acciones.'.$c->accion)</td>
                        <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-anterior') }}">{{ $reglaTexto($c->tipo_anterior, $c->valor_anterior) }}</td>
                        <td data-etiqueta="{{ trans('ventasasesor::app.precios.col-nueva') }}">{{ $reglaTexto($c->tipo_nuevo, $c->valor_nuevo) }}</td>
                        <td class="pg-num" data-etiqueta="{{ trans('ventasasesor::app.precios.col-resultante') }}">{{ F::dinero($c->precio_anterior === null ? null : (float) $c->precio_anterior) }} → <strong>{{ F::dinero($c->precio_resultante === null ? null : (float) $c->precio_resultante) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</section>
