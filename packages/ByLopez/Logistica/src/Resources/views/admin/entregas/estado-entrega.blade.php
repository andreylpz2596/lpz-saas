{{-- Estado de la ENTREGA, derivado de lo registrado (no es lo que reporta la transportadora). --}}
@php
    $colores = ['pendiente' => 'amarillo', 'parcial' => 'amarillo', 'entregado' => 'verde', 'retornado' => 'rojo', 'entregado_retorno' => 'azul'];
@endphp
<span class="lg-pill lg-pill--punto lg-pill--{{ $colores[$estado] ?? 'gris' }}" data-estado-entrega="{{ $estado }}">@lang('logistica::app.entregas.ee.'.$estado)</span>