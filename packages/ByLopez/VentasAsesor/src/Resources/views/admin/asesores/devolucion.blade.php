{{--
    Porcentaje de devolución TOTAL de una asesora (paquetes devueltos completos / paquetes despachados del mes). Espera $devolucion
    (AvanceAsesorService::devolucionTotal). Es informativo: no cambia comisiones. El color y el icono acompañan al texto (nunca lo sustituyen).
--}}
@php
    $nivel = $devolucion['nivel'];
    $pct = $devolucion['porcentaje'];
    $pctTexto = $pct === null ? '—' : rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',').' %';
@endphp

<div class="va-dev va-dev--{{ $nivel }}" data-cifra="devolucion" data-nivel="{{ $nivel }}">
    <span class="va-dev__icono" aria-hidden="true">
        {{-- flecha de retorno --}}
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
    </span>
    <div class="va-dev__cuerpo">
        <div class="va-dev__titulo">@lang('ventasasesor::comisiones.devolucion.titulo')</div>
        @if ($pct === null)
            <div class="va-dev__valor">—</div>
            <div class="va-dev__detalle">@lang('ventasasesor::comisiones.devolucion.sin_envios')</div>
        @else
            <div class="va-dev__valor">{{ $pctTexto }} <span class="va-dev__estado">@lang('ventasasesor::comisiones.devolucion.nivel.'.$nivel)</span></div>
            <div class="va-dev__detalle">
                {{ trans('ventasasesor::comisiones.devolucion.detalle', ['devueltos' => $devolucion['devueltos'], 'paquetes' => $devolucion['paquetes']]) }}
                @if ($devolucion['por_recibir'] > 0)
                    · {{ trans('ventasasesor::comisiones.devolucion.por_recibir', ['n' => $devolucion['por_recibir']]) }}
                @endif
            </div>
        @endif
    </div>
</div>
