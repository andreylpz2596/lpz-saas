@once
    @push('styles')
        @include('cargues::admin.estilos')
    @endpush
@endonce



<x-admin::layouts>
    <x-slot:title>
        @lang('cargues::app.tipos.formulario')
    </x-slot>

    <div class="cg-page">
        <v-cargues-tipo-form></v-cargues-tipo-form>
    </div>

    @pushOnce('scripts')
        <script>
            window.__carguesTipo = @json($config);
        </script>

        @verbatim
        <script type="text/x-template" id="v-cargues-tipo-form-template">
            <div>
                <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                    <div>
                        <h1 class="cg-title">{{ s.tipo_id ? s.nombre : t('nuevo_tipo') }}</h1>
                        <p class="cg-sub mt-1">
                            <template v-if="s.estado">{{ t('version') }} #{{ s.numero }} ·
                                <span class="cg-pill" :class="s.estado === 'activa' ? 'cg-pill--verde' : (s.estado === 'borrador' ? 'cg-pill--azul' : 'cg-pill--gris')">{{ t('estado_' + s.estado) }}</span></template>
                            <template v-else>{{ t('sin_guardar') }}</template>
                        </p>
                    </div>
                    <a :href="rutas.lista" class="cg-btn">{{ t('volver') }}</a>
                </div>

                <div v-if="mensaje" class="cg-alert mt-4" :class="mensaje.tipo === 'ok' ? 'cg-alert--ok' : 'cg-alert--error'" role="status">
                    <span class="icon-information text-2xl" aria-hidden="true"></span>
                    <div><p class="cg-alert-title">{{ mensaje.titulo }}</p><p v-if="mensaje.texto">{{ mensaje.texto }}</p></div>
                </div>

                <div v-if="s.estado && s.estado !== 'borrador'" class="cg-alert cg-alert--aviso mt-4">
                    <span class="icon-information text-2xl" aria-hidden="true"></span>
                    <div><p>{{ t('no_editable') }}</p>
                        <button type="button" class="cg-btn mt-2" :disabled="enviando" @click="nuevaVersion">{{ t('crear_version') }}</button></div>
                </div>

                <div class="cg-panel mt-4 p-5">
                    <fieldset :disabled="!editable" class="grid gap-4">
                        <div class="cg-grid2">
                            <div class="cg-field"><label for="tf-nombre">{{ t('nombre') }}</label><input id="tf-nombre" class="cg-input" maxlength="191" v-model="f.nombre" :disabled="!!s.tipo_id && !editable"></div>
                            <div class="cg-field"><label for="tf-desc">{{ t('descripcion') }}</label><input id="tf-desc" class="cg-input" maxlength="500" v-model="f.descripcion"></div>
                            <div class="cg-field"><label for="tf-conexion">{{ t('conexion') }}</label>
                                <select id="tf-conexion" class="cg-input" v-model="f.conexion"><option v-for="c in s.conexiones" :key="c" :value="c">{{ c }}</option></select>
                                <p class="cg-sub mt-1">{{ t('conexion_ayuda') }}</p></div>
                            <div class="cg-field"><label for="tf-sp">{{ t('procedimiento') }}</label><input id="tf-sp" class="cg-input cg-mono" maxlength="64" v-model="f.procedimiento"><p class="cg-sub mt-1">{{ t('procedimiento_ayuda') }}</p></div>
                            <div class="cg-field"><label for="tf-accion">{{ t('accion') }}</label><input id="tf-accion" class="cg-input cg-mono" maxlength="40" v-model="f.accion"><p class="cg-sub mt-1">{{ t('accion_ayuda') }}</p></div>
                        </div>

                        <div>
                            <p class="cg-caption">{{ t('politica') }} <span class="cg-req">*</span></p>
                            <p class="cg-sub">{{ t('politica_ayuda') }}</p>
                            <div class="mt-2 grid gap-2" role="radiogroup" :aria-label="t('politica')">
                                <label class="flex items-start gap-2 text-sm"><input type="radio" name="politica" value="bloquear_todo" v-model="f.politica" class="mt-1"><span><strong>{{ t('pol_bloquear') }}</strong><br><span class="cg-sub">{{ t('pol_bloquear_ayuda') }}</span></span></label>
                                <label class="flex items-start gap-2 text-sm"><input type="radio" name="politica" value="procesar_validas" v-model="f.politica" class="mt-1"><span><strong>{{ t('pol_procesar') }}</strong><br><span class="cg-sub">{{ t('pol_procesar_ayuda') }}</span></span></label>
                            </div>
                            <p v-if="!f.politica" class="mt-2"><span class="cg-pill cg-pill--amarillo">{{ t('politica_sin_elegir') }}</span></p>
                        </div>

                        <div>
                            <p class="cg-caption">{{ t('limites') }}</p>
                            <p class="cg-sub">{{ t('limites_ayuda') }}</p>
                            <div class="cg-grid2 mt-2">
                                <div class="cg-field" v-for="k in ['max_bytes', 'max_celdas', 'max_filas']" :key="k">
                                    <label :for="'tf-' + k">{{ t('lim_' + k) }} ({{ t('maximo_global') }}: {{ s.limites_globales[k].toLocaleString() }})</label>
                                    <input :id="'tf-' + k" type="number" min="1" :max="s.limites_globales[k]" class="cg-input" v-model.number="f.limites[k]" :placeholder="t('sin_limite_propio')">
                                </div>
                            </div>
                        </div>

                        <div class="cg-field">
                            <label for="tf-map">{{ t('map') }}</label>
                            <textarea id="tf-map" class="cg-input cg-mono" rows="22" spellcheck="false" v-model="f.map"></textarea>
                            <p class="cg-sub mt-1">{{ t('map_ayuda') }}</p>
                        </div>
                    </fieldset>

                    <div v-if="errores.length" class="cg-alert cg-alert--error mt-4" role="alert">
                        <span class="icon-information text-2xl" aria-hidden="true"></span>
                        <div><p class="cg-alert-title">{{ t('hay_errores') }} ({{ errores.length }})</p>
                            <ul class="list-disc pl-5"><li v-for="(e, i) in errores" :key="i"><span class="cg-mono">{{ e.codigo }}</span> <span v-if="e.ruta" class="cg-sub">[{{ e.ruta }}]</span> — {{ e.mensaje }}</li></ul></div>
                    </div>
                    <div v-else-if="validado" class="cg-alert cg-alert--ok mt-4"><span class="icon-tick text-2xl" aria-hidden="true"></span><p>{{ t('config_valida') }}</p></div>

                    <div class="cg-foot mt-5">
                        <span class="flex items-center gap-2"><span class="icon-information text-xl" aria-hidden="true"></span><span>{{ t('pie') }}</span></span>
                        <span class="flex flex-wrap gap-2" v-if="editable">
                            <button type="button" class="cg-btn" :disabled="enviando" @click="guardar">{{ t('guardar_borrador') }}</button>
                            <button type="button" class="cg-btn" :disabled="enviando || !s.tipo_id" @click="validar">{{ t('validar_config') }}</button>
                            <button type="button" class="cg-btn" :disabled="enviando || !s.tipo_id" @click="revisar">{{ t('revisar_contrato') }}</button>
                            <button type="button" class="cg-btn cg-btn--primario" :disabled="enviando || !s.tipo_id" @click="activar">{{ t('activar') }}</button>
                        </span>
                    </div>
                </div>

                <!-- Revisión del contrato del procedimiento -->
                <div v-if="revision" class="cg-panel mt-4 p-5">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ t('revision_titulo') }}</h2>
                    <div class="cg-alert cg-alert--aviso mt-2"><span class="icon-information text-2xl" aria-hidden="true"></span><p>{{ revision.aviso }}</p></div>

                    <ul class="mt-3 grid gap-2 text-sm">
                        <li v-if="revision.hallazgos.length === 0" class="cg-sub">{{ t('sin_hallazgos') }}</li>
                        <li v-for="(h, i) in revision.hallazgos" :key="i" class="flex items-start gap-2">
                            <span class="cg-pill" :class="h.nivel === 'error' ? 'cg-pill--rojo' : 'cg-pill--amarillo'">{{ h.nivel === 'error' ? t('error') : t('advertencia') }}</span>
                            <span><span class="cg-mono">{{ h.codigo }}</span> — {{ h.mensaje }}</span>
                        </li>
                    </ul>
                    <p v-if="revision.tablas_escritas && revision.tablas_escritas.length" class="cg-sub mt-3">{{ t('tablas_escritas') }}: <span class="cg-mono">{{ revision.tablas_escritas.join(', ') }}</span></p>

                    <p class="mt-3 text-sm">
                        <span v-if="s.revision_vigente" class="cg-pill cg-pill--verde">{{ t('revision_confirmada') }} · {{ s.revision_por }}</span>
                        <span v-else class="cg-pill cg-pill--amarillo">{{ t('revision_sin_confirmar') }}</span>
                    </p>
                </div>
            </div>
        </script>
        @endverbatim

        <script type="module">
            app.component('v-cargues-tipo-form', {
                template: '#v-cargues-tipo-form-template',

                data() {
                    const cfg = window.__carguesTipo;
                    const e = cfg.estado;

                    return {
                        cfg, s: e, rutas: cfg.rutas,
                        f: {
                            nombre: e.nombre, descripcion: e.descripcion, conexion: e.conexion, procedimiento: e.procedimiento, accion: e.accion,
                            politica: e.politica, map: e.map, limites: Object.assign({ max_bytes: null, max_celdas: null, max_filas: null }, e.limites || {}),
                        },
                        revision: e.revision, errores: [], validado: false, mensaje: null, enviando: false,
                    };
                },

                computed: {
                    editable() { return !this.s.estado || this.s.estado === 'borrador'; },
                },

                methods: {
                    t(k) { return this.cfg.t[k] || k; },

                    cuerpo() {
                        const limites = {};
                        Object.keys(this.f.limites).forEach(k => { if (this.f.limites[k]) { limites[k] = this.f.limites[k]; } });

                        return {
                            conexion: this.f.conexion, procedimiento: this.f.procedimiento, accion: this.f.accion,
                            politica_errores: this.f.politica || null, limites: Object.keys(limites).length ? limites : null, map: this.f.map,
                        };
                    },

                    llamar(promesa, titulo) {
                        this.enviando = true;
                        this.mensaje = null;

                        return promesa.catch(e => {
                            const d = e.response && e.response.data;
                            this.mensaje = { tipo: 'error', titulo: titulo || this.t('error_generico'), texto: (d && d.mensaje) || this.t('error_generico') };
                            this.errores = (d && d.detalles && d.detalles.length && d.detalles[0].mensaje) ? d.detalles : this.errores;

                            return null;
                        }).finally(() => { this.enviando = false; });
                    },

                    guardar() {
                        const crear = !this.s.tipo_id;
                        const datos = Object.assign({ nombre: this.f.nombre, descripcion: this.f.descripcion }, this.cuerpo());
                        const p = crear ? this.$axios.post(this.rutas.crear, datos) : this.$axios.put(this.rutas.base + '/borrador', datos);

                        this.llamar(p, this.t('no_se_guardo')).then(r => {
                            if (!r) { return; }
                            if (crear) { window.location = r.data.url; return; }
                            this.errores = r.data.errores || [];
                            this.validado = this.errores.length === 0;
                            this.revision = null;
                            this.s.revision_vigente = false;
                            this.mensaje = { tipo: 'ok', titulo: this.t('borrador_guardado') };
                        });
                    },

                    validar() {
                        this.llamar(this.$axios.post(this.rutas.base + '/validar'), this.t('no_se_valido')).then(r => { if (r) { this.errores = r.data.errores; this.validado = r.data.errores.length === 0; } });
                    },

                    revisar() {
                        this.llamar(this.$axios.post(this.rutas.base + '/confirmar-contrato'), this.t('no_se_reviso')).then(r => {
                            if (!r) { return; }
                            this.revision = r.data.revision;
                            const hayError = r.data.revision.hallazgos.some(h => h.nivel === 'error');
                            this.s.revision_vigente = !hayError;
                            this.s.revision_por = 'tú';
                            this.mensaje = hayError ? { tipo: 'error', titulo: this.t('contrato_con_errores') } : { tipo: 'ok', titulo: this.t('contrato_confirmado'), texto: this.t('contrato_confirmado_ayuda') };
                        });
                    },

                    activar() {
                        if (!window.confirm(this.t('confirmar_activar'))) { return; }

                        this.llamar(this.$axios.post(this.rutas.base + '/activar'), this.t('no_se_activo')).then(r => { if (r) { window.location = this.rutas.lista; } });
                    },

                    nuevaVersion() {
                        this.llamar(this.$axios.put(this.rutas.base + '/borrador', {}), this.t('no_se_guardo')).then(r => { if (r) { window.location.reload(); } });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
