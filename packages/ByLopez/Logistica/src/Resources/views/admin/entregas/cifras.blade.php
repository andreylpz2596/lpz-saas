{{-- Despachadas / Entregadas / Retornadas / Pendientes. Sin tallas: eso es del detalle. --}}
<div class="lg-stats {{ $clase ?? '' }}" data-cifras>
    <div class="lg-stat {{ $despachadas == 0 ? 'lg-stat--cero' : '' }}"><b>{{ $despachadas }}</b><span>@lang('logistica::app.entregas.col-despachadas')</span></div>
    <div class="lg-stat {{ $entregadas == 0 ? 'lg-stat--cero' : '' }}"><b>{{ $entregadas }}</b><span>@lang('logistica::app.entregas.col-entregadas')</span></div>
    <div class="lg-stat {{ $retornadas == 0 ? 'lg-stat--cero' : '' }}"><b>{{ $retornadas }}</b><span>@lang('logistica::app.entregas.col-retornadas')</span></div>
    <div class="lg-stat {{ $pendientes > 0 ? 'lg-stat--pend' : 'lg-stat--cero' }}"><b>{{ $pendientes }}</b><span>@lang('logistica::app.entregas.col-pendientes')</span></div>
</div>