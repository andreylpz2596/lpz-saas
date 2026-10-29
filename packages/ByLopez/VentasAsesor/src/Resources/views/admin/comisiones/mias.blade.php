@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $colorEtapa = ['bloqueada' => 'amarillo', 'habilitada' => 'verde', 'por_pagar' => 'indigo', 'pagada' => 'gris'];
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    $u = $datos['umbral'];
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.mis.titulo')</x-slot>

    <div class="va-orders-page bylopez-scope" data-vista="mis-comisiones">
        <div class="va-encab">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                <span class="icon-sales"></span>
            </span>
            <div class="va-encab__texto">
                <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.mis.titulo')</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.mis.subtitulo')</p>
            </div>
        </div>

        @if (empty(config('ventasasesor.comisiones.fecha_inicio')))
            <p class="va-panel mt-4 p-3 text-xs leading-5 text-amber-700 dark:text-amber-300" data-aviso="sin-fecha-inicio">⚠ @lang('ventasasesor::comisiones.aviso-sin-fecha-inicio')</p>
        @endif

        <form method="GET" action="{{ route('admin.ventas_asesor.comisiones.mias') }}" class="va-panel mt-4 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-mes">@lang('ventasasesor::comisiones.mes-comercial')</label>
                    <input id="f-mes" type="month" name="mes" value="{{ $mes }}" class="{{ $input }}">
                </div>
                <div class="flex items-end md:col-span-2">
                    <button type="submit" class="secondary-button w-full justify-center">@lang('ventasasesor::comisiones.filtrar')</button>
                </div>
            </div>
        </form>

        {{-- Las cuatro etapas se reparten el MISMO importe sin solaparse: su suma es la comision del mes. --}}
        <div class="va-panel mt-4 p-4" data-seccion="etapas">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="va-kpi-label">@lang('ventasasesor::comisiones.mis.total-mes')</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white" data-total-mes>{{ $dinero($datos['total_mes']) }}</p>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.mis.total-mes-ayuda')</p>

            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['bloqueada', 'habilitada', 'por_pagar', 'pagada'] as $e)
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-gray-800" data-etapa="{{ $e }}">
                        <span class="va-pill va-pill--sm va-pill--{{ $colorEtapa[$e] }}"><span>@lang('ventasasesor::comisiones.mis.etapas.'.$e)</span></span>
                        <p class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ $dinero($datos['etapas'][$e]) }}</p>
                        <p class="mt-1 text-xs leading-4 text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.mis.etapas-ayuda.'.$e)</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.mis.saldo-por-pagar')</p><p class="font-semibold" data-saldo-por-pagar>{{ $dinero($datos['saldo_por_pagar']) }}</p></div>
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.mis.habilitado-sin-liquidar')</p><p class="font-semibold">{{ $dinero($datos['habilitado_sin_liquidar']) }}</p></div>
            </div>
        </div>

        <div class="va-panel mt-4 p-4" data-seccion="umbral">
            <div class="flex items-center justify-between">
                <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.mis.umbral')</p>
                <span class="va-pill va-pill--sm va-pill--{{ $u['estado'] === 'definido' ? 'verde' : 'amarillo' }}"><span>@lang('ventasasesor::comisiones.umbral-estados.'.$u['estado'])</span></span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.umbral')</p><p class="font-semibold">{{ $u['valor'] ?? '—' }}</p></div>
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.computables')</p><p class="font-semibold">{{ $u['computables'] }}</p></div>
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.faltan')</p><p class="font-semibold">{{ $u['faltante'] ?? '—' }}</p></div>
                <div><p class="va-kpi-label">@lang('ventasasesor::comisiones.comisionables')</p><p class="font-semibold">{{ $u['comisionables'] }}</p></div>
            </div>
            @if ($u['avance'] !== null)
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-gray-800" title="{{ $u['avance'] }} %">
                    <div class="h-2 rounded-full bg-blue-600" style="width: {{ $u['avance'] }}%"></div>
                </div>
            @endif
        </div>

        <div class="mt-4 grid gap-3" data-seccion="pedidos-mes">
            @forelse ($datos['pedidos'] as $p)
                <div class="va-panel p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a href="{{ route('admin.ventas_asesor.comisiones.index', ['pedido' => $p['order_id']]) }}" class="text-base font-semibold text-blue-600 hover:underline">@lang('ventasasesor::comisiones.mis.pedido') #{{ $p['increment_id'] }}</a>
                        <span class="va-sub">{{ \Illuminate\Support\Carbon::parse($p['confirmado_en'])->isoFormat('D MMM YYYY, HH:mm') }}</span>
                    </div>

                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-gray-500">
                                    <th class="py-1 pr-3">@lang('ventasasesor::comisiones.referencia')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.vendidas')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.comisionables')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.valor')</th>
                                    <th class="py-1">@lang('ventasasesor::comisiones.estado') · @lang('ventasasesor::comisiones.mis.que-falta')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['lineas'] as $l)
                                    <tr class="border-t border-slate-100 dark:border-gray-800" data-etapa-linea="{{ $l['etapa'] }}">
                                        <td class="py-1.5 pr-3">{{ $l['referencia'] ?? '—' }}<span class="va-sub block">{{ $l['sku'] }}</span></td>
                                        <td class="py-1.5 pr-3 text-right">{{ $l['vendidas'] }}</td>
                                        <td class="py-1.5 pr-3 text-right">{{ $l['comisionables'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3 text-right font-semibold">{{ $dinero($l['importe']) }}</td>
                                        <td class="py-1.5">
                                            <span class="va-pill va-pill--sm va-pill--{{ $colorEtapa[$l['etapa']] }}"><span>@lang('ventasasesor::comisiones.mis.etapas.'.$l['etapa'])</span></span>
                                            @foreach ($l['motivos'] as $m)
                                                <span class="va-sub block text-amber-700 dark:text-amber-300" data-motivo="{{ $m }}">⚠ {{ trans()->has('ventasasesor::comisiones.motivos.'.$m) ? trans('ventasasesor::comisiones.motivos.'.$m) : trans('ventasasesor::comisiones.motivos.sin_distribucion') }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="va-panel p-4 text-sm text-slate-500">@lang('ventasasesor::comisiones.mis.sin-ventas')</p>
            @endforelse
        </div>

        <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.mis.politicas')</p>

        <div class="va-panel mt-4 p-4" data-seccion="liquidaciones">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.mis.liquidaciones')</p>

            @forelse ($datos['liquidaciones'] as $q)
                <div class="mt-3 rounded-lg border border-slate-100 p-3 dark:border-gray-800" data-liquidacion="{{ $q['numero'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ $q['numero'] }} <span class="va-sub">{{ $q['periodo'] }}</span></span>
                        <span class="text-sm">@lang('ventasasesor::comisiones.mis.liquidacion-total'): <strong>{{ $dinero($q['total']) }}</strong> · @lang('ventasasesor::comisiones.mis.abonado'): <strong>{{ $dinero($q['abonado']) }}</strong> · @lang('ventasasesor::comisiones.mis.saldo'): <strong>{{ $dinero($q['saldo']) }}</strong></span>
                    </div>

                    @if ($q['abonos'] === [])
                        <p class="va-sub mt-2">@lang('ventasasesor::comisiones.mis.sin-abonos')</p>
                    @else
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-xs uppercase text-gray-500">
                                    <th class="py-1 pr-3">@lang('ventasasesor::comisiones.mis.fecha')</th>
                                    <th class="py-1 pr-3">@lang('ventasasesor::comisiones.estado')</th>
                                    <th class="py-1 pr-3 text-right">@lang('ventasasesor::comisiones.valor')</th>
                                    <th class="py-1">@lang('ventasasesor::comisiones.mis.medio')</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($q['abonos'] as $a)
                                        <tr class="border-t border-slate-100 dark:border-gray-800">
                                            <td class="py-1.5 pr-3">{{ $a['fecha'] }}</td>
                                            <td class="py-1.5 pr-3">@lang('ventasasesor::comisiones.mis.tipo-abono.'.$a['tipo'])</td>
                                            <td class="py-1.5 pr-3 text-right">{{ $dinero($a['monto']) }}</td>
                                            <td class="py-1.5">{{ $a['medio'] }} {{ $a['referencia'] ? '· '.$a['referencia'] : '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @empty
                <p class="va-sub mt-2">@lang('ventasasesor::comisiones.mis.sin-liquidaciones')</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
