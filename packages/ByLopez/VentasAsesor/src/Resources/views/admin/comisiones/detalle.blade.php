@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $colorEstado = ['estimada' => 'cian', 'habilitada' => 'verde', 'anulada' => 'gris'];
    $d = $detalle;
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.detalle-titulo', ['pedido' => $d['pedido']->increment_id])</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.comisiones.encabezado')

        <div class="va-panel mt-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xl font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.detalle-titulo', ['pedido' => $d['pedido']->increment_id])</p>
                <a href="{{ route('admin.ventas_asesor.comisiones.index', ['mes' => $d['mes']]) }}" class="transparent-button">@lang('ventasasesor::comisiones.volver')</a>
            </div>
            <p class="va-sub mt-1">@lang('ventasasesor::comisiones.mes-comercial'): {{ $d['mes'] }}@if ($d['periodo'] ?? null) ({{ $d['periodo'] }})@endif · @lang('ventasasesor::comisiones.estado-pedido'): {{ $d['pedido']->status }}</p>

            {{-- Condiciones A-04: todas a la vez. Sin pendientes = habilitable. --}}
            <div class="mt-3" data-seccion="condiciones">
                <p class="text-sm font-semibold">@lang('ventasasesor::comisiones.condiciones')</p>
                @if ($d['condiciones'] === [])
                    <p class="mt-1 text-sm text-green-700 dark:text-green-400">✓ @lang('ventasasesor::comisiones.condiciones-ok')</p>
                @else
                    <ul class="mt-1 grid gap-0.5 text-sm text-amber-700 dark:text-amber-300">
                        @foreach ($d['condiciones'] as $m)
                            <li>• @lang('ventasasesor::comisiones.motivos.'.$m)</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="va-panel mt-4 overflow-x-auto p-4" data-seccion="lineas">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="py-1 pr-3">@lang('ventasasesor::comisiones.referencia')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.precio-neto')</th>
                        @if ($d['ve_costos'])
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.costo')</th>
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.base')</th>
                            <th class="py-1 pr-3 text-right">%</th>
                        @endif
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.vendidas')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.computables')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.comisionables')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.estimada')</th>
                        <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.habilitada')</th>
                        <th class="py-1">@lang('ventasasesor::comisiones.estado')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['lineas'] as $l)
                        <tr class="border-t border-slate-100 align-top dark:border-gray-800">
                            <td class="py-1.5 pr-3">{{ $l['referencia'] ?? '—' }}<span class="va-sub block">{{ $l['sku'] }}</span></td>
                            <td class="py-1.5 pr-3 text-right">{{ $dinero($l['precio_neto']) }}</td>
                            @if ($d['ve_costos'])
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($l['costo']) }}</td>
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($l['base']) }}</td>
                                <td class="py-1.5 pr-3 text-right">{{ $l['porcentaje'] === null ? '—' : rtrim(rtrim($l['porcentaje'], '0'), '.') }}</td>
                            @endif
                            <td class="py-1.5 pr-3 text-right">{{ $l['vendidas'] }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $l['computables'] ?? '—' }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $l['comisionables'] ?? '—' }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $dinero($l['estimada']) }}</td>
                            <td class="py-1.5 pr-3 text-right font-semibold">{{ $dinero($l['habilitada']) }}</td>
                            <td class="py-1.5">
                                <span class="va-pill va-pill--sm va-pill--{{ $colorEstado[$l['estado']] }}"><span>@lang('ventasasesor::comisiones.estados.'.$l['estado'])</span></span>
                                @if ($l['motivo_anulacion'])
                                    <span class="va-sub block">@lang('ventasasesor::comisiones.motivos.'.$l['motivo_anulacion'])</span>
                                @endif
                                @foreach (array_unique(array_filter([...$l['marcas'], $l['alerta']])) as $m)
                                    <span class="va-sub block text-amber-700 dark:text-amber-300">⚠ @lang('ventasasesor::comisiones.motivos.'.$m)</span>
                                @endforeach
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-xs text-blue-600">@lang('ventasasesor::comisiones.historial')</summary>
                                    <ul class="mt-1 grid gap-0.5 text-xs text-slate-600 dark:text-gray-300">
                                        @foreach ($l['eventos'] as $e)
                                            <li>{{ \Illuminate\Support\Carbon::parse($e['en'])->isoFormat('D MMM YYYY, HH:mm') }} · @lang('ventasasesor::comisiones.eventos.'.$e['tipo']){{ $e['actor'] ? ' · '.$e['actor'] : '' }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($d['ve_costos'] && $d['evidencia'])
            <details class="va-panel mt-4 p-4" data-seccion="evidencia">
                <summary class="cursor-pointer text-sm font-semibold">@lang('ventasasesor::comisiones.evidencia')</summary>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-slate-600 dark:text-gray-300">{{ json_encode($d['evidencia'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif

        @if ($d['ve_costos'] && $d['evaluacion'])
            <div class="va-panel mt-4 overflow-x-auto p-4" data-seccion="rentabilidad">
                <p class="text-sm font-semibold">@lang('ventasasesor::comisiones.rentabilidad-al-confirmar')
                    <span class="va-pill va-pill--sm va-pill--{{ $d['evaluacion']->resultado === 'cumple' ? 'verde' : ($d['evaluacion']->resultado === 'incumple' ? 'rojo' : 'amarillo') }}"><span>@lang('ventasasesor::comisiones.resultados.'.$d['evaluacion']->resultado)</span></span>
                </p>
                <table class="mt-2 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th class="py-1 pr-3">@lang('ventasasesor::comisiones.referencia')</th>
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.precio-neto')</th>
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.precio-minimo')</th>
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.contribucion')</th>
                            <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.requerido')</th>
                            <th class="py-1">@lang('ventasasesor::comisiones.estado')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d['evaluacion']->lineas as $e)
                            <tr class="border-t border-slate-100 dark:border-gray-800">
                                <td class="py-1.5 pr-3">{{ $e->referencia ?? '—' }}<span class="va-sub block">{{ $e->sku }} · @lang('ventasasesor::comisiones.estado-costo.'.$e->estado_costo)</span></td>
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($e->precio_neto_unitario) }}</td>
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($e->precio_minimo) }}</td>
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($e->contribucion_unitaria) }}</td>
                                <td class="py-1.5 pr-3 text-right">{{ $dinero($e->ganancia_requerida_unitaria) }}</td>
                                <td class="py-1.5">@lang('ventasasesor::comisiones.resultados.'.$e->resultado)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="va-sub mt-2">@lang('ventasasesor::comisiones.costo-pedido-estimado'): {{ $dinero($d['evaluacion']->costo_pedido_estimado) }} · @lang('ventasasesor::comisiones.holgura'): {{ $dinero($d['evaluacion']->holgura_pedido) }}</p>
            </div>
        @endif
    </div>
</x-admin::layouts>
