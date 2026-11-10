{{--
    Minimos de stock por variante (Doc. 09 §9). Vacio = sin minimo; 0 = minimo cero. Solo se envian las filas cambiadas;
    si otra persona cambio una entretanto, no se guarda nada y se conserva lo escrito.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Mínimos de stock</x-slot>

    @include('corteconfeccion::admin.partials.debugbar-local')

    @php
        $campo = 'w-full rounded-md border px-3 py-2 text-base text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm';
    @endphp

    <div id="cc-minimos" class="bylopez-scope cc-pagina" v-pre>
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h1 class="text-xl font-bold text-gray-800 dark:text-white">Mínimos de stock</h1>
                <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                    Disponible mínimo por variante para la <b>sugerencia por stock</b> de confección: por debajo del mínimo se sugiere Alta; sin disponible, Urgente.
                    Déjalo vacío si la variante no tiene mínimo. La sugerencia nunca cambia la prioridad asignada.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.corte_confeccion.stock_minimos.index') }}" class="box-shadow mt-4 grid gap-3 rounded bg-white p-3 dark:bg-gray-900 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end">
            <div>
                <label for="cc-m-q" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Buscar variante</label>
                <input id="cc-m-q" name="q" type="search" value="{{ $q }}" placeholder="SKU o nombre" class="{{ $campo }}">
            </div>
            <div>
                <label for="cc-m-ambito" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Mostrar</label>
                <select id="cc-m-ambito" name="ambito" class="{{ $campo }}">
                    <option value="confeccion" @selected($ambito === 'confeccion')>Variantes en confección</option>
                    <option value="todas" @selected($ambito === 'todas')>Todas las variantes</option>
                </select>
            </div>
            <button type="submit" class="primary-button justify-center">Buscar</button>
        </form>

        <form method="POST" action="{{ route('admin.corte_confeccion.stock_minimos.guardar') }}" class="mt-3" data-cc-minimos>
            @csrf

            @if ($errors->any())
                <p class="mb-2 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">{{ $errors->first() }}</p>
            @endif

            <div class="box-shadow overflow-hidden rounded bg-white dark:bg-gray-900">
                <div class="hidden gap-3 border-b px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400 md:grid md:grid-cols-[minmax(0,1fr)_6.5rem_8rem_minmax(0,14rem)]">
                    <span>Variante</span>
                    <span class="text-right">Disponible</span>
                    <span>Mínimo</span>
                    <span>Sugerencia por stock</span>
                </div>

                @forelse ($paginador as $f)
                    @php
                        $id = $f['product_id'];
                        $original = $f['minimo'] === null ? '' : (string) $f['minimo'];
                    @endphp
                    <div class="grid gap-2 border-b px-4 py-3 last:border-b-0 dark:border-gray-800 md:grid-cols-[minmax(0,1fr)_6.5rem_8rem_minmax(0,14rem)] md:items-center md:gap-3" data-minimo-fila="{{ $id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white" title="{{ $f['nombre'] }}">{{ $f['nombre'] ?: $f['sku'] }}</p>
                            <p class="text-xs text-gray-600 dark:text-gray-300">
                                {{ implode(' · ', array_filter([$f['color'], $f['talla'] ? 'Talla '.$f['talla'] : null])) }}
                                <span class="font-mono text-[11px] text-gray-400">{{ $f['sku'] }}</span>
                            </p>
                        </div>
                        <p class="text-sm tabular-nums text-gray-800 dark:text-gray-100 md:text-right">
                            <span class="text-xs text-gray-500 md:hidden">Disponible: </span>{{ $f['disponible'] ?? 'Sin dato' }}
                        </p>
                        <div>
                            <label for="cc-m-{{ $id }}" class="mb-1 block text-xs text-gray-500 md:sr-only">Mínimo</label>
                            @if ($puedeEditar)
                                {{-- Siempre el valor ACTUAL: tras un conflicto, la persona ve lo vigente y reenvia conscientemente. --}}
                                <input type="hidden" name="minimos[{{ $id }}][original]" value="{{ $original }}" data-minimo-original>
                                <input
                                    id="cc-m-{{ $id }}"
                                    name="minimos[{{ $id }}][nuevo]"
                                    value="{{ old("minimos.$id.nuevo", $original) }}"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="Sin mínimo"
                                    autocomplete="off"
                                    class="{{ $campo }} {{ $errors->has("minimos.$id") ? 'border-red-500' : '' }}"
                                    data-minimo-input
                                    @error("minimos.$id") aria-invalid="true" aria-describedby="cc-m-{{ $id }}-error" @enderror
                                >
                                @error("minimos.$id")
                                    <p id="cc-m-{{ $id }}-error" class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            @else
                                <p class="text-sm text-gray-800 dark:text-gray-100">{{ $f['minimo'] ?? 'Sin mínimo' }}</p>
                            @endif
                        </div>
                        <div class="min-w-0">
                            @include('corteconfeccion::admin.ordenes-confeccion.partials.sugerencia-stock', ['s' => $f['sugerencia'], 'conCifras' => true])
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-center text-sm text-gray-600 dark:text-gray-300">No hay variantes con estos criterios.</p>
                @endforelse
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                @if ($paginador->lastPage() > 1)
                    <nav class="flex items-center gap-2 text-sm" aria-label="Paginación">
                        @if (! $paginador->onFirstPage())<a class="secondary-button" href="{{ $paginador->previousPageUrl() }}">Anterior</a>@endif
                        <span class="text-gray-600 dark:text-gray-300">Página {{ $paginador->currentPage() }} de {{ $paginador->lastPage() }}</span>
                        @if ($paginador->hasMorePages())<a class="secondary-button" href="{{ $paginador->nextPageUrl() }}">Siguiente</a>@endif
                    </nav>
                @else
                    <span></span>
                @endif

                @if ($puedeEditar && $paginador->count())
                    <button type="submit" class="primary-button max-sm:w-full max-sm:justify-center" data-minimos-guardar>Guardar cambios</button>
                @endif
            </div>
        </form>
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                // Solo se envian las filas cambiadas (el servidor tambien ignora las iguales) y un doble clic no reenvia.
                document.addEventListener('submit', function (e) {
                    var form = e.target instanceof Element ? e.target.closest('[data-cc-minimos]') : null;
                    if (!form) { return; }
                    if (form.dataset.enviando) { e.preventDefault(); return; }
                    form.querySelectorAll('[data-minimo-fila]').forEach(function (fila) {
                        var input = fila.querySelector('[data-minimo-input]');
                        var original = fila.querySelector('[data-minimo-original]');
                        if (input && original && input.value.trim() === original.value) {
                            input.disabled = true;
                            original.disabled = true;
                        }
                    });
                    form.dataset.enviando = '1';
                    var boton = form.querySelector('[data-minimos-guardar]');
                    if (boton) { boton.disabled = true; boton.textContent = 'Guardando…'; }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
