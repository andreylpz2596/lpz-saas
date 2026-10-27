{{-- Estado de una DEVOLUCION en la bandeja (solo lo que requiere accion de Bodega). --}}
@php($colores = ['pendiente_recibir' => 'lila', 'requiere_revision' => 'rojo'])
<span class="lg-pill lg-pill--punto lg-pill--{{ $colores[$estado] ?? 'gris' }}" data-devolucion="{{ $estado }}">@lang('logistica::app.drawer.dev.'.$estado)</span>