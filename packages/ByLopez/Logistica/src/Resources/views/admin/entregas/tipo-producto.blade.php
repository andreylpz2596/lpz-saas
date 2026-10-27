@if (($tipo['principal'] ?? '') !== '')
    <span class="lg-tipo" data-tipo-producto><span class="icon-product"></span>{{ $tipo['principal'] }}</span>
    @if (($tipo['otros'] ?? 0) > 0)
        <span class="lg-sub">@lang('logistica::app.entregas.mas-tipos', ['n' => $tipo['otros']])</span>
    @endif
@else
    <span class="lg-sub">@lang('logistica::app.entregas.sin-tipo')</span>
@endif