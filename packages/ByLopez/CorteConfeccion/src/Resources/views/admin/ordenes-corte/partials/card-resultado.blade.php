@php
    $puedeReportarAhora = \ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, 'reportado_por_cortador');

    $solicitudParaCopiar = $detallesSolicitud->map(fn ($d) => [
        'id' => $d->id,
        'producto_sku' => $d->producto_sku,
        'producto_nombre' => $d->producto_nombre,
        'genero_linea' => $d->genero_linea,
        'color' => $d->color,
        'talla' => $d->talla,
        'cantidad_proyectada' => (int) $d->cantidad_proyectada,
    ])->values();
@endphp

<div class="rounded border dark:border-gray-800">
    <div class="border-b p-4 dark:border-gray-800">
        <p class="text-base font-semibold text-gray-800 dark:text-white">Resultado real del corte</p>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Registra aqui lo que realmente salio del corte.</p>
    </div>

    <div class="p-4">
        @if ($yaReportado)
            {{-- Ya reportado: solo lectura --}}
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Reportado por <strong class="text-gray-700 dark:text-gray-200">{{ $orden->reportadoPor?->name ?? 'No registrado' }}</strong></span>
                <span>{{ $orden->reportado_en?->format('Y-m-d H:i') ?: '—' }}</span>
            </div>

            @php
                $gruposResultado = \ByLopez\CorteConfeccion\Support\AgrupadorDetallesCorte::agrupar($detallesSolicitud);
            @endphp

            @forelse ($gruposResultado as $index => $grupo)
                <details class="mb-2 rounded border dark:border-gray-800" @if ($index === 0) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50 dark:hover:bg-gray-950 max-md:flex-wrap">
                        <span class="min-w-0 flex-1 break-words font-semibold text-gray-800 dark:text-white">{{ $grupo['genero_linea'] ? $grupo['genero_linea'].' · ' : '' }}{{ $grupo['producto_nombre'] }}</span>
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $grupo['cantidad_tallas'] }} talla{{ $grupo['cantidad_tallas'] === 1 ? '' : 's' }} · {{ number_format($grupo['total_real'], 0, ',', '.') }} unidades</span>
                    </summary>
                    <div class="border-t p-3 dark:border-gray-800">
                        @foreach ($grupo['filas'] as $fila)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b py-1.5 text-sm last:border-b-0 dark:border-gray-800">
                                <span class="min-w-0 flex-1 break-words text-gray-600 dark:text-gray-300">{{ $fila->color ? $fila->color.' · ' : '' }}Talla {{ $fila->talla ?: '—' }} <span class="font-mono text-xs text-gray-400">{{ $fila->producto_sku }}</span>@if ($orden->tieneProyeccion()) <span class="text-xs text-gray-400">(solicitado {{ $fila->cantidad_proyectada }})</span>@endif</span>
                                <span class="shrink-0 font-semibold text-gray-800 dark:text-white">{{ number_format((int) $fila->cantidad_real_cortada, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin resultado reportado.</p>
            @endforelse

            @if ($detallesAdicionales->isNotEmpty())
                <p class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">Adicionales no solicitados</p>
                @foreach ($detallesAdicionales as $adicional)
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950">
                        <div class="min-w-0 flex-1 break-words">
                            <span class="font-semibold text-gray-800 dark:text-white">{{ $adicional->genero_linea ? $adicional->genero_linea.' · ' : '' }}{{ $adicional->producto_nombre }}</span>
                            <span class="text-gray-600 dark:text-gray-300"> — {{ $adicional->color ? $adicional->color.' · ' : '' }}Talla {{ $adicional->talla ?: 'unica' }} <span class="font-mono text-xs text-gray-400">{{ $adicional->producto_sku }}</span></span>
                            <span class="ml-2 rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-800 dark:text-amber-100">Adicional</span>
                        </div>
                        <span class="shrink-0 font-semibold text-gray-800 dark:text-white">{{ number_format((int) $adicional->cantidad_real_cortada, 0, ',', '.') }}</span>
                    </div>
                @endforeach
            @endif
        @elseif (! $puedeReportarAhora)
            <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                @if ($orden->estado === \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::PendienteEntrega->value)
                    <p class="font-semibold">Faltan 2 pasos antes de que el cortador pueda reportar el resultado:</p>
                    <ol class="mt-1.5 list-decimal space-y-1 pl-4">
                        <li>Haz clic en <strong>"Entregar rollos"</strong> (arriba, a la derecha del titulo) y confirma la entrega fisica de los rollos. La orden pasara a "Entregado a cortador".</li>
                        <li>Ahi aparecera un nuevo boton <strong>"Marcar en corte"</strong>: haz clic en el. Recien entonces este card se activa.</li>
                    </ol>
                @elseif ($orden->estado === \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::EntregadoACortador->value)
                    Ya se entrego el peso (estado "Entregado a cortador"). Falta el ultimo paso: haz clic en <strong>"Marcar en corte"</strong> (arriba, a la derecha del titulo) para activar este card.
                @else
                    Esta orden ya no admite reportar un resultado (estado actual: {{ $orden->estado }}).
                @endif
            </div>
        @elseif (! $canEditCantidadCortada)
            <p class="text-sm text-gray-500 dark:text-gray-400">Esperando que el cortador registre el resultado real.</p>
        @else
            {{-- En corte y con permiso: formulario interactivo --}}
            <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.reportar', $orden->id) }}">
                @csrf

                <v-resultado-corte-form
                    :solicitud='@json($solicitudParaCopiar)'
                    :old-resultados='@json(old('resultados', []))'
                    :old-adicionales='@json(old('adicionales', []))'
                    opciones-url="{{ route('admin.corte_confeccion.ordenes_corte.adicionales.opciones', $orden->id) }}"
                    previsualizar-url="{{ route('admin.corte_confeccion.ordenes_corte.adicionales.previsualizar', $orden->id) }}"
                ></v-resultado-corte-form>
            </form>
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script type="text/x-template" id="v-resultado-corte-form-template">
            <div>
                <template v-if="!copiado && !adicionales.length">
                    <button type="button" class="primary-button" @click="copiarSolicitud">
                        Copiar solicitud de corte
                    </button>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Trae automaticamente los productos, tallas y generos solicitados para que solo tengas que escribir la cantidad real cortada.
                    </p>

                    <div class="mt-3">
                        <button type="button" class="secondary-button" @click="agregarAdicional">
                            + Agregar adicional
                        </button>
                    </div>
                </template>

                <template v-else>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm text-gray-600 dark:text-gray-300" v-if="filas.length">Cantidad real cortada por talla</p>
                        <button type="button" class="secondary-button" @click="agregarAdicional">
                            + Agregar adicional
                        </button>
                    </div>

                    {{-- Herramienta de captura: no se guarda ni se envia (sin name); solo llena los inputs existentes. --}}
                    <div v-if="filas.length" class="mb-3 rounded border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900 dark:bg-blue-950">
                        <p class="text-sm font-semibold text-gray-800 dark:text-white">Cantidad base por variante</p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                class="w-40 rounded-md border bg-white px-3 py-2 text-sm max-sm:w-full dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                placeholder="Ej: 120"
                                v-model="cantidadBase"
                                @keydown.enter.prevent="aplicarBase"
                            />
                            <button type="button" class="primary-button max-sm:w-full" :disabled="! baseValida" @click="aplicarBase">Aplicar a todas</button>
                        </div>

                        <p v-if="cantidadBase !== '' && ! baseValida" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">Ingresa un numero entero igual o mayor a 0.</p>

                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                            <template v-if="baseValida">@{{ filas.length }} variante@{{ filas.length === 1 ? '' : 's' }} recibira@{{ filas.length === 1 ? '' : 'n' }} inicialmente @{{ Number(cantidadBase) }} unidades. Despues puedes ajustar excepciones individualmente.</template>
                            <template v-else>Escribe una cantidad y pulsa "Aplicar a todas" para llenar las @{{ filas.length }} variante@{{ filas.length === 1 ? '' : 's' }}. Despues puedes ajustar excepciones individualmente.</template>
                        </p>

                        <div v-if="confirmandoSobrescribir" class="mt-2 flex flex-wrap items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                            <span class="min-w-0 flex-1">Ya hay cantidades digitadas. ¿Reemplazarlas con @{{ Number(cantidadBase) }} en todas las variantes?</span>
                            <button type="button" class="primary-button" @click="aplicarBase(true)">Reemplazar</button>
                            <button type="button" class="secondary-button" @click="confirmandoSobrescribir = false">Cancelar</button>
                        </div>
                    </div>

                    <x-admin::form.control-group.error control-name="resultados" />
                        v-for="(grupo, gIndex) in gruposFilas"
                        :key="'grupo-' + gIndex"
                    >
                        <details :open="gIndex === 0">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50 dark:hover:bg-gray-950 max-md:flex-wrap">
                                <span class="min-w-0 flex-1 break-words font-semibold text-gray-800 dark:text-white">@{{ grupo.titulo }}</span>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">@{{ grupo.filas.length }} talla@{{ grupo.filas.length === 1 ? '' : 's' }}</span>
                            </summary>

                            <div class="border-t p-3 dark:border-gray-800">
                                <div
                                    class="mb-2 flex flex-wrap items-center gap-3 last:mb-0"
                                    v-for="fila in grupo.filas"
                                    :key="fila._index"
                                >
                                    <input type="hidden" :name="'resultados[' + fila._index + '][detalle_id]'" :value="filas[fila._index].detalle_id" />

                                    <span class="w-56 shrink-0 text-sm text-gray-600 dark:text-gray-300">@{{ filas[fila._index].color ? filas[fila._index].color + ' · ' : '' }}Talla @{{ filas[fila._index].talla || '—' }} <span class="font-mono text-xs text-gray-400">@{{ filas[fila._index].producto_sku }}</span></span>

                                    <input
                                        type="number"
                                        min="0"
                                        class="w-32 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                        :name="'resultados[' + fila._index + '][cantidad_real_cortada]'"
                                        :value="filas[fila._index].cantidad_real_cortada"
                                        @input="filas[fila._index].cantidad_real_cortada = $event.target.value"
                                        placeholder="Cantidad"
                                    />

                                    <span
                                        v-if="diferenciaBase(filas[fila._index]) !== 0"
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="diferenciaBase(filas[fila._index]) > 0 ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300'"
                                    >@{{ diferenciaBase(filas[fila._index]) > 0 ? '+' : '' }}@{{ diferenciaBase(filas[fila._index]) }} sobre base</span>

                                    <span v-if="filas[fila._index].cantidad_proyectada > 0" class="text-xs text-gray-400">solicitado: @{{ filas[fila._index].cantidad_proyectada }}</span>
                                </div>
                            </div>
                        </details>
                    </div>

                    <template v-if="adicionales.length">
                        <p class="mb-1 mt-4 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                            Prendas adicionales (no solicitadas originalmente)
                        </p>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                            Elige linea, color y talla: el sistema identifica el producto exacto.
                        </p>

                        <x-admin::form.control-group.error control-name="adicionales" />

                        <div
                            class="mb-2 rounded border border-amber-200 p-3 dark:border-amber-900"
                            v-for="(adicional, index) in adicionales"
                            :key="adicional.key"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-800 dark:text-amber-100">Adicional</span>
                                <button type="button" class="transparent-button" @click="quitarAdicional(index)">Quitar</button>
                            </div>

                            <input type="hidden" :name="'adicionales[' + index + '][attribute_family_id]'" :value="adicional.familia" />
                            <input type="hidden" :name="'adicionales[' + index + '][color_option_id]'" :value="adicional.color" />
                            <input type="hidden" :name="'adicionales[' + index + '][eje_option_id]'" :value="adicional.eje" />

                            <p v-if="cargandoMatriz" class="text-xs text-gray-500 dark:text-gray-400">Cargando opciones...</p>
                            <p v-else-if="errorMatriz" class="text-xs text-red-600">@{{ errorMatriz }}</p>

                            <div v-else class="grid grid-cols-4 gap-2 max-md:grid-cols-1">
                                <select
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    v-model="adicional.familia"
                                    @change="cambioLinea(adicional)"
                                >
                                    <option value="">Linea</option>
                                    <option v-for="linea in matriz.lineas" :key="linea.attribute_family_id" :value="linea.attribute_family_id">@{{ linea.nombre }}</option>
                                </select>

                                <select
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    v-model="adicional.color"
                                    :disabled="! adicional.familia"
                                    @change="cambioColor(adicional)"
                                >
                                    <option value="">Color</option>
                                    <option
                                        v-for="celda in coloresDeLinea(adicional)"
                                        :key="celda.color_option_id"
                                        :value="celda.color_option_id"
                                        :disabled="!! celda.error"
                                    >@{{ celda.color }}@{{ celda.error ? ' (no disponible)' : '' }}</option>
                                </select>

                                <select
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    v-model="adicional.eje"
                                    :disabled="! adicional.color"
                                    @change="previsualizar(adicional)"
                                >
                                    <option value="">@{{ etiquetaEje(adicional) }}</option>
                                    <option v-for="opcion in tallasDe(adicional)" :key="opcion.option_id" :value="opcion.option_id">@{{ opcion.label }}</option>
                                </select>

                                <input
                                    type="number"
                                    min="1"
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    placeholder="Cantidad cortada"
                                    :name="'adicionales[' + index + '][cantidad_real_cortada]'"
                                    v-model="adicional.cantidad_real_cortada"
                                />
                            </div>

                            <p v-if="mensajeCelda(adicional)" class="mt-2 text-xs text-red-600">@{{ mensajeCelda(adicional) }}</p>

                            <div v-if="adicional.preview" class="mt-2 rounded bg-amber-50 p-2 text-xs text-gray-700 dark:bg-amber-950 dark:text-gray-200">
                                <span class="font-semibold">@{{ adicional.preview.linea }} · @{{ adicional.preview.color }} · Talla @{{ adicional.preview.talla }}</span>
                                <span class="ml-1 font-mono text-gray-500">@{{ adicional.preview.sku }}</span>
                                <span v-if="adicional.cantidad_real_cortada" class="ml-1">— @{{ adicional.cantidad_real_cortada }} unidades</span>
                                <p v-for="aviso in adicional.preview.advertencias" :key="aviso" class="mt-1 text-amber-700 dark:text-amber-300">@{{ aviso }}</p>
                            </div>

                            <p v-for="mensaje in adicional.errores" :key="mensaje" class="mt-2 text-xs text-red-600">@{{ mensaje }}</p>
                        </div>
                    </template>

                    <button type="submit" class="primary-button mt-4">Reportar corte terminado</button>
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-resultado-corte-form', {
                template: '#v-resultado-corte-form-template',

                props: {
                    solicitud: { type: Array, default: () => [] },
                    oldResultados: { type: Array, default: () => [] },
                    oldAdicionales: { type: Array, default: () => [] },
                    opcionesUrl: { type: String, required: true },
                    previsualizarUrl: { type: String, required: true },
                },

                data() {
                    return {
                        copiado: this.oldResultados.length > 0,
                        filas: this.oldResultados.length ? this.prepararFilasDesdeOld() : [],
                        adicionales: [],
                        matriz: { lineas: [], colores: [] },
                        cargandoMatriz: false,
                        errorMatriz: '',
                        matrizCargada: false,
                        cantidadBase: '',
                        baseAplicada: null,
                        confirmandoSobrescribir: false,
                    };
                },

                watch: {
                    cantidadBase() {
                        this.confirmandoSobrescribir = false;
                    },
                },

                mounted() {
                    if (this.oldAdicionales.length) {
                        this.adicionales = this.oldAdicionales.map((a) => this.nuevoAdicional(a));
                        this.cargarMatriz();
                    }
                },

                computed: {
                    baseValida() {
                        let valor = String(this.cantidadBase).trim();

                        return valor !== '' && /^\d+$/.test(valor);
                    },

                    gruposFilas() {
                        let grupos = {};

                        this.filas.forEach((fila, index) => {
                            let clave = (fila.genero_linea || '') + '|' + fila.producto_nombre;

                            if (!grupos[clave]) {
                                grupos[clave] = {
                                    titulo: (fila.genero_linea ? fila.genero_linea + ' · ' : '') + fila.producto_nombre,
                                    filas: [],
                                };
                            }

                            grupos[clave].filas.push({ ...fila, _index: index });
                        });

                        return Object.values(grupos);
                    },
                },

                methods: {
                    prepararFilasDesdeOld() {
                        return this.oldResultados.map((r) => {
                            let origen = this.solicitud.find((s) => String(s.id) === String(r.detalle_id));

                            return {
                                detalle_id: r.detalle_id,
                                producto_nombre: origen ? origen.producto_nombre : '',
                                producto_sku: origen ? origen.producto_sku : '',
                                color: origen ? origen.color : '',
                                genero_linea: origen ? origen.genero_linea : '',
                                talla: origen ? origen.talla : '',
                                cantidad_proyectada: origen ? origen.cantidad_proyectada : 0,
                                cantidad_real_cortada: r.cantidad_real_cortada || '',
                            };
                        });
                    },

                    copiarSolicitud() {
                        this.filas = this.solicitud.map((detalle) => ({
                            detalle_id: detalle.id,
                            producto_nombre: detalle.producto_nombre,
                            producto_sku: detalle.producto_sku,
                            color: detalle.color,
                            genero_linea: detalle.genero_linea,
                            talla: detalle.talla,
                            cantidad_proyectada: detalle.cantidad_proyectada,
                            cantidad_real_cortada: '',
                        }));

                        this.copiado = true;
                    },

                    // Herramienta de captura: llena los inputs existentes; la base nunca se envia.
                    aplicarBase(confirmado = false) {
                        if (! this.baseValida) {
                            return;
                        }

                        let hayCantidades = this.filas.some((fila) => String(fila.cantidad_real_cortada ?? '').trim() !== '');

                        if (hayCantidades && confirmado !== true) {
                            this.confirmandoSobrescribir = true;

                            return;
                        }

                        let base = Number(this.cantidadBase);

                        this.filas.forEach((fila) => { fila.cantidad_real_cortada = String(base); });
                        this.baseAplicada = base;
                        this.confirmandoSobrescribir = false;
                    },

                    // Diferencia informativa contra la ultima base aplicada (0 = sin badge).
                    diferenciaBase(fila) {
                        let valor = String(fila.cantidad_real_cortada ?? '').trim();

                        if (this.baseAplicada === null || valor === '' || isNaN(Number(valor))) {
                            return 0;
                        }

                        return Number(valor) - this.baseAplicada;
                    },

                    nuevoAdicional(origen = {}) {
                        return {
                            key: 'ad-' + Date.now() + '-' + Math.random(),
                            familia: origen.attribute_family_id || '',
                            color: origen.color_option_id || '',
                            eje: origen.eje_option_id || '',
                            cantidad_real_cortada: origen.cantidad_real_cortada || '',
                            preview: null,
                            errores: [],
                        };
                    },

                    agregarAdicional() {
                        this.adicionales.push(this.nuevoAdicional());
                        this.cargarMatriz();
                    },

                    quitarAdicional(index) {
                        this.adicionales.splice(index, 1);
                    },

                    cargarMatriz() {
                        if (this.matrizCargada || this.cargandoMatriz) {
                            return;
                        }

                        this.cargandoMatriz = true;
                        this.errorMatriz = '';

                        this.$axios.get(this.opcionesUrl)
                            .then((response) => {
                                this.matriz = response.data.data;
                                this.matrizCargada = true;
                                this.adicionales.forEach((a) => a.eje && this.previsualizar(a));
                            })
                            .catch((error) => {
                                this.errorMatriz = (error.response && error.response.data && error.response.data.message) || 'No se pudieron cargar las opciones.';
                            })
                            .finally(() => {
                                this.cargandoMatriz = false;
                            });
                    },

                    lineaDe(adicional) {
                        return this.matriz.lineas.find((l) => String(l.attribute_family_id) === String(adicional.familia));
                    },

                    celdaDe(adicional) {
                        let linea = this.lineaDe(adicional);

                        return linea ? linea.colores.find((c) => String(c.color_option_id) === String(adicional.color)) : null;
                    },

                    coloresDeLinea(adicional) {
                        let linea = this.lineaDe(adicional);

                        return linea ? linea.colores : [];
                    },

                    tallasDe(adicional) {
                        let celda = this.celdaDe(adicional);

                        return celda ? celda.opciones : [];
                    },

                    etiquetaEje(adicional) {
                        let linea = this.lineaDe(adicional);

                        return (linea && linea.eje_etiqueta) || 'Talla';
                    },

                    mensajeCelda(adicional) {
                        let celda = this.celdaDe(adicional);

                        return celda && celda.error ? celda.mensaje : '';
                    },

                    cambioLinea(adicional) {
                        adicional.color = '';
                        adicional.eje = '';
                        adicional.preview = null;
                        adicional.errores = [];
                    },

                    cambioColor(adicional) {
                        adicional.eje = '';
                        adicional.preview = null;
                        adicional.errores = [];
                    },

                    previsualizar(adicional) {
                        adicional.preview = null;
                        adicional.errores = [];

                        if (!adicional.familia || !adicional.color || !adicional.eje) {
                            return;
                        }

                        this.$axios.post(this.previsualizarUrl, {
                            adicionales: [{
                                attribute_family_id: adicional.familia,
                                color_option_id: adicional.color,
                                eje_option_id: adicional.eje,
                            }],
                        })
                            .then((response) => {
                                let data = response.data.data;

                                adicional.preview = data.filas.length ? data.filas[0] : null;
                                adicional.errores = data.errores;
                            })
                            .catch((error) => {
                                adicional.errores = [(error.response && error.response.data && error.response.data.message) || 'No se pudo resolver el producto.'];
                            });
                    },
                },
            });
        </script>
    @endpush
@endonce
