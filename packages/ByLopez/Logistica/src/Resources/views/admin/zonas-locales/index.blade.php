@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.zonas_locales.titulo')
    </x-slot>

    <div class="lg-page" data-vista="zonas-locales">
        <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('logistica::app.zonas_locales.titulo')</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('logistica::app.zonas_locales.subtitulo')</p>

        @include('logistica::admin.flash')

        <p class="lg-panel mt-4 p-3 text-sm text-slate-700 dark:text-gray-300">@lang('logistica::app.zonas_locales.ayuda')</p>

        @if ($zonas->isEmpty())
            <p class="lg-panel mt-4 p-3 text-sm">@lang('logistica::app.zonas_locales.sin-zonas')</p>
        @endif

        @forelse ($bodegas as $bodega)
            <form method="POST" action="{{ route('admin.logistica.zonas_locales.guardar', $bodega->id) }}" class="lg-panel mt-4 p-4" data-bodega="{{ $bodega->id }}">
                @csrf
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $bodega->name }}
                    <span class="text-xs font-normal text-slate-500">{{ trim($bodega->city.' '.$bodega->state) }}</span>
                </h2>

                <div class="mt-3 flex flex-wrap gap-4">
                    @foreach ($zonas as $zona)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="zonas[]" value="{{ $zona->id }}" @checked(in_array((int) $zona->id, $asignadas[(int) $bodega->id] ?? [], true)) @disabled(! $puedeGestionar)>
                            {{ $zona->nombre }}
                        </label>
                    @endforeach
                </div>

                @if ($puedeGestionar)
                    <button type="submit" class="lg-btn mt-3">@lang('logistica::app.zonas_locales.guardar')</button>
                @endif
            </form>
        @empty
            <p class="lg-panel mt-4 p-3 text-sm">@lang('logistica::app.zonas_locales.sin-bodegas')</p>
        @endforelse
    </div>
</x-admin::layouts>
