{{--
    Consolidado general de confeccion (Doc. 09). Todo se calcula en ConsolidadoConfeccionService; esta vista solo pinta.
    v-pre en los bloques con datos: Bagisto compila #app como plantilla Vue y los textos (notas, nombres) no deben
    interpretarse. La unica parte Vue es la modal «Cambiar prioridad» (x-admin::modal de Bagisto).
    #cc-resultados se reemplaza tras guardar (sin recargar): indicadores, filas y paginacion.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Consolidado de confección</x-slot>

    @include('corteconfeccion::admin.partials.debugbar-local')

    @push('styles')
        {{-- Solo esta pantalla: WebKit pinta su propio triangulo en <summary>; se deja solo la flecha del diseño. --}}
        <style>
            #cc-consolidado summary { list-style: none; }
            #cc-consolidado summary::-webkit-details-marker { display: none; }
            #cc-consolidado details[open] > summary .cc-flecha { transform: rotate(90deg); }
            #cc-consolidado [data-foco="1"] { scroll-margin-top: 90px; }
        </style>
    @endpush

    @php
        $etiquetaPrioridad = fn ($v) => \ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion::tryFrom((string) $v)?->label() ?? $v;
        $filtrosActivos = collect($filtros)->except('producto')->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->count();
        $campo = 'w-full rounded-md border px-3 py-2 text-base text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm';
        $etiqueta = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300';
    @endphp

    <div id="cc-consolidado" class="bylopez-scope cc-pagina">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold text-gray-800 dark:text-white">Órdenes de confección</h1>
        </div>

        @include('corteconfeccion::admin.ordenes-confeccion.partials.pestanas', ['activa' => 'consolidado'])

        {{-- Llegada desde una alerta: lo historico y lo actual, separados. Solo existe si se abrio una alerta propia. --}}
        @if ($aviso && $aviso['cambio'])
            @php
                $cambio = $aviso['cambio'];
                $actual = $aviso['actual'];
            @endphp
            <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100" role="status" data-testid="aviso-contexto" v-pre>
                <p class="font-semibold">
                    Alerta del {{ \Illuminate\Support\Carbon::parse($aviso['datos']['fecha'] ?? now())->format('d-m-Y H:i') }}:
                    {{ $aviso['datos']['usuario'] ?? 'Un administrador' }} pasó {{ $cambio['cantidad'] }} u. de {{ $etiquetaPrioridad($cambio['desde']) }} a {{ $etiquetaPrioridad($cambio['hacia']) }}
                    en {{ $cambio['orden_codigo'] }}
                    ({{ implode(' · ', array_filter([$cambio['nombre'] ?? $cambio['sku'] ?? null, $cambio['color'] ?? null, ! empty($cambio['talla']) ? 'Talla '.$cambio['talla'] : null])) }}).
                </p>
                @if (! empty($aviso['datos']['nota']))
                    <p class="mt-1">Nota: {{ $aviso['datos']['nota'] }}</p>
                @endif

                @if ($actual)
                    <p class="mt-2">
                        <span class="font-semibold">Hoy:</span>
                        @if ($actual['por_confeccionar'] > 0)
                            quedan {{ $actual['por_confeccionar'] }} por confeccionar en esta orden —
                            @include('corteconfeccion::admin.ordenes-confeccion.partials.prioridades', ['p' => $actual['prioridades']])
                        @else
                            esta orden ya no tiene prendas por confeccionar de esa variante.
                            @if ($actual['cedida'] > 0)
                                {{ $actual['cedida'] }} se reasignaron a otro taller.
                            @endif
                            @if ($actual['reportada'] > 0)
                                El taller reportó {{ $actual['reportada'] }} como terminadas{{ $actual['pendiente_recibir'] > 0 ? '; '.$actual['pendiente_recibir'].' siguen pendientes de recibir en bodega' : '' }}.
                            @endif
                        @endif
                    </p>
                @endif
                <p class="mt-1 text-xs opacity-80">La alerta conserva el cambio tal como ocurrió; las cifras de abajo son las actuales. Leerla no indica que el trabajo comenzó.</p>
            </div>
        @endif

        {{-- Filtros: compactos en escritorio; plegables en celular con el numero de filtros activos. --}}
        <div class="mt-4" v-pre>
            <button type="button" class="secondary-button w-full justify-between lg:hidden" data-cc-filtros aria-expanded="false" aria-controls="cc-filtros">
                <span>Filtros @if ($filtrosActivos)<span class="ml-1 rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white">{{ $filtrosActivos }}</span>@endif</span>
                <span class="icon-sort-down text-xl"></span>
            </button>

            <form id="cc-filtros" method="GET" action="{{ route('admin.corte_confeccion.ordenes_confeccion.consolidado') }}" class="box-shadow rounded bg-white p-3 max-lg:mt-2 max-lg:hidden dark:bg-gray-900" data-testid="filtros">
                <div class="grid gap-3 sm:grid-cols-2 {{ $verTalleres ? 'lg:grid-cols-[minmax(0,2fr)_repeat(5,minmax(0,1fr))]' : 'lg:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]' }}">
                    <div class="sm:col-span-2 lg:col-span-1">
                        <label for="cc-q" class="{{ $etiqueta }}">Buscar</label>
                        <input id="cc-q" name="q" type="search" value="{{ $filtros['q'] }}" placeholder="Producto, color, orden…" class="{{ $campo }}">
                    </div>

                    @if ($verTalleres)
                        <div>
                            <label for="cc-taller" class="{{ $etiqueta }}">Taller</label>
                            <select id="cc-taller" name="taller" class="{{ $campo }}">
                                <option value="">Todos</option>
                                @foreach ($opciones['talleres'] as $id => $nombre)
                                    <option value="{{ $id }}" @selected($filtros['taller'] === (int) $id)>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label for="cc-referencia" class="{{ $etiqueta }}">Referencia</label>
                        <select id="cc-referencia" name="referencia" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($opciones['referencias'] as $id => $nombre)
                                <option value="{{ $id }}" @selected($filtros['referencia'] === (int) $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="cc-color" class="{{ $etiqueta }}">Color</label>
                        <select id="cc-color" name="color" class="{{ $campo }}">
                            <option value="">Todos</option>
                            @foreach ($opciones['colores'] as $color)
                                <option value="{{ $color }}" @selected(mb_strtolower((string) $filtros['color']) === mb_strtolower($color))>{{ $color }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="cc-prioridad" class="{{ $etiqueta }}">Prioridad asignada</label>
                        <select id="cc-prioridad" name="prioridad" class="{{ $campo }}">
                            <option value="">Todas</option>
                            <option value="prioritaria" @selected($filtros['prioridad'] === 'prioritaria')>Urgente o alta</option>
                            <option value="urgente" @selected($filtros['prioridad'] === 'urgente')>Urgente</option>
                            <option value="alta" @selected($filtros['prioridad'] === 'alta')>Alta</option>
                            <option value="normal" @selected($filtros['prioridad'] === 'normal')>Normal</option>
                        </select>
                    </div>

                    <div>
                        <label for="cc-sugerencia" class="{{ $etiqueta }}">Sugerencia por stock</label>
                        <select id="cc-sugerencia" name="sugerencia" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach (\ByLopez\CorteConfeccion\Services\SugerenciaStockService::opciones() as $valor => $texto)
                                <option value="{{ $valor }}" @selected($filtros['sugerencia'] === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                    @if ($opciones['tallas'])
                        <fieldset class="min-w-0">
                            <legend class="{{ $etiqueta }}">Tallas</legend>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($opciones['tallas'] as $talla)
                                    @php $marcada = in_array(mb_strtolower($talla), array_map('mb_strtolower', $filtros['tallas']), true); @endphp
                                    <label class="inline-flex cursor-pointer items-center gap-1 rounded-full border px-2.5 py-1 text-sm text-gray-700 focus-within:ring-2 focus-within:ring-blue-300 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-700 dark:border-gray-700 dark:text-gray-200 dark:has-[:checked]:bg-blue-950 dark:has-[:checked]:text-blue-200">
                                        <input type="checkbox" name="tallas[]" value="{{ $talla }}" class="h-3.5 w-3.5 rounded border-gray-300" @checked($marcada)>
                                        {{ $talla }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    @if ($filtros['producto'])
                        <input type="hidden" name="producto" value="{{ $filtros['producto'] }}">
                    @endif

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        @if ($filtros['producto'])
                            <span class="text-xs text-gray-600 dark:text-gray-300">Solo la variante de la alerta.</span>
                        @endif
                        @if ($filtrosActivos || $filtros['producto'])
                            <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.consolidado') }}" class="secondary-button">Limpiar</a>
                        @endif
                        <button type="submit" class="primary-button">Filtrar</button>
                    </div>
                </div>
            </form>
        </div>

        <div id="cc-resultados" v-pre>
            @include('corteconfeccion::admin.ordenes-confeccion.partials.consolidado-resultados')
        </div>

        @if ($puedeCambiar)
            @include('corteconfeccion::admin.ordenes-confeccion.partials.modal-prioridad')
        @endif
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                // Filtros plegables en celular (delegado: Bagisto reemplaza los nodos al montar Vue).
                document.addEventListener('click', function (e) {
                    var boton = e.target instanceof Element ? e.target.closest('[data-cc-filtros]') : null;
                    if (!boton) { return; }
                    var form = document.getElementById('cc-filtros');
                    var abierto = form.classList.toggle('max-lg:hidden') === false;
                    boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
                });

                // Llegada desde una alerta o tras guardar: lleva la vista a la orden resaltada.
                window.addEventListener('load', function () {
                    setTimeout(function () {
                        var foco = document.querySelector('#cc-consolidado [data-foco="1"]');
                        if (foco) { foco.scrollIntoView({ block: 'center' }); }
                    }, 300);
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
