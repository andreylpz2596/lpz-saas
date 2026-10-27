{{--
    Hoja de estilos propia de packages/ByLopez/CorteConfeccion (ver package.json /
    tailwind.config.js en la raiz del paquete). El build de Tailwind de
    packages/Webkul/Admin solo escanea sus propios blade files, asi que las clases
    usadas unicamente en las vistas de este paquete nunca se compilaban ahi.
    Este @include se agrega en cada vista admin del paquete que use clases Tailwind
    propias; @once evita duplicar el <link> si una vista incluye varios partials.
--}}
@once
    @php
        $bylopezCssPath = public_path('themes/admin/default/build-bylopez/corteconfeccion.css');
    @endphp

    @if (file_exists($bylopezCssPath))
        @push('styles')
            <link rel="stylesheet" href="{{ asset('themes/admin/default/build-bylopez/corteconfeccion.css') }}?v={{ filemtime($bylopezCssPath) }}">
        @endpush
    @endif
@endonce
