@pushOnce('scripts')
    <script type="text/x-template" id="v-movimiento-detalle-template">
        <div>
            <slot :abrir="abrir"></slot>
            <x-admin::modal ref="detalle" panel-class="max-w-[860px]">
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Detalle del movimiento</p>
                </x-slot>
                <x-slot:content>
                    <div class="bylopez-scope" style="max-height: 72vh; overflow-y: auto" :aria-busy="cargando">
                        <p v-if="cargando" role="status" class="p-4 text-sm text-gray-600 dark:text-gray-300">Cargando movimiento...</p>
                        <div v-else-if="error" role="alert" class="p-4">
                            <p class="text-sm text-red-600" v-text="error"></p>
                            <button type="button" class="secondary-button mt-3" @click="abrir(url)">Reintentar</button>
                        </div>
                        <div v-else v-html="contenido"></div>
                    </div>
                </x-slot>
                <x-slot:footer>
                    <button type="button" class="secondary-button" @click="$refs.detalle.close()">Cerrar</button>
                </x-slot>
            </x-admin::modal>
        </div>
    </script>
    <script type="module">
        app.component('v-movimiento-detalle', {
            template: '#v-movimiento-detalle-template',
            data() {
                return { cargando: false, contenido: '', error: '', url: '', solicitud: 0 };
            },
            methods: {
                async abrir(url) {
                    const solicitud = ++this.solicitud;
                    this.url = url;
                    this.contenido = '';
                    this.error = '';
                    this.cargando = true;
                    this.$refs.detalle.open();
                    try {
                        const respuesta = await this.$axios.get(url, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (solicitud === this.solicitud) {
                            if (typeof respuesta.data !== 'string' || ! respuesta.data.includes('data-movimiento-detalle')) {
                                throw new Error('Detalle no disponible');
                            }
                            this.contenido = respuesta.data;
                        }
                    } catch (error) {
                        if (solicitud === this.solicitud) {
                            this.error = 'No se pudo cargar el movimiento. Comprueba tu sesion y vuelve a intentar.';
                        }
                    } finally {
                        if (solicitud === this.solicitud) this.cargando = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
