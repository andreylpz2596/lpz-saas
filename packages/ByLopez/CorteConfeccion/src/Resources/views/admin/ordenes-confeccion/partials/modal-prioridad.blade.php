{{--
    «Cambiar prioridad» de una cantidad concreta (no edita la orden). Reutiliza x-admin::modal de Bagisto (fondo, panel,
    scroll interno, bloqueo del scroll del cuerpo) — la misma que ya usa el detalle de movimientos de inventario — y le
    agrega lo que no trae: Escape, foco atrapado dentro, foco de vuelta al boton que la abrio y restauracion del scroll.
    Los botones «Cambiar prioridad» viven en contenido estatico (v-pre): se abren por delegacion en document.
--}}
<v-cc-prioridad
    url="{{ route('admin.corte_confeccion.ordenes_confeccion.prioridad') }}"
></v-cc-prioridad>

@pushOnce('scripts')
    <script type="text/x-template" id="v-cc-prioridad-template">
        <div @keydown.tab="atraparFoco">
            <x-admin::modal ref="modal" panel-class="max-w-[520px]" @close="alCerrar" @toggle="alAlternar">
                <x-slot:header>
                    <h2 id="cc-prioridad-titulo" class="text-lg font-bold text-gray-800 dark:text-white">Cambiar prioridad</h2>
                </x-slot>

                <x-slot:content class="max-h-[calc(100dvh-9rem)] overflow-y-auto overscroll-contain !py-3">
                    <div class="bylopez-scope grid gap-3" v-if="fila" role="dialog" aria-modal="true" aria-labelledby="cc-prioridad-titulo" data-testid="modal-prioridad">
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 rounded-md bg-gray-50 p-2.5 text-sm dark:bg-gray-950">
                            <dt class="text-gray-500">Taller</dt><dd class="truncate text-gray-800 dark:text-gray-100" v-text="fila.taller"></dd>
                            <dt class="text-gray-500">Orden</dt><dd class="font-semibold text-gray-800 dark:text-gray-100" v-text="fila.orden"></dd>
                            <dt class="text-gray-500">Variante</dt>
                            <dd class="min-w-0 text-gray-800 dark:text-gray-100">
                                <span class="block truncate" :title="fila.producto" v-text="fila.producto"></span>
                                <span class="text-xs text-gray-600 dark:text-gray-300" v-text="detalleVariante"></span>
                            </dd>
                            <template v-if="fila.sugerencia">
                                <dt class="text-gray-500">Stock</dt>
                                <dd class="text-gray-800 dark:text-gray-100">
                                    <span data-p="sugerencia" v-text="'Sugerencia: ' + fila.sugerencia"></span>
                                    <span class="block text-xs text-gray-500">Solo referencia: la prioridad la decides tú.</span>
                                </dd>
                            </template>
                        </dl>

                        <p v-if="errores.general" class="rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" v-text="errores.general"></p>

                        <div class="grid gap-3 min-[420px]:grid-cols-2">
                            <div>
                                <label for="cc-p-desde" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Prioridad de origen</label>
                                <select id="cc-p-desde" ref="primero" v-model="desde" class="w-full rounded-md border px-3 py-2 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm">
                                    <option v-for="k in origenes" :key="k" :value="k">@{{ nombres[k] }} — @{{ fila.reparto[k] }} disp.</option>
                                </select>
                            </div>
                            <div>
                                <label for="cc-p-hacia" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Nueva prioridad</label>
                                <select id="cc-p-hacia" v-model="hacia" class="w-full rounded-md border px-3 py-2 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm">
                                    <option v-for="k in destinos" :key="k" :value="k" v-text="nombres[k]"></option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="cc-p-cantidad" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Cantidad a cambiar <span class="font-normal text-gray-500">(máx. @{{ disponible }})</span></label>
                            <input
                                id="cc-p-cantidad"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                v-model.trim="cantidad"
                                @focus="$event.target.select()"
                                :aria-invalid="!!errorCantidad"
                                aria-describedby="cc-p-cantidad-error"
                                class="w-full rounded-md border px-3 py-2 text-base dark:bg-gray-900 dark:text-white sm:text-sm"
                                :class="errorCantidad ? 'border-red-500' : 'dark:border-gray-800'"
                            >
                            <p id="cc-p-cantidad-error" class="mt-1 text-xs text-red-600" v-if="errorCantidad" v-text="errorCantidad"></p>
                        </div>

                        <div>
                            <label for="cc-p-nota" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Nota para el taller <span class="font-normal text-gray-500">(opcional)</span></label>
                            <textarea id="cc-p-nota" rows="2" maxlength="500" v-model="nota" class="w-full rounded-md border px-3 py-2 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm"></textarea>
                        </div>

                        <p class="rounded-md bg-blue-50 p-2 text-sm text-blue-900 dark:bg-blue-950 dark:text-blue-100" aria-live="polite" data-p="vista-previa" v-text="vistaPrevia"></p>
                    </div>
                </x-slot>

                <x-slot:footer class="gap-2 pb-[max(0.625rem,env(safe-area-inset-bottom))]">
                    <button type="button" class="secondary-button" @click="cerrar" :disabled="enviando">Cancelar</button>
                    <button type="button" class="primary-button" @click="guardar" :disabled="!valida || enviando" data-p-guardar v-text="enviando ? 'Guardando…' : 'Guardar prioridad'"></button>
                </x-slot>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        app.component('v-cc-prioridad', {
            template: '#v-cc-prioridad-template',

            props: ['url'],

            data() {
                return {
                    fila: null, desde: 'normal', hacia: 'alta', cantidad: '', nota: '',
                    errores: {}, enviando: false, llave: null, origen: null, scrollY: 0,
                    nombres: { urgente: 'Urgente', alta: 'Alta', normal: 'Normal' },
                };
            },

            computed: {
                origenes() {
                    return this.fila ? ['normal', 'alta', 'urgente'].filter((k) => this.fila.reparto[k] > 0) : [];
                },
                destinos() {
                    return ['urgente', 'alta', 'normal'].filter((k) => k !== this.desde);
                },
                disponible() {
                    return this.fila ? (this.fila.reparto[this.desde] || 0) : 0;
                },
                detalleVariante() {
                    if (!this.fila) { return ''; }
                    return [this.fila.referencia ? 'Ref. ' + this.fila.referencia : null, this.fila.color, this.fila.talla ? 'Talla ' + this.fila.talla : null].filter(Boolean).join(' · ');
                },
                numero() {
                    return /^\d+$/.test(this.cantidad) ? parseInt(this.cantidad, 10) : null;
                },
                errorCantidad() {
                    if (this.errores.cantidad) { return this.errores.cantidad; }
                    if (this.cantidad === '') { return ''; }
                    if (this.numero === null || this.numero < 1) { return 'Escribe un número entero mayor que cero.'; }
                    if (this.numero > this.disponible) { return 'Solo hay ' + this.disponible + ' en ' + this.nombres[this.desde] + '.'; }
                    return '';
                },
                valida() {
                    return this.numero !== null && this.numero >= 1 && this.numero <= this.disponible && this.desde !== this.hacia;
                },
                vistaPrevia() {
                    if (!this.valida) { return 'Indica cuántas unidades cambian (entre 1 y ' + this.disponible + ').'; }
                    const quedan = this.disponible - this.numero;
                    return this.numero + ' pasarán a ' + this.nombres[this.hacia] + (quedan > 0 ? ' · ' + quedan + ' seguirán en ' + this.nombres[this.desde] : '');
                },
            },

            watch: {
                desde(nuevo, anterior) {
                    if (!this.destinos.includes(this.hacia)) { this.hacia = this.destinos[0]; }
                    // Por defecto se cambia todo el origen; si la persona ya escribio otra cantidad, se respeta.
                    if (this.fila && (this.cantidad === '' || this.cantidad === String(this.fila.reparto[anterior] || 0))) {
                        this.cantidad = String(this.disponible);
                    }
                    delete this.errores.cantidad;
                },
                cantidad() { delete this.errores.cantidad; },
            },

            mounted() {
                this.alClic = (e) => {
                    const objetivo = e.target instanceof Element ? e.target : null;
                    const boton = objetivo?.closest('[data-p-abrir]');
                    if (boton) { this.abrir(boton); return; }

                    // «Cambiar prioridad» desde la sugerencia de una variante: con una sola orden abre su modal; con
                    // varias, despliega la variante y enfoca el primer boton para elegir la orden. Nunca copia el nivel
                    // sugerido; la cantidad sale del origen, no de la sugerencia.
                    const enlace = objetivo?.closest('[data-p-desde-sugerencia]');
                    const grupo = enlace?.closest('details[data-grupo]');
                    if (!grupo) { return; }
                    e.preventDefault();
                    const botones = grupo.querySelectorAll('[data-p-abrir]');
                    if (botones.length === 1) { this.abrir(botones[0]); return; }
                    grupo.open = true;
                    botones[0]?.scrollIntoView({ block: 'center' });
                    botones[0]?.focus({ preventScroll: true });
                };
                document.addEventListener('click', this.alClic);

                // Escape a nivel de documento: WebKit no propaga Escape desde un <select> enfocado.
                this.alTeclear = (e) => {
                    if (e.key === 'Escape' && this.$refs.modal?.isOpen) { e.preventDefault(); this.cerrar(); }
                };
                document.addEventListener('keydown', this.alTeclear, true);
            },

            beforeUnmount() {
                document.removeEventListener('click', this.alClic);
                document.removeEventListener('keydown', this.alTeclear, true);
            },

            methods: {
                leerBoton(boton) {
                    return {
                        detalle: Number(boton.dataset.detalle), orden: boton.dataset.orden, taller: boton.dataset.taller,
                        producto: boton.dataset.producto, referencia: boton.dataset.referencia, color: boton.dataset.color,
                        talla: boton.dataset.talla, reparto: JSON.parse(boton.dataset.reparto || '{}'),
                        sugerencia: boton.dataset.sugerencia || '',
                    };
                },

                abrir(boton) {
                    this.origen = boton;
                    this.scrollY = window.scrollY;
                    this.fila = this.leerBoton(boton);
                    this.desde = this.origenes.includes('normal') ? 'normal' : this.origenes[0];
                    this.hacia = this.destinos[0] === 'urgente' && this.desde === 'normal' ? 'alta' : this.destinos[0];
                    // Por defecto, todo lo que hay en el origen; se puede bajar (p. ej. solo 30 a Urgente).
                    this.cantidad = String(this.disponible);
                    this.nota = '';
                    this.errores = {};
                    this.enviando = false;
                    // Una llave por apertura: un doble clic o reintento reutiliza la misma y no duplica nada.
                    this.llave = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
                    this.$refs.modal.open();
                    this.$nextTick(() => setTimeout(() => this.$refs.primero?.focus(), 50));
                },

                cerrar() {
                    if (this.enviando) { return; }
                    this.$refs.modal.close();
                },

                alAlternar(estado) {
                    if (!estado.isActive) { this.alCerrar(); }
                },

                // Al cerrar: scroll donde estaba y foco al boton que la abrio (si sigue en la pagina, o su reemplazo).
                alCerrar() {
                    window.scrollTo(0, this.scrollY);
                    const detalle = this.fila?.detalle;
                    const destino = (this.origen && document.body.contains(this.origen))
                        ? this.origen
                        : document.querySelector('[data-p-abrir][data-detalle="' + detalle + '"]');
                    this.$nextTick(() => destino?.focus({ preventScroll: true }));
                },

                atraparFoco(e) {
                    const panel = this.$el.querySelector('[data-testid="modal-prioridad"]')?.closest('.box-shadow');
                    if (!panel) { return; }
                    const enfocables = [...panel.querySelectorAll('button:not([disabled]), select, input, textarea, [tabindex]:not([tabindex="-1"])')];
                    if (!enfocables.length) { return; }
                    const primero = enfocables[0];
                    const ultimo = enfocables[enfocables.length - 1];
                    if (e.shiftKey && document.activeElement === primero) { e.preventDefault(); ultimo.focus(); }
                    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primero.focus(); }
                },

                guardar() {
                    if (!this.valida || this.enviando) { return; }
                    this.enviando = true;
                    this.errores = {};

                    this.$axios.post(this.url, {
                        idempotency_key: this.llave,
                        nota: this.nota,
                        cambios: [{ detalle_id: this.fila.detalle, cantidad: this.numero, desde: this.desde, hacia: this.hacia }],
                    })
                        .then((r) => {
                            this.enviando = false;
                            this.$refs.modal.close();
                            this.$emitter.emit('add-flash', { type: r.data.notificado ? 'success' : 'warning', message: r.data.message });
                            // El aviso de Bagisto dura 2 s: la confirmacion (sobre todo «nadie recibio la alerta») queda en la fila.
                            this.refrescar(r.data);
                        })
                        .catch((error) => {
                            this.enviando = false;
                            const datos = error.response?.data || {};
                            const primero = datos.errors ? Object.values(datos.errors)[0]?.[0] : null;
                            if (error.response?.status === 422 && datos.errors?.cambios) {
                                // El saldo pudo cambiar en otra pestana/usuario: se muestra junto al campo y se actualizan los disponibles.
                                this.errores = { cantidad: primero };
                                this.refrescar().then(() => {
                                    const boton = document.querySelector('[data-p-abrir][data-detalle="' + this.fila.detalle + '"]');
                                    if (boton) { this.fila.reparto = this.leerBoton(boton).reparto; }
                                });
                            } else {
                                this.errores = { general: primero || datos.message || 'Sin conexión con el servidor. Intenta de nuevo: el envío no se duplicará.' };
                            }
                        });
                },

                // Actualiza indicadores y filas sin recargar: conserva filtros, posicion y lo desplegado.
                refrescar(resultado = null) {
                    const contenedor = document.getElementById('cc-resultados');
                    if (!contenedor) { return Promise.resolve(); }
                    const abiertos = [...contenedor.querySelectorAll('details[open][data-grupo]')].map((d) => d.dataset.grupo);
                    const url = new URL(window.location.href);
                    url.searchParams.delete('aviso');

                    return fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                        .then((r) => r.text())
                        .then((html) => {
                            const nuevo = new DOMParser().parseFromString(html, 'text/html').getElementById('cc-resultados');
                            if (!nuevo) { return; }
                            const y = window.scrollY;
                            contenedor.innerHTML = nuevo.innerHTML;
                            abiertos.forEach((clave) => contenedor.querySelector('details[data-grupo="' + CSS.escape(clave) + '"]')?.setAttribute('open', ''));
                            const fila = contenedor.querySelector('[data-detalle="' + this.fila?.detalle + '"]:not([data-p-abrir])');
                            if (fila && resultado) {
                                const nota = document.createElement('p');
                                nota.setAttribute('role', 'status');
                                nota.dataset.p = 'confirmacion';
                                nota.className = 'mt-2 rounded-md px-2 py-1 text-xs lg:col-span-full ' + (resultado.notificado
                                    ? 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200'
                                    : 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200');
                                nota.textContent = resultado.message;
                                fila.appendChild(nota);
                                fila.classList.add('ring-2', 'ring-green-300');
                                setTimeout(() => fila.classList.remove('ring-2', 'ring-green-300'), 2500);
                            }
                            window.scrollTo(0, y);
                        })
                        .catch(() => {});
                },
            },
        });
    </script>
@endPushOnce
