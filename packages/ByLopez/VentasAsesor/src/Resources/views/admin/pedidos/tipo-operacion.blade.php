{{-- G-11: tipo de operacion no normal en la vista nativa del pedido (no comisiona). --}}
<span class="label-pending ml-2" data-tipo-operacion="{{ $tipo->value }}" title="{{ trans('ventasasesor::app.tipo-operacion.no-comisiona') }}">
    @lang('ventasasesor::app.tipo-operacion.tipos.'.$tipo->value)
</span>
@if ($advertir)
    <span class="ml-2 text-sm font-semibold text-amber-700 dark:text-amber-400" data-tipo-operacion-advertencia>
        ⚠ @lang('ventasasesor::app.tipo-operacion.advertencia-total')
    </span>
@endif
