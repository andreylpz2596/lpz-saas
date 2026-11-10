{{--
    «Tu avance del mes». Todo viene de AvanceAsesorService (el servidor decide umbral, avance y estimación; la vista solo lo muestra).
    Espera: $avance (arreglo del servicio), $verEstimacion (bool: tiene comisiones.view_own), $urlMisComisiones (?string).
--}}
@php
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $dineroAv = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $sit = $avance['situacion'];
    $mensaje = match ($sit) {
        'antes' => trans('ventasasesor::comisiones.avance.antes', ['computables' => $n($avance['computables']), 'umbral' => $n($avance['umbral'])]),
        'exacto' => trans('ventasasesor::comisiones.avance.exacto'),
        'despues' => trans('ventasasesor::comisiones.avance.despues', ['comisionables' => $n($avance['comisionables'])]),
        default => trans('ventasasesor::comisiones.avance.'.($avance['estado_umbral'] === 'sin_acuerdo' ? 'sin_acuerdo' : 'sin_umbral')),
    };
    $detalle = $sit === 'antes' ? trans('ventasasesor::comisiones.avance.antes-detalle', ['faltante' => $n($avance['faltante'])]) : null;
    $mesLegible = \Illuminate\Support\Carbon::createFromFormat('Y-m', $avance['mes'])->locale(app()->getLocale())->isoFormat('MMMM YYYY');
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.comisiones.estilos-panel')
    @endpush
@endonce

<section class="va-panel va-avance mt-6" data-seccion="avance-mes" data-situacion="{{ $sit }}" aria-labelledby="va-avance-titulo">
    <div class="va-avance__cab">
        <h2 id="va-avance-titulo" class="va-avance__titulo">@lang('ventasasesor::comisiones.avance.titulo')</h2>
        <span class="va-avance__mes">{{ ucfirst($mesLegible) }}</span>
    </div>

    <p class="va-avance__mensaje" data-avance-mensaje>{{ $mensaje }}</p>
    @if ($detalle)
        <p class="va-avance__detalle">{{ $detalle }}</p>
    @endif

    @if ($avance['avance'] !== null)
        <div class="va-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $avance['avance'] }}" aria-label="{{ trans('ventasasesor::comisiones.avance.barra', ['pct' => $avance['avance']]) }}">
            <div class="va-barra__relleno {{ $avance['avance'] >= 100 ? 'va-barra__relleno--ok' : '' }}" style="width: {{ $avance['avance'] }}%"></div>
        </div>
    @endif

    <dl class="va-avance__cifras">
        <div><dt>@lang('ventasasesor::comisiones.avance.unidades-cuentan')</dt><dd data-cifra="computables">{{ $n($avance['computables']) }}@if ($avance['umbral'] !== null) <span class="va-sub"> / {{ $n($avance['umbral']) }}</span>@endif</dd></div>
        <div><dt>@lang('ventasasesor::comisiones.avance.despachadas')</dt><dd data-cifra="despachadas">{{ $n($avance['despachadas']) }}</dd></div>
        <div><dt>@lang('ventasasesor::comisiones.avance.entregadas')</dt><dd data-cifra="entregadas">{{ $n($avance['entregadas']) }}</dd></div>
        @if ($verEstimacion && in_array($sit, ['despues'], true))
            <div>
                <dt>@lang('ventasasesor::comisiones.avance.estimada')</dt>
                <dd data-cifra="estimada">{{ $avance['estimada'] !== null ? $dineroAv($avance['estimada']) : '—' }}</dd>
            </div>
        @endif
    </dl>

    @include('ventasasesor::admin.asesores.devolucion', ['devolucion' => $avance['devolucion']])

    @if ($verEstimacion && $sit === 'despues')
        <p class="va-avance__nota">
            @if ($avance['estimada'] !== null)
                @lang('ventasasesor::comisiones.avance.estimada-ayuda')
            @elseif ($avance['estimada_parcial'] !== null && (float) $avance['estimada_parcial'] > 0)
                @lang('ventasasesor::comisiones.avance.estimada-parcial', ['valor' => $dineroAv($avance['estimada_parcial'])])
            @else
                @lang('ventasasesor::comisiones.avance.estimada-no')
            @endif
        </p>
    @endif

    <div class="va-avance__pie">
        @if (in_array($sit, ['exacto', 'despues'], true))
            <p class="va-avance__nota" style="margin:0;flex:1 1 280px">@lang('ventasasesor::comisiones.avance.superar-no-es-pago')</p>
        @endif
        @if ($urlMisComisiones)
            <a href="{{ $urlMisComisiones }}" class="secondary-button" data-accion="mis-comisiones">@lang('ventasasesor::comisiones.avance.ver-mis-comisiones')</a>
        @endif
    </div>

    @if ($verEstimacion)
        @include('ventasasesor::admin.pedidos.simulador')
    @endif
</section>
