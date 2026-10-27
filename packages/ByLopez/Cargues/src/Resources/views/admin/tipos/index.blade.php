@once
    @push('styles')
        @include('cargues::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('cargues::app.tipos.titulo')
    </x-slot>

    <div class="cg-page">
        <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
            <div>
                <h1 class="cg-title">@lang('cargues::app.tipos.titulo')</h1>
                <p class="cg-sub mt-1">@lang('cargues::app.tipos.subtitulo')</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.cargues.index') }}" class="cg-btn">@lang('cargues::app.tipos.volver')</a>
                <a href="{{ route('admin.cargues.tipos.crear') }}" class="cg-btn cg-btn--primario">@lang('cargues::app.tipos.nuevo')</a>
            </div>
        </div>

        <div class="cg-alert cg-alert--info mt-4">
            <span class="icon-information text-2xl" aria-hidden="true"></span>
            <p>@lang('cargues::app.tipos.ayuda')</p>
        </div>

        <div class="cg-panel mt-4 cg-scroll-x">
            <table class="cg-table">
                <thead>
                    <tr>
                        <th>@lang('cargues::app.tipos.col_nombre')</th>
                        <th>@lang('cargues::app.tipos.col_estado')</th>
                        <th>@lang('cargues::app.tipos.col_version')</th>
                        <th>@lang('cargues::app.tipos.col_politica')</th>
                        <th>@lang('cargues::app.tipos.col_procedimiento')</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tipos as $t)
                        @php($v = $t->versionVigente)
                        @php($borrador = $t->versiones->firstWhere('estado', 'borrador'))
                        <tr>
                            <td>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $t->nombre }}</p>
                                <p class="cg-sub">{{ $t->descripcion }}</p>
                            </td>
                            <td>
                                @if ($t->activo)
                                    <span class="cg-pill cg-pill--verde">@lang('cargues::app.tipos.activo')</span>
                                @elseif ($v)
                                    <span class="cg-pill cg-pill--gris">@lang('cargues::app.tipos.inactivo')</span>
                                @else
                                    <span class="cg-pill cg-pill--amarillo">@lang('cargues::app.tipos.sin_activar')</span>
                                @endif
                                @if ($borrador)
                                    <span class="cg-pill cg-pill--azul">@lang('cargues::app.tipos.borrador')</span>
                                @endif
                            </td>
                            <td>{{ $v ? '#'.$v->numero : '—' }}</td>
                            <td>
                                @php($p = $v?->politica_errores ?? $borrador?->politica_errores)
                                @if ($p)
                                    @lang('cargues::app.tipos.politica_'.$p)
                                @else
                                    <span class="cg-pill cg-pill--amarillo">@lang('cargues::app.tipos.politica_sin_elegir')</span>
                                @endif
                            </td>
                            <td class="cg-mono">{{ $v?->procedimiento ?? $borrador?->procedimiento ?? '—' }}</td>
                            <td class="text-right"><a href="{{ route('admin.cargues.tipos.editar', $t->id) }}" class="cg-link">@lang('cargues::app.tipos.configurar')</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cg-vacio">@lang('cargues::app.tipos.vacio')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
