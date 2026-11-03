{{--
    Avisos GENERALES de la cuenta del mes: se dicen una sola vez, arriba. Los motivos de un pedido concreto van junto al pedido.
    Espera: $panel (de PanelComisionesService). Con las comisiones desactivadas lo dice claramente, sin ocultar las estimaciones.
--}}
@php
    $generales = array_values(array_diff($panel['avisos'], ['sin_fecha_inicio']));
@endphp

@if (! $panel['comisiones_activas'])
    <p class="va-aviso mt-4" data-aviso="comisiones-inactivas" role="status">⚠ @lang('ventasasesor::comisiones.panel.inactivas')</p>
@endif

@if ($generales !== [] || $panel['total_parcial'])
    <div class="va-aviso mt-4" data-aviso="cuenta" role="status">
        <strong>@lang('ventasasesor::comisiones.panel.avisos-titulo')</strong>
        <ul>
            @foreach ($generales as $a)
                <li data-aviso-motivo="{{ $a }}">{{ trans()->has('ventasasesor::comisiones.motivos.'.$a) ? trans('ventasasesor::comisiones.motivos.'.$a) : $a }}</li>
            @endforeach
            @if ($panel['total_parcial'])
                <li data-aviso-motivo="total_parcial">@lang('ventasasesor::comisiones.panel.total-parcial', ['n' => $panel['sin_calcular']])</li>
            @endif
        </ul>
    </div>
@endif
