{{-- Transportadora de un despacho: codigo del catalogo, o el texto libre historico marcado como tal. --}}
@if (($codigo ?? null) !== null && $codigo !== '')
    <span class="lg-pill lg-pill--azul" title="{{ $codigo }}">{{ $nombre ?? $codigo }}</span>
@elseif (trim((string) ($titulo ?? '')) !== '')
    <span class="text-gray-800 dark:text-gray-200">{{ $titulo }}</span>
    <span class="lg-sub">· @lang('logistica::app.entregas.texto-libre')</span>
@else
    <span class="lg-sub">@lang('logistica::app.entregas.sin-transportadora')</span>
@endif
