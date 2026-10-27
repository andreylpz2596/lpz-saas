<script
    type="text/x-template"
    id="v-taller-edit-template"
>
    <div>
        <button
            type="button"
            :class="buttonClass"
            @click="openModal"
        >
            Editar taller
        </button>

        <x-admin::form
            v-slot="{ meta, errors, handleSubmit }"
            as="div"
        >
            <form
                @submit="handleSubmit($event, edit)"
                ref="tallerEditForm"
            >
                <x-admin::modal ref="tallerEditModal">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            Editar taller
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
                                    ::value="taller.nombre"
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
                                    ::value="taller.nit"
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
                                    ::value="taller.contacto"
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
                                    ::value="taller.telefono"
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
                                        Seleccionar usuario TALLER CONFECCION
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

                            <x-admin::form.control-group.error control-name="usuario_id" />
                        </x-admin::form.control-group>

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
                                    ::value="taller.direccion"
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
                                ::value="taller.observacion"
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
                            title="Guardar taller"
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
    app.component('v-taller-edit', {
        template: '#v-taller-edit-template',

        props: {
            taller: {
                type: Object,
                required: true,
            },

            usuarios: {
                type: Array,
                required: true,
            },

            variant: {
                type: String,
                default: 'button',
            },
        },

        emits: ['update-taller'],

        data() {
            return {
                usuarioId: this.taller.usuario_id,
                estado: this.taller.estado,
                isLoading: false,
            };
        },

        computed: {
            buttonClass() {
                if (this.variant === 'link') {
                    return 'flex cursor-pointer items-center justify-between gap-1.5 px-2.5 text-blue-600 transition-all hover:underline';
                }

                return 'primary-button whitespace-nowrap';
            },
        },

        methods: {
            openModal() {
                this.usuarioId = this.taller.usuario_id;
                this.estado = this.taller.estado;
                this.$refs.tallerEditModal.open();
                this.centerModal();
            },

            centerModal() {
                this.$nextTick(() => {
                    let root = this.$refs.tallerEditModal?.$el;
                    let content = root?.querySelector('.fixed.inset-0.transform');
                    let wrapper = content?.firstElementChild;

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
                });
            },

            edit(params, { resetForm, setErrors }) {
                this.isLoading = true;

                let formData = new FormData(this.$refs.tallerEditForm);

                formData.append('_method', 'put');

                this.$axios.post('{{ route('admin.corte_confeccion.talleres.update', $taller->id) }}', formData)
                    .then((response) => {
                        this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                        this.$emit('update-taller', response.data.data.taller);

                        resetForm();

                        this.isLoading = false;

                        this.$refs.tallerEditModal.close();
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
