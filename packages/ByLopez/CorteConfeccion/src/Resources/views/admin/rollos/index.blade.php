@php
    $formatKg = fn ($valor) => number_format((float) $valor, 3, ',', '.').' kg';
    $estadoActivo = request()->query('estado');

    $cards = [
        [
            'clave' => null,
            'titulo' => 'Total inventario',
            'datos' => $resumen['total'],
            'color' => 'border-gray-300 dark:border-gray-700',
        ], [
            'clave' => \ByLopez\CorteConfeccion\Enums\EstadoRollo::Disponible->value,
            'titulo' => 'Disponibles',
            'datos' => $resumen['disponible'],
            'color' => 'border-emerald-300 dark:border-emerald-800',
        ], [
            'clave' => \ByLopez\CorteConfeccion\Enums\EstadoRollo::ReservadoEnCorte->value,
            'titulo' => 'Reservados en corte',
            'datos' => $resumen['reservado_en_corte'],
            'color' => 'border-blue-300 dark:border-blue-800',
        ], [
            'clave' => \ByLopez\CorteConfeccion\Enums\EstadoRollo::ParcialmenteUsado->value,
            'titulo' => 'En uso / en corte',
            'datos' => $resumen['parcialmente_usado'],
            'color' => 'border-amber-300 dark:border-amber-800',
        ], [
            'clave' => \ByLopez\CorteConfeccion\DataGrids\RolloDataGrid::FILTRO_AGOTADO_CERRADO,
            'titulo' => 'Agotados / cerrados',
            'datos' => $resumen['agotado_cerrado'],
            'color' => 'border-gray-400 dark:border-gray-600',
        ],
    ];
@endphp

@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Rollos</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Rollos</p>
        </div>

        @if (bouncer()->hasPermission('corte_confeccion.rollos.edit'))
            <v-rollo-edit-modal
                ref="rolloEditModal"
                @rollo-updated="$refs.rollosDatagrid.get()"
            ></v-rollo-edit-modal>
        @endif

        {{--
            Cards de resumen en fila horizontal (5 columnas en escritorio), compactas en
            dos lineas, para que la tabla empiece mucho mas arriba. Siguen siendo
            enlaces que filtran el listado por estado y conservan el resaltado activo.
        --}}
        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($cards as $card)
                @php
                    $url = $card['clave']
                        ? route('admin.corte_confeccion.rollos.index', ['estado' => $card['clave']])
                        : route('admin.corte_confeccion.rollos.index');
                    $seleccionada = $estadoActivo === $card['clave'] || (! $estadoActivo && ! $card['clave']);
                @endphp

                <a
                    href="{{ $url }}"
                    class="box-shadow rounded border-l-4 bg-white px-3 py-2 transition hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-950 {{ $card['color'] }} {{ $seleccionada ? 'ring-2 ring-blue-500' : '' }}"
                >
                    <p class="truncate text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['titulo'] }}</p>

                    <p class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="text-xl font-bold leading-none text-gray-800 dark:text-white">{{ $card['datos']['cantidad'] }}</span>
                        <span class="truncate text-xs text-gray-500 dark:text-gray-400">rollo{{ $card['datos']['cantidad'] === 1 ? '' : 's' }} · {{ $formatKg($card['datos']['peso']) }}</span>
                    </p>
                </a>
            @endforeach
        </div>

        <div class="mt-4">
            <x-admin::datagrid
                :src="route('admin.corte_confeccion.rollos.index')"
                :isMultiRow="true"
                ref="rollosDatagrid"
            >
                <template #header="{ isLoading, available, applied, sort }">
                    <template v-if="isLoading">
                        <x-admin::shimmer.datagrid.table.head
                            :isMultiRow="true"
                            template="1.3fr 1.1fr 1.7fr 1.7fr 0.4fr"
                            :groups="[2, 2, 3, 4, 0]"
                            :massAction="false"
                        />
                    </template>

                    <template v-else>
                        <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.3fr_1.1fr_1.7fr_1.7fr_0.4fr]">
                            <div
                                class="flex select-none items-center gap-2.5"
                                v-for="(columnGroup, index) in [['codigo', 'referencia'], ['tipo_tela', 'color'], ['peso_inicial', 'peso_disponible', 'porcentaje_disponible'], ['estado', 'orden_corte_codigo', 'fecha_compra', 'proveedor'], []]"
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
                            template="1.3fr 1.1fr 1.7fr 1.7fr 0.4fr"
                            :groups="[2, 2, 3, 4, 0]"
                            :massAction="false"
                        />
                    </template>

                    <template v-else>
                        <div
                            class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.3fr_1.1fr_1.7fr_1.7fr_0.4fr] dark:border-gray-800 dark:hover:bg-gray-950"
                            v-for="record in available.records"
                        >
                            <!-- Columna 1: Rollo (codigo / referencia) -->
                            <div class="flex flex-col gap-1.5">
                                <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">
                                    @{{ record.codigo }}
                                </p>

                                <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                    @{{ record.referencia ?? '—' }}
                                </p>
                            </div>

                            <!-- Columna 2: Producto (tela / color) -->
                            <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                                <p class="text-gray-800 dark:text-white">
                                    @{{ record.tipo_tela ?? '—' }}
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    @{{ record.color ?? '—' }}
                                </p>
                            </div>

                            <!-- Columna 3: Inventario (peso inicial / disponible / %) -->
                            <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                                <p class="text-gray-600 dark:text-gray-300">
                                    Inicial: <span class="text-gray-800 dark:text-white">@{{ Number(record.peso_inicial ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) }} kg</span>
                                </p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    Disponible: <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.peso_disponible ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) }} kg</span>
                                </p>

                                <p v-html="record.porcentaje_disponible"></p>
                            </div>

                            <!-- Columna 4: Estado / relaciones (estado / orden de corte / compra / proveedor) -->
                            <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                                <p v-html="record.estado"></p>

                                <p v-html="record.orden_corte_codigo"></p>

                                <p class="text-gray-600 dark:text-gray-300">
                                    Compra: @{{ record.fecha_compra ?? '—' }}
                                </p>

                                <p class="truncate text-gray-600 dark:text-gray-300">
                                    @{{ record.proveedor ?? '—' }}
                                </p>
                            </div>

                            <!-- Columna 5: Accion -->
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
                                    @click="action.title === 'Editar' ? $refs.rolloEditModal.openModal(record, action.url) : performAction(action)"
                                ></span>
                            </div>
                        </div>
                    </template>
                </template>
            </x-admin::datagrid>
        </div>
    </div>

    @if (bouncer()->hasPermission('corte_confeccion.rollos.edit'))
        @pushOnce('scripts')
            <script
                type="text/x-template"
                id="v-rollo-edit-modal-template"
            >
                <x-admin::form
                    v-slot="{ errors, handleSubmit }"
                    as="div"
                    ::key="formKey"
                >
                    <form
                        @submit="handleSubmit($event, save)"
                        ref="rolloEditForm"
                    >
                        <x-admin::modal
                            ref="rolloEditModal"
                            panel-class="max-w-[768px]"
                        >
                            <x-slot:header>
                                <p class="text-lg font-bold text-gray-800 dark:text-white">
                                    Editar rollo
                                </p>
                            </x-slot>

                            <x-slot:content>
                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Codigo
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="codigo"
                                            v-model="form.codigo"
                                            rules="required"
                                            label="Codigo"
                                            placeholder="Codigo"
                                        />

                                        <x-admin::form.control-group.error control-name="codigo" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Proveedor
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="proveedor"
                                            v-model="form.proveedor"
                                            rules="required"
                                            label="Proveedor"
                                            placeholder="Proveedor"
                                        />

                                        <x-admin::form.control-group.error control-name="proveedor" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Tipo de tela
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="tipo_tela"
                                            v-model="form.tipo_tela"
                                            rules="required"
                                            label="Tipo de tela"
                                            placeholder="Tipo de tela"
                                        />

                                        <x-admin::form.control-group.error control-name="tipo_tela" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Color
                                        </x-admin::form.control-group.label>

                                        <select
                                            name="color_option_id"
                                            v-model="form.color_option_id"
                                            class="custom-select w-full rounded-md border px-3 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            :disabled="colorBloqueado"
                                            :required="! colorBloqueado"
                                        >
                                            <option value="">Seleccionar color del catálogo</option>

                                            @foreach ($coloresTela as $color)
                                                <option value="{{ $color->id }}">
                                                    {{ $color->admin_name }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <p
                                            class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                                            v-if="colorBloqueado"
                                        >
                                            @{{ motivoColorBloqueado }}
                                        </p>

                                        <div
                                            class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                                            v-if="! colorBloqueado && ! form.color_option_id"
                                        >
                                            <p>Color sin vincular al catalogo</p>
                                            <p v-if="form.color">Color historico: @{{ form.color }}</p>
                                        </div>

                                        <x-admin::form.control-group.error control-name="color" />
                                        <x-admin::form.control-group.error control-name="color_option_id" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Peso inicial
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="number"
                                            name="peso_inicial"
                                            v-model="form.peso_inicial"
                                            rules="required"
                                            step="0.001"
                                            label="Peso inicial"
                                            placeholder="0.000"
                                        />

                                        <x-admin::form.control-group.error control-name="peso_inicial" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Peso disponible
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="number"
                                            name="peso_disponible"
                                            v-model="form.peso_disponible"
                                            rules="required"
                                            step="0.001"
                                            label="Peso disponible"
                                            placeholder="0.000"
                                        />

                                        <x-admin::form.control-group.error control-name="peso_disponible" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Costo total
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="number"
                                            name="costo_total"
                                            v-model="form.costo_total"
                                            rules="required"
                                            step="0.01"
                                            label="Costo total"
                                            placeholder="0.00"
                                        />

                                        <x-admin::form.control-group.error control-name="costo_total" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            Fecha compra
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="date"
                                            name="fecha_compra"
                                            v-model="form.fecha_compra"
                                            label="Fecha compra"
                                        />

                                        <x-admin::form.control-group.error control-name="fecha_compra" />
                                    </x-admin::form.control-group>
                                </div>

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
                                <div class="flex items-center gap-2.5">
                                    <button
                                        type="button"
                                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                                        :disabled="isLoading"
                                        @click="closeModal"
                                    >
                                        Cancelar
                                    </button>

                                    <x-admin::button
                                        button-type="submit"
                                        class="primary-button justify-center"
                                        title="Guardar rollo"
                                        ::loading="isLoading"
                                        ::disabled="isLoading"
                                    />
                                </div>
                            </x-slot>
                        </x-admin::modal>
                    </form>
                </x-admin::form>
            </script>

            <script type="module">
                app.component('v-rollo-edit-modal', {
                    template: '#v-rollo-edit-modal-template',

                    emits: ['rollo-updated'],

                    data() {
                        return {
                            formKey: 0,
                            isLoading: false,
                            updateUrl: null,
                            form: this.blankForm(),
                        };
                    },

                    computed: {
                        colorBloqueado() {
                            return Boolean(this.form.proveedor_tela_referencia_id)
                                || this.form.estado_raw === '{{ \ByLopez\CorteConfeccion\Enums\EstadoRollo::ReservadoEnCorte->value }}'
                                || this.form.tiene_orden_corte_rollos
                                || this.form.tiene_orden_corte_legacy;
                        },

                        motivoColorBloqueado() {
                            if (this.form.proveedor_tela_referencia_id) {
                                return 'El color de este rollo proviene de su referencia de proveedor. Para corregirlo, edita la referencia.';
                            }

                            return 'No se puede modificar el color de un rollo reservado o que ya participo en una orden de corte.';
                        },
                    },

                    methods: {
                        blankForm() {
                            return {
                                codigo: '',
                                proveedor: '',
                                tipo_tela: '',
                                color: '',
                                color_option_id: '',
                                proveedor_tela_referencia_id: null,
                                estado_raw: '',
                                tiene_orden_corte_rollos: false,
                                tiene_orden_corte_legacy: false,
                                peso_inicial: '',
                                peso_disponible: '',
                                costo_total: '',
                                fecha_compra: '',
                                observacion: '',
                            };
                        },

                        openModal(record, updateUrl) {
                            this.updateUrl = updateUrl;
                            this.form = {
                                codigo: record.codigo ?? '',
                                proveedor: record.proveedor ?? '',
                                tipo_tela: record.tipo_tela ?? '',
                                color: record.color ?? '',
                                color_option_id: record.color_option_id ? String(record.color_option_id) : '',
                                proveedor_tela_referencia_id: record.proveedor_tela_referencia_id ?? null,
                                estado_raw: record.estado_raw ?? '',
                                tiene_orden_corte_rollos: Boolean(Number(record.tiene_orden_corte_rollos ?? 0)),
                                tiene_orden_corte_legacy: Boolean(Number(record.tiene_orden_corte_legacy ?? 0)),
                                peso_inicial: record.peso_inicial ?? '',
                                peso_disponible: record.peso_disponible ?? '',
                                costo_total: record.costo_total ?? '',
                                fecha_compra: this.formatDate(record.fecha_compra),
                                observacion: record.observacion ?? '',
                            };
                            this.formKey++;

                            this.$nextTick(() => {
                                this.$refs.rolloEditModal.open();
                                this.centerModal();
                            });
                        },

                        closeModal() {
                            this.$refs.rolloEditModal.close();
                        },

                        centerModal() {
                            this.$nextTick(() => {
                                let root = this.$refs.rolloEditModal?.$el;
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

                        formatDate(value) {
                            return value ? String(value).slice(0, 10) : '';
                        },

                        save(params, { setErrors }) {
                            this.isLoading = true;

                            let formData = new FormData(this.$refs.rolloEditForm);

                            formData.append('_method', 'put');

                            this.$axios.post(this.updateUrl, formData)
                                .then((response) => {
                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                                    this.$emit('rollo-updated', response.data.data.rollo);

                                    this.isLoading = false;

                                    this.$refs.rolloEditModal.close();
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
