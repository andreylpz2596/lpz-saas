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

        <p class="lg-nota-fina mt-3" data-ayuda-zonas>@lang('logistica::app.zonas_locales.ayuda')</p>

        @include('logistica::admin.zonas-locales.componente')
    </div>
</x-admin::layouts>
