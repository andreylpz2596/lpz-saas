{{-- Encabezado comun de Rentabilidad: titulo, pestañas segun permisos y mensajes. Espera $vista y $permisos. --}}
@php
    $tabs = ['diagnostico' => $permisos['ver'], 'configuracion' => $permisos['configurar'], 'panel' => $permisos['panel']];
    $tabClass = fn ($t) => $vista === $t
        ? 'border-b-2 border-blue-600 px-3 py-2 text-sm font-semibold text-blue-600'
        : 'border-b-2 border-transparent px-3 py-2 text-sm text-gray-600 hover:text-blue-600 dark:text-gray-300';
@endphp

<div class="flex items-start gap-4">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
        <span class="icon-report"></span>
    </span>
    <div>
        <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.rentabilidad.titulo')</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('ventasasesor::comisiones.rentabilidad.subtitulo')</p>
    </div>
</div>

<nav class="mt-4 flex gap-1 border-b border-slate-200 dark:border-gray-800">
    @foreach ($tabs as $t => $visible)
        @if ($visible)
            <a href="{{ route('admin.ventas_asesor.rentabilidad.index', ['vista' => $t]) }}" class="{{ $tabClass($t) }}">@lang('ventasasesor::comisiones.rentabilidad.tabs.'.$t)</a>
        @endif
    @endforeach
</nav>

<p class="va-panel mt-4 p-3 text-xs leading-5 {{ config('ventasasesor.rentabilidad.bloqueo_activo') ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-300' }}" data-aviso="bloqueo">
    {{ config('ventasasesor.rentabilidad.bloqueo_activo') ? trans('ventasasesor::comisiones.rentabilidad.bloqueo-activo') : trans('ventasasesor::comisiones.rentabilidad.bloqueo-inactivo') }}
</p>

@foreach (['success' => 'verde', 'error' => 'rojo'] as $tipo => $color)
    @if (session($tipo))
        <div class="va-panel mt-4 p-3 text-sm" data-flash="{{ $tipo }}"><span class="va-pill va-pill--{{ $color }}"><span>{{ session($tipo) }}</span></span></div>
    @endif
@endforeach
