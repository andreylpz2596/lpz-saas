{{--
    Indicadores + variantes + paginacion del consolidado. Se pinta al cargar y se vuelve a pedir tras guardar una prioridad
    (la modal reemplaza #cc-resultados con esta misma parte, sin recargar la pagina).
    Escritorio (lg+): filas alineadas en columnas. Celular: tarjetas.
--}}
@php
    $etiquetaPrioridad = fn ($v) => \ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion::tryFrom((string) $v)?->label() ?? $v;
    $puedeVerOrden = \ByLopez\CorteConfeccion\Support\OrdenConfeccionGate::puedeVer();
    $dias = fn (int $n) => $n === 0 ? 'hoy' : ($n === 1 ? 'hace 1 día' : "hace $n días");
    $miles = fn (int $n) => number_format($n, 0, ',', '.');
    // Columnas literales (Tailwind no compila clases armadas en tiempo de ejecucion).
    // lg: prioridad asignada y sugerencia apiladas en una columna; xl: columnas separadas; >=1400 px: columna de taller.
    $columnas = $verTalleres
        ? 'lg:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,12rem)_4rem_1.25rem] xl:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,10rem)_minmax(0,11rem)_4rem_1.25rem] min-[1400px]:grid-cols-[minmax(0,1fr)_minmax(0,9rem)_6rem_6rem_minmax(0,10rem)_minmax(0,11rem)_4rem_1.25rem]'
        : 'lg:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,12rem)_4rem_1.25rem] xl:grid-cols-[minmax(0,1fr)_6rem_6rem_minmax(0,10rem)_minmax(0,11rem)_4rem_1.25rem]';
    $columnasOrden = 'xl:grid-cols-[minmax(0,1fr)_7rem_6.5rem_6.5rem_minmax(0,11rem)_10rem]';
    $hora = $calculadaEn?->format('H:i:s');
@endphp

{{-- Indicadores: sobre TODO lo filtrado, no solo esta pagina. --}}
<div class="mt-4 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 xl:grid-cols-4" data-testid="indicadores">
    <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Por confeccionar</p>
        <p class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white" data-i="por_confeccionar">{{ $miles($totales['por_confeccionar']) }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Aún no reportado como terminado</p>
    </div>
    <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Pendiente de recibir</p>
        <p class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white" data-i="pendiente_recibir">{{ $miles($totales['pendiente_recibir']) }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Reportado; falta que bodega lo reciba</p>
    </div>
    <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Prioridad</p>
        <p class="flex flex-wrap items-baseline gap-x-3 text-sm text-gray-700 dark:text-gray-200">
            <span><b class="text-2xl tabular-nums text-red-600" data-i="urgente">{{ $miles($totales['urgente']) }}</b> urgentes</span>
            <span><b class="text-2xl tabular-nums text-amber-600" data-i="alta">{{ $miles($totales['alta']) }}</b> altas</span>
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">De lo que está por confeccionar</p>
    </div>
    <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">En tránsito</p>
        <p class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white" data-i="en_transito">{{ $miles($totales['en_transito']) }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Reasignado, sin confirmar llegada; no cuenta como por confeccionar</p>
    </div>
</div>

<div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
    <p>{{ $totales['variantes'] }} {{ $totales['variantes'] === 1 ? 'variante' : 'variantes' }} · {{ $totales['ordenes'] }} {{ $totales['ordenes'] === 1 ? 'orden' : 'órdenes' }}</p>
    <p title="«Por confeccionar» no prueba que las prendas estén físicamente sin coser: solo que el taller aún no las reporta como terminadas.">«Por confeccionar» = sin reporte de terminado, no necesariamente sin coser.</p>
</div>

{{-- La prioridad asignada manda; la sugerencia solo ayuda a elegir entre trabajos con la misma prioridad asignada. --}}
<p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-testid="sugerencia-calculada">
    @if ($hora)
        Sugerencia por stock calculada a las <b data-sugerencia-hora>{{ $hora }}</b> con el disponible actual de cada variante (se recalcula al filtrar o guardar).
    @endif
    La prioridad asignada manda; la sugerencia nunca la cambia{{ $verCifras ? ' y se repite en cada taller porque es del inventario global de la variante' : '' }}.
    @if ($puedeVerMinimos)
        <a href="{{ route('admin.corte_confeccion.stock_minimos.index') }}" class="text-blue-600 hover:underline dark:text-blue-400">Mínimos de stock</a>
    @endif
</p>

<div class="box-shadow mt-2 overflow-hidden rounded bg-white dark:bg-gray-900" data-testid="grupos">
    {{-- Encabezado de columnas (solo escritorio). --}}
    <div class="hidden gap-3 border-b px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400 lg:grid {{ $columnas }}">
        <span>Producto / variante</span>
        @if ($verTalleres)<span class="hidden min-[1400px]:block">Taller</span>@endif
        <span class="text-right">Por confeccionar</span>
        <span class="text-right">Por recibir</span>
        <span class="xl:hidden">Prioridad asignada · sugerencia</span>
        <span class="hidden xl:block">Prioridad asignada</span>
        <span class="hidden xl:block">Sugerencia por stock</span>
        <span class="text-right">Órdenes</span>
        <span></span>
    </div>

    @forelse ($paginador as $g)
        @php
            $abierto = $foco && $g['ordenes']->contains('detalle_id', $foco);
            $s = $g['sugerencia'];
            $textoSugerencia = $s ? $s['etiqueta'].' · '.$s['motivo'] : 'Sin información';
            // Detalle global de lo pendiente de recibir: solo administracion (el taller ve solo lo suyo, sin enlace).
            $enlaceRecepcion = $verCifras ? route('admin.corte_confeccion.ordenes_confeccion.consolidado', ['producto' => $g['product_id']]) : null;
            $botonesOrden = $puedeCambiar ? $g['ordenes']->where('por_confeccionar', '>', 0)->count() : 0;
        @endphp
        <details class="group border-b last:border-b-0 dark:border-gray-800" @if ($abierto) open @endif data-grupo="{{ $g['clave'] }}">
            <summary class="cursor-pointer px-4 py-3 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:hover:bg-gray-950 lg:grid lg:items-center lg:gap-3 {{ $columnas }}">
                {{-- Producto / variante --}}
                <div class="flex min-w-0 items-start gap-2">
                    <span class="cc-flecha icon-sort-right mt-0.5 shrink-0 text-xl text-gray-500 transition-transform lg:hidden"></span>
                    <div class="min-w-0">
                        <p class="flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white sm:text-base">
                            <span class="truncate" title="{{ $g['nombre'] }}">{{ $g['nombre'] }}</span>
                            @if ($g['activo'] === false)
                                <span class="label-closed shrink-0">Inactivo</span>
                            @endif
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                            @include('corteconfeccion::admin.ordenes-confeccion.partials.color-variante', ['color' => $g['color'], 'hex' => $g['color_hex']])
                            @if ($g['talla'] !== '-')
                                <span class="rounded border px-1.5 py-0.5 font-semibold text-gray-800 dark:border-gray-700 dark:text-gray-100">Talla {{ $g['talla'] }}</span>
                            @endif
                            @if ($g['referencia']) <span>Ref. {{ $g['referencia'] }}</span> @endif
                            @if ($g['tela']) <span class="truncate" title="{{ $g['tela'] }}">· {{ $g['tela'] }}</span> @endif
                        </div>
                        <p class="mt-0.5 truncate font-mono text-[11px] text-gray-400" title="{{ $g['sku'] }}">{{ $g['sku'] }}</p>
                        @if ($verTalleres)
                            <p class="mt-1 truncate text-sm text-gray-700 dark:text-gray-200 min-[1400px]:hidden">{{ $g['taller'] }}</p>
                        @endif
                    </div>
                </div>

                @if ($verTalleres)
                    <p class="hidden truncate text-sm text-gray-700 dark:text-gray-200 min-[1400px]:block" title="{{ $g['taller'] }}">{{ $g['taller'] }}</p>
                @endif

                {{-- Cantidades: en celular, bloque de tres cifras destacadas. --}}
                <div class="mt-3 grid grid-cols-3 gap-2 rounded-md bg-gray-50 p-2 text-center dark:bg-gray-950 lg:contents">
                    <div class="lg:text-right">
                        <p class="text-[11px] uppercase text-gray-500 lg:hidden">Por confeccionar</p>
                        <p class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $miles($g['por_confeccionar']) }}</p>
                    </div>
                    <div class="lg:text-right">
                        <p class="text-[11px] uppercase text-gray-500 lg:hidden">Por recibir</p>
                        <p class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $miles($g['pendiente_recibir']) }}</p>
                    </div>
                    <div class="lg:order-last lg:text-right">
                        <p class="text-[11px] uppercase text-gray-500 lg:hidden">Órdenes</p>
                        <p class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $g['ordenes']->count() }}</p>
                    </div>
                </div>

                {{-- lg: una celda con ambas; xl: «contents» las vuelve dos columnas. --}}
                <div class="mt-2 grid gap-2 min-[420px]:grid-cols-2 lg:mt-0 lg:grid-cols-1 lg:gap-1.5 xl:contents">
                    <div class="min-w-0" data-testid="prioridad-asignada">
                        <p class="text-[11px] uppercase text-gray-500 xl:hidden">Prioridad asignada</p>
                        <div class="flex flex-wrap items-center gap-1">
                            {{-- Cada nivel con su cantidad: nunca una sola etiqueta si la linea mezcla niveles. --}}
                            @include('corteconfeccion::admin.ordenes-confeccion.partials.prioridades', ['p' => $g['prioridades']])
                            @if ($g['en_transito'] > 0)
                                <span class="label-pending whitespace-nowrap" title="Reasignado, sin confirmar llegada">{{ $g['en_transito'] }} en tránsito</span>
                            @endif
                        </div>
                        <span class="block text-xs text-gray-500">Más antigua: {{ $dias($g['antiguedad']) }}</span>
                    </div>
                    <div class="min-w-0" data-testid="sugerencia-stock">
                        <p class="text-[11px] uppercase text-gray-500 xl:hidden">Sugerencia por stock</p>
                        @include('corteconfeccion::admin.ordenes-confeccion.partials.sugerencia-stock', ['s' => $s, 'conCifras' => $verCifras, 'enlaceRecepcion' => $enlaceRecepcion])
                        @if ($botonesOrden > 0)
                            <button type="button" class="mt-0.5 block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400" data-p-desde-sugerencia>
                                {{ $botonesOrden === 1 ? 'Cambiar prioridad' : 'Cambiar prioridad (elige orden)' }}
                            </button>
                        @endif
                    </div>
                </div>

                <span class="cc-flecha icon-sort-right hidden text-xl text-gray-500 transition-transform lg:order-last lg:block"></span>
            </summary>

            {{-- Ordenes de origen --}}
            <div class="border-t bg-gray-50/70 px-3 py-2 dark:border-gray-800 dark:bg-gray-950/40 xl:px-4">
                <div class="hidden gap-3 px-2 pb-1 text-[11px] font-semibold uppercase text-gray-500 xl:grid {{ $columnasOrden }}">
                    <span>Orden · entrega al taller</span>
                    <span>Estado</span>
                    <span class="text-right">Por confeccionar</span>
                    <span class="text-right">Por recibir</span>
                    <span>Prioridad asignada</span>
                    <span></span>
                </div>

                @foreach ($g['ordenes'] as $o)
                    @php
                        $cambios = $historial->get($o['detalle_id'], collect());
                        $resaltada = $foco === $o['detalle_id'];
                    @endphp
                    <div
                        class="my-1.5 rounded-md border bg-white p-3 dark:border-gray-800 dark:bg-gray-900 xl:grid xl:items-center xl:gap-3 xl:p-2 {{ $columnasOrden }} {{ $resaltada ? 'border-blue-600 ring-2 ring-blue-200 dark:ring-blue-900' : '' }}"
                        data-detalle="{{ $o['detalle_id'] }}"
                        @if ($resaltada) data-foco="1" @endif
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                @if ($puedeVerOrden)
                                    <a class="text-blue-600 hover:underline dark:text-blue-400" href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $o['orden_id']) }}">{{ $o['orden_codigo'] }}</a>
                                @else
                                    {{ $o['orden_codigo'] }}
                                @endif
                                <span class="font-normal text-gray-500">· corte {{ $o['corte_codigo'] }}</span>
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-300">{{ $o['desde']->format('d-m-Y') }} · {{ $dias($o['antiguedad']) }}</p>
                        </div>

                        <div class="mt-1 xl:mt-0">{!! \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::badgeHtml($o['orden_estado']) !!}</div>

                        <div class="mt-2 grid grid-cols-2 gap-2 xl:contents">
                            <div class="xl:text-right">
                                <p class="text-[11px] uppercase text-gray-500 xl:hidden">Por confeccionar</p>
                                <p class="font-semibold tabular-nums">{{ $o['por_confeccionar'] }}</p>
                            </div>
                            <div class="xl:text-right">
                                <p class="text-[11px] uppercase text-gray-500 xl:hidden">Por recibir</p>
                                <p class="font-semibold tabular-nums">{{ $o['pendiente_recibir'] }}</p>
                                @if ($o['reporte_pendiente_desde'])
                                    <p class="text-[11px] text-gray-500">reportado {{ $o['reporte_pendiente_desde']->format('d-m-Y') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 xl:mt-0">
                            @include('corteconfeccion::admin.ordenes-confeccion.partials.prioridades', ['p' => $o['prioridades']])
                            @if ($o['en_transito'] > 0)
                                <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $o['en_transito'] }} en tránsito (aún no por confeccionar)</p>
                            @endif
                            @if ($o['por_confeccionar'] === 0 && $o['pendiente_recibir'] > 0)
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Todo reportado; falta bodega.</p>
                            @endif
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2 xl:mt-0 xl:justify-end">
                            @if ($puedeCambiar && $o['por_confeccionar'] > 0)
                                <button
                                    type="button"
                                    class="secondary-button max-sm:w-full max-sm:justify-center"
                                    data-p-abrir
                                    data-detalle="{{ $o['detalle_id'] }}"
                                    data-orden="{{ $o['orden_codigo'] }}"
                                    data-taller="{{ $o['taller'] }}"
                                    data-producto="{{ $g['nombre'] }}"
                                    data-referencia="{{ $g['referencia'] }}"
                                    data-color="{{ $g['color'] }}"
                                    data-talla="{{ $g['talla'] !== '-' ? $g['talla'] : '' }}"
                                    data-reparto="{{ json_encode($o['prioridades']) }}"
                                    data-sugerencia="{{ $textoSugerencia }}"
                                >Cambiar prioridad</button>
                            @endif
                        </div>

                        @if ($cambios->isNotEmpty())
                            <details class="mt-2 text-xs text-gray-600 dark:text-gray-300 xl:col-span-full xl:mt-0">
                                <summary class="cursor-pointer text-blue-600 dark:text-blue-400">Historial de prioridad ({{ $cambios->count() }})</summary>
                                <ul class="mt-1 grid gap-1">
                                    @foreach ($cambios as $c)
                                        <li>
                                            {{ $c->created_at->format('d-m-Y H:i') }} · {{ $c->operacion?->usuario?->name ?? '—' }}:
                                            {{ $c->cantidad }} u. de {{ $etiquetaPrioridad($c->prioridad_anterior) }} a {{ $etiquetaPrioridad($c->prioridad_nueva) }}
                                            @if ($c->operacion?->nota) — «{{ $c->operacion->nota }}» @endif
                                            @if ($c->operacion?->notificacion_estado === \ByLopez\CorteConfeccion\Models\PrioridadOperacion::SIN_DESTINATARIO)
                                                <span class="text-amber-700 dark:text-amber-400">(sin usuario vinculado: nadie fue notificado)</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @empty
        <p class="p-6 text-center text-sm text-gray-600 dark:text-gray-300">No hay prendas pendientes con estos filtros.</p>
    @endforelse
</div>

@if ($paginador->lastPage() > 1)
    <nav class="mt-3 flex items-center justify-between gap-2 text-sm" aria-label="Paginación">
        @if ($paginador->onFirstPage())
            <span class="secondary-button pointer-events-none opacity-50">Anterior</span>
        @else
            <a class="secondary-button" href="{{ $paginador->previousPageUrl() }}">Anterior</a>
        @endif
        <span class="text-gray-600 dark:text-gray-300">Página {{ $paginador->currentPage() }} de {{ $paginador->lastPage() }}</span>
        @if ($paginador->hasMorePages())
            <a class="secondary-button" href="{{ $paginador->nextPageUrl() }}">Siguiente</a>
        @else
            <span class="secondary-button pointer-events-none opacity-50">Siguiente</span>
        @endif
    </nav>
@endif
