{{--
    Mismo patron que el listado de Clientes del Admin: identidad a la izquierda,
    atributos en el medio, resumen comercial a la derecha, accion aparte.

    Los tres agregados (compras, total comprado y rollos) llegan ya calculados
    desde ProveedorTelaDataGrid con una subconsulta agrupada: no se consulta nada
    por fila desde la vista.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Proveedores de tela</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Proveedores de tela</p>

            @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.create'))
                <v-proveedor-create
                    ref="proveedorCreate"
                    @proveedor-created="$refs.proveedoresDatagrid.get()"
                ></v-proveedor-create>

                <button
                    type="button"
                    class="primary-button"
                    @click="$refs.proveedorCreate.openModal()"
                >
                    Crear proveedor
                </button>
            @endif
        </div>

        <x-admin::datagrid
            :src="route('admin.corte_confeccion.proveedores_tela.index')"
            :isMultiRow="true"
            ref="proveedoresDatagrid"
        >
            <template #header="{ isLoading, available, applied, sort }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head
                        :isMultiRow="true"
                        template="1.8fr 1.3fr 1.2fr 1.8fr 0.5fr"
                        :groups="[2, 2, 2, 3, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.8fr_1.3fr_1.2fr_1.8fr_0.5fr]">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [['nombre', 'nit'], ['contacto', 'telefono'], ['tipo', 'estado'], ['total_comprado', 'total_compras', 'total_rollos_comprados'], []]"
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
                        template="1.8fr 1.3fr 1.2fr 1.8fr 0.5fr"
                        :groups="[2, 2, 2, 3, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div
                        class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.8fr_1.3fr_1.2fr_1.8fr_0.5fr] dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="record in available.records"
                    >
                        <!-- Proveedor / NIT -->
                        <div class="flex flex-col gap-1.5">
                            <p
                                class="text-sm font-semibold sm:text-base"
                                v-html="record.nombre"
                            ></p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                NIT: @{{ record.nit ?? '—' }}
                            </p>
                        </div>

                        <!-- Contacto / telefono -->
                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-800 dark:text-white">
                                @{{ record.contacto ?? '—' }}
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ record.telefono ?? '—' }}
                            </p>
                        </div>

                        <!-- Tipo de tela / estado -->
                        <div class="flex flex-col gap-1.5">
                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                @{{ record.tipo ?? '—' }}
                            </p>

                            <p>
                                <span :class="record.estado === 'activo' ? 'label-active' : 'label-canceled'">
                                    @{{ record.estado }}
                                </span>
                            </p>
                        </div>

                        <!-- Resumen comercial -->
                        <div class="flex flex-col gap-1.5">
                            <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">
                                @{{ '$' + Number(record.total_comprado ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 }) }}
                            </p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                @{{ record.total_compras ?? 0 }} compra@{{ Number(record.total_compras) === 1 ? '' : 's' }}
                            </p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                @{{ record.total_rollos_comprados ?? 0 }} rollo@{{ Number(record.total_rollos_comprados) === 1 ? '' : 's' }}
                            </p>
                        </div>

                        <!-- Accion -->
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

    @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.create'))
        @pushOnce('scripts')
            <script
                type="text/x-template"
                id="v-proveedor-create-template"
            >
                <x-admin::form
                    v-slot="{ errors, handleSubmit }"
                    as="div"
                    ::key="formKey"
                >
                    <form
                        @submit="handleSubmit($event, create)"
                        ref="proveedorCreateForm"
                    >
                        <input
                            type="hidden"
                            name="estado"
                            value="activo"
                        >

                        <template v-for="tipoTelaId in selectedTipoTelaIds">
                            <input
                                type="hidden"
                                name="tipo_tela_ids[]"
                                :value="tipoTelaId"
                            >
                        </template>

                        <x-admin::modal
                            ref="proveedorCreateModal"
                            panel-class="max-w-[768px]"
                        >
                            <x-slot:header>
                                <p class="text-lg font-bold text-gray-800 dark:text-white">
                                    Crear proveedor
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
                                        <x-admin::form.control-group.label class="required">
                                            NIT
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="nit"
                                            v-model="form.nit"
                                            rules="required"
                                            label="NIT"
                                            placeholder="NIT"
                                        />

                                        <x-admin::form.control-group.error control-name="nit" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Telefono
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="telefono"
                                            v-model="form.telefono"
                                            rules="required"
                                            label="Telefono"
                                            placeholder="Telefono"
                                        />

                                        <x-admin::form.control-group.error control-name="telefono" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            Contacto
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="contacto"
                                            v-model="form.contacto"
                                            label="Contacto"
                                            placeholder="Contacto"
                                        />

                                        <x-admin::form.control-group.error control-name="contacto" />
                                    </x-admin::form.control-group>
                                </div>

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
                                    <x-admin::form.control-group.label class="required">
                                        Tipos de tela
                                    </x-admin::form.control-group.label>

                                    <div
                                        class="grid grid-cols-2 gap-3 rounded border p-4 dark:border-gray-800 max-sm:grid-cols-1"
                                        :class="[errors['tipo_tela_ids'] || errors['tipo_tela_ids.0'] ? 'border border-red-600 hover:border-red-600' : '']"
                                    >
                                        <label
                                            class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                                            v-for="tipoTela in tiposTela"
                                            :key="tipoTela.id"
                                        >
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                                :value="tipoTela.id"
                                                v-model="selectedTipoTelaIds"
                                            >

                                            <span>@{{ tipoTela.nombre }}</span>
                                        </label>
                                    </div>

                                    <x-admin::form.control-group.error control-name="tipo_tela_ids" />
                                    <x-admin::form.control-group.error control-name="tipo_tela_ids.0" />
                                </x-admin::form.control-group>

                            </x-slot>

                            <x-slot:footer>
                                <x-admin::button
                                    button-type="submit"
                                    class="primary-button justify-center"
                                    title="Guardar proveedor"
                                    ::loading="isLoading"
                                    ::disabled="isLoading"
                                />
                            </x-slot>
                        </x-admin::modal>
                    </form>
                </x-admin::form>
            </script>

            <script type="module">
                app.component('v-proveedor-create', {
                    template: '#v-proveedor-create-template',

                    emits: ['proveedor-created'],

                    data() {
                        return {
                            formKey: 0,
                            isLoading: false,
                            tiposTela: @json($tiposTela->map(fn ($tipoTela) => ['id' => (int) $tipoTela->id, 'nombre' => $tipoTela->admin_name])->values()),
                            selectedTipoTelaIds: [],
                            form: this.blankForm(),
                        };
                    },

                    methods: {
                        blankForm() {
                            return {
                                nombre: '',
                                nit: '',
                                telefono: '',
                                contacto: '',
                                direccion: '',
                            };
                        },

                        openModal() {
                            this.$refs.proveedorCreateModal.open();
                            this.centerModal();
                        },

                        centerModal() {
                            this.$nextTick(() => {
                                let root = this.$refs.proveedorCreateModal?.$el;
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

                            let formData = new FormData(this.$refs.proveedorCreateForm);

                            this.$axios.post('{{ route('admin.corte_confeccion.proveedores_tela.store') }}', formData)
                                .then((response) => {
                                    this.$refs.proveedorCreateModal.close();

                                    this.$emit('proveedor-created', response.data.data.proveedor);

                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                                    resetForm();

                                    this.form = this.blankForm();
                                    this.selectedTipoTelaIds = [];
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
