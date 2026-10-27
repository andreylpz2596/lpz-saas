{{-- Boton «Entregas» en la vista nativa del pedido (hook page_action.before). --}}
<a href="{{ route('admin.logistica.entregas.index', ['pedido' => $pedidoId, 'estado' => 'todos']) }}"
   class="transparent-button px-1 py-1.5 hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
   data-logistica="entregas-pedido"
   title="{{ trans('logistica::app.entregas.boton-pedido-saldo', ['entregadas' => $resumen['entregadas'], 'despachadas' => $resumen['despachadas']]) }}">
    <span class="icon-ship text-2xl"></span>
    @lang('logistica::app.entregas.boton-pedido') ({{ $resumen['entregadas'] }}/{{ $resumen['despachadas'] }})
</a>
