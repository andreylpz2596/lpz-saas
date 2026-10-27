@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $colores = ['cumple' => 'verde', 'incumple' => 'rojo', 'incompleta' => 'amarillo', 'sin_politica' => 'gris', 'no_aplica' => 'gris'];
    $nivel = ['bloquea' => 'rojo', 'advierte' => 'amarillo', 'info' => 'cian'];
    $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.rentabilidad.titulo')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.rentabilidad.encabezado')

        {{-- Configuracion faltante: lo que impide evaluar o habilitar (nunca se suple con valores por defecto). --}}
        <div class="va-panel mt-4 p-4" data-seccion="faltantes">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.rentabilidad.faltantes-titulo')</p>
            @if ($faltantes === [])
                <p class="mt-1 text-sm text-green-700 dark:text-green-400">✓ @lang('ventasasesor::comisiones.rentabilidad.sin-faltantes')</p>
            @else
                <ul class="mt-2 grid gap-1 text-sm">
                    @foreach ($faltantes as $f)
                        <li><span class="va-pill va-pill--sm va-pill--{{ $nivel[$f['nivel']] }}"><span>@lang('ventasasesor::comisiones.rentabilidad.niveles.'.$f['nivel'])</span></span> {{ $f['detalle'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="GET" action="{{ route('admin.ventas_asesor.rentabilidad.index') }}" class="va-panel mt-4 p-4">
            <input type="hidden" name="vista" value="diagnostico">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <label class="{{ $label }}" for="f-familia">@lang('ventasasesor::comisiones.referencia')</label>
                    <select id="f-familia" name="familia" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach ($familias as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['familia'] === (int) $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="{{ $label }}" for="f-grupo">@lang('ventasasesor::comisiones.rentabilidad.grupo')</label>
                    <select id="f-grupo" name="grupo" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach ($grupos as $id => $nombre)
                            <option value="{{ $id }}" @selected($filtros['grupo'] === (int) $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-end gap-2 text-sm md:col-span-3"><input type="checkbox" name="solo" value="1" @checked($filtros['solo_incumple'])> @lang('ventasasesor::comisiones.rentabilidad.solo-incumple')</label>
                <div class="flex items-end md:col-span-2"><button type="submit" class="secondary-button w-full justify-center">@lang('ventasasesor::comisiones.filtrar')</button></div>
            </div>
        </form>

        <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach (['cumple', 'incumple', 'incompleta', 'sin_politica'] as $r)
                <div class="va-panel va-kpi-box">
                    <p class="va-kpi-label">@lang('ventasasesor::comisiones.resultados.'.$r)</p>
                    <p class="va-kpi-value">{{ $precios['resumen'][$r] ?? 0 }}</p>
                </div>
            @endforeach
        </div>

        <p class="va-panel mt-4 p-3 text-xs leading-5 text-slate-600 dark:text-gray-300">
            @lang('ventasasesor::comisiones.rentabilidad.diagnostico-ayuda', ['tasa' => $precios['tasa'] === null ? trans('ventasasesor::comisiones.rentabilidad.sin-tasa') : rtrim(rtrim($precios['tasa'], '0'), '.').' %'])
        </p>

        <div class="va-panel mt-4 overflow-x-auto p-4" data-seccion="precios-por-grupo">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="py-1 pr-3">@lang('ventasasesor::comisiones.referencia')</th>
                        <th class="py-1 pr-3">@lang('ventasasesor::comisiones.rentabilidad.grupo')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.rentabilidad.desde-unidades')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.rentabilidad.variantes')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.precio-neto')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.costo')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.precio-minimo')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.rentabilidad.diferencia')</th>
                        <th class="py-1">@lang('ventasasesor::comisiones.estado')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($precios['filas'] as $f)
                        <tr class="border-t border-slate-100 dark:border-gray-800">
                            <td class="py-1.5 pr-3">{{ $f['referencia'] ?? '—' }}<span class="va-sub block">@lang('ventasasesor::comisiones.estado-costo.'.$f['estado_costo'])</span></td>
                            <td class="py-1.5 pr-3">{{ $f['grupo'] }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $f['qty'] }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $f['variantes'] }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $dinero($f['precio_neto']) }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $dinero($f['costo']) }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $dinero($f['precio_minimo']) }}</td>
                            <td class="py-1.5 pr-3 text-right {{ $f['diferencia'] !== null && (float) $f['diferencia'] < 0 ? 'text-red-600 font-semibold' : '' }}">{{ $dinero($f['diferencia']) }}</td>
                            <td class="py-1.5"><span class="va-pill va-pill--sm va-pill--{{ $colores[$f['resultado']] }}"><span>@lang('ventasasesor::comisiones.resultados.'.$f['resultado'])</span></span></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-3 text-sm text-slate-500">@lang('ventasasesor::comisiones.rentabilidad.sin-filas')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
