{{-- Transportadora y guia debajo del estado de despacho (dato del envio; el PDF se ofrece aparte en las acciones). --}}
@foreach (array_slice($pedido->guias ?? [], 0, 2) as $g)
    <p class="va-sub" data-guia-resumen>
        {{ $g['transportadora'] }} &middot; <span class="font-semibold">{{ $g['numero_guia'] }}</span>
        @if ($g['tracking'])
            &middot;
            @if ($g['tracking_es_url'])
                <a href="{{ $g['tracking'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">@lang('logistica::app.despachos.modal.tracking-l')</a>
            @else
                {{ $g['tracking'] }}
            @endif
        @endif
    </p>
@endforeach
@if (count($pedido->guias ?? []) > 2)
    <p class="va-sub">+{{ count($pedido->guias) - 2 }}</p>
@endif
