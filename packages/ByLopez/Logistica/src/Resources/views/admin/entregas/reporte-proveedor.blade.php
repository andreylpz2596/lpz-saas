{{-- Listado: indicador COMPACTO del reporte del proveedor intermediario (99 Envios). Valores e historial estan en «Ver».
     «Cruzada» = hay un reporte aplicado y persistido para esa guia. Es informativo: no es entrega registrada ni validada, ni pago. --}}
@php($r = $r ?? null)
@if ($r && $r['cruzada'])
    <div class="lg-estado" data-reporte-proveedor="cruzada" title="@lang('logistica::app.entregas.rp-ayuda')">
        <span class="lg-pill lg-pill--azul">@lang('logistica::app.entregas.rp-cruzada')</span>
        <span class="lg-sub">@lang('logistica::app.entregas.rp-estado', ['estado' => $r['estado']])</span>
        @if ($r['discrepancia'])
            <span class="lg-pill lg-pill--amarillo" data-reporte-proveedor-aviso="discrepancia">@lang('logistica::app.entregas.rp-discrepancia')</span>
        @endif
    </div>
@elseif ($r && $r['esperada'])
    <div class="lg-estado" data-reporte-proveedor="sin-reporte">
        <span class="lg-sub">@lang('logistica::app.entregas.rp-sin-reporte')</span>
    </div>
@endif
