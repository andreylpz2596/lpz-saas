{{--
    Modales de revision de pagos (PAG-E), una sola instancia por pantalla. Los botones de las filas (y del detalle) son
    HTML de servidor con `data-revisar="confirmar|rechazar"`, `data-pago` y `data-texto`; este componente los atiende por
    delegacion de eventos. El servidor valida todo otra vez: aqui solo se evita el clic accidental y se pide el motivo.
--}}
<v-revision-pago
    url-confirmar="{{ route('admin.ventas_asesor.cartera.pagos.confirmar', ['pago' => '__ID__']) }}"
    url-rechazar="{{ route('admin.ventas_asesor.cartera.pagos.rechazar', ['pago' => '__ID__']) }}"
    :motivos='@json($motivosRechazo)'
    :textos='@json(trans('ventasasesor::app.cartera'))'
></v-revision-pago>

@pushOnce('scripts')
    <script type="text/x-template" id="v-revision-pago-template">
        <div>
            <x-admin::modal ref="modalConfirmar">
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ textos.revision['confirmar-titulo'] }}</p>
                </x-slot>

                <x-slot:content>
                    <div class="grid gap-3 text-sm">
                        <dl v-if="detalle" class="va-pago-detalle" data-revision="detalle">
                            <div><dt>@{{ textos.detalle.cliente }}</dt><dd data-revision-dato="cliente">@{{ detalle.cliente }}</dd></div>
                            <div><dt>@{{ textos.detalle.monto }}</dt><dd data-revision-dato="monto">@{{ detalle.monto }}</dd></div>
                            <div><dt>@{{ textos.detalle.medio }}</dt><dd data-revision-dato="medio">@{{ detalle.medio }}</dd></div>
                            <div v-if="detalle.referencia"><dt>@{{ textos.detalle.referencia }}</dt><dd data-revision-dato="referencia">@{{ detalle.referencia }}</dd></div>
                            <div><dt>@{{ textos.revision.pedidos }}</dt><dd data-revision-dato="pedidos">@{{ detalle.pedidos.length ? detalle.pedidos.join(", ") : "—" }}</dd></div>
                        </dl>
                        <p v-else class="rounded-lg bg-gray-100 px-3 py-2 font-semibold dark:bg-gray-950">@{{ texto }}</p>
                        <p class="text-gray-600 dark:text-gray-300">@{{ textos.revision['confirmar-texto'] }}</p>
                        <p v-if="error" class="font-medium text-red-600 dark:text-red-400">@{{ error }}</p>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <button type="button" class="transparent-button" :disabled="enviando" @click="$refs.modalConfirmar.close()">
                        @{{ textos.revision.cancelar }}
                    </button>

                    <button type="button" class="primary-button" :disabled="enviando" @click="confirmar">
                        @{{ enviando ? textos.revision.procesando : textos.revision['confirmar-boton'] }}
                    </button>
                </x-slot:footer>
            </x-admin::modal>

            <x-admin::modal ref="modalRechazar">
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ textos.revision['rechazar-titulo'] }}</p>
                </x-slot>

                <x-slot:content>
                    <div class="grid gap-3 text-sm">
                        <dl v-if="detalle" class="va-pago-detalle" data-revision="detalle">
                            <div><dt>@{{ textos.detalle.cliente }}</dt><dd data-revision-dato="cliente">@{{ detalle.cliente }}</dd></div>
                            <div><dt>@{{ textos.detalle.monto }}</dt><dd data-revision-dato="monto">@{{ detalle.monto }}</dd></div>
                            <div><dt>@{{ textos.detalle.medio }}</dt><dd data-revision-dato="medio">@{{ detalle.medio }}</dd></div>
                            <div v-if="detalle.referencia"><dt>@{{ textos.detalle.referencia }}</dt><dd data-revision-dato="referencia">@{{ detalle.referencia }}</dd></div>
                            <div><dt>@{{ textos.revision.pedidos }}</dt><dd data-revision-dato="pedidos">@{{ detalle.pedidos.length ? detalle.pedidos.join(", ") : "—" }}</dd></div>
                        </dl>
                        <p v-else class="rounded-lg bg-gray-100 px-3 py-2 font-semibold dark:bg-gray-950">@{{ texto }}</p>
                        <p class="text-gray-600 dark:text-gray-300">@{{ textos.revision['rechazar-texto'] }}</p>

                        <div>
                            <label class="text-gray-600 dark:text-gray-300">@{{ textos.revision.categoria }} *</label>
                            <select class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900" v-model="categoria">
                                <option v-for="m in motivos" :key="m.codigo" :value="m.codigo">@{{ m.etiqueta }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-gray-600 dark:text-gray-300">@{{ textos.revision.detalle }} *</label>
                            <textarea
                                rows="3"
                                maxlength="500"
                                class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                v-model="motivo"
                            ></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.revision['detalle-ayuda'] }}</p>
                        </div>

                        <p v-if="error" class="font-medium text-red-600 dark:text-red-400">@{{ error }}</p>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <button type="button" class="transparent-button" :disabled="enviando" @click="$refs.modalRechazar.close()">
                        @{{ textos.revision.cancelar }}
                    </button>

                    <button type="button" class="primary-button" :disabled="enviando || motivo.trim().length < 5" @click="rechazar">
                        @{{ enviando ? textos.revision.procesando : textos.revision['rechazar-boton'] }}
                    </button>
                </x-slot:footer>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        app.component('v-revision-pago', {
            template: '#v-revision-pago-template',

            props: ['urlConfirmar', 'urlRechazar', 'motivos', 'textos'],

            data() {
                return { pagoId: null, texto: '', detalle: null, categoria: '', motivo: '', enviando: false, error: null };
            },

            mounted() {
                document.addEventListener('click', this.alHacerClic);
            },

            beforeUnmount() {
                document.removeEventListener('click', this.alHacerClic);
            },

            methods: {
                // Los botones son HTML del servidor: se atienden por delegacion de eventos.
                alHacerClic(evento) {
                    const boton = evento.target.closest('[data-revisar]');

                    if (! boton) {
                        return;
                    }

                    evento.preventDefault();

                    this.pagoId = boton.dataset.pago;
                    this.texto = boton.dataset.texto || '';
                    this.detalle = this.leerDetalle(boton.dataset.detalle);
                    this.categoria = this.motivos.length ? this.motivos[0].codigo : '';
                    this.motivo = '';
                    this.error = null;
                    this.enviando = false;

                    this.$refs[boton.dataset.revisar === 'rechazar' ? 'modalRechazar' : 'modalConfirmar'].open();
                },

                // Datos estructurados del pago (cliente, importe, medio, referencia, pedidos); si faltan, se usa el texto corto de siempre.
                leerDetalle(crudo) {
                    try {
                        const d = crudo ? JSON.parse(crudo) : null;

                        return d && Array.isArray(d.pedidos) ? d : null;
                    } catch (e) {
                        return null;
                    }
                },

                enviar(url, cuerpo, resultado) {
                    if (this.enviando) {
                        return;
                    }

                    this.enviando = true;
                    this.error = null;

                    this.$axios.post(url.replace('__ID__', this.pagoId), cuerpo)
                        .then(() => {
                            // La pantalla se recarga desde el servidor (las cifras no se recalculan aqui) con un aviso.
                            const destino = new URL(window.location.href);

                            destino.searchParams.set('ok', resultado);
                            destino.searchParams.set('pago_ok', this.pagoId);
                            destino.searchParams.delete('page');

                            window.location.href = destino.toString();
                        })
                        .catch(e => {
                            const datos = (e.response && e.response.data) || {};
                            const primero = datos.errors ? Object.values(datos.errors)[0][0] : null;

                            this.error = datos.mensaje || primero || this.textos.revision.error;
                            this.enviando = false;
                        });
                },

                confirmar() {
                    this.enviar(this.urlConfirmar, {}, 'confirmado');
                },

                rechazar() {
                    this.enviar(this.urlRechazar, { categoria: this.categoria, motivo: this.motivo.trim() }, 'rechazado');
                },
            },
        });
    </script>
@endPushOnce
