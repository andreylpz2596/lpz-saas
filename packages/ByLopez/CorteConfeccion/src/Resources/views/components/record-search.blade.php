@props([
    'name',
    'searchUrl',
    'selectedId' => null,
    'selectedLabel' => null,
    'selectedSublabel' => null,
    'placeholder' => 'Buscar...',
    'rules' => '',
    'label' => null,
])

<v-record-search
    name="{{ $name }}"
    search-url="{{ $searchUrl }}"
    :selected-id='@json($selectedId)'
    selected-label="{{ $selectedLabel }}"
    selected-sublabel="{{ $selectedSublabel }}"
    placeholder="{{ $placeholder }}"
    rules="{{ $rules }}"
    label="{{ $label ?? $placeholder }}"
></v-record-search>

@once
    @push('scripts')
        <script type="text/x-template" id="v-record-search-template">
            <div class="relative">
                <v-field
                    type="hidden"
                    :name="name"
                    v-model="id"
                    :rules="rules"
                    :label="label"
                />

                <input
                    type="text"
                    class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    :placeholder="placeholder"
                    v-model="query"
                    @input="buscar"
                    @focus="abrir"
                    @blur="cerrarConRetardo"
                    autocomplete="off"
                />

                <div
                    class="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
                    v-if="mostrarResultados"
                >
                    <button
                        type="button"
                        class="flex w-full flex-col gap-0.5 border-b px-3 py-2 text-left last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="resultado in resultados"
                        :key="resultado.id"
                        @mousedown.prevent="seleccionar(resultado)"
                    >
                        <span class="font-semibold text-gray-800 dark:text-white">@{{ resultado.label }}</span>
                        <span class="text-xs text-gray-600 dark:text-gray-300" v-if="resultado.sublabel">@{{ resultado.sublabel }}</span>
                    </button>

                    <div class="px-3 py-2 text-sm text-gray-500" v-if="! resultados.length && ! buscando && query.length > 1">
                        Sin resultados
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-record-search', {
                template: '#v-record-search-template',

                props: {
                    name: { type: String, required: true },
                    searchUrl: { type: String, required: true },
                    selectedId: { default: null },
                    selectedLabel: { type: String, default: '' },
                    selectedSublabel: { type: String, default: '' },
                    placeholder: { type: String, default: 'Buscar...' },
                    rules: { type: String, default: '' },
                    label: { type: String, default: '' },
                },

                data() {
                    return {
                        id: this.selectedId || '',
                        query: this.selectedLabel
                            ? (this.selectedSublabel ? `${this.selectedLabel} - ${this.selectedSublabel}` : this.selectedLabel)
                            : '',
                        resultados: [],
                        mostrarResultados: false,
                        buscando: false,
                        timer: null,
                    };
                },

                methods: {
                    abrir() {
                        if (this.resultados.length) {
                            this.mostrarResultados = true;
                        }
                    },

                    cerrarConRetardo() {
                        setTimeout(() => { this.mostrarResultados = false; }, 150);
                    },

                    buscar() {
                        this.id = '';

                        clearTimeout(this.timer);

                        this.timer = setTimeout(() => {
                            if (this.query.length <= 1) {
                                this.resultados = [];
                                this.mostrarResultados = false;

                                return;
                            }

                            this.buscando = true;
                            this.mostrarResultados = true;

                            this.$axios.get(this.searchUrl, { params: { query: this.query } })
                                .then((response) => {
                                    this.resultados = response.data.data || [];
                                    this.buscando = false;
                                })
                                .catch(() => {
                                    this.resultados = [];
                                    this.buscando = false;
                                });
                        }, 400);
                    },

                    seleccionar(resultado) {
                        this.id = resultado.id;
                        this.query = resultado.sublabel ? `${resultado.label} - ${resultado.sublabel}` : resultado.label;
                        this.resultados = [];
                        this.mostrarResultados = false;
                    },
                },
            });
        </script>
    @endpush
@endonce
