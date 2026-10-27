@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $colorEstado = ['estimada' => 'cian', 'habilitada' => 'verde', 'anulada' => 'gris'];
    $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.titulo')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.comisiones.encabezado')

        <form method="GET" action="{{ route('admin.ventas_asesor.comisiones.index') }}" class="va-panel mt-4 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                <div class="md:col-span-3">
                    <label class="{{ $label }}" for="f-mes">@lang('ventasasesor::comisiones.mes-comercial')</label>
                    <input id="f-mes" type="month" name="mes" value="{{ $mes }}" class="{{ $input }}">
                </div>
                @if ($asesores !== [])
                    <div class="md:col-span-4">
                        <label class="{{ $label }}" for="f-asesor">@lang('ventasasesor::comisiones.asesor')</label>
                        <select id="f-asesor" name="asesor" class="custom-select {{ $input }}">
                            <option value="">@lang('ventasasesor::comisiones.todos')</option>
                            @foreach ($asesores as $id => $nombre)
                                <option value="{{ $id }}" @selected($asesor === (int) $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="md:col-span-3">
                    <label class="{{ $label }}" for="f-estado">@lang('ventasasesor::comisiones.estado')</label>
                    <select id="f-estado" name="estado" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach (['estimada', 'habilitada', 'anulada'] as $e)
                            <option value="{{ $e }}" @selected($estado === $e)>@lang('ventasasesor::comisiones.estados.'.$e)</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end md:col-span-2">
                    <button type="submit" class="secondary-button w-full justify-center">@lang('ventasasesor::comisiones.filtrar')</button>
                </div>
            </div>
        </form>

        {{-- Avance del umbral mensual por asesor (A-07: el umbral sale del acuerdo de cada asesor, nunca de un valor fijo). --}}
        <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-2" data-seccion="resumen-mes">
            @forelse ($resumen as $r)
                <div class="va-panel p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $r['asesor'] }}</p>
                        <span class="va-pill va-pill--sm va-pill--{{ $r['estado_umbral'] === 'definido' ? 'verde' : 'amarillo' }}"><span>@lang('ventasasesor::comisiones.umbral-estados.'.$r['estado_umbral'])</span></span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                        <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.umbral')</p><p class="font-semibold">{{ $r['umbral'] ?? '—' }}</p></div>
                        <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.computables')</p><p class="font-semibold">{{ $r['computables'] }}</p></div>
                        <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.faltan')</p><p class="font-semibold">{{ $r['faltante'] ?? '—' }}</p></div>
                        <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.comisionables')</p><p class="font-semibold">{{ $r['comisionables'] }}</p></div>
                    </div>

                    @if ($r['avance'] !== null)
                        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-gray-800" title="{{ $r['avance'] }} %">
                            <div class="h-2 rounded-full bg-blue-600" style="width: {{ $r['avance'] }}%"></div>
                        </div>
                    @endif

                    {{-- Estimada → habilitada son etapas del MISMO importe: se muestran juntas y nunca como una suma. --}}
                    <div class="va-etapas mt-3" data-etapas="comision">
                        <span>@lang('ventasasesor::comisiones.estimada-etapa'): <strong>{{ $dinero($r['estimada']) }}</strong></span>
                        <span>@lang('ventasasesor::comisiones.habilitada-etapa'): <strong class="text-green-700 dark:text-green-400">{{ $dinero($r['habilitada']) }}</strong></span>
                        <p class="va-etapas__nota">@lang('ventasasesor::comisiones.etapas-nota')</p>
                    </div>
                </div>
            @empty
                <p class="va-panel p-4 text-sm text-slate-500">@lang('ventasasesor::comisiones.sin-lineas-mes')</p>
            @endforelse
        </div>

        <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.ayuda-estados')</p>

        <div class="mt-4 grid gap-3" data-seccion="pedidos-mes">
            @foreach ($pedidos as $p)
                <div class="va-panel p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a href="{{ route('admin.ventas_asesor.comisiones.index', ['pedido' => $p['order_id']]) }}" class="text-base font-semibold text-blue-600 hover:underline">#{{ $p['increment_id'] }}</a>
                        <span class="va-sub">{{ $p['asesor'] }} · {{ \Illuminate\Support\Carbon::parse($p['confirmado_en'])->isoFormat('D MMM YYYY, HH:mm') }}</span>
                    </div>

                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-gray-500">
                                    <th class="py-1 pr-3">@lang('ventasasesor::comisiones.referencia')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.vendidas')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.computables')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.comisionables')</th>
                                    @if ($ve_costos)
                                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.base')</th>
                                    @endif
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.estimada')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.habilitada')</th>
                                    <th class="py-1">@lang('ventasasesor::comisiones.estado')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['lineas'] as $l)
                                    <tr class="border-t border-slate-100 dark:border-gray-800">
                                        <td class="py-1.5 pr-3">{{ $l['referencia'] ?? '—' }}<span class="va-sub block">{{ $l['sku'] }}</span></td>
                                        <td class="py-1.5 pr-3 text-right">{{ $l['vendidas'] }}</td>
                                        <td class="py-1.5 pr-3 text-right">{{ $l['computables'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3 text-right">{{ $l['comisionables'] ?? '—' }}</td>
                                        @if ($ve_costos)
                                            <td class="py-1.5 pr-3 text-right">{{ $dinero($l['base'] ?? null) }}</td>
                                        @endif
                                        <td class="py-1.5 pr-3 text-right">{{ $dinero($l['estimada']) }}</td>
                                        <td class="py-1.5 pr-3 text-right font-semibold">{{ $dinero($l['habilitada']) }}</td>
                                        <td class="py-1.5">
                                            <span class="va-pill va-pill--sm va-pill--{{ $colorEstado[$l['estado']] }}"><span>@lang('ventasasesor::comisiones.estados.'.$l['estado'])</span></span>
                                            @if ($l['liquidacion_id'])
                                                <span class="va-pill va-pill--sm va-pill--indigo"><span>@lang('ventasasesor::comisiones.en-liquidacion')</span></span>
                                            @endif
                                            @foreach (array_unique(array_filter([...$l['marcas'], $l['alerta']])) as $m)
                                                <span class="va-sub block text-amber-700 dark:text-amber-300">⚠ @lang('ventasasesor::comisiones.motivos.'.$m)</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-admin::layouts>
