{{-- Doc. 32: «Costos y precios». Encabezado y pestañas compartidos por «Costos» y «Precios por grupo». $activa: costos|precios --}}
@php
    use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autorizacion;

    $autorizacion = app(Autorizacion::class);
    $quien = auth()->guard('admin')->user();
    $verCostos = $autorizacion->tienePermiso($quien, Autorizacion::PERMISO_COSTOS);
    $verPrecios = $autorizacion->tienePermiso($quien, Autorizacion::PERMISO_PRECIOS_GRUPO);
    $tabClass = fn ($t) => $activa === $t
        ? 'border-b-2 border-blue-600 px-3 py-2 text-sm font-semibold text-blue-600'
        : 'border-b-2 border-transparent px-3 py-2 text-sm text-gray-600 hover:text-blue-600 dark:text-gray-300';
@endphp

<div class="flex items-start gap-4">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
        <span class="icon-report"></span>
    </span>

    <div>
        <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::app.costos.titulo')</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">
            {{ $activa === 'precios' ? trans('ventasasesor::app.precios.subtitulo') : trans('ventasasesor::app.costos.subtitulo') }}
        </p>
    </div>
</div>

<nav class="va-tabs mt-4 border-b border-slate-200 dark:border-gray-800" aria-label="@lang('ventasasesor::app.costos.titulo')">
    @if ($verCostos)<a href="{{ route('admin.ventas_asesor.costos.index') }}" class="{{ $tabClass('costos') }}" @if ($activa === 'costos') aria-current="page" @endif>@lang('ventasasesor::app.costos.pestana-costos')</a>@endif
    @if ($verPrecios)<a href="{{ route('admin.ventas_asesor.precios_grupo.index') }}" class="{{ $tabClass('precios') }}" @if ($activa === 'precios') aria-current="page" @endif data-pestana="precios">@lang('ventasasesor::app.costos.pestana-precios')</a>@endif
</nav>
