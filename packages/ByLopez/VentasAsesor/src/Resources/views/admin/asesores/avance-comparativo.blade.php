{{--
    Avance del mes por asesora (administración): mismas definiciones que la tarjeta «Tu avance del mes» de cada una. Espera $comparativo
    (AvanceAsesorService::comparativo). La estimación desconocida no se muestra como $ 0.
--}}
@php
    $nuA = fn ($v) => number_format((int) $v, 0, ',', '.');
    $dineroA = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $mesA = \Illuminate\Support\Carbon::createFromFormat('Y-m', $comparativo[0]['mes'] ?? now()->format('Y-m'))->locale(app()->getLocale())->isoFormat('MMMM YYYY');
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.comisiones.estilos-panel')
    @endpush
@endonce

@if ($comparativo !== [])
    <section class="mt-4" data-seccion="avance-asesoras" aria-label="@lang('ventasasesor::comisiones.panel.resumen-asesora')">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.panel.resumen-asesora') · {{ ucfirst($mesA) }}</h2>
        </div>

        <div class="mt-2 grid grid-cols-1 gap-3 lg:grid-cols-2">
            @foreach ($comparativo as $c)
                <article class="va-panel va-asesora" data-avance-asesora="{{ $c['asesor_id'] }}" data-situacion="{{ $c['situacion'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a class="text-base font-semibold text-blue-600 hover:underline" href="{{ route('admin.ventas_asesor.asesores.index', ['asesor' => $c['asesor_id']]) }}">{{ $c['asesora'] }}</a>
                        <span class="va-sub">
                            @if ($c['umbral'] !== null){{ $nuA($c['computables']) }} / {{ $nuA($c['umbral']) }}@else @lang('ventasasesor::comisiones.umbral-estados.'.$c['estado_umbral']) @endif
                        </span>
                    </div>

                    @if ($c['avance'] !== null)
                        <div class="va-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $c['avance'] }}" aria-label="{{ trans('ventasasesor::comisiones.avance.barra', ['pct' => $c['avance']]) }}">
                            <div class="va-barra__relleno {{ $c['avance'] >= 100 ? 'va-barra__relleno--ok' : '' }}" style="width: {{ $c['avance'] }}%"></div>
                        </div>
                    @endif

                    <dl class="va-asesora__cifras">
                        <div><dt>@lang('ventasasesor::comisiones.computables')</dt><dd data-cifra="computables">{{ $nuA($c['computables']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.comisionables')</dt><dd data-cifra="comisionables">{{ $nuA($c['comisionables']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.avance.despachadas')</dt><dd data-cifra="despachadas">{{ $nuA($c['despachadas']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.avance.entregadas')</dt><dd data-cifra="entregadas">{{ $nuA($c['entregadas']) }}</dd></div>
                        <div>
                            <dt>@lang('ventasasesor::comisiones.avance.estimada')</dt>
                            <dd data-cifra="estimada">
                                @if ($c['estimada'] !== null)
                                    {{ $dineroA($c['estimada']) }}
                                @else
                                    <span title="@lang('ventasasesor::comisiones.avance.estimada-no')">@lang('ventasasesor::comisiones.panel.sin-calcular')</span>
                                @endif
                            </dd>
                        </div>
                        <div><dt>@lang('ventasasesor::comisiones.panel.saldo-aprobado')</dt><dd data-cifra="saldo-aprobado">{{ $dineroA($c['saldo_aprobado']) }}</dd></div>
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
@endif
