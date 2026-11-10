{{-- Pestañas de la sección «Órdenes de confección». $activa: 'ordenes' | 'consolidado'. --}}
@php
    $pestanas = [
        'ordenes' => ['Órdenes de confección', route('admin.corte_confeccion.ordenes_confeccion.index')],
        'consolidado' => ['Consolidado general', route('admin.corte_confeccion.ordenes_confeccion.consolidado')],
    ];
@endphp

<nav class="mt-3 flex gap-1 overflow-x-auto border-b dark:border-gray-800" aria-label="Vistas de órdenes de confección">
    @foreach ($pestanas as $clave => [$texto, $url])
        <a
            href="{{ $url }}"
            class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold transition-all {{ $activa === $clave ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white' }}"
            @if ($activa === $clave) aria-current="page" @endif
        >{{ $texto }}</a>
    @endforeach
</nav>
