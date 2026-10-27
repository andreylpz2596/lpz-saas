{{--
    Tabla «Referencias y precios» de la ficha de un taller o de un cortador (Doc. 05). Se incluye UNA vez por pagina:

        <v-referencias-tarifa
            titulo="Referencias y precios de confeccion"
            subtitulo="..."
            :puede-gestionar="true|false"
            grid-url="..." historial-url="..." store-url="..." anular-url="...__ID__..."
            :opciones="opcionesTarifa"
            :aviso="tarifaGeneralAnterior"
        ></v-referencias-tarifa>

    Misma estructura que «Referencias que ofrece» del proveedor de tela (DataGrid de tarjetas + modal para agregar/cambiar),
    adaptada al servicio: cada fila es una combinacion tipo de tela + referencia del producto con su valor por unidad y su
    vigencia. Cambiar la tarifa crea una vigencia nueva; nunca se edita ni se borra el historico, y las ordenes ya creadas
    conservan la suya. La autorizacion real vive en el backend (TarifaPrestadorGate); `puede-gestionar` solo oculta controles.
--}}
@include('corteconfeccion::admin.partials.moneda')

@pushOnce('scripts', 'bylopez-referencias-tarifa')
    <script
        type="text/x-template"
        id="v-referencias-tarifa-template"
    >
        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                    @{{ titulo }}
                </p>

                <button
                    type="button"
                    class="primary-button whitespace-nowrap"
                    v-if="puedeGestionar"
                    @click="abrirAgregar"
                >
                    Agregar referencia
                </button>
            </div>

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@{{ subtitulo }}</p>

            <div
                class="mt-3 rounded border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                v-if="aviso"
            >
                <p class="font-semibold">Tarifa general anterior sin aplicar</p>

                <p class="mt-1">
                    Existe una tarifa general de @{{ dinero(aviso.valor_unitario) }} (desde @{{ aviso.vigente_desde }}). Ya no se usa:
                    cada referencia debe configurarse de forma individual, y mientras no tenga tarifa se muestra «Sin tarifa».
                </p>
            </div>

            <div class="mt-4">
                <x-admin::datagrid
                    ::src="gridUrl"
                    :isMultiRow="true"
                    ref="datagrid"
                >
                    <template #header="{ isLoading, available, applied, sort }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.head
                                :isMultiRow="true"
                                template="1.8fr 1fr 1.6fr 0.5fr"
                                :groups="[2, 1, 1, 0]"
                                :massAction="false"
                            />
                        </template>

                        <template v-else>
                            <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.8fr_1fr_1.6fr_0.5fr]">
                                <div
                                    class="flex select-none items-center gap-2.5"
                                    v-for="(columnGroup, index) in [['tipo_tela', 'referencia'], ['valor_unitario'], ['vigente_desde'], []]"
                                >
                                    <p class="text-gray-600 dark:text-gray-300">
                                        <span class="[&>*]:after:content-['_/_']">
                                            <template v-for="column in columnGroup">
                                                <span
                                                    class="after:content-['/'] last:after:content-['']"
                                                    :class="{
                                                        'font-medium text-gray-800 dark:text-white': applied.sort.column == column,
                                                        'cursor-pointer hover:text-gray-800 dark:hover:text-white': available.columns.find(columnTemp => columnTemp.index === column)?.sortable,
                                                    }"
                                                    @click="
                                                        available.columns.find(columnTemp => columnTemp.index === column)?.sortable ? sort(available.columns.find(columnTemp => columnTemp.index === column)) : {}
                                                    "
                                                >
                                                    @{{ available.columns.find(columnTemp => columnTemp.index === column)?.label }}
                                                </span>
                                            </template>
                                        </span>

                                        <i
                                            class="align-text-bottom text-base text-gray-800 dark:text-white ltr:ml-1.5 rtl:mr-1.5"
                                            :class="[applied.sort.order === 'asc' ? 'icon-down-stat': 'icon-up-stat']"
                                            v-if="columnGroup.includes(applied.sort.column)"
                                        ></i>
                                    </p>
                                </div>
                            </div>
                        </template>
                    </template>

                    <template #body="{ isLoading, available, performAction }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.body
                                :isMultiRow="true"
                                template="1.8fr 1fr 1.6fr 0.5fr"
                                :groups="[2, 1, 1, 0]"
                                :massAction="false"
                            />
                        </template>

                        <template v-else>
                            <div
                                class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.8fr_1fr_1.6fr_0.5fr] dark:border-gray-800 dark:hover:bg-gray-950"
                                v-for="record in available.records"
                            >
                                <!-- Tela / referencia -->
                                <div class="flex flex-col gap-1.5">
                                    <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">@{{ record.tipo_tela }}</p>

                                    <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">@{{ record.referencia }}</p>
                                </div>

                                <!-- Valor por unidad -->
                                <div class="flex flex-col gap-1.5">
                                    <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">@{{ dinero(record.valor_unitario) }}</p>
                                </div>

                                <!-- Vigencia -->
                                <div class="flex flex-col gap-1.5" v-html="record.vigente_desde"></div>

                                <!-- Acciones -->
                                <div
                                    class="flex items-center gap-x-0.5 max-sm:justify-end"
                                    v-if="record.actions?.length"
                                >
                                    <span
                                        class="cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                        :class="action.icon"
                                        :title="action.title"
                                        v-for="action in record.actions"
                                        @click="accion(action.title, record)"
                                    ></span>
                                </div>
                            </div>
                        </template>
                    </template>
                </x-admin::datagrid>
            </div>

            {{-- Agregar referencia / Cambiar tarifa (nueva vigencia) --}}
            <x-admin::modal
                ref="modalTarifa"
                panel-class="max-w-[640px]"
            >
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">
                        @{{ cambiando ? 'Cambiar tarifa' : 'Agregar referencia' }}
                    </p>
                </x-slot>

                <x-slot:content>
                    <form
                        id="form-tarifa-referencia"
                        @submit.prevent="guardar"
                    >
                        <div class="flex gap-4 max-sm:flex-wrap">
                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Tipo de tela
                                </x-admin::form.control-group.label>

                                <select
                                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    :class="{ 'border-red-600': errores.tipo_tela_id }"
                                    v-model="form.tipo_tela_id"
                                    :disabled="cambiando"
                                    @change="form.attribute_family_id = ''"
                                >
                                    <option value="">Seleccionar tipo de tela</option>

                                    <option
                                        v-for="tela in opciones"
                                        :key="tela.id"
                                        :value="tela.id"
                                    >
                                        @{{ tela.nombre }}
                                    </option>
                                </select>

                                <p class="mt-1 text-xs text-red-600" v-if="errores.tipo_tela_id">@{{ errores.tipo_tela_id[0] }}</p>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Referencia del producto
                                </x-admin::form.control-group.label>

                                <select
                                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    :class="{ 'border-red-600': errores.attribute_family_id }"
                                    v-model="form.attribute_family_id"
                                    :disabled="cambiando || ! form.tipo_tela_id"
                                >
                                    <option value="">Seleccionar referencia</option>

                                    <option
                                        v-for="referencia in referenciasDeTela"
                                        :key="referencia.id"
                                        :value="referencia.id"
                                    >
                                        @{{ referencia.nombre }}
                                    </option>
                                </select>

                                <p class="mt-1 text-xs text-red-600" v-if="errores.attribute_family_id">@{{ errores.attribute_family_id[0] }}</p>
                            </x-admin::form.control-group>
                        </div>

                        <div class="flex gap-4 max-sm:flex-wrap">
                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Tarifa por unidad
                                </x-admin::form.control-group.label>

                                <v-moneda
                                    numero
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    v-model="form.valor_unitario"
                                ></v-moneda>

                                <p class="mt-1 text-xs text-red-600" v-if="errores.valor_unitario">@{{ errores.valor_unitario[0] }}</p>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Inicio de vigencia
                                </x-admin::form.control-group.label>

                                <input
                                    type="date"
                                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    :class="{ 'border-red-600': errores.vigente_desde }"
                                    v-model="form.vigente_desde"
                                >

                                <p class="mt-1 text-xs text-red-600" v-if="errores.vigente_desde">@{{ errores.vigente_desde[0] }}</p>
                            </x-admin::form.control-group>
                        </div>

                        <x-admin::form.control-group class="mb-2.5 w-full">
                            <x-admin::form.control-group.label>
                                Observacion
                            </x-admin::form.control-group.label>

                            <input
                                type="text"
                                maxlength="500"
                                placeholder="Opcional"
                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                v-model="form.observacion"
                            >

                            <p class="mt-1 text-xs text-red-600" v-if="errores.observacion">@{{ errores.observacion[0] }}</p>
                        </x-admin::form.control-group>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Una tarifa nueva rige desde su fecha de inicio y debe ser posterior a la vigencia actual de esa referencia.
                            Las ordenes ya creadas conservan la tarifa con la que nacieron.
                        </p>
                    </form>
                </x-slot>

                <x-slot:footer>
                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                            :disabled="guardando"
                            @click="$refs.modalTarifa.close()"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            form="form-tarifa-referencia"
                            class="primary-button justify-center"
                            :disabled="guardando"
                        >
                            @{{ cambiando ? 'Guardar nueva tarifa' : 'Agregar referencia' }}
                        </button>
                    </div>
                </x-slot>
            </x-admin::modal>

            {{-- Historial de vigencias de una combinacion --}}
            <x-admin::modal
                ref="modalHistorial"
                panel-class="max-w-[640px]"
            >
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Historial de tarifas</p>
                </x-slot>

                <x-slot:content>
                    <p class="text-sm font-semibold text-gray-800 dark:text-white" v-if="seleccion">
                        @{{ seleccion.tipo_tela }} · @{{ seleccion.referencia }}
                    </p>

                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400" v-if="cargandoHistorial">Cargando...</p>

                    <div class="mt-3 grid gap-2">
                        <div
                            class="rounded-md border p-2.5 text-sm dark:border-gray-800"
                            :class="{ 'opacity-60': vigencia.estado === 'anulada' }"
                            v-for="vigencia in historial"
                            :key="vigencia.id"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <p
                                    class="font-semibold text-gray-800 dark:text-white"
                                    :class="{ 'line-through': vigencia.estado === 'anulada' }"
                                >
                                    @{{ dinero(vigencia.valor_unitario) }}
                                </p>

                                <span :class="etiqueta(vigencia.estado).clase">@{{ etiqueta(vigencia.estado).texto }}</span>
                            </div>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Desde @{{ vigencia.vigente_desde }}<template v-if="vigencia.creado_por"> · registrada por @{{ vigencia.creado_por }}</template>
                            </p>

                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" v-if="vigencia.observacion">@{{ vigencia.observacion }}</p>

                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" v-if="vigencia.estado === 'anulada'">
                                Anulada @{{ vigencia.anulada_en }}<template v-if="vigencia.anulada_por"> por @{{ vigencia.anulada_por }}</template>: @{{ vigencia.motivo_anulacion }}
                            </p>
                        </div>
                    </div>
                </x-slot>

                <x-slot:footer>
                    <button
                        type="button"
                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                        @click="$refs.modalHistorial.close()"
                    >
                        Cerrar
                    </button>
                </x-slot>
            </x-admin::modal>

            {{-- Anular una vigencia --}}
            <x-admin::modal
                ref="modalAnular"
                panel-class="max-w-[520px]"
            >
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Anular vigencia</p>
                </x-slot>

                <x-slot:content>
                    <form
                        id="form-anular-tarifa"
                        @submit.prevent="anular"
                    >
                        <p class="text-sm text-gray-600 dark:text-gray-300" v-if="seleccion">
                            @{{ seleccion.tipo_tela }} · @{{ seleccion.referencia }}: @{{ dinero(seleccion.valor_unitario) }}.
                            La vigencia queda en el historial; las ordenes ya creadas conservan su tarifa.
                        </p>

                        <x-admin::form.control-group class="mt-3 w-full">
                            <x-admin::form.control-group.label class="required">
                                Motivo
                            </x-admin::form.control-group.label>

                            <input
                                type="text"
                                maxlength="500"
                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                :class="{ 'border-red-600': errores.motivo }"
                                v-model="motivo"
                            >

                            <p class="mt-1 text-xs text-red-600" v-if="errores.motivo">@{{ errores.motivo[0] }}</p>
                        </x-admin::form.control-group>
                    </form>
                </x-slot>

                <x-slot:footer>
                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                            :disabled="guardando"
                            @click="$refs.modalAnular.close()"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            form="form-anular-tarifa"
                            class="primary-button justify-center"
                            :disabled="guardando"
                        >
                            Anular vigencia
                        </button>
                    </div>
                </x-slot>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        app.component('v-referencias-tarifa', {
            template: '#v-referencias-tarifa-template',

            props: {
                titulo: { type: String, required: true },
                subtitulo: { type: String, default: '' },
                puedeGestionar: { type: Boolean, default: false },
                gridUrl: { type: String, required: true },
                historialUrl: { type: String, required: true },
                storeUrl: { type: String, required: true },
                anularUrl: { type: String, required: true },
                opciones: { type: Array, default: () => [] },
                aviso: { type: Object, default: null },
            },

            data() {
                return {
                    form: this.formVacio(),
                    cambiando: false,
                    seleccion: null,
                    historial: [],
                    cargandoHistorial: false,
                    motivo: '',
                    errores: {},
                    guardando: false,
                };
            },

            computed: {
                referenciasDeTela() {
                    return this.opciones.find((tela) => String(tela.id) === String(this.form.tipo_tela_id))?.referencias ?? [];
                },
            },

            methods: {
                formVacio() {
                    const fecha = new Date();
                    const pad = (n) => String(n).padStart(2, '0');

                    return {
                        tipo_tela_id: '',
                        attribute_family_id: '',
                        valor_unitario: null,
                        vigente_desde: `${fecha.getFullYear()}-${pad(fecha.getMonth() + 1)}-${pad(fecha.getDate())}`,
                        observacion: '',
                    };
                },

                dinero(valor) {
                    return window.ByLopezMoneda ? window.ByLopezMoneda.formatear(Number(valor)) : `$ ${valor}`;
                },

                etiqueta(estado) {
                    return {
                        vigente: { texto: 'Vigente', clase: 'label-active text-xs' },
                        programada: { texto: 'Programada', clase: 'label-pending text-xs' },
                        anterior: { texto: 'Anterior', clase: 'text-xs text-gray-500' },
                        anulada: { texto: 'Anulada', clase: 'label-canceled text-xs' },
                    }[estado];
                },

                centrar(ref, ancho) {
                    this.$nextTick(() => {
                        const content = this.$refs[ref]?.$el?.querySelector('.fixed.inset-0.transform');
                        const wrapper = content?.firstElementChild;
                        const panel = wrapper?.querySelector('.box-shadow');

                        if (content) {
                            content.style.height = '100dvh';
                            content.style.maxHeight = '100dvh';
                            content.style.overflowY = 'auto';
                        }

                        if (wrapper) {
                            wrapper.style.minHeight = '100dvh';
                            wrapper.style.alignItems = 'center';
                            wrapper.style.justifyContent = 'center';
                            wrapper.style.padding = '16px';
                        }

                        if (panel) {
                            panel.style.width = `min(${ancho}px, calc(100vw - 32px))`;
                            panel.style.maxWidth = `${ancho}px`;
                            panel.style.marginTop = 'auto';
                            panel.style.marginBottom = 'auto';
                        }
                    });
                },

                abrir(ref, ancho) {
                    this.$refs[ref].open();
                    this.centrar(ref, ancho);
                },

                abrirAgregar() {
                    this.cambiando = false;
                    this.errores = {};
                    this.form = this.formVacio();
                    this.$nextTick(() => this.abrir('modalTarifa', 640));
                },

                accion(titulo, fila) {
                    this.seleccion = fila;
                    this.errores = {};

                    if (titulo === 'Historial') {
                        this.verHistorial(fila);
                    } else if (titulo === 'Cambiar tarifa') {
                        this.cambiando = true;
                        this.form = {
                            ...this.formVacio(),
                            tipo_tela_id: fila.tipo_tela_id,
                            attribute_family_id: fila.attribute_family_id,
                            valor_unitario: Number(fila.valor_unitario),
                        };
                        this.$nextTick(() => this.abrir('modalTarifa', 640));
                    } else if (titulo === 'Anular vigencia') {
                        this.motivo = '';
                        this.$nextTick(() => this.abrir('modalAnular', 520));
                    }
                },

                verHistorial(fila) {
                    this.historial = [];
                    this.cargandoHistorial = true;
                    this.abrir('modalHistorial', 640);

                    this.$axios.get(this.historialUrl, { params: { tipo_tela_id: fila.tipo_tela_id, attribute_family_id: fila.attribute_family_id } })
                        .then((response) => {
                            this.historial = response.data.data.vigencias;
                        })
                        .finally(() => {
                            this.cargandoHistorial = false;
                        });
                },

                guardar() {
                    this.enviar(this.storeUrl, this.form, 'modalTarifa');
                },

                anular() {
                    this.enviar(this.anularUrl.replace('__ID__', this.seleccion.id), { motivo: this.motivo }, 'modalAnular');
                },

                enviar(url, datos, modal) {
                    this.guardando = true;
                    this.errores = {};

                    this.$axios.post(url, datos)
                        .then((response) => {
                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                            this.$refs[modal].close();
                            this.$refs.datagrid.get();
                        })
                        .catch((error) => {
                            if (error.response?.status === 422) {
                                this.errores = error.response.data.errors || {};

                                return;
                            }

                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || 'No se pudo completar la accion.' });
                        })
                        .finally(() => {
                            this.guardando = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
