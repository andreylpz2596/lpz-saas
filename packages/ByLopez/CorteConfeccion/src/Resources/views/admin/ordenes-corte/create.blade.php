@php
    /*
     * F4 - Creacion V2. Flujo: rollos -> resumen de tela -> lineas -> variantes
     * reales -> revision -> crear. Los IDs (familia, color, eje) viajan solo como
     * campos ocultos: el usuario ve tela, rollo, color, linea y talla. La
     * resolucion a simples exactos ocurre SIEMPRE en el servidor.
     */
    $rollosData = $rollos->map(fn ($rollo) => [
        'id' => $rollo->id,
        'codigo' => $rollo->codigo,
        'proveedor' => $rollo->proveedor,
        'referencia' => $rollo->referencia,
        'color' => $rollo->color,
        'gramaje' => $rollo->gramaje,
        'tipo_tela_id' => $rollo->tipo_tela_id,
        'tipo_tela' => $rollo->tipo_tela,
        'peso_disponible' => (float) $rollo->peso_disponible,
        'estado' => \ByLopez\CorteConfeccion\Enums\EstadoRollo::tryFrom((string) $rollo->estado)?->label() ?? $rollo->estado,
        'bloqueo' => ! $rollo->tipo_tela_id
            ? 'Sin tipo de tela vinculado al catalogo'
            : (! $rollo->color_option_id ? 'Sin color vinculado al catalogo' : null),
    ])->values();
@endphp

@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Crear orden de corte</x-slot>

    <div class="bylopez-scope">
    <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.store') }}">
        @csrf

        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Crear orden de corte</p>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <p class="font-semibold">No se pudo crear la orden. Revisa lo siguiente:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 rounded border bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2">
                <p class="text-base font-semibold text-gray-800 dark:text-white">1. Datos de la orden</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">La orden define QUE se va a cortar; las cantidades las reporta luego el cortador.</p>
            </div>

            <div class="grid grid-cols-2 gap-x-4 max-md:grid-cols-1">
                <x-admin::form.control-group class="!mb-2">
                    <x-admin::form.control-group.label class="required">Cortador</x-admin::form.control-group.label>
                    <x-corteconfeccion::record-search
                        name="cortador_id"
                        :search-url="route('admin.corte_confeccion.usuarios.cortadores')"
                        :selected-id="old('cortador_id')"
                        placeholder="Buscar cortador..."
                        rules="required"
                        label="Cortador"
                    />
                    <x-admin::form.control-group.error control-name="cortador_id" />
                </x-admin::form.control-group>

                <x-admin::form.control-group class="!mb-2">
                    <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="observacion" rows="2" value="{{ old('observacion') }}" />
                    <x-admin::form.control-group.error control-name="observacion" />
                </x-admin::form.control-group>
            </div>
        </div>

        <v-orden-corte-form
            :rollos='@json($rollosData)'
            :old-rollo-ids='@json(array_map("intval", (array) old("rollo_ids", [])))'
            :old-seleccion='@json(array_values((array) old("seleccion", [])))'
            opciones-url="{{ route('admin.corte_confeccion.ordenes_corte.opciones') }}"
            previsualizar-url="{{ route('admin.corte_confeccion.ordenes_corte.previsualizar') }}"
        ></v-orden-corte-form>
    </form>
    </div>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-orden-corte-form-template">
            <div>
                {{-- Campos ocultos: lo unico que viaja al servidor (sin product_id). --}}
                <input v-for="id in rolloIds" :key="'r' + id" type="hidden" name="rollo_ids[]" :value="id" />
                <template v-for="(fila, i) in seleccionPlana" :key="'s' + i">
                    <input type="hidden" :name="'seleccion[' + i + '][attribute_family_id]'" :value="fila.attribute_family_id" />
                    <input type="hidden" :name="'seleccion[' + i + '][color_option_id]'" :value="fila.color_option_id" />
                    <input type="hidden" :name="'seleccion[' + i + '][eje_option_id]'" :value="fila.eje_option_id" />
                </template>

                {{-- 2. ROLLOS --}}
                <div class="mt-4 rounded border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-base font-semibold text-gray-800 dark:text-white">2. Rollos</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Elige los rollos a cortar. Deben ser del mismo tipo de tela; pueden ser de distintos colores y gramajes.</p>
                        </div>
                        <input
                            type="text"
                            v-model="filtro"
                            placeholder="Buscar por codigo, color o referencia..."
                            class="w-72 rounded-md border px-3 py-2 text-sm max-sm:w-full dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        />
                    </div>

                    <p v-if="! rollosVisibles.length" class="text-sm text-gray-500 dark:text-gray-400">No hay rollos disponibles.</p>

                    <div class="grid grid-cols-3 gap-3 max-xl:grid-cols-2 max-md:grid-cols-1">
                        <label
                            v-for="rollo in rollosVisibles"
                            :key="rollo.id"
                            class="flex cursor-pointer gap-3 rounded border p-3 text-sm dark:border-gray-800"
                            :class="[rolloIds.includes(rollo.id) ? 'border-blue-500 bg-blue-50/60 dark:bg-blue-950' : 'hover:bg-gray-50 dark:hover:bg-gray-950', rolloDeshabilitado(rollo) ? '!cursor-not-allowed opacity-50' : '']"
                        >
                            <input type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-blue-600" :checked="rolloIds.includes(rollo.id)" :disabled="rolloDeshabilitado(rollo)" @change="toggleRollo(rollo)" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-start justify-between gap-2">
                                    <span class="min-w-0 break-words font-semibold text-gray-800 dark:text-white">@{{ rollo.codigo }}</span>
                                    <span class="shrink-0 text-base font-bold text-gray-900 dark:text-white">@{{ formatoKg(rollo.peso_disponible) }}</span>
                                </span>
                                <span class="mt-0.5 block text-gray-700 dark:text-gray-200">@{{ rollo.color }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ rollo.tipo_tela }}<template v-if="rollo.gramaje"> · @{{ rollo.gramaje }} g</template></span>
                                <span class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                    <span class="min-w-0 break-words text-xs text-gray-500 dark:text-gray-400">@{{ rollo.proveedor }}<template v-if="rollo.referencia"> · @{{ rollo.referencia }}</template></span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">@{{ rollo.estado }}</span>
                                </span>
                                <span v-if="rollo.bloqueo" class="mt-1 block text-xs text-amber-600 dark:text-amber-400">@{{ rollo.bloqueo }}</span>
                                <span v-else-if="rolloDeshabilitado(rollo)" class="mt-1 block text-xs text-amber-600 dark:text-amber-400">Otra tela: no se puede mezclar con la seleccionada.</span>
                            </span>
                        </label>
                    </div>

                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                        <strong>@{{ rolloIds.length }}</strong> rollo(s) seleccionado(s) · <strong>@{{ formatoKg(pesoSeleccionado) }}</strong>
                    </p>
                </div>

                {{-- Resumen de tela (compacto) --}}
                <div v-if="rolloIds.length" class="mt-3 rounded border bg-gray-50 px-4 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <p v-if="cargando" class="text-gray-500 dark:text-gray-400">Cargando lineas y tallas disponibles...</p>

                    <div v-else-if="errorOpciones" class="rounded border border-red-300 bg-red-50 p-3 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        <p v-for="mensaje in errorOpciones">@{{ mensaje }}</p>
                    </div>

                    <div v-else-if="matriz" class="flex flex-wrap gap-x-8 gap-y-1">
                        <p><span class="text-xs text-gray-500 dark:text-gray-400">Tipo de tela</span> <span class="ml-1 font-semibold text-gray-800 dark:text-white">@{{ matriz.tela.nombre }}</span></p>
                        <p><span class="text-xs text-gray-500 dark:text-gray-400">Peso disponible</span> <span class="ml-1 font-semibold text-gray-800 dark:text-white">@{{ formatoKg(matriz.tela.peso_total) }}</span></p>
                        <p><span class="text-xs text-gray-500 dark:text-gray-400">Colores de esta orden</span> <span class="ml-1 font-semibold text-gray-800 dark:text-white">@{{ matriz.colores.map((c) => c.label).join(', ') }}</span></p>
                    </div>
                </div>

                {{-- 3. LINEAS --}}
                <div v-if="matriz" class="mt-4 rounded border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">3. Lineas</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Elige una o varias lineas a cortar con esta tela.</p>

                    <p v-if="! matriz.lineas.length" class="mt-3 text-sm text-amber-600 dark:text-amber-400">No hay productos en el catalogo asociados a este tipo de tela.</p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <label v-for="linea in matriz.lineas" :key="linea.attribute_family_id" class="flex cursor-pointer items-center gap-2 rounded border px-3 py-1.5 text-sm dark:border-gray-800" :class="lineasElegidas.includes(linea.attribute_family_id) ? 'border-blue-500 bg-blue-50/60 dark:bg-blue-950' : 'hover:bg-gray-50 dark:hover:bg-gray-950'">
                            <input type="checkbox" class="h-4 w-4 accent-blue-600" :checked="lineasElegidas.includes(linea.attribute_family_id)" @change="toggleLinea(linea)" />
                            <span class="font-semibold text-gray-800 dark:text-white">@{{ linea.nombre }}</span>
                        </label>
                    </div>
                </div>

                {{-- 4. VARIANTES --}}
                <div v-if="matriz && lineasSeleccionadas.length" class="mt-4 rounded border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">4. Variantes</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Solo aparecen las tallas que existen en el catalogo para cada linea y color.</p>

                    <details v-for="linea in lineasSeleccionadas" :key="'v' + linea.attribute_family_id" :open="lineasSeleccionadas.length === 1" class="mt-3 rounded border dark:border-gray-800">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-950">
                            <span class="min-w-0">
                                <span class="block font-semibold text-gray-900 dark:text-white">@{{ linea.nombre }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ resumenLinea(linea) }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform [details[open]_&]:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </summary>

                        <div class="border-t px-3 py-2 dark:border-gray-800">
                            <div v-for="celda in linea.colores" :key="celda.color_option_id" class="border-b py-2 last:border-b-0 dark:border-gray-800">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">@{{ celda.color }}</p>
                                    <div v-if="! celda.error && celda.opciones.length" class="flex gap-3 text-xs">
                                        <button type="button" class="text-blue-600 hover:underline dark:text-blue-400" @click="marcarTodas(linea, celda, true)">Todas</button>
                                        <span class="text-gray-300 dark:text-gray-700">|</span>
                                        <button type="button" class="text-gray-500 hover:underline dark:text-gray-400" @click="marcarTodas(linea, celda, false)">Ninguna</button>
                                    </div>
                                </div>

                                <p v-if="celda.error" class="mt-1 text-xs text-amber-600 dark:text-amber-400">No se puede cortar: @{{ celda.mensaje }}</p>

                                <template v-else>
                                    <div class="mt-1.5 flex flex-wrap gap-2">
                                        <label v-for="opcion in celda.opciones" :key="opcion.option_id" class="flex min-w-[2.75rem] cursor-pointer items-center justify-center rounded border px-3 py-1 text-sm focus-within:ring-2 focus-within:ring-blue-300 dark:border-gray-700" :class="estaElegida(linea, celda, opcion) ? 'border-blue-500 bg-blue-50 font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300' : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200'">
                                            <input type="checkbox" class="sr-only" :checked="estaElegida(linea, celda, opcion)" @change="toggleOpcion(linea, celda, opcion)" />
                                            @{{ opcion.label }}
                                        </label>
                                    </div>
                                    <p v-for="aviso in celda.advertencias" class="mt-1 text-xs text-amber-600 dark:text-amber-400">Inactivo en tienda — permitido para producción</p>
                                </template>
                            </div>
                        </div>
                    </details>
                </div>

                {{-- 5. REVISION --}}
                <div v-if="matriz" class="mt-4 rounded border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-base font-semibold text-gray-800 dark:text-white">5. Revision</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <template v-if="seleccionPlana.length"><strong>@{{ seleccionPlana.length }}</strong> variante(s) seleccionada(s). </template>
                                Revisa los productos exactos que se van a cortar antes de crear la orden.
                            </p>
                        </div>
                        <button type="button" class="secondary-button" :disabled="! seleccionPlana.length || revisando" @click="revisar">
                            @{{ revisando ? 'Revisando...' : 'Revisar seleccion' }}
                        </button>
                    </div>
                </div>

                {{-- MODAL DE REVISION: ultimo paso antes de crear --}}
                <div v-if="modalAbierta && preview" class="fixed inset-0 z-[10010] flex items-center justify-center bg-gray-900/50 p-4 max-sm:p-2" @click.self="cerrarModal(false)">
                    <div class="flex max-h-full w-full max-w-4xl flex-col rounded-lg border bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="revision-orden-titulo">
                        <div class="shrink-0 border-b px-5 py-4 max-sm:px-4 dark:border-gray-800">
                            <p id="revision-orden-titulo" class="text-lg font-bold text-gray-800 dark:text-white">Revisar selección</p>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Confirma que los productos seleccionados sean correctos antes de crear la orden de corte.</p>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 max-sm:px-4">
                            <div v-if="faltantes.length" class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                <p class="font-semibold">Falta información para crear la orden:</p>
                                <ul class="mt-1 list-disc pl-5"><li v-for="falta in faltantes">@{{ falta }}</li></ul>
                            </div>

                            <div v-if="preview.errores.length" class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                <p class="font-semibold">Hay combinaciones que no se pueden cortar:</p>
                                <ul class="mt-1 list-disc pl-5"><li v-for="error in preview.errores">@{{ error }}</li></ul>
                            </div>

                            {{-- Indicadores --}}
                            <div class="grid grid-cols-4 gap-3 max-md:grid-cols-2">
                                <div class="rounded border p-3 dark:border-gray-800">
                                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Rollos seleccionados</p>
                                    <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white">@{{ rollosSeleccionados.length }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">@{{ formatoKg(pesoSeleccionado) }}</p>
                                </div>
                                <div class="rounded border p-3 dark:border-gray-800">
                                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Líneas</p>
                                    <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white">@{{ previewAgrupado.length }}</p>
                                </div>
                                <div class="rounded border p-3 dark:border-gray-800">
                                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Colores</p>
                                    <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white">@{{ coloresPreview.length }}</p>
                                    <p class="break-words text-xs text-gray-500 dark:text-gray-400">@{{ coloresPreview.join(', ') }}</p>
                                </div>
                                <div class="rounded border p-3 dark:border-gray-800">
                                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Variantes</p>
                                    <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white">@{{ preview.filas.length }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">tallas seleccionadas</p>
                                </div>
                            </div>

                            {{-- Rollos --}}
                            <p class="mb-2 mt-5 text-sm font-semibold text-gray-800 dark:text-white">Rollos seleccionados</p>
                            <div class="rounded border dark:border-gray-800">
                                <table class="w-full text-sm max-md:hidden">
                                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                                        <tr><th class="px-3 py-2 font-semibold">Código</th><th class="px-3 py-2 font-semibold">Color</th><th class="px-3 py-2 font-semibold">Tipo de tela</th><th class="px-3 py-2 font-semibold">Gramaje</th><th class="px-3 py-2 text-right font-semibold">Peso disponible</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="rollo in rollosSeleccionados" :key="'rm' + rollo.id" class="border-t dark:border-gray-800">
                                            <td class="px-3 py-2 font-medium text-gray-800 dark:text-white">@{{ rollo.codigo }}</td>
                                            <td class="px-3 py-2">@{{ rollo.color }}</td>
                                            <td class="px-3 py-2">@{{ rollo.tipo_tela }}</td>
                                            <td class="px-3 py-2">@{{ rollo.gramaje ? rollo.gramaje + ' g' : '—' }}</td>
                                            <td class="px-3 py-2 text-right font-semibold text-gray-800 dark:text-white">@{{ formatoKg(rollo.peso_disponible) }}</td>
                                        </tr>
                                    </tbody>
                                </table>

                                <ul class="md:hidden">
                                    <li v-for="rollo in rollosSeleccionados" :key="'rl' + rollo.id" class="border-b px-3 py-2 text-sm last:border-b-0 dark:border-gray-800">
                                        <p class="break-words font-medium text-gray-800 dark:text-white">@{{ rollo.codigo }}</p>
                                        <p class="text-gray-600 dark:text-gray-300">@{{ rollo.color }} · @{{ rollo.tipo_tela }}<template v-if="rollo.gramaje"> · @{{ rollo.gramaje }} g</template></p>
                                        <p class="font-semibold text-gray-800 dark:text-white">@{{ formatoKg(rollo.peso_disponible) }}</p>
                                    </li>
                                </ul>
                            </div>
                            <p class="mt-2 text-right text-sm font-semibold text-gray-800 dark:text-white">Total de tela seleccionada: @{{ formatoKg(pesoSeleccionado) }}</p>

                            {{-- Lineas --}}
                            <p class="mb-2 mt-4 text-sm font-semibold text-gray-800 dark:text-white">Líneas seleccionadas</p>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="grupo in previewAgrupado" :key="'lc' + grupo.linea" class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300">@{{ grupo.linea }}</span>
                            </div>

                            {{-- Variantes a cortar: consolidado visual por linea (tallas unicas; la seleccion real no cambia) --}}
                            <p class="mb-2 mt-5 text-sm font-semibold text-gray-800 dark:text-white">Variantes a cortar</p>
                            <div class="space-y-2">
                                <div v-for="grupo in tallasPorLinea" :key="'tl' + grupo.linea" class="rounded border px-3 py-2.5 dark:border-gray-800">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="min-w-0 break-words font-semibold text-gray-800 dark:text-white">@{{ grupo.linea }}</p>
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">@{{ grupo.tallas.length }} @{{ grupo.tallas.length === 1 ? 'talla' : 'tallas' }}</span>
                                    </div>
                                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Tallas incluidas</p>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <span v-for="talla in grupo.tallas" :key="talla" class="rounded border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-sm font-medium text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300">@{{ talla }}</span>
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-white">@{{ tallasPorLinea.length }} @{{ tallasPorLinea.length === 1 ? 'línea' : 'líneas' }} · @{{ totalTallasConsolidadas }} @{{ totalTallasConsolidadas === 1 ? 'talla consolidada' : 'tallas consolidadas' }}</p>
                        </div>

                        <div class="flex shrink-0 items-center justify-between gap-3 border-t px-5 py-3 max-sm:px-4 dark:border-gray-800">
                            <button type="button" class="secondary-button max-sm:flex-1" :disabled="enviando" @click="cerrarModal(true)">Volver a editar</button>
                            <button type="button" class="primary-button max-sm:flex-1" :disabled="! puedeConfirmar || enviando" @click="confirmarCrear">
                                @{{ enviando ? 'Creando orden...' : 'Crear orden de corte' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-orden-corte-form', {
                template: '#v-orden-corte-form-template',

                props: {
                    rollos: { type: Array, default: () => [] },
                    oldRolloIds: { type: Array, default: () => [] },
                    oldSeleccion: { type: Array, default: () => [] },
                    opcionesUrl: { type: String, required: true },
                    previsualizarUrl: { type: String, required: true },
                },

                data() {
                    return {
                        rolloIds: this.oldRolloIds.slice(),
                        filtro: '',
                        matriz: null,
                        cargando: false,
                        errorOpciones: null,
                        lineasElegidas: [],
                        elegidas: {},
                        preview: null,
                        previewVigente: false,
                        revisando: false,
                        modalAbierta: false,
                        enviando: false,
                        faltantes: [],
                        temporizador: null,
                        oldPendiente: this.oldSeleccion.slice(),
                    };
                },

                computed: {
                    rollosSeleccionados() {
                        return this.rollos.filter((r) => this.rolloIds.includes(r.id));
                    },

                    tipoTelaElegida() {
                        return this.rollosSeleccionados.length ? this.rollosSeleccionados[0].tipo_tela_id : null;
                    },

                    pesoSeleccionado() {
                        return this.rollosSeleccionados.reduce((total, r) => total + Number(r.peso_disponible || 0), 0);
                    },

                    rollosVisibles() {
                        let texto = this.filtro.trim().toLowerCase();

                        if (! texto) {
                            return this.rollos;
                        }

                        return this.rollos.filter((r) => [r.codigo, r.color, r.referencia, r.proveedor, r.tipo_tela].join(' ').toLowerCase().includes(texto));
                    },

                    lineasSeleccionadas() {
                        return this.matriz ? this.matriz.lineas.filter((l) => this.lineasElegidas.includes(l.attribute_family_id)) : [];
                    },

                    seleccionPlana() {
                        return Object.values(this.elegidas).filter((f) => this.lineasElegidas.includes(f.attribute_family_id));
                    },

                    previewAgrupado() {
                        if (! this.preview) {
                            return [];
                        }

                        let grupos = {};

                        this.preview.filas.forEach((fila) => {
                            grupos[fila.linea] = grupos[fila.linea] || { linea: fila.linea, filas: [] };
                            grupos[fila.linea].filas.push(fila);
                        });

                        return Object.values(grupos);
                    },

                    puedeCrear() {
                        return Boolean(this.preview) && this.previewVigente && this.preview.errores.length === 0 && this.preview.filas.length > 0;
                    },

                    puedeConfirmar() {
                        return this.puedeCrear && this.faltantes.length === 0;
                    },

                    filasPreview() {
                        return this.previewAgrupado.flatMap((grupo) => grupo.filas);
                    },

                    coloresPreview() {
                        return [...new Set(this.filasPreview.map((fila) => fila.color))];
                    },

                    // Solo presentacion: cada talla una vez por linea. La seleccion enviada sigue siendo seleccionPlana.
                    tallasPorLinea() {
                        return this.previewAgrupado.map((grupo) => ({
                            linea: grupo.linea,
                            tallas: [...new Set(grupo.filas.map((fila) => fila.talla))],
                        }));
                    },

                    totalTallasConsolidadas() {
                        return this.tallasPorLinea.reduce((total, grupo) => total + grupo.tallas.length, 0);
                    },
                },

                watch: {
                    rolloIds() {
                        this.invalidarRevision();
                        clearTimeout(this.temporizador);
                        this.temporizador = setTimeout(() => this.cargarOpciones(), 250);
                    },

                    lineasElegidas() { this.invalidarRevision(); },
                    elegidas: { deep: true, handler() { this.invalidarRevision(); } },
                },

                mounted() {
                    if (this.rolloIds.length) {
                        this.cargarOpciones();
                    }

                    document.addEventListener('keydown', this.alPulsarTecla);
                    window.addEventListener('pageshow', this.alMostrarPagina);
                },

                beforeUnmount() {
                    document.removeEventListener('keydown', this.alPulsarTecla);
                    window.removeEventListener('pageshow', this.alMostrarPagina);
                    document.body.style.overflow = '';
                },

                methods: {
                    formatoKg(valor) {
                        return Number(valor || 0).toLocaleString('es-CO', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' kg';
                    },

                    invalidarRevision() {
                        this.previewVigente = false;
                    },

                    rolloDeshabilitado(rollo) {
                        if (rollo.bloqueo) {
                            return true;
                        }

                        return ! this.rolloIds.includes(rollo.id) && this.tipoTelaElegida !== null && rollo.tipo_tela_id !== this.tipoTelaElegida;
                    },

                    toggleRollo(rollo) {
                        this.rolloIds = this.rolloIds.includes(rollo.id)
                            ? this.rolloIds.filter((id) => id !== rollo.id)
                            : [...this.rolloIds, rollo.id];
                    },

                    mensajesDeError(error) {
                        let errores = error.response?.data?.errors;

                        if (errores) {
                            return Object.values(errores).flat();
                        }

                        return [error.response?.data?.message || 'No se pudieron cargar las opciones.'];
                    },

                    cargarOpciones() {
                        this.matriz = null;
                        this.errorOpciones = null;
                        this.preview = null;

                        if (! this.rolloIds.length) {
                            this.lineasElegidas = [];
                            this.elegidas = {};

                            return;
                        }

                        this.cargando = true;

                        this.$axios.get(this.opcionesUrl, { params: { rollo_ids: this.rolloIds } })
                            .then((respuesta) => {
                                this.matriz = respuesta.data.data;
                                this.depurarSeleccion();
                                this.aplicarOld();
                            })
                            .catch((error) => { this.errorOpciones = this.mensajesDeError(error); })
                            .finally(() => { this.cargando = false; });
                    },

                    // Tras cambiar los rollos, solo sobreviven las combinaciones que siguen siendo validas.
                    depurarSeleccion() {
                        let validas = {};
                        let lineas = [];

                        this.matriz.lineas.forEach((linea) => {
                            linea.colores.forEach((celda) => {
                                celda.opciones.forEach((opcion) => {
                                    let clave = this.clave(linea.attribute_family_id, celda.color_option_id, opcion.option_id);

                                    if (this.elegidas[clave]) {
                                        validas[clave] = this.elegidas[clave];
                                    }
                                });
                            });

                            if (this.lineasElegidas.includes(linea.attribute_family_id)) {
                                lineas.push(linea.attribute_family_id);
                            }
                        });

                        this.elegidas = validas;
                        this.lineasElegidas = lineas;
                    },

                    // Si el envio anterior fallo, se restaura la seleccion que habia.
                    aplicarOld() {
                        if (! this.oldPendiente.length) {
                            return;
                        }

                        this.oldPendiente.forEach((fila) => {
                            let familia = Number(fila.attribute_family_id);

                            if (! this.lineasElegidas.includes(familia)) {
                                this.lineasElegidas.push(familia);
                            }

                            let clave = this.clave(familia, Number(fila.color_option_id), Number(fila.eje_option_id));
                            this.elegidas[clave] = { attribute_family_id: familia, color_option_id: Number(fila.color_option_id), eje_option_id: Number(fila.eje_option_id) };
                        });

                        this.oldPendiente = [];
                    },

                    clave(familia, color, eje) {
                        return familia + '|' + color + '|' + eje;
                    },

                    toggleLinea(linea) {
                        this.lineasElegidas = this.lineasElegidas.includes(linea.attribute_family_id)
                            ? this.lineasElegidas.filter((id) => id !== linea.attribute_family_id)
                            : [...this.lineasElegidas, linea.attribute_family_id];
                    },

                    estaElegida(linea, celda, opcion) {
                        return Boolean(this.elegidas[this.clave(linea.attribute_family_id, celda.color_option_id, opcion.option_id)]);
                    },

                    toggleOpcion(linea, celda, opcion) {
                        let clave = this.clave(linea.attribute_family_id, celda.color_option_id, opcion.option_id);
                        let siguientes = { ...this.elegidas };

                        if (siguientes[clave]) {
                            delete siguientes[clave];
                        } else {
                            siguientes[clave] = { attribute_family_id: linea.attribute_family_id, color_option_id: celda.color_option_id, eje_option_id: opcion.option_id };
                        }

                        this.elegidas = siguientes;
                    },

                    marcarTodas(linea, celda, marcar) {
                        let siguientes = { ...this.elegidas };

                        celda.opciones.forEach((opcion) => {
                            let clave = this.clave(linea.attribute_family_id, celda.color_option_id, opcion.option_id);

                            if (marcar) {
                                siguientes[clave] = { attribute_family_id: linea.attribute_family_id, color_option_id: celda.color_option_id, eje_option_id: opcion.option_id };
                            } else {
                                delete siguientes[clave];
                            }
                        });

                        this.elegidas = siguientes;
                    },

                    contarLinea(linea) {
                        return this.seleccionPlana.filter((f) => f.attribute_family_id === linea.attribute_family_id).length;
                    },

                    // "3 colores · 7 tallas seleccionadas" (tallas = variantes marcadas de la linea).
                    resumenLinea(linea) {
                        let elegidas = this.seleccionPlana.filter((f) => f.attribute_family_id === linea.attribute_family_id);
                        let colores = new Set(elegidas.map((f) => f.color_option_id)).size;

                        return colores + (colores === 1 ? ' color' : ' colores') + ' · ' + elegidas.length + (elegidas.length === 1 ? ' talla seleccionada' : ' tallas seleccionadas');
                    },

                    // Datos del formulario que el servidor exige pero que viven fuera de este componente.
                    calcularFaltantes() {
                        let cortador = document.querySelector('input[name="cortador_id"]');

                        return cortador && cortador.value ? [] : ['Selecciona el cortador en "Datos de la orden".'];
                    },

                    revisar() {
                        this.revisando = true;

                        this.$axios.post(this.previsualizarUrl, { rollo_ids: this.rolloIds, seleccion: this.seleccionPlana })
                            .then((respuesta) => {
                                this.preview = respuesta.data.data;
                                this.previewVigente = true;
                            })
                            .catch((error) => {
                                this.preview = { filas: [], errores: this.mensajesDeError(error) };
                                this.previewVigente = true;
                            })
                            .finally(() => {
                                this.revisando = false;
                                this.faltantes = this.calcularFaltantes();
                                this.modalAbierta = true;
                                document.body.style.overflow = 'hidden';
                            });
                    },

                    cerrarModal(irAFaltante) {
                        if (this.enviando) {
                            return;
                        }

                        this.modalAbierta = false;
                        document.body.style.overflow = '';

                        if (irAFaltante === true && this.faltantes.length) {
                            let cortador = document.querySelector('input[name="cortador_id"]');
                            let campo = cortador ? cortador.parentElement.querySelector('input[type="text"]') : null;

                            if (campo) {
                                campo.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                campo.focus();
                            }
                        }
                    },

                    // Mismo submit nativo de siempre; el modal solo lo dispara una vez.
                    confirmarCrear() {
                        this.faltantes = this.calcularFaltantes();

                        if (this.enviando || ! this.puedeConfirmar) {
                            return;
                        }

                        this.enviando = true;
                        this.$el.closest('form').requestSubmit();
                    },

                    alPulsarTecla(evento) {
                        if (evento.key === 'Escape' && this.modalAbierta) {
                            this.cerrarModal(false);
                        }
                    },

                    // Al volver con "atras" la pagina puede restaurarse con el boton bloqueado.
                    alMostrarPagina() {
                        this.enviando = false;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
