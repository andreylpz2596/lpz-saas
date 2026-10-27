@foreach (['success' => 'verde', 'error' => 'rojo'] as $tipo => $color)
    @if (session($tipo))
        <div class="lg-panel mt-4 p-3 text-sm" data-flash="{{ $tipo }}"><span class="lg-pill lg-pill--{{ $color }}">{{ session($tipo) }}</span></div>
    @endif
@endforeach
