@php
    use Illuminate\Support\Carbon;

    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 focus:border-blue-500 focus:outline-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $fecha = fn ($f) => $f ? Carbon::parse($f)->isoFormat('D MMM YYYY, HH:mm') : '—';
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.').' %';
    $ahora = now();
    $iniciales = fn ($n) => mb_strtoupper(collect(preg_split('/\s+/', trim((string) $n)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode(''));
    $t = fn ($k, $p = []) => trans('ventasasesor::comisiones.acuerdos-ui.'.$k, $p);

    // Estado de cada asesor: sin acuerdo | umbral sin definir | completo (+ si tiene una version programada a futuro).
    $filas = collect($panel)->map(function ($f) use ($ahora) {
        $v = $f['vigente'];
        $programada = $f['historial']->first(fn ($h) => $h->activo && $h->vigente_desde->gt($ahora));
        $estado = $v === null ? 'sin-acuerdo' : ($v->umbral_mensual_unidades === null ? 'umbral-pendiente' : 'completo');

        return $f + ['estado' => $estado, 'programada' => $programada];
    });
    $totales = [
        'asesores' => $filas->count(),
        'completos' => $filas->where('estado', 'completo')->count(),
        'pendientes' => $filas->whereIn('estado', ['sin-acuerdo', 'umbral-pendiente'])->count(),
        'programadas' => $filas->whereNotNull('programada')->count(),
    ];
    $color = ['completo' => 'verde', 'umbral-pendiente' => 'amarillo', 'sin-acuerdo' => 'rojo'];
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
        <style>
            .va-ac-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(min(100%, 420px), 1fr)); }
            .va-ac-card { transition: box-shadow .15s ease, border-color .15s ease; }
            .va-ac-card:hover { box-shadow: 0 4px 14px rgba(15, 23, 42, .08); }
            .va-ac-card.is-focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
            .va-ac-avatar { display: flex; align-items: center; justify-content: center; flex: none; width: 44px; height: 44px; border-radius: 999px; background: #e0e7ff; color: #3730a3; font-weight: 700; font-size: 15px; }
            .dark .va-ac-avatar { background: #1e1b4b; color: #a5b4fc; }
            .va-ac-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
            .va-ac-metric { border-radius: 10px; background: #f8fafc; padding: 10px 12px; min-width: 0; }
            .dark .va-ac-metric { background: #0f172a; }
            .va-ac-metric dt { font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
            .va-ac-metric dd { margin-top: 2px; font-size: 18px; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }
            .dark .va-ac-metric dd { color: #f1f5f9; }
            .va-ac-metric dd small { font-size: 12px; font-weight: 500; color: #64748b; }
            .va-ac-metric.is-warn dd { color: #b45309; font-size: 14px; }
            .va-ac-details > summary { list-style: none; cursor: pointer; }
            .va-ac-details > summary::-webkit-details-marker { display: none; }
            .va-ac-details[open] > summary .va-ac-chevron { transform: rotate(90deg); }
            .va-ac-chevron { display: inline-block; transition: transform .15s ease; }
            .va-ac-hist-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; }
            .dark .va-ac-hist-card { border-color: #1f2937; }
            @media (max-width: 640px) {
                .va-ac-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .va-ac-metric--wide { grid-column: span 2; }
            }
        </style>
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.tabs.acuerdos')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.comisiones.encabezado')

        {{-- Resumen --}}
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-seccion="resumen-acuerdos">
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">{{ $t('kpi-asesores') }}</p>
                <p class="va-kpi-value">{{ $totales['asesores'] }}</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">{{ $t('kpi-completos') }}</p>
                <p class="va-kpi-value text-green-700">{{ $totales['completos'] }}</p>
                <p class="va-sub">{{ $t('kpi-completos-ayuda') }}</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">{{ $t('kpi-pendientes') }}</p>
                <p class="va-kpi-value {{ $totales['pendientes'] > 0 ? 'text-amber-600' : '' }}">{{ $totales['pendientes'] }}</p>
                <p class="va-sub">{{ $t('kpi-pendientes-ayuda') }}</p>
            </div>
            <div class="va-panel va-kpi-box">
                <p class="va-kpi-label">{{ $t('kpi-programadas') }}</p>
                <p class="va-kpi-value">{{ $totales['programadas'] }}</p>
                <p class="va-sub">{{ $t('kpi-programadas-ayuda') }}</p>
            </div>
        </div>

        {{-- Ayuda plegable: la regla completa solo cuando se necesita --}}
        <details class="va-panel va-ac-details mt-3 p-3 text-sm" data-seccion="ayuda">
            <summary class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
                <span class="va-ac-chevron text-slate-400">▸</span> {{ $t('como-funciona') }}
            </summary>
            <ul class="mt-2 list-disc space-y-1 pl-6 text-xs leading-5 text-slate-600 dark:text-gray-300">
                @foreach (trans('ventasasesor::comisiones.acuerdos-ui.reglas') as $regla)
                    <li>{{ $regla }}</li>
                @endforeach
            </ul>
        </details>

        {{-- Buscador y filtro por estado --}}
        <div class="va-panel mt-3 flex flex-wrap items-center gap-3 p-3" data-seccion="filtros">
            <div class="min-w-[220px] flex-1">
                <label class="sr-only" for="ac-buscar">{{ $t('buscar') }}</label>
                <input id="ac-buscar" type="search" autocomplete="off" placeholder="{{ $t('buscar') }}" class="{{ $input }}" data-ac-buscar>
            </div>
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ $t('filtrar-estado') }}">
                @foreach (['todos' => $t('f-todos'), 'pendiente' => $t('f-pendientes'), 'completo' => $t('f-completos')] as $clave => $texto)
                    <button type="button" data-ac-filtro="{{ $clave }}" class="rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-blue-400 hover:text-blue-600 dark:border-gray-700 dark:text-gray-300 {{ $clave === 'todos' ? 'bg-blue-50 !text-blue-700 dark:bg-blue-950' : '' }}">{{ $texto }}</button>
                @endforeach
            </div>
        </div>

        {{-- Asesores --}}
        <div class="va-ac-grid mt-4" data-seccion="asesores">
            @forelse ($filas as $fila)
                @php
                    $a = $fila['asesor'];
                    $v = $fila['vigente'];
                    $enfocado = (int) $asesor === (int) $a->admin_id;
                    $pendiente = $fila['estado'] !== 'completo';
                @endphp
                <div class="va-panel va-ac-card flex flex-col p-4 {{ $enfocado ? 'is-focus' : '' }}" id="asesor-{{ $a->admin_id }}"
                     data-ac-card data-nombre="{{ mb_strtolower($a->nombre) }}" data-estado="{{ $pendiente ? 'pendiente' : 'completo' }}">
                    <div class="flex items-start gap-3">
                        <span class="va-ac-avatar" aria-hidden="true">{{ $iniciales($a->nombre) ?: '#' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-semibold text-gray-900 dark:text-white" title="{{ $a->nombre }}">{{ $a->nombre }}</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span class="va-pill va-pill--sm va-pill--{{ $color[$fila['estado']] }}"><span>{{ $t('estado-'.$fila['estado']) }}</span></span>
                                @unless ($a->activo)
                                    <span class="va-pill va-pill--sm va-pill--gris"><span>@lang('ventasasesor::comisiones.inactivo')</span></span>
                                @endunless
                                @if ($fila['programada'])
                                    <span class="va-pill va-pill--sm va-pill--cian" title="{{ $fecha($fila['programada']->vigente_desde) }}"><span>{{ $t('programada-desde', ['fecha' => Carbon::parse($fila['programada']->vigente_desde)->isoFormat('D MMM')]) }}</span></span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($v)
                        <dl class="va-ac-metrics mt-4">
                            <div class="va-ac-metric">
                                <dt>@lang('ventasasesor::comisiones.porcentaje')</dt>
                                <dd>{{ $pct($v->porcentaje) }}</dd>
                            </div>
                            <div class="va-ac-metric {{ $v->umbral_mensual_unidades === null ? 'is-warn' : '' }}">
                                <dt>@lang('ventasasesor::comisiones.umbral')</dt>
                                <dd>
                                    @if ($v->umbral_mensual_unidades === null)
                                        {{ $t('sin-definir') }}
                                    @else
                                        {{ number_format($v->umbral_mensual_unidades, 0, ',', '.') }} <small>{{ $t('unidades') }}</small>
                                    @endif
                                </dd>
                            </div>
                            <div class="va-ac-metric va-ac-metric--wide">
                                <dt>@lang('ventasasesor::comisiones.desde')</dt>
                                <dd><span class="text-sm">{{ Carbon::parse($v->vigente_desde)->isoFormat('D MMM YYYY') }}</span> <small>v{{ $v->version }}</small></dd>
                            </div>
                        </dl>
                        @if ($v->umbral_mensual_unidades === null)
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">⚠ {{ $t('aviso-umbral') }}</p>
                        @endif
                    @else
                        <div class="mt-4 rounded-lg border border-dashed border-amber-300 bg-amber-50 p-3 text-xs leading-5 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            ⚠ @lang('ventasasesor::comisiones.sin-acuerdo')
                        </div>
                    @endif

                    {{-- Programar nueva version --}}
                    <details class="va-ac-details mt-4 border-t border-slate-100 pt-3 dark:border-gray-800" @if ($enfocado || ! $v) open @endif>
                        <summary class="flex items-center justify-between gap-2 text-sm font-semibold text-blue-600">
                            <span><span class="va-ac-chevron">▸</span> {{ $v ? trans('ventasasesor::comisiones.programar-acuerdo') : $t('definir-acuerdo') }}</span>
                        </summary>
                        <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.acuerdos.store', $a->admin_id) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" data-form="acuerdo">
                            @csrf
                            <div>
                                <label class="{{ $label }}" for="pct-{{ $a->admin_id }}">@lang('ventasasesor::comisiones.porcentaje') (%)</label>
                                <input id="pct-{{ $a->admin_id }}" type="number" inputmode="decimal" name="porcentaje" min="0" max="99.9999" step="0.0001" required class="{{ $input }}">
                            </div>
                            <div>
                                <label class="{{ $label }}" for="umb-{{ $a->admin_id }}">@lang('ventasasesor::comisiones.umbral') ({{ $t('unidades') }})</label>
                                <input id="umb-{{ $a->admin_id }}" type="number" inputmode="numeric" name="umbral" min="0" step="1" placeholder="@lang('ventasasesor::comisiones.umbral-placeholder')" class="{{ $input }}">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="{{ $label }}" for="des-{{ $a->admin_id }}">@lang('ventasasesor::comisiones.vigente-desde')</label>
                                <input id="des-{{ $a->admin_id }}" type="datetime-local" name="vigente_desde" class="{{ $input }}">
                                <p class="va-sub mt-1">@lang('ventasasesor::comisiones.programar-ayuda')</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="{{ $label }}" for="mot-{{ $a->admin_id }}">@lang('ventasasesor::comisiones.motivo')</label>
                                <input id="mot-{{ $a->admin_id }}" type="text" name="motivo" minlength="5" maxlength="500" required placeholder="{{ $t('motivo-ejemplo') }}" class="{{ $input }}">
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::comisiones.guardar')</button>
                            </div>
                        </form>
                    </details>

                    {{-- Historial --}}
                    @if ($fila['historial']->isNotEmpty())
                        <details class="va-ac-details mt-3">
                            <summary class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                <span class="va-ac-chevron">▸</span> @lang('ventasasesor::comisiones.historial') ({{ $fila['historial']->count() }})
                            </summary>
                            <ol class="mt-3 space-y-2">
                                @foreach ($fila['historial'] as $h)
                                    @php
                                        $estadoVersion = ! $h->activo ? 'cancelada' : ($h->vigente_desde->gt($ahora) ? 'programada' : ($h->vigente_hasta !== null && $h->vigente_hasta->lte($ahora) ? 'cerrada' : 'vigente'));
                                        $colorVersion = ['vigente' => 'verde', 'programada' => 'cian', 'cerrada' => 'gris', 'cancelada' => 'gris'][$estadoVersion];
                                    @endphp
                                    <li class="va-ac-hist-card text-xs">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">v{{ $h->version }} · {{ $pct($h->porcentaje) }} · {{ $h->umbral_mensual_unidades === null ? $t('sin-definir') : $h->umbral_mensual_unidades.' '.$t('unidades') }}</span>
                                            <span class="va-pill va-pill--sm va-pill--{{ $colorVersion }}"><span>@lang('ventasasesor::comisiones.rentabilidad.versiones.'.$estadoVersion)</span></span>
                                        </div>
                                        <p class="mt-1 text-slate-500">{{ $fecha($h->vigente_desde) }} → {{ $h->vigente_hasta ? $fecha($h->vigente_hasta) : trans('ventasasesor::comisiones.abierta') }}</p>
                                        <p class="mt-1 text-slate-600 dark:text-gray-300">{{ $h->motivo }}@if ($h->creado_por_name_snapshot) <span class="text-slate-400">· {{ $h->creado_por_name_snapshot }}</span>@endif</p>
                                    </li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </div>
            @empty
                <div class="va-panel p-8 text-center text-sm text-slate-500">{{ $t('sin-asesores') }}</div>
            @endforelse
        </div>

        <p class="va-panel mt-4 hidden p-6 text-center text-sm text-slate-500" data-ac-vacio>{{ $t('sin-resultados') }}</p>
    </div>
</x-admin::layouts>

@pushOnce('scripts')
    <script>
        (function () {
            const buscar = document.querySelector('[data-ac-buscar]');
            const tarjetas = Array.from(document.querySelectorAll('[data-ac-card]'));
            const vacio = document.querySelector('[data-ac-vacio]');
            const botones = Array.from(document.querySelectorAll('[data-ac-filtro]'));
            let estado = 'todos';

            function aplicar() {
                const q = (buscar?.value || '').trim().toLowerCase();
                let visibles = 0;

                tarjetas.forEach(function (t) {
                    const ok = (estado === 'todos' || t.dataset.estado === estado) && (q === '' || t.dataset.nombre.includes(q));
                    t.classList.toggle('hidden', !ok);
                    ok && visibles++;
                });

                vacio?.classList.toggle('hidden', visibles > 0 || tarjetas.length === 0);
            }

            buscar?.addEventListener('input', aplicar);
            botones.forEach(function (b) {
                b.addEventListener('click', function () {
                    estado = b.dataset.acFiltro;
                    botones.forEach(function (x) {
                        const activo = x === b;
                        x.classList.toggle('bg-blue-50', activo);
                        x.classList.toggle('!text-blue-700', activo);
                    });
                    aplicar();
                });
            });

            // Un solo envio por formulario: evita guardar dos versiones con un doble clic.
            document.querySelectorAll('form[data-form="acuerdo"]').forEach(function (f) {
                f.addEventListener('submit', function () {
                    const boton = f.querySelector('button[type="submit"]');
                    boton && (boton.disabled = true);
                });
            });

            // Si se llega con ?asesor=ID, la tarjeta queda a la vista.
            document.querySelector('.va-ac-card.is-focus')?.scrollIntoView({ block: 'center' });
        })();
    </script>
@endPushOnce
