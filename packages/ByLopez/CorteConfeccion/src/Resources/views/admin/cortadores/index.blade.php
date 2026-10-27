@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Cortadores</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Cortadores</p>

            @if (bouncer()->hasPermission('corte_confeccion.cortadores.create'))
                <v-cortador-create
                    ref="cortadorCreate"
                    @cortador-created="$refs.cortadoresDatagrid.get()"
                ></v-cortador-create>

                <button
                    type="button"
                    class="primary-button"
                    @click="$refs.cortadorCreate.openModal()"
                >
                    Crear cortador
                </button>
            @endif
        </div>

        <x-admin::datagrid
            :src="route('admin.corte_confeccion.cortadores.index')"
            :isMultiRow="true"
            ref="cortadoresDatagrid"
        >
            <template #header="{ isLoading, available, applied, sort }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head
                        :isMultiRow="true"
                        template="2fr 1.6fr 1.6fr 0.8fr 0.5fr"
                        :groups="[3, 3, 2, 1, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div class="row datagrid-head datagrid-head-cards grid grid-cols-[2fr_1.6fr_1.6fr_0.8fr_0.5fr]">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [['nombre', 'contacto', 'telefono'], ['ordenes_activas', 'prendas_cortadas', 'pendientes_cortador'], ['pendientes_recepcion', 'ordenes_historicas'], ['estado'], []]"
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
                        template="2fr 1.6fr 1.6fr 0.8fr 0.5fr"
                        :groups="[3, 3, 2, 1, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div
                        class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[2fr_1.6fr_1.6fr_0.8fr_0.5fr] dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="record in available.records"
                    >
                        <div class="flex flex-col gap-1.5">
                            <p
                                class="text-sm font-semibold sm:text-base"
                                v-html="record.nombre"
                            ></p>

                            <p
                                class="text-xs text-gray-600 sm:text-sm dark:text-gray-300"
                                v-if="record.contacto"
                            >
                                @{{ record.contacto }}
                            </p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                @{{ record.telefono ?? '-' }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ record.ordenes_activas }} orden@{{ Number(record.ordenes_activas) === 1 ? '' : 'es' }} activa@{{ Number(record.ordenes_activas) === 1 ? '' : 's' }}
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.prendas_cortadas ?? 0).toLocaleString('es-CO') }}</span> prendas cortadas
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ Number(record.pendientes_cortador ?? 0).toLocaleString('es-CO') }} pendiente cortador
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ Number(record.pendientes_recepcion ?? 0).toLocaleString('es-CO') }} pendiente recepcion
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ record.ordenes_historicas }} orden@{{ Number(record.ordenes_historicas) === 1 ? '' : 'es' }} historica@{{ Number(record.ordenes_historicas) === 1 ? '' : 's' }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <p>
                                <span :class="record.estado === 'activo' ? 'label-active' : 'label-canceled'">
                                    @{{ record.estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </p>
                        </div>

                        <div
                            class="flex items-center gap-x-0.5 max-sm:justify-end"
                            v-if="record.actions?.length"
                        >
                            <span
                                class="cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                :class="action.icon"
                                :title="action.title"
                                v-text="! action.icon ? action.title : ''"
                                v-for="action in record.actions"
                                @click="performAction(action)"
                            ></span>
                        </div>
                    </div>
                </template>
            </template>
        </x-admin::datagrid>
    </div>

    @if (bouncer()->hasPermission('corte_confeccion.cortadores.create'))
        @pushOnce('scripts')
            <script
                type="text/x-template"
                id="v-cortador-create-template"
            >
                <x-admin::form
                    v-slot="{ errors, handleSubmit }"
                    as="div"
                    ::key="formKey"
                >
                    <form
                        @submit="handleSubmit($event, create)"
                        ref="cortadorCreateForm"
                    >
                        <input
                            type="hidden"
                            name="estado"
                            value="activo"
                        >

                        <x-admin::modal
                            ref="cortadorCreateModal"
                            panel-class="max-w-[768px]"
                        >
                            <x-slot:header>
                                <p class="text-lg font-bold text-gray-800 dark:text-white">
                                    Crear cortador
                                </p>
                            </x-slot>

                            <x-slot:content>
                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Nombre
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="nombre"
                                            v-model="form.nombre"
                                            rules="required"
                                            label="Nombre"
                                            placeholder="Nombre"
                                        />

                                        <x-admin::form.control-group.error control-name="nombre" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            NIT
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="nit"
                                            v-model="form.nit"
                                            label="NIT"
                                            placeholder="NIT"
                                        />

                                        <x-admin::form.control-group.error control-name="nit" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            Responsable
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="contacto"
                                            v-model="form.contacto"
                                            label="Responsable"
                                            placeholder="Responsable"
                                        />

                                        <x-admin::form.control-group.error control-name="contacto" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            Telefono
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="telefono"
                                            v-model="form.telefono"
                                            label="Telefono"
                                            placeholder="Telefono"
                                        />

                                        <x-admin::form.control-group.error control-name="telefono" />
                                    </x-admin::form.control-group>
                                </div>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        Usuario asociado
                                    </x-admin::form.control-group.label>

                                    <v-field
                                        name="usuario_id"
                                        rules="required"
                                        label="Usuario asociado"
                                        v-model="usuarioId"
                                    >
                                        <select
                                            name="usuario_id"
                                            class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            :class="[errors['usuario_id'] ? 'border border-red-600 hover:border-red-600' : '']"
                                            v-model="usuarioId"
                                        >
                                            <option value="">
                                                Seleccionar usuario CORTADOR
                                            </option>

                                            <option
                                                v-for="usuario in usuarios"
                                                :key="usuario.id"
                                                :value="usuario.id"
                                            >
                                                @{{ usuario.name }} - @{{ usuario.email }}
                                            </option>
                                        </select>
                                    </v-field>

                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Solo aparecen usuarios activos con rol CORTADOR.
                                    </p>

                                    <x-admin::form.control-group.error control-name="usuario_id" />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        Direccion
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="text"
                                        name="direccion"
                                        v-model="form.direccion"
                                        label="Direccion"
                                        placeholder="Direccion"
                                    />

                                    <x-admin::form.control-group.error control-name="direccion" />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        Observacion
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="textarea"
                                        name="observacion"
                                        v-model="form.observacion"
                                        label="Observacion"
                                        placeholder="Observacion"
                                    />

                                    <x-admin::form.control-group.error control-name="observacion" />
                                </x-admin::form.control-group>
                            </x-slot>

                            <x-slot:footer>
                                <x-admin::button
                                    button-type="submit"
                                    class="primary-button justify-center"
                                    title="Guardar cortador"
                                    ::loading="isLoading"
                                    ::disabled="isLoading"
                                />
                            </x-slot>
                        </x-admin::modal>
                    </form>
                </x-admin::form>
            </script>

            <script type="module">
                app.component('v-cortador-create', {
                    template: '#v-cortador-create-template',

                    emits: ['cortador-created'],

                    data() {
                        return {
                            formKey: 0,
                            isLoading: false,
                            usuarios: @json($usuariosCortador),
                            usuarioId: '',
                            form: this.blankForm(),
                        };
                    },

                    methods: {
                        blankForm() {
                            return {
                                nombre: '',
                                nit: '',
                                contacto: '',
                                telefono: '',
                                direccion: '',
                                observacion: '',
                            };
                        },

                        openModal() {
                            this.$refs.cortadorCreateModal.open();
                            this.centerModal();
                        },

                        centerModal() {
                            this.$nextTick(() => {
                                let root = this.$refs.cortadorCreateModal?.$el;
                                let content = root?.querySelector('.fixed.inset-0.transform');
                                let wrapper = content?.firstElementChild;
                                let panel = wrapper?.querySelector('.box-shadow');

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
                                    panel.style.width = 'min(768px, calc(100vw - 32px))';
                                    panel.style.maxWidth = '768px';
                                    panel.style.marginTop = 'auto';
                                    panel.style.marginBottom = 'auto';
                                }
                            });
                        },

                        create(params, { resetForm, setErrors }) {
                            this.isLoading = true;

                            let formData = new FormData(this.$refs.cortadorCreateForm);

                            this.$axios.post('{{ route('admin.corte_confeccion.cortadores.store') }}', formData)
                                .then((response) => {
                                    this.$refs.cortadorCreateModal.close();

                                    this.$emit('cortador-created', response.data.data.cortador);

                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                                    resetForm();

                                    this.form = this.blankForm();
                                    this.usuarioId = '';
                                    this.formKey++;
                                    this.isLoading = false;
                                })
                                .catch((error) => {
                                    this.isLoading = false;

                                    if (error.response.status == 422) {
                                        setErrors(error.response.data.errors);
                                    }
                                });
                        },
                    },
                });
            </script>
        @endPushOnce
    @endif
</x-admin::layouts>
