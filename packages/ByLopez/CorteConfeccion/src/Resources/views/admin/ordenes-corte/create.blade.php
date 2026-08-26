@php
    $rollosData = $rollos->map(fn ($rollo) => [
        'id' => $rollo->id,
        'codigo' => $rollo->codigo,
        'compra_origen' => $rollo->compraTela?->codigo ?? $rollo->compra_tela_id,
        'tipo_tela_id' => $rollo->tipo_tela_id,
        'tipo_tela' => $rollo->tipo_tela,
        'color' => $rollo->color,
        'referencia' => $rollo->referencia,
        'gramaje' => $rollo->gramaje,
        'peso_disponible' => (float) $rollo->peso_disponible,
    ])->values();

    $productLabels = $products->keyBy('id')->map(fn ($product) => $product->sku.' - '.$product->name);

    $oldDetalles = collect(old('detalles', [[
        'product_id' => '',
        'cantidad_cortada' => '',
        'cantidad_real_cortada' => '',
        'observacion' => '',
    ]]))->map(function ($detalle) use ($productLabels) {
        $productId = $detalle['product_id'] ?? '';

        return array_merge([
            'product_id' => '',
            'product_label' => '',
            'cantidad_cortada' => '',
            'cantidad_real_cortada' => '',
            'observacion' => '',
        ], $detalle, [
            'product_label' => $productId ? ($productLabels[$productId] ?? '') : '',
        ]);
    })->values();
@endphp

<x-admin::layouts>
    <x-slot:title>Crear orden de corte</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.store') }}">
        @csrf

        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Crear orden de corte</p>
            <button class="primary-button">Guardar</button>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-admin::form.control-group>
                <x-admin::form.control-group.label>Codigo</x-admin::form.control-group.label>
                <div class="min-h-10 rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                    Se generara automaticamente al guardar
                </div>
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Cortador</x-admin::form.control-group.label>
                <select
                    name="cortador_id"
                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                    <option value="">Seleccionar cortador</option>
                    @foreach ($cortadores as $cortador)
                        <option value="{{ $cortador->id }}" @selected((string) old('cortador_id') === (string) $cortador->id)>
                            {{ $cortador->name }}{{ $cortador->email ? ' - '.$cortador->email : '' }}
                        </option>
                    @endforeach
                </select>
                <x-admin::form.control-group.error control-name="cortador_id" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>Fecha entrega</x-admin::form.control-group.label>
                <x-admin::form.control-group.control type="date" name="fecha_entrega" value="{{ old('fecha_entrega') }}" />
                <x-admin::form.control-group.error control-name="fecha_entrega" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>Fecha solicitud</x-admin::form.control-group.label>
                <x-admin::form.control-group.control type="date" name="fecha_solicitud" value="{{ old('fecha_solicitud') }}" />
                <x-admin::form.control-group.error control-name="fecha_solicitud" />
            </x-admin::form.control-group>
        </div>

        <x-admin::form.control-group class="mt-4">
            <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion') }}" />
            <x-admin::form.control-group.error control-name="observacion" />
        </x-admin::form.control-group>

        <v-orden-corte-form
            :rollos='@json($rollosData)'
            :old-rollo-ids='@json(old('rollo_ids', []))'
            :old-detalles='@json($oldDetalles)'
            :can-edit-cantidad-cortada='@json($canEditCantidadCortada)'
            product-search-url="{{ route('admin.catalog.products.search') }}"
        ></v-orden-corte-form>
    </form>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-orden-corte-form-template">
            <div>
                <div class="mt-6 rounded border p-4 dark:border-gray-800">
                    <div class="mb-3 flex items-center justify-between gap-3 max-md:flex-wrap">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">Rollos a cortar</p>

                        <div class="flex min-w-[420px] gap-2 max-md:w-full max-md:min-w-0">
                            <select
                                class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                v-model="rolloPickerId"
                            >
                                <option value="">Seleccionar rollo</option>
                                <option
                                    v-for="rollo in availableRollos"
                                    :key="rollo.id"
                                    :value="rollo.id"
                                >
                                    @{{ rollo.codigo }} - @{{ rollo.tipo_tela }} - @{{ rollo.color }} - @{{ formatKg(rollo.peso_disponible) }}
                                </option>
                            </select>

                            <button
                                type="button"
                                class="secondary-button whitespace-nowrap"
                                @click="addSelectedRollo"
                            >
                                Agregar
                            </button>
                        </div>
                    </div>

                    <input
                        v-for="(rollo, index) in selectedRollos"
                        type="hidden"
                        :name="'rollo_ids[' + index + ']'"
                        :value="rollo.id"
                    />

                    <x-admin::form.control-group.error control-name="rollo_ids" />
                    <x-admin::form.control-group.error control-name="rollo_ids.0" />

                    <p
                        class="mb-3 text-sm font-semibold"
                        :class="rolloWarningType === 'error' ? 'text-red-600' : 'text-amber-600'"
                        v-if="rolloWarning"
                    >
                        @{{ rolloWarning }}
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-left text-sm">
                            <thead class="border-b text-gray-600 dark:border-gray-800 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-2">Codigo rollo</th>
                                    <th class="px-3 py-2">Compra origen</th>
                                    <th class="px-3 py-2">Tipo tela</th>
                                    <th class="px-3 py-2">Color</th>
                                    <th class="px-3 py-2">Referencia</th>
                                    <th class="px-3 py-2">Gramaje</th>
                                    <th class="px-3 py-2">Peso disponible</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    class="border-b last:border-b-0 dark:border-gray-800"
                                    v-for="(rollo, index) in selectedRollos"
                                    :key="rollo.id"
                                >
                                    <td class="px-3 py-2">@{{ rollo.codigo }}</td>
                                    <td class="px-3 py-2">@{{ rollo.compra_origen }}</td>
                                    <td class="px-3 py-2">@{{ rollo.tipo_tela }}</td>
                                    <td class="px-3 py-2">@{{ rollo.color }}</td>
                                    <td class="px-3 py-2">@{{ rollo.referencia }}</td>
                                    <td class="px-3 py-2">@{{ rollo.gramaje }}</td>
                                    <td class="px-3 py-2">@{{ formatKg(rollo.peso_disponible) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            class="transparent-button"
                                            @click="removeRollo(index)"
                                        >
                                            Quitar
                                        </button>
                                    </td>
                                </tr>

                                <tr v-if="! selectedRollos.length">
                                    <td class="px-3 py-4 text-center text-gray-500" colspan="8">Sin rollos seleccionados</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
                        <div class="rounded border p-3 dark:border-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Cantidad rollos</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ selectedRollos.length }}</p>
                        </div>

                        <div class="rounded border p-3 dark:border-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Peso total disponible</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ formatKg(pesoTotalDisponible) }}</p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 rounded border p-4 dark:border-gray-800">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">Productos a cortar</p>

                        <button
                            type="button"
                            class="secondary-button"
                            @click="addDetalle"
                        >
                            Agregar producto
                        </button>
                    </div>

                    <x-admin::form.control-group.error control-name="detalles" />

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[980px] text-left text-sm">
                            <thead class="border-b text-gray-600 dark:border-gray-800 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-2 min-w-[420px]">Producto Bagisto</th>
                                    <th class="px-3 py-2">Cantidad cortada</th>
                                    <th class="px-3 py-2">Observacion</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    class="border-b align-top last:border-b-0 dark:border-gray-800"
                                    v-for="(detalle, index) in detalles"
                                    :key="detalle.key"
                                >
                                    <td class="px-3 py-2 min-w-[420px]">
                                        <input
                                            type="hidden"
                                            :name="'detalles[' + index + '][product_id]'"
                                            :value="detalle.product_id"
                                        />

                                        <div class="relative">
                                            <input
                                                type="text"
                                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                placeholder="Buscar producto"
                                                v-model="detalle.product_query"
                                                @input="searchProducts(index)"
                                                @focus="openProductResults(index)"
                                            />

                                            <template v-if="detalle.is_searching">
                                                <img
                                                    class="absolute top-2.5 h-5 w-5 animate-spin ltr:right-3 rtl:left-3"
                                                    src="{{ bagisto_asset('images/spinner.svg') }}"
                                                />
                                            </template>

                                            <template v-else>
                                                <span class="icon-search pointer-events-none absolute top-1.5 flex items-center text-2xl ltr:right-3 rtl:left-3"></span>
                                            </template>

                                            <div
                                                class="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
                                                v-if="detalle.show_results"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex w-full flex-col gap-1 border-b px-3 py-2 text-left last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                                                    v-for="product in detalle.searched_products"
                                                    :key="product.id"
                                                    @click="selectProduct(index, product)"
                                                >
                                                    <span class="font-semibold text-gray-800 dark:text-white">@{{ product.name }}</span>
                                                    <span class="text-xs text-gray-600 dark:text-gray-300">SKU: @{{ product.sku }}</span>
                                                </button>

                                                <div
                                                    class="px-3 py-2 text-sm text-gray-500"
                                                    v-if="! detalle.searched_products.length && detalle.product_query.length > 1 && ! detalle.is_searching"
                                                >
                                                    Sin productos encontrados
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-3 py-2">
                                        <input
                                            v-if="canEditCantidadCortada"
                                            type="number"
                                            min="0"
                                            class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            :name="'detalles[' + index + '][cantidad_cortada]'"
                                            v-model="detalle.cantidad_cortada"
                                        />

                                        <div
                                            v-else
                                            class="min-h-10 rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300"
                                        >
                                            @{{ detalle.cantidad_cortada || 0 }}
                                        </div>
                                    </td>

                                    <td class="px-3 py-2">
                                        <input
                                            class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            :name="'detalles[' + index + '][observacion]'"
                                            v-model="detalle.observacion"
                                        />
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            class="transparent-button"
                                            @click="removeDetalle(index)"
                                        >
                                            Quitar
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-6 rounded border p-4 dark:border-gray-800">
                    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Cierre de corte</p>

                    <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
                        <div class="mb-4">
                            <label class="mb-1.5 flex gap-1 text-xs font-medium text-gray-800 dark:text-white">
                                Peso sobrante
                            </label>

                            <input
                                type="number"
                                step="0.001"
                                name="peso_sobrante_total"
                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                v-model="pesoSobrante"
                            />

                            <x-admin::form.control-group.error control-name="peso_sobrante_total" />
                        </div>

                        <div class="mb-4">
                            <label class="mb-1.5 flex gap-1 text-xs font-medium text-gray-800 dark:text-white">
                                Merma
                            </label>

                            <input
                                type="number"
                                step="0.001"
                                name="merma_total"
                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                v-model="merma"
                            />

                            <x-admin::form.control-group.error control-name="merma_total" />
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
                        <div class="rounded border p-3 dark:border-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Peso consumido calculado</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ formatKg(pesoConsumido) }}</p>
                        </div>

                        <div class="rounded border p-3 dark:border-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Total piezas cortadas</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ totalPiezas }}</p>
                        </div>

                        <div class="rounded border p-3 dark:border-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Peso promedio por pieza</p>
                            <p class="text-lg font-semibold text-gray-800 dark:text-white">@{{ formatKg(pesoPromedio, 6) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded border dark:border-gray-800">
                        <div
                            class="grid grid-cols-3 gap-3 border-b p-3 text-sm last:border-b-0 dark:border-gray-800 max-md:grid-cols-1"
                            v-for="resumen in resumenPorProducto"
                            :key="resumen.producto"
                        >
                            <span>@{{ resumen.producto }}</span>
                            <span>@{{ resumen.piezas }} piezas</span>
                            <span>@{{ formatKg(pesoPromedio, 6) }} aprox. por pieza</span>
                        </div>

                        <div class="p-3 text-sm text-gray-500" v-if="! resumenPorProducto.length">Sin cantidades reales</div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-orden-corte-form', {
                template: '#v-orden-corte-form-template',

                props: {
                    rollos: {
                        type: Array,
                        default: () => [],
                    },

                    oldRolloIds: {
                        type: Array,
                        default: () => [],
                    },

                    oldDetalles: {
                        type: Array,
                        default: () => [],
                    },

                    productSearchUrl: {
                        type: String,
                        required: true,
                    },

                    canEditCantidadCortada: {
                        type: Boolean,
                        default: false,
                    },
                },

                data() {
                    return {
                        rolloPickerId: '',
                        catalogRollos: [...this.rollos],
                        selectedRollos: [],
                        rolloWarning: '',
                        rolloWarningType: 'warning',
                        pesoSobrante: @json(old('peso_sobrante_total', '')),
                        merma: @json(old('merma_total', '')),
                        searchTimers: {},
                        detalles: this.prepareDetalles(this.oldDetalles),
                    };
                },

                computed: {
                    availableRollos() {
                        return this.catalogRollos.filter((rollo) => ! this.selectedRollos.some((selected) => Number(selected.id) === Number(rollo.id)));
                    },

                    pesoTotalDisponible() {
                        return this.selectedRollos.reduce((total, rollo) => total + Number(rollo.peso_disponible || 0), 0);
                    },

                    pesoConsumido() {
                        return Math.max(this.pesoTotalDisponible - (Number.parseFloat(this.pesoSobrante) || 0) - (Number.parseFloat(this.merma) || 0), 0);
                    },

                    totalPiezas() {
                        return this.detalles.reduce((total, detalle) => total + (Number.parseInt(detalle.cantidad_cortada, 10) || 0), 0);
                    },

                    pesoPromedio() {
                        return this.totalPiezas > 0 ? this.pesoConsumido / this.totalPiezas : 0;
                    },

                    resumenPorProducto() {
                        let grouped = {};

                        this.detalles.forEach((detalle) => {
                            let producto = detalle.selected_product_label || detalle.product_query || 'Sin producto';

                            grouped[producto] = (grouped[producto] || 0) + (Number.parseInt(detalle.cantidad_cortada, 10) || 0);
                        });

                        return Object.keys(grouped)
                            .filter((producto) => grouped[producto] > 0)
                            .map((producto) => ({
                                producto,
                                piezas: grouped[producto],
                            }));
                    },
                },

                mounted() {
                    this.oldRolloIds.forEach((rolloId) => this.addRolloById(rolloId));
                },

                methods: {
                    prepareDetalles(detalles) {
                        let rows = detalles.length ? detalles : [{}];

                        return rows.map((detalle, index) => ({
                            key: `${Date.now()}-${index}-${Math.random()}`,
                            product_id: detalle.product_id || '',
                            product_query: detalle.product_label || '',
                            selected_product_label: detalle.product_label || '',
                            cantidad_cortada: detalle.cantidad_cortada || detalle.cantidad_real_cortada || '',
                            observacion: detalle.observacion || '',
                            searched_products: [],
                            show_results: false,
                            is_searching: false,
                        }));
                    },

                    addSelectedRollo() {
                        if (! this.rolloPickerId) {
                            this.rolloWarning = 'Seleccione un rollo disponible antes de agregar.';
                            this.rolloWarningType = 'warning';

                            return;
                        }

                        this.addRolloById(this.rolloPickerId);
                        this.rolloPickerId = '';
                    },

                    addRolloById(rolloId) {
                        let rollo = this.catalogRollos.find((item) => Number(item.id) === Number(rolloId));

                        if (! rollo || this.selectedRollos.some((selected) => Number(selected.id) === Number(rollo.id))) {
                            return;
                        }

                        if (! this.compatibleWithSelection(rollo)) {
                            this.rolloWarning = 'No se pueden mezclar rollos con diferente tipo de tela, color o gramaje.';
                            this.rolloWarningType = 'error';

                            return;
                        }

                        if (
                            this.selectedRollos.length
                            && this.normalize(this.selectedRollos[0].referencia) !== this.normalize(rollo.referencia)
                        ) {
                            this.rolloWarning = 'Referencia diferente entre rollos seleccionados.';
                            this.rolloWarningType = 'warning';
                        } else {
                            this.rolloWarning = '';
                        }

                        this.selectedRollos.push(rollo);
                    },

                    rolloLabel(rollo) {
                        return `${rollo.codigo} - ${rollo.tipo_tela} - ${rollo.color} - ${rollo.referencia} - ${this.formatKg(rollo.peso_disponible)}`;
                    },

                    removeRollo(index) {
                        this.selectedRollos.splice(index, 1);
                        this.rolloWarning = '';
                    },

                    compatibleWithSelection(rollo) {
                        if (! this.selectedRollos.length) {
                            return true;
                        }

                        let base = this.selectedRollos[0];

                        return String(base.tipo_tela_id ?? '') === String(rollo.tipo_tela_id ?? '')
                            && this.normalize(base.color) === this.normalize(rollo.color)
                            && this.normalize(base.gramaje) === this.normalize(rollo.gramaje);
                    },

                    addDetalle() {
                        this.detalles.push(this.prepareDetalles([{}])[0]);
                    },

                    removeDetalle(index) {
                        this.detalles.splice(index, 1);

                        if (! this.detalles.length) {
                            this.addDetalle();
                        }
                    },

                    openProductResults(index) {
                        let detalle = this.detalles[index];

                        if (detalle.searched_products.length) {
                            detalle.show_results = true;
                        }
                    },

                    searchProducts(index) {
                        let detalle = this.detalles[index];

                        if (detalle.product_query !== detalle.selected_product_label) {
                            detalle.product_id = '';
                        }

                        clearTimeout(this.searchTimers[index]);

                        this.searchTimers[index] = setTimeout(() => {
                            if ((detalle.product_query || '').length <= 1) {
                                detalle.searched_products = [];
                                detalle.show_results = false;

                                return;
                            }

                            detalle.is_searching = true;
                            detalle.show_results = true;

                            this.$axios.get(this.productSearchUrl, {
                                params: {
                                    query: detalle.product_query,
                                },
                            })
                                .then((response) => {
                                    detalle.searched_products = response.data.data || [];
                                    detalle.is_searching = false;
                                })
                                .catch(() => {
                                    detalle.searched_products = [];
                                    detalle.is_searching = false;
                                });
                        }, 500);
                    },

                    selectProduct(index, product) {
                        let detalle = this.detalles[index];
                        let label = `${product.sku} - ${product.name}`;

                        detalle.product_id = product.id;
                        detalle.product_query = label;
                        detalle.selected_product_label = label;
                        detalle.searched_products = [];
                        detalle.show_results = false;
                    },

                    normalize(value) {
                        return String(value ?? '').trim().toLowerCase();
                    },

                    formatNumber(value, decimals = 3) {
                        return Number(value || 0).toFixed(decimals);
                    },

                    formatKg(value, decimals = 3) {
                        return `${this.formatNumber(value, decimals)} kg`;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
