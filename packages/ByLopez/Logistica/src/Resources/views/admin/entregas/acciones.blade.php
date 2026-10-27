{{-- Acciones de un envio en la bandeja. Cada una es un enlace a la pagina clasica (respaldo sin JavaScript) que el drawer intercepta. --}}
@php($movil = $movil ?? false)
<div class="lg-acciones" style="{{ $movil ? 'justify-content: stretch' : '' }}" data-acciones>
    <a href="{{ route('admin.logistica.entregas.index', ['envio' => $e->shipment_id]) }}" class="lg-btn" style="{{ $movil ? 'flex: 1' : '' }}" data-logistica-entregas data-envio="{{ $e->shipment_id }}" data-modo="ver">@lang('logistica::app.entregas.ver')</a>
    @if ($permisos['registrar'] && $e->pendientes > 0)
        <a href="{{ route('admin.logistica.entregas.crear', $e->shipment_id) }}" class="lg-btn lg-btn--ok" style="{{ $movil ? 'flex: 1' : '' }}" data-logistica-entregas data-envio="{{ $e->shipment_id }}" data-modo="entregar">@lang('logistica::app.entregas.registrar-entrega')</a>
        <a href="{{ route('admin.logistica.retornos.crear', $e->shipment_id) }}" class="lg-btn lg-btn--no" style="{{ $movil ? 'flex: 1' : '' }}" data-logistica-entregas data-envio="{{ $e->shipment_id }}" data-modo="devolver">@lang('logistica::app.drawer.lista.devolucion')</a>
    @endif
    @if (! empty($permisos['validar']) && $e->lista_para_validar)
        <a href="{{ route('admin.logistica.entregas.index', ['envio' => $e->shipment_id]) }}" class="lg-btn lg-btn--ok" style="{{ $movil ? 'flex: 1' : '' }}" data-logistica-entregas data-envio="{{ $e->shipment_id }}" data-modo="validar">@lang('logistica::app.drawer.validar_entrega')</a>
    @endif
</div>