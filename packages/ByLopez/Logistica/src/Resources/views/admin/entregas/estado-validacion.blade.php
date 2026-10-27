{{-- Validacion ByLopez. Hoy solo existen sin_registro / registrada / anulada; pendiente_confirmar, confirmado y requiere_revision
     ya tienen su aspecto y texto para cuando exista la validacion administrativa (ver el reporte de arquitectura). --}}
@php
    $colores = ['sin_registro' => 'gris', 'registrada' => 'verde', 'anulada' => 'gris', 'pendiente_confirmar' => 'lila', 'confirmado' => 'verde', 'requiere_revision' => 'rojo'];
@endphp
<span class="lg-pill lg-pill--punto lg-pill--{{ $colores[$estado] ?? 'gris' }}" data-validacion="{{ $estado }}" title="{{ Lang::has('logistica::app.entregas.val-ayuda.'.$estado) ? trans('logistica::app.entregas.val-ayuda.'.$estado) : '' }}">@lang('logistica::app.entregas.val.'.$estado)</span>