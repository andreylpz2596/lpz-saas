@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Distribuir Corte {{ $ordenCorte->codigo }}</x-slot>

    <div class="bylopez-scope">
        <v-distribuir-confeccion
            orden-corte-id="{{ $ordenCorte->id }}"
            orden-corte-codigo="{{ $ordenCorte->codigo }}"
            fecha-recepcion="{{ optional($ordenCorte->recepcion?->fecha_recepcion)->format('Y-m-d') }}"
            estado-label="{{ \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::tryFrom((string) $ordenCorte->estado)?->label() }}"
            estado-clase="{{ \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::tryFrom((string) $ordenCorte->estado)?->badgeClass() }}"
            :filas='@json($filas)'
            :talleres='@json($talleres)'
            :taller-preseleccionado-id='@json($tallerPreseleccionado?->id)'
            contexto-url="{{ route('admin.corte_confeccion.ordenes_confeccion.contexto', $ordenCorte->id) }}"
            distribuir-url="{{ route('admin.corte_confeccion.ordenes_confeccion.distribuir') }}"
            orden-corte-url="{{ route('admin.corte_confeccion.ordenes_corte.show', $ordenCorte->id) }}"
        ></v-distribuir-confeccion>
    </div>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-distribuir-confeccion-template">
            <div>
                <template v-if="vista === 'editar'">
                <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                    <div>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white">Distribuir Corte @{{ ordenCorteCodigo }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                            <span class="inline-flex items-center gap-1.5" v-if="fechaRecepcion">
                                <span class="icon-calendar text-base"></span>
                                Recibido en bodega: <strong class="font-semibold text-gray-800 dark:text-white">@{{ fechaRecepcion }}</strong>
                            </span>
                            <span class="inline-flex items-center gap-1.5" v-if="estadoLabel">
                                Estado:
                                <span :class="estadoClase || 'label-active'" data-testid="estado-orden-corte">@{{ estadoLabel }}</span>
                            </span>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="primary-button"
                        :class="{ 'cursor-not-allowed opacity-50': !puedeRevisar }"
                        :disabled="!puedeRevisar"
                        :title="puedeRevisar ? '' : motivoRevisarDeshabilitado"
                        data-testid="btn-revisar-distribucion"
                        @click="irARevisar"
                    >
                        Revisar distribucion
                    </button>
                </div>

                <div class="mt-5 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1" data-testid="kpis-distribucion">
                    <div class="flex items-center gap-4 rounded-xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900 dark:text-emerald-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Apto total</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">@{{ totalApto }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 rounded-xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9-4 9 4-9 4-9-4zm0 5l9 4 9-4M3 17l9 4 9-4" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Ya distribuido</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">@{{ totalYaDistribuido }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 rounded-xl border border-amber-100 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Por distribuir</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">@{{ totalPendiente }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">@{{ totalDistribuirAhora }} en esta distribucion</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 rounded-xl border border-violet-100 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">Variantes</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">@{{ filasState.length }}</p>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    v-if="mensajeErrorGeneral && tipoErrorEnvio === 'validacion'"
                    data-testid="banner-error-validacion"
                >
                    <p class="font-semibold">No se pudo generar la distribucion con los datos anteriores.</p>
                    <p class="mt-1">Actualizamos la disponibilidad y los talleres activos. Revisa las variantes marcadas antes de continuar.</p>
                    <ul class="mt-2 list-disc pl-5" v-if="listaErroresValidacion.length">
                        <li v-for="(mensaje, indice) in listaErroresValidacion" :key="indice">@{{ mensaje }}</li>
                    </ul>
                </div>

                <div class="mt-5 rounded-xl border bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-4 text-sm font-semibold text-gray-800 dark:text-white">Configuracion general</p>

                    <div class="grid grid-cols-3 gap-5 max-md:grid-cols-1">
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Taller predeterminado
                            </label>
                            <select
                                v-model="tallerPredeterminado"
                                @change="aplicarTallerPredeterminado"
                                data-testid="taller-predeterminado"
                                class="custom-select w-full rounded-lg border px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                            >
                                <option :value="null">Sin taller predeterminado</option>
                                <option v-for="taller in talleresState" :key="taller.id" :value="taller.id">@{{ taller.nombre }}</option>
                            </select>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                Ayuda de captura: rellena filas simples sin taller propio. No asigna cantidades ni se guarda.
                            </p>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Fecha de entrega predeterminada
                            </label>
                            <input
                                type="date"
                                v-model="fechaPredeterminada"
                                class="w-full rounded-lg border px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                            >
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Observacion general
                            </label>
                            <input
                                type="text"
                                v-model="observacionGeneral"
                                placeholder="Opcional -- aplica a todas las ordenes"
                                class="w-full rounded-lg border px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                            >
                        </div>
                    </div>

                    <div class="mt-5 border-t pt-4 dark:border-gray-800" v-if="talleresUtilizados.length">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Fechas por taller</p>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 py-1 text-sm" v-for="taller in talleresUtilizados" :key="taller.id">
                            <span class="min-w-[140px] font-medium text-gray-800 dark:text-white">@{{ taller.nombre }}</span>

                            <template v-if="configuracionTalleres[taller.id]">
                                <input
                                    type="date"
                                    :value="configuracionTalleres[taller.id]"
                                    @change="personalizarFecha(taller.id, $event.target.value)"
                                    class="rounded-md border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                <span class="text-xs text-amber-600 dark:text-amber-400">[personalizada]</span>
                                <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-400" @click="quitarPersonalizacion(taller.id)">
                                    Usar predeterminada
                                </button>
                            </template>
                            <template v-else>
                                <span class="text-gray-700 dark:text-gray-300">@{{ fechaPredeterminada }}</span>
                                <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-400" @click="personalizarFecha(taller.id, fechaPredeterminada)">
                                    Personalizar
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Disponibilidad para confeccion
                    </p>

                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300" v-if="filasSeleccionables.length">
                        <input type="checkbox" v-model="todasSeleccionadas">
                        Seleccionar todas
                    </label>
                </div>

                <div
                    class="fixed z-30 mb-3 flex flex-wrap items-center gap-3 rounded border bg-gray-50 p-3 text-sm shadow-md dark:border-gray-800 dark:bg-gray-900"
                    v-if="filasSeleccionadas.length"
                >
                    <span class="font-semibold text-gray-800 dark:text-white">@{{ filasSeleccionadas.length }} seleccionadas</span>

                    <select
                        @change="asignarTallerMasivo($event.target.value ? Number($event.target.value) : null); $event.target.value = ''"
                        data-testid="masivo-taller"
                        class="custom-select rounded-md border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">Taller...</option>
                        <option v-for="taller in talleresState" :key="taller.id" :value="taller.id">@{{ taller.nombre }}</option>
                    </select>

                    <select
                        @change="asignarPrioridadMasiva($event.target.value); $event.target.value = ''"
                        class="custom-select rounded-md border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">Prioridad...</option>
                        <option value="normal">Normal</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>

                    <button type="button" class="secondary-button" @click="asignarDisponibleMasivo">Asignar disponible</button>
                    <button type="button" class="transparent-button" @click="limpiarSeleccionadas">Limpiar asignacion</button>
                    <button type="button" class="ml-auto text-gray-400 hover:text-gray-600" @click="deseleccionarTodas" title="Deseleccionar todas">✕</button>

                    <p class="w-full text-xs text-amber-600 dark:text-amber-400" v-if="avisoMasivo">@{{ avisoMasivo }}</p>
                </div>

                <div class="md:overflow-x-auto md:rounded-xl md:border md:bg-white md:shadow-sm dark:md:border-gray-800 dark:md:bg-gray-900" v-if="filasState.length">
                <div
                    class="hidden md:grid md:min-w-[1080px] md:grid-cols-[32px_minmax(220px,1fr)_120px_72px_88px_176px_170px_116px_84px] md:items-center md:gap-x-3 md:border-b md:bg-gray-50 md:px-4 md:py-3 md:text-[11px] md:font-semibold md:uppercase md:tracking-wide md:text-gray-500 dark:md:border-gray-800 dark:md:bg-gray-950 dark:md:text-gray-400"
                >
                    <span></span>
                    <span>Producto / variante</span>
                    <span>SKU</span>
                    <span>Stock</span>
                    <span>Disponible</span>
                    <span>Cantidad a entregar</span>
                    <span>Taller</span>
                    <span>Prioridad</span>
                    <span class="text-right">Acciones</span>
                </div>

                <div class="space-y-3 md:min-w-[1080px] md:space-y-0 md:divide-y dark:md:divide-gray-800">
                    <div
                        class="rounded-lg border p-4 dark:border-gray-800 md:rounded-none md:border-0 md:p-0"
                        v-for="fila in filasState"
                        :key="fila.product_id"
                    >
                        <!-- Escritorio (>=768px): una fila por variante, misma logica/estado que la tarjeta movil de abajo -->
                        <div
                            data-testid="fila-compacta"
                            class="hidden md:grid md:grid-cols-[32px_minmax(220px,1fr)_120px_72px_88px_176px_170px_116px_84px] md:items-center md:gap-x-3 md:px-4 md:py-3 hover:md:bg-gray-50 dark:hover:md:bg-gray-950"
                            :class="{ 'md:bg-blue-50/40': fila.seleccionada }"
                        >
                            <input
                                type="checkbox"
                                v-model="fila.seleccionada"
                                :disabled="fila.modo === 'dividido' || fila.disponible_confeccion <= 0"
                            >

                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-white" :title="fila.nombre">
                                    @{{ fila.nombre }}
                                    <span
                                        v-if="fila.modo === 'dividido'"
                                        class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-900 dark:text-blue-200"
                                    >Dividida</span>
                                </p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="fila.color" class="inline-flex items-center gap-1.5 rounded-full border bg-white px-2 py-0.5 dark:border-gray-700 dark:bg-gray-900" data-testid="color-variante">
                                        <span
                                            class="inline-block h-3.5 w-3.5 shrink-0 rounded-full border border-black/20"
                                            :style="{ backgroundColor: fila.color_hex || '#e5e7eb' }"
                                            :title="fila.color_hex || 'Sin color registrado'"
                                        ></span>
                                        <span class="font-medium text-gray-700 dark:text-gray-200">@{{ fila.color }}</span>
                                    </span>
                                    <span v-if="fila.talla">@{{ fila.talla_etiqueta || 'Talla' }} @{{ fila.talla }}</span>
                                </p>
                                <p class="mt-1 text-[11px] font-semibold text-red-600 dark:text-red-400" v-if="fila._disponibleAnterior !== null && fila._disponibleAnterior !== undefined">
                                    Disponible cambio: antes @{{ fila._disponibleAnterior }}, ahora @{{ fila.disponible_confeccion }}. Corrige la cantidad.
                                </p>
                            </div>

                            <p class="truncate font-mono text-xs text-gray-600 dark:text-gray-300" :title="fila.sku">@{{ fila.sku }}</p>

                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">@{{ fila.stock_actual === null ? '—' : fila.stock_actual }}</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500" v-if="fila.stock_actual !== null" title="Stock proyectado (informativo, no persistido)">
                                    ~@{{ fila.stock_actual + filaEntregarActual(fila) }}
                                </p>
                            </div>

                            <div>
                                <span
                                    class="inline-flex min-w-[2.5rem] justify-center rounded-full px-2.5 py-1 text-xs font-bold"
                                    :class="fila.disponible_confeccion > 0 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                    title="Disponible (apto) para confeccion"
                                >@{{ fila.disponible_confeccion }}</span>
                            </div>

                            <div>
                                <div v-if="fila.modo === 'simple'" class="inline-flex items-center overflow-hidden rounded-lg border bg-white dark:border-gray-700 dark:bg-gray-900">
                                    <button
                                        type="button"
                                        class="h-9 w-8 text-lg leading-none text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:text-gray-300 dark:text-gray-300 dark:hover:bg-gray-800"
                                        :disabled="fila.disponible_confeccion <= 0 || Number(fila.entregar || 0) <= 0"
                                        title="Restar 1"
                                        @click="ajustarEntregar(fila, -1)"
                                    >&minus;</button>
                                    <input
                                        type="number"
                                        min="0"
                                        :max="fila.disponible_confeccion"
                                        v-model.number="fila.entregar"
                                        @input="alEditarEntregar(fila)"
                                        :disabled="fila.disponible_confeccion <= 0"
                                        data-testid="input-entregar"
                                        class="h-9 w-16 border-x px-1 text-center text-sm font-bold text-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    >
                                    <button
                                        type="button"
                                        class="h-9 w-8 text-lg leading-none text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:text-gray-300 dark:text-gray-300 dark:hover:bg-gray-800"
                                        :disabled="fila.disponible_confeccion <= 0 || Number(fila.entregar || 0) >= fila.disponible_confeccion"
                                        title="Sumar 1"
                                        @click="ajustarEntregar(fila, 1)"
                                    >+</button>
                                </div>
                                <p v-else class="text-sm font-bold text-gray-900 dark:text-white">@{{ filaEntregarActual(fila) }}</p>

                                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                    Pendiente: <strong>@{{ Math.max(fila.disponible_confeccion - filaEntregarActual(fila), 0) }}</strong>
                                </p>
                                <button
                                    type="button"
                                    class="text-[11px] text-blue-600 hover:underline dark:text-blue-400"
                                    v-if="fila.modo === 'simple' && fila.taller_id && fila.entregarEditado && Number(fila.entregar || 0) !== fila.disponible_confeccion"
                                    data-testid="usar-disponible"
                                    @click="usarDisponible(fila)"
                                >Usar disponible (@{{ fila.disponible_confeccion }})</button>
                            </div>

                            <div class="min-w-0">
                                <select
                                    v-if="fila.modo === 'simple'"
                                    v-model="fila.taller_id"
                                    @change="alCambiarTaller(fila)"
                                    data-testid="select-taller-fila"
                                    class="custom-select w-full rounded-lg border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                    <option :value="null">Sin taller</option>
                                    <option v-for="taller in talleresState" :key="taller.id" :value="taller.id">@{{ taller.nombre }}</option>
                                </select>
                                <p v-else class="truncate text-sm font-semibold text-gray-800 dark:text-white" :title="fila.repartos.length + ' taller(es) &middot; total ' + filaEntregarActual(fila)">
                                    @{{ fila.repartos.length }} taller@{{ fila.repartos.length === 1 ? '' : 'es' }} &middot; @{{ filaEntregarActual(fila) }}
                                </p>
                                <p class="mt-1 text-[10px] font-semibold text-red-600 dark:text-red-400" v-if="fila.modo === 'simple' && fila._tallerInvalido && !fila.taller_id">
                                    Taller ya no activo, reasigna
                                </p>
                            </div>

                            <div class="min-w-0">
                                <select
                                    v-if="fila.modo === 'simple'"
                                    v-model="fila.prioridad"
                                    class="custom-select w-full rounded-lg border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                    <option value="normal">Normal</option>
                                    <option value="alta">Alta</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                                <span v-else class="text-sm font-semibold text-gray-800 dark:text-white">
                                    @{{ prioridadMostrada(fila) === 'mixta' ? 'Mixta' : prioridadMostrada(fila) }}
                                </span>
                            </div>

                            <div class="flex flex-col items-end gap-1">
                                <button
                                    type="button"
                                    class="rounded-md border px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-transparent dark:border-gray-700 dark:text-blue-400 dark:hover:bg-gray-800 dark:disabled:text-gray-600"
                                    :disabled="fila.disponible_confeccion <= 0"
                                    @click="abrirDrawer(fila)"
                                >
                                    @{{ fila.modo === 'dividido' ? 'Editar' : 'Dividir' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-[11px] text-gray-500 hover:text-red-600 dark:text-gray-400"
                                    v-if="fila.modo === 'dividido'"
                                    @click="solicitarQuitarDivision(fila)"
                                >
                                    Quitar
                                </button>
                            </div>
                        </div>

                        <!-- Tarjeta (<768px): sin cambios respecto al diseno aprobado en 03C1/03C2 -->
                        <div class="md:hidden">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <input
                                    type="checkbox"
                                    class="mt-1"
                                    v-model="fila.seleccionada"
                                    :disabled="fila.modo === 'dividido' || fila.disponible_confeccion <= 0"
                                >

                                <div class="min-w-0">
                                    <p class="break-words font-semibold text-gray-900 dark:text-white">
                                        @{{ fila.nombre }}
                                        <span
                                            v-if="fila.modo === 'dividido'"
                                            class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-900 dark:text-blue-200"
                                        >Dividida</span>
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300" v-if="fila.color || fila.talla">
                                        <span v-if="fila.color">@{{ fila.color }}</span>
                                        <span v-if="fila.color && fila.talla"> · </span>
                                        <span v-if="fila.talla">@{{ fila.talla_etiqueta || 'Talla' }}: @{{ fila.talla }}</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">SKU: @{{ fila.sku }}</p>
                                    <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400" v-if="fila._disponibleAnterior !== null && fila._disponibleAnterior !== undefined">
                                        Disponible cambio: antes @{{ fila._disponibleAnterior }}, ahora @{{ fila.disponible_confeccion }}. Corrige la cantidad.
                                    </p>
                                </div>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Stock actual</p>
                                <p class="text-lg font-semibold text-gray-900 dark:text-white">
                                    @{{ fila.stock_actual === null ? 'No disponible' : fila.stock_actual }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500" v-if="fila.stock_actual !== null">
                                    Proyectado ~@{{ fila.stock_actual + filaEntregarActual(fila) }} · no disponible para venta
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-4 max-md:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Disponible corte</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">@{{ fila.disponible_confeccion }}</p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Entregar ahora</p>
                                <input
                                    v-if="fila.modo === 'simple'"
                                    type="number"
                                    min="0"
                                    :max="fila.disponible_confeccion"
                                    v-model.number="fila.entregar"
                                    @input="alEditarEntregar(fila)"
                                    :disabled="fila.disponible_confeccion <= 0"
                                    class="mt-1 w-24 rounded-md border px-2 py-1.5 text-sm font-semibold dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                <p v-else class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">@{{ filaEntregarActual(fila) }}</p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pendiente despues</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                                    @{{ Math.max(fila.disponible_confeccion - filaEntregarActual(fila), 0) }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Taller</p>

                                <select
                                    v-if="fila.modo === 'simple'"
                                    v-model="fila.taller_id"
                                    @change="alCambiarTaller(fila)"
                                    class="custom-select mt-1 w-full rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                    <option :value="null">Seleccionar taller</option>
                                    <option v-for="taller in talleresState" :key="taller.id" :value="taller.id">@{{ taller.nombre }}</option>
                                </select>
                                <p v-else class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">
                                    @{{ fila.repartos.length }} taller@{{ fila.repartos.length === 1 ? '' : 'es' }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400" v-if="fila.modo === 'simple' && fila._tallerInvalido && !fila.taller_id">
                                    Taller ya no activo, reasigna
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                            <span>Recibido: @{{ fila.cantidad_recibida }}</span>
                            <span>Defectuosa: @{{ fila.cantidad_defectuosa }}</span>
                            <span>Apta: @{{ fila.apta_confeccion }}</span>
                            <span>Ya distribuido: @{{ fila.ya_distribuido }}</span>

                            <span class="flex items-center gap-2 max-sm:w-full" v-if="fila.modo === 'simple'">
                                <span>Prioridad</span>
                                <select
                                    v-model="fila.prioridad"
                                    class="custom-select rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >
                                    <option value="normal">Normal</option>
                                    <option value="alta">Alta</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                            </span>
                            <span v-else>Prioridad: <strong>@{{ prioridadMostrada(fila) === 'mixta' ? 'Prioridad mixta' : prioridadMostrada(fila) }}</strong></span>

                            <span class="ml-auto flex gap-3">
                                <button
                                    type="button"
                                    class="text-blue-600 hover:underline dark:text-blue-400 disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline dark:disabled:text-gray-600"
                                    :disabled="fila.disponible_confeccion <= 0"
                                    @click="abrirDrawer(fila)"
                                >
                                    @{{ fila.modo === 'dividido' ? 'Editar division' : 'Dividir entre talleres' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-gray-500 hover:underline dark:text-gray-400"
                                    v-if="fila.modo === 'dividido'"
                                    @click="solicitarQuitarDivision(fila)"
                                >
                                    Quitar division
                                </button>
                            </span>
                        </div>
                        </div>
                    </div>
                </div>
                </div>

                <p class="rounded border p-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400" v-else>
                    Sin recepcion registrada para esta orden de corte.
                </p>

                <x-admin::drawer ref="drawerDividir" width="480px">
                    <x-slot:header>
                        <template v-if="filaEnEdicion">
                            <p class="text-base font-semibold text-gray-800 dark:text-white">Dividir · @{{ filaEnEdicion.nombre }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="filaEnEdicion.color">@{{ filaEnEdicion.color }}</span>
                                <span v-if="filaEnEdicion.color && filaEnEdicion.talla"> · </span>
                                <span v-if="filaEnEdicion.talla">@{{ filaEnEdicion.talla_etiqueta || 'Talla' }}: @{{ filaEnEdicion.talla }}</span>
                                — SKU: @{{ filaEnEdicion.sku }}
                            </p>
                        </template>
                    </x-slot:header>

                    <x-slot:content>
                        <template v-if="filaEnEdicion">
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                                Disponible: <strong class="text-gray-900 dark:text-white">@{{ filaEnEdicion.disponible_confeccion }}</strong>
                            </p>

                            <div class="mb-2" v-for="(reparto, index) in repartosDraft" :key="index">
                                <div class="grid grid-cols-[1fr_90px_110px_28px] items-center gap-2">
                                    <select
                                        v-model="reparto.taller_id"
                                        @change="reparto._tallerInvalido = false"
                                        class="custom-select w-full rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    >
                                        <option :value="null">Taller...</option>
                                        <option v-for="taller in opcionesTallerDraft(index)" :key="taller.id" :value="taller.id">@{{ taller.nombre }}</option>
                                    </select>

                                    <input
                                        type="number"
                                        min="0"
                                        v-model.number="reparto.cantidad"
                                        class="w-full rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    >

                                    <select
                                        v-model="reparto.prioridad"
                                        class="custom-select w-full rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    >
                                        <option value="normal">Normal</option>
                                        <option value="alta">Alta</option>
                                        <option value="urgente">Urgente</option>
                                    </select>

                                    <button type="button" class="text-gray-400 hover:text-red-600" @click="repartosDraft.splice(index, 1)" title="Eliminar">🗑</button>
                                </div>
                                <p class="mt-0.5 text-[10px] font-semibold text-red-600 dark:text-red-400" v-if="reparto._tallerInvalido && !reparto.taller_id">
                                    Taller ya no activo, elige otro
                                </p>
                            </div>

                            <button type="button" class="secondary-button mt-1" @click="agregarTallerDraft">+ Agregar Taller</button>

                            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t pt-3 text-sm dark:border-gray-800">
                                <span>Disponible <strong class="text-gray-900 dark:text-white">@{{ filaEnEdicion.disponible_confeccion }}</strong></span>
                                <span>Asignado <strong class="text-gray-900 dark:text-white">@{{ asignadoDraft }}</strong></span>
                                <span>Pendiente <strong class="text-gray-900 dark:text-white">@{{ pendienteDraft }}</strong></span>
                            </div>

                            <ul class="mt-2 list-disc pl-5 text-xs text-red-600 dark:text-red-400" v-if="erroresDraft.length">
                                <li v-for="error in erroresDraft" :key="error">@{{ error }}</li>
                            </ul>
                        </template>
                    </x-slot:content>

                    <x-slot:footer>
                        <div class="flex w-full items-center justify-between">
                            <button type="button" class="transparent-button" v-if="filaEnEdicion && filaEnEdicion.modo === 'dividido'" @click="solicitarQuitarDivision(filaEnEdicion)">
                                Quitar division
                            </button>
                            <span v-else></span>

                            <div class="flex gap-2">
                                <button type="button" class="transparent-button" @click="cancelarDrawer">Cancelar</button>
                                <button type="button" class="primary-button" :disabled="erroresDraft.length > 0" @click="confirmarDrawer">Aplicar</button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-admin::drawer>

                <div style="display:none" id="distribuir-payload-preview" data-testid="payload-preview">@{{ JSON.stringify(construirAsignaciones()) }}</div>
                </template>

                <template v-if="vista === 'revisar'">
                    <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                        <div>
                            <p class="text-xl font-bold text-gray-800 dark:text-white">Revisar distribucion @{{ ordenCorteCodigo }}</p>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">Confirma antes de generar las ordenes de confeccion.</p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
                        <span>Apto: <strong class="text-gray-900 dark:text-white">@{{ totalApto }}</strong></span>
                        <span>Ya distribuido: <strong class="text-gray-900 dark:text-white">@{{ totalYaDistribuido }}</strong></span>
                        <span>Distribuir ahora: <strong class="text-gray-900 dark:text-white">@{{ totalDistribuirAhora }}</strong></span>
                        <span>Pendiente: <strong class="text-gray-900 dark:text-white">@{{ totalPendiente }}</strong></span>
                    </div>

                    <div
                        class="mt-4 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
                        v-if="resultadoIncierto"
                        data-testid="banner-resultado-incierto"
                    >
                        <p class="font-semibold">@{{ mensajeErrorGeneral }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a :href="ordenCorteUrl" class="secondary-button">Volver a la Orden de Corte</a>
                            <a :href="ordenCorteUrl" class="transparent-button">Ver ordenes generadas</a>
                        </div>
                    </div>

                    <div class="mt-4 space-y-4" v-if="!resultadoIncierto" data-testid="resumen-por-taller">
                        <div class="rounded border p-4 dark:border-gray-800" v-for="grupo in resumenPorTaller" :key="grupo.taller_id">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">@{{ grupo.taller_nombre }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Entrega: @{{ grupo.fecha_entrega || 'Sin fecha' }}</p>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Total: <strong class="text-gray-800 dark:text-gray-200">@{{ grupo.total }}</strong> prendas</p>

                            <div class="mt-2 divide-y text-sm dark:divide-gray-800">
                                <div class="flex items-center justify-between gap-2 py-1.5" v-for="(detalle, indiceDetalle) in grupo.detalles" :key="indiceDetalle">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-gray-800 dark:text-white">@{{ detalle.fila ? detalle.fila.nombre : ('Producto #' + detalle.product_id) }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400" v-if="detalle.fila">
                                            <span v-if="detalle.fila.color">@{{ detalle.fila.color }}</span>
                                            <span v-if="detalle.fila.color && detalle.fila.talla"> · </span>
                                            <span v-if="detalle.fila.talla">@{{ detalle.fila.talla_etiqueta || 'Talla' }}: @{{ detalle.fila.talla }}</span>
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="font-semibold text-gray-900 dark:text-white">@{{ detalle.cantidad }}</p>
                                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400">@{{ detalle.prioridad }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded border border-dashed p-4 dark:border-gray-700" v-if="sinDistribuir.length" data-testid="sin-distribuir">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sin distribuir</p>
                            <div class="mt-2 divide-y text-sm dark:divide-gray-800">
                                <div class="flex items-center justify-between gap-2 py-1.5" v-for="(item, indiceItem) in sinDistribuir" :key="indiceItem">
                                    <p class="text-gray-700 dark:text-gray-300">
                                        @{{ item.nombre }}
                                        <span v-if="item.color"> · @{{ item.color }}</span>
                                        <span v-if="item.talla"> · @{{ item.talla }}</span>
                                    </p>
                                    <p class="font-semibold text-gray-800 dark:text-white">@{{ item.pendiente }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t pt-4 dark:border-gray-800">
                        <button type="button" class="transparent-button" :disabled="enviando" data-testid="btn-volver-editar" @click="volverAEditar">
                            Volver y corregir
                        </button>
                        <button
                            type="button"
                            class="primary-button"
                            :disabled="enviando || !puedeRevisar || resultadoIncierto"
                            data-testid="btn-generar-ordenes"
                            @click="confirmarDistribucion"
                        >
                            @{{ enviando ? 'Generando ordenes...' : textoBotonGenerar }}
                        </button>
                    </div>
                </template>

                <template v-if="vista === 'exito'">
                    <div class="rounded border border-green-300 bg-green-50 p-6 text-center dark:border-green-900 dark:bg-green-950" data-testid="pantalla-exito">
                        <p class="text-lg font-semibold text-green-800 dark:text-green-200">Distribucion realizada correctamente.</p>
                        <p class="mt-1 text-sm text-green-700 dark:text-green-300" v-if="resultadoExito">
                            Se generaron @{{ resultadoExito.ordenes.length }} orden@{{ resultadoExito.ordenes.length === 1 ? '' : 'es' }} de confeccion.
                        </p>
                        <ul class="mt-3 inline-block text-left text-sm text-green-800 dark:text-green-200" v-if="resultadoExito">
                            <li v-for="orden in resultadoExito.ordenes" :key="orden.id">@{{ orden.codigo }}</li>
                        </ul>
                        <p class="mt-4 text-xs text-green-600 dark:text-green-400">Redirigiendo a la Orden de Corte…</p>
                    </div>
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-distribuir-confeccion', {
                template: '#v-distribuir-confeccion-template',

                props: {
                    ordenCorteId: { type: [Number, String], required: true },
                    ordenCorteCodigo: { type: String, required: true },
                    fechaRecepcion: { type: String, default: null },
                    estadoLabel: { type: String, default: null },
                    estadoClase: { type: String, default: null },
                    filas: { type: Array, default: () => [] },
                    talleres: { type: Array, default: () => [] },
                    tallerPreseleccionadoId: { default: null },
                    contextoUrl: { type: String, required: true },
                    distribuirUrl: { type: String, required: true },
                    ordenCorteUrl: { type: String, required: true },
                },

                data() {
                    return {
                        // Copia mutable del prop "talleres" -- Fase 03C3 necesita
                        // refrescarla tras un 422 (reconciliarConContexto), y un prop
                        // nunca puede reasignarse directamente (Vue lo hace de solo
                        // lectura; en un <script type="module"> -- modo estricto -- el
                        // intento lanza una excepcion real en vez de solo advertir).
                        talleresState: [...this.talleres],

                        filasState: this.filas.map((fila) => ({
                            ...fila,
                            modo: 'simple',
                            entregar: 0,
                            taller_id: this.tallerPreseleccionadoId ?? null,
                            prioridad: 'normal',
                            repartos: [],
                            tallerTocadoManualmente: false,
                            entregarEditado: false,
                            seleccionada: false,
                            _disponibleAnterior: null,
                            _tallerInvalido: false,
                        })),
                        tallerPredeterminado: this.tallerPreseleccionadoId ?? null,
                        fechaPredeterminada: new Date().toISOString().slice(0, 10),
                        observacionGeneral: null,
                        configuracionTalleres: {},

                        // Estado del drawer "Dividir entre talleres" -- unico, compartido
                        // por todas las filas; nunca escribe en fila.repartos hasta "Aplicar".
                        filaEnEdicionId: null,
                        repartosDraft: [],

                        avisoMasivo: null,

                        // Fase 03C3: patron de estado EDITAR/REVISAR/EXITO en el mismo
                        // componente, sin navegacion ni recarga -- filasState y el resto
                        // del estado de captura nunca se destruyen al cambiar de vista.
                        vista: 'editar',
                        enviando: false,
                        erroresValidacion: null,
                        mensajeErrorGeneral: null,
                        tipoErrorEnvio: null, // 'validacion' | 'incierto' | null
                        resultadoExito: null,
                    };
                },

                computed: {
                    totalApto() {
                        return this.filasState.reduce((total, fila) => total + Number(fila.apta_confeccion || 0), 0);
                    },

                    totalYaDistribuido() {
                        return this.filasState.reduce((total, fila) => total + Number(fila.ya_distribuido || 0), 0);
                    },

                    totalDistribuirAhora() {
                        return this.filasState.reduce((total, fila) => total + this.filaEntregarActual(fila), 0);
                    },

                    totalPendiente() {
                        return this.filasState.reduce((total, fila) => {
                            return total + Math.max(Number(fila.disponible_confeccion || 0) - this.filaEntregarActual(fila), 0);
                        }, 0);
                    },

                    filasSeleccionables() {
                        return this.filasState.filter((f) => f.modo === 'simple' && f.disponible_confeccion > 0);
                    },

                    filasSeleccionadas() {
                        return this.filasState.filter((f) => f.seleccionada && f.modo === 'simple');
                    },

                    todasSeleccionadas: {
                        get() {
                            return this.filasSeleccionables.length > 0 && this.filasSeleccionables.every((f) => f.seleccionada);
                        },
                        set(valor) {
                            this.filasSeleccionables.forEach((f) => { f.seleccionada = valor; });
                        },
                    },

                    talleresUtilizados() {
                        const ids = new Set();

                        this.filasState.forEach((fila) => {
                            if (fila.modo === 'simple') {
                                if (fila.taller_id && Number(fila.entregar || 0) > 0) ids.add(fila.taller_id);
                            } else {
                                fila.repartos.forEach((r) => {
                                    if (r.taller_id && Number(r.cantidad || 0) > 0) ids.add(r.taller_id);
                                });
                            }
                        });

                        return this.talleresState.filter((t) => ids.has(t.id));
                    },

                    filaEnEdicion() {
                        return this.filasState.find((f) => f.product_id === this.filaEnEdicionId) ?? null;
                    },

                    asignadoDraft() {
                        return this.repartosDraft.reduce((total, r) => total + Number(r.cantidad || 0), 0);
                    },

                    pendienteDraft() {
                        const disponible = this.filaEnEdicion ? this.filaEnEdicion.disponible_confeccion : 0;

                        return Math.max(disponible - this.asignadoDraft, 0);
                    },

                    erroresDraft() {
                        const errores = [];
                        const tallerIds = this.repartosDraft.map((r) => r.taller_id).filter((id) => id !== null);
                        const hayDuplicados = new Set(tallerIds).size !== tallerIds.length;

                        if (hayDuplicados) {
                            errores.push('El mismo taller no puede aparecer dos veces en esta variante.');
                        }

                        if (this.repartosDraft.some((r) => Number(r.cantidad || 0) > 0 && !r.taller_id)) {
                            errores.push('Cada cantidad necesita un taller.');
                        }

                        if (this.filaEnEdicion && this.asignadoDraft > this.filaEnEdicion.disponible_confeccion) {
                            errores.push('La suma asignada supera el disponible.');
                        }

                        return errores;
                    },

                    /**
                     * Fase 03C3 -- condiciones exactas para pasar a REVISAR. NO exige
                     * distribuir todo el disponible (una cantidad pendiente es valida).
                     * Reutiliza unicamente filaEntregarActual()/disponible_confeccion,
                     * ya existentes -- ninguna formula nueva.
                     */
                    puedeRevisar() {
                        if (this.totalDistribuirAhora <= 0) return false;

                        for (const fila of this.filasState) {
                            if (fila.modo === 'simple') {
                                const cantidad = Number(fila.entregar || 0);

                                if (cantidad > 0 && !fila.taller_id) return false;
                                if (cantidad > fila.disponible_confeccion) return false;
                            } else {
                                const tallerIds = fila.repartos.map((r) => r.taller_id).filter((id) => id !== null);

                                if (new Set(tallerIds).size !== tallerIds.length) return false;
                                if (fila.repartos.some((r) => Number(r.cantidad || 0) > 0 && !r.taller_id)) return false;
                                if (this.filaEntregarActual(fila) > fila.disponible_confeccion) return false;
                            }
                        }

                        return true;
                    },

                    motivoRevisarDeshabilitado() {
                        if (this.totalDistribuirAhora <= 0) return 'Ingresa una cantidad a entregar en al menos una variante.';

                        for (const fila of this.filasState) {
                            if (fila.modo === 'simple') {
                                const cantidad = Number(fila.entregar || 0);
                                if (cantidad > 0 && !fila.taller_id) return 'Falta asignar Taller en una o mas variantes.';
                                if (cantidad > fila.disponible_confeccion) return 'Una variante tiene una cantidad mayor a la disponible.';
                            } else {
                                if (this.filaEntregarActual(fila) > fila.disponible_confeccion) return 'Una division tiene una cantidad mayor a la disponible.';
                                if (fila.repartos.some((r) => Number(r.cantidad || 0) > 0 && !r.taller_id)) return 'Falta asignar Taller en una division.';
                            }
                        }

                        return '';
                    },

                    /**
                     * Resumen agrupado por Taller para REVISAR -- capa de presentacion
                     * exclusivamente sobre construirAsignaciones() (el mismo payload
                     * canonico de 03C2). Nunca se reconstruye el payload aparte.
                     */
                    resumenPorTaller() {
                        return this.construirAsignaciones().map((asignacion) => {
                            const taller = this.talleresState.find((t) => t.id === asignacion.taller_id);
                            const total = asignacion.detalles.reduce((suma, d) => suma + d.cantidad, 0);

                            return {
                                taller_id: asignacion.taller_id,
                                taller_nombre: taller ? taller.nombre : ('Taller #' + asignacion.taller_id),
                                fecha_entrega: asignacion.fecha_entrega,
                                total,
                                detalles: asignacion.detalles.map((d) => ({
                                    ...d,
                                    fila: this.filasState.find((f) => f.product_id === d.product_id) || null,
                                })),
                            };
                        });
                    },

                    sinDistribuir() {
                        return this.filasState
                            .map((fila) => ({ fila, pendiente: Math.max(Number(fila.disponible_confeccion || 0) - this.filaEntregarActual(fila), 0) }))
                            .filter((item) => item.pendiente > 0)
                            .map((item) => ({
                                nombre: item.fila.nombre,
                                color: item.fila.color,
                                talla: item.fila.talla,
                                pendiente: item.pendiente,
                            }));
                    },

                    talleresConCantidad() {
                        return this.resumenPorTaller.length;
                    },

                    textoBotonGenerar() {
                        const n = this.talleresConCantidad;

                        return `Generar ${n} orden${n === 1 ? '' : 'es'} de confeccion`;
                    },

                    listaErroresValidacion() {
                        if (!this.erroresValidacion) return [];

                        return Object.values(this.erroresValidacion).flat();
                    },

                    resultadoIncierto() {
                        return this.tipoErrorEnvio === 'incierto';
                    },
                },

                methods: {
                    /**
                     * Unica fuente para "cuanto se esta entregando" -- alimenta totales,
                     * tarjeta y el constructor de payload. Nunca se recalcula distinto
                     * en otro lugar.
                     */
                    filaEntregarActual(fila) {
                        return fila.modo === 'dividido'
                            ? fila.repartos.reduce((total, r) => total + Number(r.cantidad || 0), 0)
                            : Number(fila.entregar || 0);
                    },

                    prioridadMostrada(fila) {
                        if (fila.modo === 'simple') return fila.prioridad;

                        const valores = new Set(fila.repartos.map((r) => r.prioridad));

                        return valores.size === 1 ? [...valores][0] : 'mixta';
                    },

                    /**
                     * Autocompleta la cantidad al elegir taller: solo si el usuario no la
                     * ha escrito a mano (o esta en 0). Una cantidad manual nunca se pisa;
                     * en su lugar la fila ofrece "Usar disponible".
                     */
                    alCambiarTaller(fila) {
                        fila.tallerTocadoManualmente = true;
                        fila._tallerInvalido = false;

                        if (!fila.taller_id) {
                            if (!fila.entregarEditado) fila.entregar = 0;

                            return;
                        }

                        if (!fila.entregarEditado || Number(fila.entregar || 0) === 0) {
                            fila.entregar = fila.disponible_confeccion;
                            fila.entregarEditado = false;
                        }
                    },

                    alEditarEntregar(fila) {
                        fila.entregarEditado = true;
                        this.limitarEntregar(fila);
                    },

                    ajustarEntregar(fila, delta) {
                        fila.entregar = Number(fila.entregar || 0) + delta;
                        this.alEditarEntregar(fila);
                    },

                    usarDisponible(fila) {
                        fila.entregar = fila.disponible_confeccion;
                        fila.entregarEditado = false;
                    },

                    limitarEntregar(fila) {
                        let valor = Number(fila.entregar || 0);

                        if (Number.isNaN(valor) || valor < 0) valor = 0;
                        if (valor > fila.disponible_confeccion) valor = fila.disponible_confeccion;

                        fila.entregar = valor;
                    },

                    /**
                     * Solo rellena filas simples que el usuario nunca decidio
                     * manualmente -- las divididas jamas se tocan (filtro modo==='simple').
                     */
                    aplicarTallerPredeterminado() {
                        this.filasState
                            .filter((f) => f.modo === 'simple' && !f.tallerTocadoManualmente)
                            .forEach((f) => { f.taller_id = this.tallerPredeterminado; });
                    },

                    deseleccionarTodas() {
                        this.filasState.forEach((f) => { f.seleccionada = false; });
                        this.avisoMasivo = null;
                    },

                    asignarTallerMasivo(tallerId) {
                        if (!tallerId) return;

                        this.filasSeleccionadas.forEach((f) => {
                            f.taller_id = tallerId;
                            f.tallerTocadoManualmente = true;
                            f._tallerInvalido = false;
                        });
                    },

                    asignarPrioridadMasiva(prioridad) {
                        if (!prioridad) return;

                        this.filasSeleccionadas.forEach((f) => { f.prioridad = prioridad; });
                    },

                    asignarDisponibleMasivo() {
                        const sinTaller = this.filasSeleccionadas.filter((f) => !f.taller_id);

                        this.filasSeleccionadas.forEach((f) => {
                            if (f.taller_id) f.entregar = f.disponible_confeccion;
                        });

                        this.avisoMasivo = sinTaller.length
                            ? 'Selecciona un Taller predeterminado o asigna Taller antes de distribuir.'
                            : null;
                    },

                    limpiarSeleccionadas() {
                        const objetivo = [...this.filasSeleccionadas];
                        const conCantidad = objetivo.filter((f) => Number(f.entregar || 0) > 0);

                        const ejecutar = () => {
                            objetivo.forEach((f) => {
                                f.entregar = 0;
                                f.prioridad = 'normal';
                                f.taller_id = this.tallerPredeterminado ?? null;
                                f.tallerTocadoManualmente = false;
                                f.entregarEditado = false;
                            });
                            this.avisoMasivo = null;
                        };

                        if (conCantidad.length > 0) {
                            this.$emitter.emit('open-confirm-modal', {
                                title: 'Limpiar asignacion',
                                message: `Esto borrara las cantidades ya capturadas en ${conCantidad.length} variante(s) seleccionada(s). ¿Confirmas?`,
                                agree: ejecutar,
                            });
                        } else {
                            ejecutar();
                        }
                    },

                    abrirDrawer(fila) {
                        if (fila.disponible_confeccion <= 0) return;

                        this.filaEnEdicionId = fila.product_id;

                        if (fila.modo === 'dividido') {
                            this.repartosDraft = fila.repartos.map((r) => ({ ...r }));
                        } else if (fila.taller_id && fila.entregar > 0) {
                            this.repartosDraft = [{ taller_id: fila.taller_id, cantidad: fila.entregar, prioridad: fila.prioridad }];
                        } else {
                            this.repartosDraft = [{ taller_id: null, cantidad: 0, prioridad: fila.prioridad || 'normal' }];
                        }

                        this.$nextTick(() => this.$refs.drawerDividir.open());
                    },

                    agregarTallerDraft() {
                        this.repartosDraft.push({ taller_id: null, cantidad: 0, prioridad: 'normal' });
                    },

                    opcionesTallerDraft(index) {
                        const usadosEnOtrasFilas = this.repartosDraft
                            .filter((_, i) => i !== index)
                            .map((r) => r.taller_id);

                        return this.talleresState.filter((t) => !usadosEnOtrasFilas.includes(t.id));
                    },

                    cerrarDrawerSinAplicar() {
                        this.filaEnEdicionId = null;
                        this.repartosDraft = [];
                        this.$refs.drawerDividir?.close();
                    },

                    cancelarDrawer() {
                        // El borrador nunca se escribio en la fila -- no hay nada que revertir.
                        this.cerrarDrawerSinAplicar();
                    },

                    confirmarDrawer() {
                        if (this.erroresDraft.length > 0 || !this.filaEnEdicion) return;

                        const fila = this.filaEnEdicion;
                        const validos = this.repartosDraft
                            .filter((r) => r.taller_id && Number(r.cantidad) > 0)
                            .map((r) => ({ taller_id: r.taller_id, cantidad: Number(r.cantidad), prioridad: r.prioridad }));

                        if (validos.length === 0) {
                            // Dividir en nada equivale a no dividir -- mismo reset que "Quitar division".
                            this.quitarDivision(fila);
                        } else {
                            fila.repartos = validos;
                            fila.modo = 'dividido';
                            fila.tallerTocadoManualmente = true;
                            fila.seleccionada = false; // una fila dividida nunca queda seleccionada para masivas
                        }

                        this.cerrarDrawerSinAplicar();
                    },

                    solicitarQuitarDivision(fila) {
                        if (fila.repartos.length > 0) {
                            this.$emitter.emit('open-confirm-modal', {
                                title: 'Quitar division',
                                message: 'Se eliminaran las asignaciones a los talleres configurados para esta variante. ¿Confirmas?',
                                agree: () => this.quitarDivision(fila),
                            });
                        } else {
                            this.quitarDivision(fila);
                        }
                    },

                    quitarDivision(fila) {
                        fila.repartos = [];
                        fila.modo = 'simple';
                        fila.entregar = 0;
                        fila.prioridad = 'normal';
                        fila.taller_id = this.tallerPredeterminado ?? null;
                        fila.tallerTocadoManualmente = false;
                        fila.entregarEditado = false;

                        if (this.filaEnEdicionId === fila.product_id) {
                            this.cerrarDrawerSinAplicar();
                        }
                    },

                    fechaEfectiva(tallerId) {
                        return this.configuracionTalleres[tallerId] ?? this.fechaPredeterminada;
                    },

                    personalizarFecha(tallerId, fecha) {
                        this.configuracionTalleres[tallerId] = fecha;
                    },

                    quitarPersonalizacion(tallerId) {
                        delete this.configuracionTalleres[tallerId];
                    },

                    /**
                     * Construccion canonica del payload -- agrupada por taller_id via un
                     * Map, de modo que es estructuralmente imposible generar dos bloques
                     * del mismo taller (regla congelada en 03A). Es la UNICA fuente del
                     * payload real enviado al backend (Fase 03C3) y tambien de lo que se
                     * muestra en REVISAR (resumenPorTaller) -- nunca se reconstruye aparte.
                     */
                    construirAsignaciones() {
                        const detallesPorTaller = new Map();

                        const agregar = (tallerId, detalle) => {
                            if (!detallesPorTaller.has(tallerId)) detallesPorTaller.set(tallerId, []);
                            detallesPorTaller.get(tallerId).push(detalle);
                        };

                        this.filasState.forEach((fila) => {
                            if (fila.modo === 'simple') {
                                if (fila.taller_id && Number(fila.entregar || 0) > 0) {
                                    agregar(fila.taller_id, {
                                        product_id: fila.product_id,
                                        cantidad: Number(fila.entregar),
                                        prioridad: fila.prioridad,
                                    });
                                }
                            } else {
                                fila.repartos.forEach((r) => {
                                    if (r.taller_id && Number(r.cantidad) > 0) {
                                        agregar(r.taller_id, {
                                            product_id: fila.product_id,
                                            cantidad: Number(r.cantidad),
                                            prioridad: r.prioridad,
                                        });
                                    }
                                });
                            }
                        });

                        return [...detallesPorTaller.entries()].map(([tallerId, detalles]) => ({
                            taller_id: tallerId,
                            fecha_entrega: this.fechaEfectiva(tallerId),
                            observacion: this.observacionGeneral || null,
                            detalles,
                        }));
                    },

                    irARevisar() {
                        if (!this.puedeRevisar) return;

                        this.mensajeErrorGeneral = null;
                        this.erroresValidacion = null;
                        this.tipoErrorEnvio = null;
                        this.vista = 'revisar';
                    },

                    volverAEditar() {
                        if (this.enviando) return;

                        this.vista = 'editar';
                    },

                    async confirmarDistribucion() {
                        if (this.enviando || this.resultadoIncierto) return;

                        // Fase 03C3 §7/§18: revalidacion final justo antes de enviar --
                        // no se confia en que haber entrado a REVISAR siga siendo valido.
                        if (!this.puedeRevisar) return;

                        this.enviando = true;
                        this.mensajeErrorGeneral = null;
                        this.erroresValidacion = null;
                        this.tipoErrorEnvio = null;

                        const payload = {
                            orden_corte_id: this.ordenCorteId,
                            // Payload canonico de 03C2, reconstruido aqui mismo justo antes
                            // de enviar -- nunca un payload separado para REVISAR.
                            asignaciones: this.construirAsignaciones(),
                        };

                        try {
                            const { data } = await this.$axios.post(this.distribuirUrl, payload, {
                                headers: { Accept: 'application/json' },
                            });

                            this.manejarExito(data);
                        } catch (error) {
                            await this.manejarError(error);
                            this.enviando = false;
                        }
                    },

                    manejarExito(data) {
                        this.resultadoExito = data;
                        this.vista = 'exito';

                        // Espera breve para que el mensaje de exito sea legible antes de
                        // salir de la pantalla -- nunca se deja al usuario varado en un
                        // formulario ya persistido (decision documentada en 03C3 §17).
                        setTimeout(() => {
                            window.location.href = data.redirect_url;
                        }, 1500);
                    },

                    /**
                     * Fase 03C3 §10 -- NUNCA se identifica la causa de un error
                     * comparando/parseando el texto del mensaje del backend. Un 422
                     * siempre dispara la misma reaccion generica: mostrar los errores
                     * de validacion tal como los devuelve Laravel, reconciliar contra
                     * el contexto real (disponibilidad + talleres activos) y volver a
                     * EDITAR. Nunca se reenvia automaticamente.
                     */
                    async manejarError(error) {
                        const response = error.response;

                        // Sin respuesta HTTP (timeout, desconexion): el cliente no sabe
                        // si el servidor alcanzo a confirmar la operacion. Nunca se
                        // afirma "no se creo ninguna orden" en este caso, y no se
                        // reactiva un submit ciego que pueda duplicar ordenes -- se
                        // reemplaza la barra de accion por navegacion segura
                        // (resultadoIncierto en el template).
                        if (!response) {
                            this.tipoErrorEnvio = 'incierto';
                            this.mensajeErrorGeneral = 'No fue posible confirmar el resultado de la operacion. No vuelvas a enviar todavia. Revisa las ordenes de confeccion del corte.';
                            return;
                        }

                        if (response.status === 422) {
                            this.erroresValidacion = response.data?.errors || {};
                            this.tipoErrorEnvio = 'validacion';
                            this.mensajeErrorGeneral = 'No se pudo generar la distribucion. Revisa los cambios indicados. No se creo ninguna orden de confeccion.';

                            await this.refrescarContexto();

                            this.vista = 'editar';
                            return;
                        }

                        // 500 u otro error inesperado: mismo tratamiento que la falla de
                        // red -- el motor es transaccional, pero el cliente no tiene
                        // forma fiable de saber si la respuesta se perdio despues del commit.
                        this.tipoErrorEnvio = 'incierto';
                        this.mensajeErrorGeneral = 'No fue posible confirmar el resultado de la operacion. No vuelvas a enviar todavia. Revisa las ordenes de confeccion del corte.';
                    },

                    async refrescarContexto() {
                        try {
                            const { data } = await this.$axios.get(this.contextoUrl);
                            this.reconciliarConContexto(data);
                        } catch (e) {
                            // Si ni siquiera se pudo refrescar el contexto, el aviso de
                            // validacion general (ya seteado en manejarError) sigue
                            // visible -- no se agrega un segundo error encima.
                        }
                    },

                    /**
                     * Fase 03C3 §11-13 -- reconciliacion explicita contra el contexto
                     * real: NUNCA se autoajustan cantidades ni se reenvian solas. Se
                     * conserva todo lo capturado y se marca lo que quedo invalido
                     * (cantidad > disponible actual, o Taller que ya no esta activo)
                     * para que Bodega decida -- Revisar queda bloqueado por las mismas
                     * condiciones de puedeRevisar() hasta que se corrija.
                     */
                    reconciliarConContexto(data) {
                        const nuevasFilasPorId = new Map((data.filas || []).map((f) => [f.product_id, f]));
                        const tallerIdsActivos = new Set((data.talleres || []).map((t) => t.id));

                        this.talleresState = data.talleres || [];

                        this.filasState.forEach((fila) => {
                            const nueva = nuevasFilasPorId.get(fila.product_id);
                            if (!nueva) return;

                            const disponibleAnterior = fila.disponible_confeccion;

                            fila.disponible_confeccion = nueva.disponible_confeccion;
                            fila.apta_confeccion = nueva.apta_confeccion;
                            fila.ya_distribuido = nueva.ya_distribuido;
                            fila.stock_actual = nueva.stock_actual;

                            fila._disponibleAnterior = this.filaEntregarActual(fila) > fila.disponible_confeccion
                                ? disponibleAnterior
                                : null;

                            if (fila.modo === 'simple') {
                                if (fila.taller_id && !tallerIdsActivos.has(fila.taller_id)) {
                                    fila.taller_id = null;
                                    fila._tallerInvalido = true;
                                    fila.tallerTocadoManualmente = false;
                                }
                            } else {
                                fila.repartos.forEach((r) => {
                                    if (r.taller_id && !tallerIdsActivos.has(r.taller_id)) {
                                        r.taller_id = null;
                                        r._tallerInvalido = true;
                                    }
                                });
                            }
                        });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
