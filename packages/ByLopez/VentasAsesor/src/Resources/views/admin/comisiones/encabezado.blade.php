{{-- Encabezado comun de Comisiones: titulo, pestañas segun permisos y mensajes. Espera $vista, $permisos, $mes, $asesor. --}}
@php
    $tabs = ['resumen' => true, 'liquidaciones' => true, 'acuerdos' => $permisos['configurar']];
    $tabClass = fn ($t) => $vista === $t
        ? 'border-b-2 border-blue-600 px-3 py-2 text-sm font-semibold text-blue-600'
        : 'border-b-2 border-transparent px-3 py-2 text-sm text-gray-600 hover:text-blue-600 dark:text-gray-300';
@endphp

<div class="va-encab">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
        <span class="icon-sales"></span>
    </span>

    <div class="va-encab__texto">
        <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.titulo')</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">
            {{ $permisos['ver_todas'] ? trans('ventasasesor::comisiones.subtitulo-admin') : trans('ventasasesor::comisiones.subtitulo-asesor') }}
        </p>
    </div>

    @if ($permisos['calcular'])
        <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.evaluar') }}" class="va-encab__accion">
            @csrf
            <button type="submit" class="secondary-button" title="@lang('ventasasesor::comisiones.reevaluar-ayuda')" aria-describedby="com-reevaluar-ayuda">@lang('ventasasesor::comisiones.reevaluar')</button>
            <span id="com-reevaluar-ayuda" class="sr-only">@lang('ventasasesor::comisiones.reevaluar-ayuda')</span>
            @if (! empty($bandeja) && $bandeja['pendientes'] > 0)
                <span class="text-xs {{ $bandeja['agotadas'] > 0 ? 'text-red-600' : 'text-slate-500' }}">
                    @lang('ventasasesor::comisiones.bandeja-pendientes', ['n' => $bandeja['pendientes']])
                    @if ($bandeja['agotadas'] > 0) · @lang('ventasasesor::comisiones.bandeja-agotadas', ['n' => $bandeja['agotadas']]) @endif
                </span>
            @endif
        </form>
    @endif
</div>

<nav class="va-tabs mt-4 border-b border-slate-200 dark:border-gray-800" aria-label="Secciones de comisiones">
    @foreach ($tabs as $t => $visible)
        @if ($visible)
            <a href="{{ route('admin.ventas_asesor.comisiones.index', array_filter(['vista' => $t, 'mes' => $mes, 'asesor' => $asesor])) }}" class="{{ $tabClass($t) }}" @if ($vista === $t) aria-current="page" @endif>@lang('ventasasesor::comisiones.tabs.'.$t)</a>
        @endif
    @endforeach
</nav>

@foreach (['success' => 'verde', 'error' => 'rojo'] as $tipo => $color)
    @if (session($tipo))
        <div class="va-panel mt-4 p-3 text-sm" data-flash="{{ $tipo }}"><span class="va-pill va-pill--{{ $color }}"><span>{{ session($tipo) }}</span></span></div>
    @endif
@endforeach

@if (empty(config('ventasasesor.comisiones.fecha_inicio')) && $permisos['ver_todas'])
    <p class="va-panel mt-4 p-3 text-xs leading-5 text-amber-700 dark:text-amber-300" data-aviso="sin-fecha-inicio">⚠ @lang('ventasasesor::comisiones.aviso-sin-fecha-inicio')</p>
@endif
