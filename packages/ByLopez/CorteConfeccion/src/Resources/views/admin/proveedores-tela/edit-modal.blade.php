{{--
    Edicion rapida de los datos basicos del proveedor, mismo patron Vue+axios
    que v-taller-edit. Los tipos de tela NO se tocan aqui (van como campos
    ocultos, sin cambios): esa seleccion sigue viviendo solo en la pantalla
    completa de edicion, para que este modal no se vuelva el formulario grande
    que la ficha de detalle no debe ser.
--}}
<script
    type="text/x-template"
    id="v-proveedor-edit-template"
>
    <div>
        <button
            type="button"
            :class="buttonClass"
            @click="openModal"
        >
            Editar proveedor
        </button>

        <x-admin::form
            v-slot="{ errors, handleSubmit }"
            as="div"
        >
            <form
                @submit="handleSubmit($event, edit)"
                ref="proveedorEditForm"
            >
                {{-- Tipos de tela sin cambios: se reenvian tal cual llegaron. --}}
                <template v-for="tipoTelaId in proveedor.tipo_tela_ids">
                    <input
                        type="hidden"
                        name="tipo_tela_ids[]"
                        :value="tipoTelaId"
                    >
                </template>

                <x-admin::modal
                    ref="proveedorEditModal"
                    panel-class="max-w-[768px]"
                >
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            Editar proveedor
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex gap-4 max-sm:flex-wrap">
                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Nombre / razon social
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="nombre"
                                    ::value="proveedor.nombre"
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
                                    ::value="proveedor.nit"
                                    rules="required"
                                    label="NIT"
                                    placeholder="NIT"
                                />

                                <x-admin::form.control-group.error control-name="nit" />
                            </x-admin::form.control-group>
                        </div>

                        <div class="flex gap-4 max-sm:flex-wrap">
                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label>
                                    Contacto
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="contacto"
                                    ::value="proveedor.contacto"
                                    label="Contacto"
                                    placeholder="Contacto"
                                />

                                <x-admin::form.control-group.error control-name="contacto" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Telefono
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="telefono"
                                    ::value="proveedor.telefono"
                                    rules="required"
                                    label="Telefono"
                                    placeholder="Telefono"
                                />

                                <x-admin::form.control-group.error control-name="telefono" />
                            </x-admin::form.control-group>
                        </div>

                        <div class="flex gap-4 max-sm:flex-wrap">
                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label class="required">
                                    Estado
                                </x-admin::form.control-group.label>

                                <v-field
                                    name="estado"
                                    rules="required"
                                    label="Estado"
                                    v-model="estado"
                                >
                                    <select
                                        name="estado"
                                        class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                        :class="[errors['estado'] ? 'border border-red-600 hover:border-red-600' : '']"
                                        v-model="estado"
                                    >
                                        <option value="activo">Activo</option>
                                        <option value="inactivo">Inactivo</option>
                                    </select>
                                </v-field>

                                <x-admin::form.control-group.error control-name="estado" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="mb-2.5 w-full">
                                <x-admin::form.control-group.label>
                                    Direccion
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="direccion"
                                    ::value="proveedor.direccion"
                                    label="Direccion"
                                    placeholder="Direccion"
                                />

                                <x-admin::form.control-group.error control-name="direccion" />
                            </x-admin::form.control-group>
                        </div>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                Observacion
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="textarea"
                                name="observacion"
                                ::value="proveedor.observacion"
                                label="Observacion"
                                placeholder="Observacion"
                            />

                            <x-admin::form.control-group.error control-name="observacion" />
                        </x-admin::form.control-group>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Los tipos de tela asociados no se editan aqui. Se cambian desde "Editar proveedor" en el listado.
                        </p>
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
    </div>
</script>

<script type="module">
    app.component('v-proveedor-edit', {
        template: '#v-proveedor-edit-template',

        props: {
            proveedor: {
                type: Object,
                required: true,
            },

            variant: {
                type: String,
                default: 'button',
            },
        },

        emits: ['update-proveedor'],

        data() {
            return {
                estado: this.proveedor.estado,
                isLoading: false,
            };
        },

        computed: {
            buttonClass() {
                if (this.variant === 'link') {
                    return 'flex cursor-pointer items-center justify-between gap-1.5 whitespace-nowrap px-2.5 text-blue-600 transition-all hover:underline';
                }

                return 'primary-button whitespace-nowrap';
            },
        },

        methods: {
            openModal() {
                this.estado = this.proveedor.estado;
                this.$refs.proveedorEditModal.open();
                this.centerModal();
            },

            centerModal() {
                this.$nextTick(() => {
                    let root = this.$refs.proveedorEditModal?.$el;
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

            edit(params, { resetForm, setErrors }) {
                this.isLoading = true;

                let formData = new FormData(this.$refs.proveedorEditForm);

                formData.append('_method', 'put');

                this.$axios.post('{{ route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id) }}', formData)
                    .then((response) => {
                        this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                        this.$emit('update-proveedor', response.data.data.proveedor);

                        resetForm();

                        this.isLoading = false;

                        this.$refs.proveedorEditModal.close();
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
