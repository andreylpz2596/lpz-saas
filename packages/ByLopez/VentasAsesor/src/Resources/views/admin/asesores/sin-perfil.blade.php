{{-- ASESOR-01: usuario con `asesores.view_own` sin perfil de asesor: aviso claro, sin listado ni datos ajenos. --}}
<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.asesores.titulo')
    </x-slot>

    <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('ventasasesor::app.asesores.sin-perfil-titulo')</p>

    @include('ventasasesor::admin.asesores.aviso-sin-asociacion', ['asesorSinAsociacion' => true])
</x-admin::layouts>
