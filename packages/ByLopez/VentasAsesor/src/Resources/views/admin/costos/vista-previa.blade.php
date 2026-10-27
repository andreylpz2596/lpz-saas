@php
    $dinero = fn (?float $v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear($v);
    $nuevo = (float) $vista->nuevoCosto;
    $variacion = function (?float $d) use ($dinero) {
        if ($d === null) {
            return '<span class="text-slate-400">—</span>';
        }

        return '<span class="font-semibold '.($d > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300').'">'.($d > 0 ? '+' : '').e($dinero($d)).'</span>';
    };
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.costos.vp-titulo')
    </x-slot>

    <div class="va-orders-page bylopez-scope" data-vista-previa="costos">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::app.costos.vp-titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.costos.vp-subtitulo')</p>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.vp-variantes')</p>
                <p class="va-kpi-value" data-kpi="seleccionadas">{{ $vista->cantidad() }}</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.vp-actual')</p>
                <p class="va-kpi-value text-xl">
                    @if ($vista->actualMinimo === null)
                        —
                    @elseif ($vista->actualMinimo === $vista->actualMaximo)
                        {{ $dinero($vista->actualMinimo) }}
                    @else
                        @lang('ventasasesor::app.costos.vp-rango', ['minimo' => $dinero($vista->actualMinimo), 'maximo' => $dinero($vista->actualMaximo)])
                    @endif
                </p>
                @if ($vista->sinCostoActual > 0)
                    <p class="va-sub">@lang('ventasasesor::app.costos.vp-sin-costo', ['cantidad' => $vista->sinCostoActual])</p>
                @endif
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.vp-nuevo')</p>
                <p class="va-kpi-value" data-kpi="nuevo">{{ $dinero($nuevo) }}</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">@lang('ventasasesor::app.costos.vp-con-cambio')</p>
                <p class="va-kpi-value" data-kpi="con-cambio">{{ $vista->conCambio }}</p>
                @if ($vista->sinCambio > 0)
                    <p class="va-sub">@lang('ventasasesor::app.costos.vp-sin-cambio', ['cantidad' => $vista->sinCambio])</p>
                @endif
            </div>
        </div>

        <p class="va-panel mt-4 p-3 text-sm text-slate-700 dark:text-gray-300">@lang('ventasasesor::app.costos.vp-impacto')</p>

        <div class="va-panel va-desktop-table mt-4 overflow-hidden">
            <table class="va-orders-table text-left text-sm">
                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        <th>@lang('ventasasesor::app.costos.col-sku')</th>
                        <th>@lang('ventasasesor::app.costos.col-producto')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-actual')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-nuevo')</th>
                        <th class="text-right">@lang('ventasasesor::app.costos.col-diferencia')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vista->filas as $f)
                        <tr data-variante="{{ $f['id'] }}">
                            <td class="font-semibold text-gray-900 dark:text-white">{{ $f['sku'] }}</td>
                            <td class="text-xs text-slate-600 dark:text-gray-300">{{ $f['producto'] ?? $f['nombre'] }}</td>
                            <td class="text-right">{{ $dinero($f['actual']) }}</td>
                            <td class="text-right font-semibold">{{ $dinero($f['nuevo']) }}</td>
                            <td class="text-right">{!! $variacion($f['diferencia']) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="va-mobile-orders mt-4 gap-3">
            @foreach ($vista->filas as $f)
                <div class="va-panel p-4">
                    <p class="truncate font-bold text-gray-900 dark:text-white">{{ $f['sku'] }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $f['producto'] ?? $f['nombre'] }}</p>
                    <div class="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-3 text-sm dark:bg-gray-950">
                        <div><p class="va-caption">@lang('ventasasesor::app.costos.col-actual')</p>{{ $dinero($f['actual']) }}</div>
                        <div><p class="va-caption">@lang('ventasasesor::app.costos.col-nuevo')</p><span class="font-semibold">{{ $dinero($f['nuevo']) }}</span></div>
                        <div><p class="va-caption">@lang('ventasasesor::app.costos.col-diferencia')</p>{!! $variacion($f['diferencia']) !!}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.ventas_asesor.costos.actualizar') }}" class="mt-4 flex flex-wrap items-center justify-end gap-2" data-form="confirmar-costos">
            @csrf
            @foreach ($vista->ids as $id)
                <input type="hidden" name="ids[]" value="{{ $id }}">
            @endforeach
            <input type="hidden" name="nuevo_costo" value="{{ $vista->nuevoCosto }}">
            <input type="hidden" name="token" value="{{ $vista->token }}">
            <input type="hidden" name="motivo" value="{{ $motivo }}">

            <a href="{{ route('admin.ventas_asesor.costos.index', array_filter($filtros)) }}" class="transparent-button">@lang('ventasasesor::app.costos.volver')</a>
            @if ($vista->conCambio > 0)
                <button type="submit" class="primary-button">@lang('ventasasesor::app.costos.confirmar')</button>
            @endif
        </form>
    </div>
</x-admin::layouts>
