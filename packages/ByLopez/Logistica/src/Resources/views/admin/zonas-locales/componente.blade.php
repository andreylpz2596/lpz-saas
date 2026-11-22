{{--
    ZONAS LOCALES Y TARIFAS (una sola pantalla): bodega de origen → ciudades locales → tarifas del operador en la ciudad elegida.

    Es una capa de presentacion. El estado sale de `ZonasLocalesService::estado()` y cada cambio (agregar/quitar ciudad, nueva tarifa) llama
    al servidor, que vuelve a validar permiso, bodega, municipio (catalogo), operador, valor y vigencia; aqui solo se ahorran viajes.
    Nada se calcula en el navegador: las tarifas son SUGERIDAS (la operaria ajusta el costo al despachar) y nunca se editan: un cambio
    es una vigencia nueva. El buscador consulta de a 15 municipios; nunca se envia el catalogo completo.
--}}
@include('corteconfeccion::admin.partials.moneda')

<v-logistica-zonas-locales
    :inicial='@json($estado, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)'
    :puede-gestionar='@json($puedeGestionar)'
    url-municipios="{{ route('admin.logistica.zonas_locales.municipios') }}"
    url-agregar="{{ route('admin.logistica.zonas_locales.ciudades.agregar', ['origen' => '__O__']) }}"
    url-quitar="{{ route('admin.logistica.zonas_locales.ciudades.quitar', ['origen' => '__O__', 'zona' => '__Z__']) }}"
    url-tarifa="{{ route('admin.logistica.zonas_locales.tarifas.store', ['origen' => '__O__']) }}"
    :textos='@json(trans('logistica::app.zonas_locales'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)'
></v-logistica-zonas-locales>

@pushOnce('styles')
    <style>
        .zl-card { padding: 16px; }
        .zl-card h2 { margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
        .zl-grid { display: grid; grid-template-columns: minmax(0, 340px) minmax(0, 1fr); gap: 16px; align-items: start; margin-top: 16px; }
        .zl-col { display: grid; gap: 16px; min-width: 0; }
        .zl-bodega { display: flex; flex-wrap: wrap; align-items: end; gap: 12px 20px; margin-top: 16px; }
        .zl-bodega .zl-campo { min-width: min(100%, 320px); }
        .zl-lugar { font-size: 12px; color: #64748b; }
        .zl-lista { display: grid; gap: 6px; margin: 10px 0 0; padding: 0; list-style: none; }
        .zl-ciudad { display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 4px 4px 4px 4px; }
        .zl-ciudad--sel { border-color: #2563eb; background: #eff6ff; }
        .zl-ciudad-btn { flex: 1; min-width: 0; min-height: 44px; border: 0; background: transparent; padding: 6px 10px; text-align: left; cursor: pointer; border-radius: 8px; font: inherit; color: #0f172a; }
        .zl-ciudad-btn:focus-visible, .zl-opcion:focus-visible, .zl-chip:focus-visible { outline: 2px solid #2563eb; outline-offset: 1px; }
        .zl-ciudad-nombre { display: block; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
        .zl-ciudad-sub { display: block; font-size: 12px; color: #64748b; }
        .zl-ciudad-fin { display: flex; align-items: center; gap: 6px; }
        .zl-quitar { min-height: 36px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; padding: 4px 10px; font-size: 12px; font-weight: 600; color: #be123c; cursor: pointer; }
        .zl-quitar:hover { background: #fff1f2; }
        .zl-confirma { margin-top: 6px; border: 1px solid #fda4af; border-radius: 10px; background: #fff1f2; padding: 10px; font-size: 12px; color: #881337; }
        .zl-confirma-acc { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .zl-buscar { position: relative; margin-top: 8px; }
        .zl-opciones { position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 4px); max-height: 260px; overflow-y: auto; margin: 0; padding: 4px; list-style: none; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; box-shadow: 0 8px 24px rgba(15, 23, 42, .12); }
        .zl-opcion { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 40px; padding: 6px 10px; border-radius: 8px; font-size: 13px; cursor: pointer; }
        .zl-opcion--act, .zl-opcion:hover { background: #eff6ff; }
        .zl-opcion--off { cursor: not-allowed; color: #94a3b8; background: #f8fafc; }
        .zl-opcion-vacia { padding: 10px; font-size: 13px; color: #64748b; }
        .zl-elegida { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; border: 1px solid #bfdbfe; border-radius: 10px; background: #eff6ff; padding: 8px 10px; font-size: 13px; }
        .zl-elegida b { overflow-wrap: anywhere; }
        .zl-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 12px; }
        .zl-filtros { display: grid; grid-template-columns: repeat(2, minmax(0, 220px)); gap: 10px; margin-top: 12px; }
        .zl-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
        .zl-tabla th, .zl-tabla td { padding: 10px 8px; text-align: left; vertical-align: middle; }
        .zl-tabla th { color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f8fafc; }
        .zl-tabla tbody tr { border-top: 1px solid #f1f5f9; }
        .zl-tabla .zl-num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
        .zl-tabla-wrap { margin-top: 12px; overflow-x: auto; }
        .zl-tarjetas { display: none; margin-top: 12px; gap: 8px; }
        .zl-tarjeta { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 10px 12px; }
        .zl-tarjeta-cab { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .zl-tarjeta-valor { font-size: 16px; font-weight: 800; font-variant-numeric: tabular-nums; color: #0f172a; }
        .zl-tarjeta-fechas { margin-top: 4px; font-size: 12px; color: #64748b; }
        .zl-form { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
        .zl-campo label { display: block; margin-bottom: 3px; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
        .zl-input { width: 100%; min-height: 40px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; padding: 8px 10px; font-size: 16px; color: #0f172a; }
        .zl-input:focus { outline: 2px solid #2563eb; outline-offset: 0; border-color: #2563eb; }
        .zl-sep { margin: 16px 0 0; padding-top: 14px; border-top: 1px solid #f1f5f9; }
        .zl-msg { margin-top: 10px; border-radius: 10px; padding: 8px 12px; font-size: 13px; }
        .zl-msg--ok { border: 1px solid #86efac; background: #f0fdf4; color: #166534; }
        .zl-msg--err { border: 1px solid #fda4af; background: #fff1f2; color: #9f1239; }
        .zl-vacio { margin-top: 12px; font-size: 13px; color: #64748b; }
        .zl-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; border: 1px solid #1d4ed8; border-radius: 8px; background: #2563eb; padding: 8px 16px; font-size: 14px; font-weight: 600; color: #fff; cursor: pointer; }
        .zl-btn:hover { background: #1d4ed8; }
        .zl-btn:disabled { opacity: .55; cursor: not-allowed; }
        .zl-btn--sec { border-color: #cbd5e1; background: #fff; color: #334155; }
        .zl-btn--sec:hover { background: #f1f5f9; }
        .zl-btn--no { border-color: #e11d48; background: #e11d48; }
        .zl-btn--no:hover { background: #be123c; }
        @media (max-width: 1100px) {
            .zl-grid { grid-template-columns: minmax(0, 1fr); }
            .zl-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .zl-tabla-wrap { display: none; }
            .zl-tarjetas { display: grid; }
            .zl-card { padding: 12px; }
            .zl-filtros, .zl-form { grid-template-columns: minmax(0, 1fr); }
            .zl-bodega .zl-campo { min-width: 100%; }
        }
    </style>
@endPushOnce

@pushOnce('scripts')
    <script type="text/x-template" id="v-logistica-zonas-locales-template">
        <div data-zonas-locales>
            <p v-if="! bodegas.length" class="lg-panel mt-4 p-3 text-sm" data-sin-bodegas>@{{ textos['sin-bodegas'] }}</p>

            <template v-else>
                <div class="zl-bodega" data-bloque="bodega">
                    <div class="zl-campo" v-if="bodegas.length > 1">
                        <label for="zl-bodega">@{{ textos.bodega }}</label>
                        <select id="zl-bodega" class="zl-input" v-model.number="bodegaId" data-campo="bodega">
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">@{{ b.nombre }}</option>
                        </select>
                    </div>
                    <div v-else>
                        <span class="lg-caption">@{{ textos.bodega }}</span>
                        <p class="m-0 text-base font-bold text-gray-900 dark:text-white" data-bodega-nombre>@{{ bodega.nombre }}</p>
                    </div>
                    <span v-if="bodega.lugar" class="zl-lugar">@{{ bodega.lugar }}</span>
                </div>

                <p v-if="! puedeGestionar" class="lg-nota-fina mt-2" data-solo-lectura>@{{ textos['solo-lectura'] }}</p>

                <div v-if="mensaje" class="zl-msg" :class="mensaje.tipo === 'ok' ? 'zl-msg--ok' : 'zl-msg--err'" role="status" aria-live="polite" data-mensaje>@{{ mensaje.texto }}</div>

                <div class="zl-grid">
                    {{-- Columna izquierda: ciudades locales de la bodega + agregar --}}
                    <div class="zl-col">
                        <section class="lg-panel zl-card" data-bloque="ciudades">
                            <h2>@{{ textos.ciudades }}</h2>
                            <p class="lg-sub">@{{ textos['ciudades-ayuda'] }}</p>

                            <p v-if="! bodega.ciudades.length" class="zl-vacio" data-sin-ciudades>@{{ textos['sin-ciudades'] }}</p>

                            <ul v-else class="zl-lista" role="list">
                                <li v-for="c in bodega.ciudades" :key="c.zona_id" role="listitem">
                                    <div class="zl-ciudad" :class="{ 'zl-ciudad--sel': c.zona_id === zonaId }" :data-ciudad="c.zona_id">
                                        <button type="button" class="zl-ciudad-btn" :aria-pressed="c.zona_id === zonaId ? 'true' : 'false'" @click="elegirCiudad(c.zona_id)">
                                            <span class="zl-ciudad-nombre">@{{ c.nombre }}</span>
                                            <span class="zl-ciudad-sub" v-if="c.departamento">@{{ c.departamento }}</span>
                                        </button>
                                        <span class="zl-ciudad-fin">
                                            <span class="lg-pill lg-pill--verde">@{{ textos.activa }}</span>
                                            <button v-if="puedeGestionar" type="button" class="zl-quitar" data-accion="quitar" :aria-label="textos.quitar + ' ' + c.nombre" @click="pedirQuitar(c)">@{{ textos.quitar }}</button>
                                        </span>
                                    </div>
                                    <div v-if="quitando && quitando.zona_id === c.zona_id" class="zl-confirma" role="alertdialog" :aria-label="textos.quitar + ' ' + c.nombre" data-confirmar-quitar>
                                        <p class="m-0">@{{ textos['quitar-aviso'] }}</p>
                                        <div class="zl-confirma-acc">
                                            <button type="button" class="zl-btn zl-btn--no" :disabled="enviando" data-accion="quitar-confirmar" @click="quitar(c)">@{{ enviando ? textos.guardando : textos['quitar-confirmar'] }}</button>
                                            <button type="button" class="zl-btn zl-btn--sec" :disabled="enviando" @click="quitando = null">@{{ textos.cancelar }}</button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <section v-if="puedeGestionar" class="lg-panel zl-card" data-bloque="agregar">
                            <h2 id="zl-agregar-titulo">@{{ textos['agregar-titulo'] }}</h2>
                            <div class="zl-buscar">
                                <input type="text" class="zl-input" role="combobox" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="60"
                                    aria-autocomplete="list" aria-labelledby="zl-agregar-titulo" aria-controls="zl-opciones"
                                    :aria-expanded="abierto ? 'true' : 'false'" :aria-activedescendant="activa >= 0 ? 'zl-op-' + activa : null"
                                    :placeholder="textos['buscar-placeholder']" v-model="texto" data-campo="buscar"
                                    @input="alEscribir" @keydown="alTeclear" @focus="abierto = texto.trim().length >= 2" @blur="cerrarConRetraso">
                                <ul v-if="abierto" id="zl-opciones" class="zl-opciones" role="listbox" data-opciones>
                                    <li v-if="buscando" class="zl-opcion-vacia" data-estado="buscando">@{{ textos.buscando }}</li>
                                    <li v-else-if="texto.trim().length < 2" class="zl-opcion-vacia">@{{ textos['buscar-minimo'] }}</li>
                                    <li v-else-if="! resultados.length" class="zl-opcion-vacia" data-estado="sin-resultados">@{{ textos['sin-resultados'] }}</li>
                                    <template v-else>
                                        <li v-for="(r, i) in resultados" :key="r.municipio_codigo" :id="'zl-op-' + i" role="option" class="zl-opcion"
                                            :class="{ 'zl-opcion--act': i === activa, 'zl-opcion--off': yaHabilitada(r) }" :aria-selected="i === activa ? 'true' : 'false'"
                                            :aria-disabled="yaHabilitada(r) ? 'true' : 'false'" :data-municipio="r.municipio_codigo"
                                            @mousedown.prevent="elegirResultado(r)">
                                            <span>@{{ r.etiqueta }}</span>
                                            <span v-if="yaHabilitada(r)" class="lg-pill lg-pill--gris">@{{ textos['ya-habilitada'] }}</span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <div v-if="elegida" class="zl-elegida" data-elegida>
                                <b>@{{ elegida.etiqueta }}</b>
                                <button type="button" class="zl-btn" :disabled="enviando" data-accion="agregar" @click="agregar">@{{ enviando ? textos.guardando : textos.agregar }}</button>
                                <button type="button" class="zl-btn zl-btn--sec" :disabled="enviando" @click="elegida = null">@{{ textos.cancelar }}</button>
                            </div>
                        </section>
                    </div>

                    {{-- Columna derecha: tarifas de la ciudad elegida + nueva tarifa --}}
                    <section class="lg-panel zl-card" data-bloque="tarifas">
                        <template v-if="ciudad">
                            <div class="zl-cab">
                                <h2 data-titulo-tarifas>@{{ t(textos['tarifas-de'], { ciudad: ciudad.nombre }) }}</h2>
                                <span class="lg-sub" v-if="ciudad.departamento">@{{ ciudad.departamento }}</span>
                            </div>
                            <p class="lg-sub">@{{ textos['tarifas-ayuda'] }}</p>

                            <div class="zl-filtros">
                                <div class="zl-campo">
                                    <label for="zl-f-operador">@{{ textos['filtro-operador'] }}</label>
                                    <select id="zl-f-operador" class="zl-input" v-model.number="filtroOperador" data-filtro="operador">
                                        <option :value="0">@{{ textos.todos }}</option>
                                        <option v-for="o in operadoresConTarifa" :key="o.id" :value="o.id">@{{ o.nombre }}</option>
                                    </select>
                                </div>
                                <div class="zl-campo">
                                    <label for="zl-f-estado">@{{ textos['filtro-estado'] }}</label>
                                    <select id="zl-f-estado" class="zl-input" v-model="filtroEstado" data-filtro="estado">
                                        <option value="">@{{ textos.todos }}</option>
                                        <option value="vigente">@{{ textos.vigente }}</option>
                                        <option value="programada">@{{ textos.programada }}</option>
                                        <option value="historica">@{{ textos.historica }}</option>
                                    </select>
                                </div>
                            </div>

                            <p v-if="! tarifasFiltradas.length" class="zl-vacio" data-sin-tarifas>@{{ textos['sin-tarifas'] }}</p>

                            <template v-else>
                                <div class="zl-tabla-wrap">
                                    <table class="zl-tabla" data-tarifas>
                                        <thead>
                                            <tr>
                                                <th>@{{ textos['col-operador'] }}</th>
                                                <th class="zl-num">@{{ textos['col-tarifa'] }}</th>
                                                <th>@{{ textos['col-desde'] }}</th>
                                                <th>@{{ textos['col-hasta'] }}</th>
                                                <th>@{{ textos['col-estado'] }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="f in tarifasFiltradas" :key="f.id" :data-tarifa="f.id" :data-estado="f.estado">
                                                <td>@{{ f.operador }} <span v-if="! f.operador_activo" class="lg-sub">(@{{ textos.inactivo }})</span></td>
                                                <td class="zl-num">@{{ dinero(f.valor) }}</td>
                                                <td>@{{ fecha(f.desde) }}</td>
                                                <td>@{{ f.hasta ? fecha(f.hasta) : '—' }}</td>
                                                <td><span class="lg-pill" :class="colorEstado(f.estado)">@{{ textos[f.estado] }}</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="zl-tarjetas">
                                    <article v-for="f in tarifasFiltradas" :key="'m' + f.id" class="zl-tarjeta" :data-tarifa-movil="f.id">
                                        <div class="zl-tarjeta-cab">
                                            <b>@{{ f.operador }}</b>
                                            <span class="lg-pill" :class="colorEstado(f.estado)">@{{ textos[f.estado] }}</span>
                                        </div>
                                        <div class="zl-tarjeta-valor">@{{ dinero(f.valor) }}</div>
                                        <div class="zl-tarjeta-fechas">@{{ textos['col-desde'] }} @{{ fecha(f.desde) }}<template v-if="f.hasta"> · @{{ textos['col-hasta'] }} @{{ fecha(f.hasta) }}</template></div>
                                    </article>
                                </div>
                            </template>

                            <form v-if="puedeGestionar" class="zl-sep" data-nueva-tarifa-local novalidate @submit.prevent="crearTarifa">
                                <h2>@{{ textos['nueva-tarifa'] }}</h2>
                                <p v-if="! operadores.length" class="zl-vacio">@{{ textos['sin-operadores'] }}</p>
                                <div v-else class="zl-form">
                                    <div class="zl-campo">
                                        <label for="zl-n-operador">@{{ textos.operador }}</label>
                                        <select id="zl-n-operador" class="zl-input" v-model.number="nueva.operador_id" required data-campo="operador">
                                            <option :value="0" disabled>—</option>
                                            <option v-for="o in operadores" :key="o.id" :value="o.id">@{{ o.nombre }}</option>
                                        </select>
                                    </div>
                                    <div class="zl-campo">
                                        <label for="zl-n-destino">@{{ textos.destino }}</label>
                                        <select id="zl-n-destino" class="zl-input" v-model.number="nueva.zona_id" required data-campo="destino">
                                            <option v-for="c in bodega.ciudades" :key="c.zona_id" :value="c.zona_id">@{{ c.nombre }}</option>
                                        </select>
                                    </div>
                                    <div class="zl-campo">
                                        <label for="zl-n-valor">@{{ textos.valor }}</label>
                                        <v-moneda id="zl-n-valor" v-model="nueva.valor" :max-enteros="7" class="zl-input" data-campo="valor" :aria-label="textos.valor"></v-moneda>
                                    </div>
                                    <div class="zl-campo">
                                        <label for="zl-n-desde">@{{ textos.vigencia }}</label>
                                        <input id="zl-n-desde" type="date" class="zl-input" v-model="nueva.vigente_desde" :min="hoy" required data-campo="vigencia">
                                    </div>
                                </div>
                                <button type="submit" class="zl-btn mt-3" :disabled="enviando || ! operadores.length || ! tarifaValida" data-accion="crear-tarifa">@{{ enviando ? textos.guardando : textos['crear-tarifa'] }}</button>
                            </form>
                        </template>
                        <p v-else class="zl-vacio" data-sin-seleccion>@{{ textos['selecciona-ciudad'] }}</p>
                    </section>
                </div>
            </template>
        </div>
    </script>

    <script type="module">
        app.component('v-logistica-zonas-locales', {
            template: '#v-logistica-zonas-locales-template',

            props: ['inicial', 'puedeGestionar', 'urlMunicipios', 'urlAgregar', 'urlQuitar', 'urlTarifa', 'textos'],

            data() {
                const e = this.inicial || {};
                const bodegas = e.bodegas || [];

                return {
                    estado: e,
                    bodegaId: bodegas.length ? bodegas[0].id : null,
                    zonaId: bodegas.length && bodegas[0].ciudades.length ? bodegas[0].ciudades[0].zona_id : null,
                    filtroOperador: 0,
                    filtroEstado: '',
                    texto: '',
                    resultados: [],
                    abierto: false,
                    buscando: false,
                    activa: -1,
                    elegida: null,
                    quitando: null,
                    enviando: false,
                    mensaje: null,
                    nueva: { operador_id: 0, zona_id: null, valor: '', vigente_desde: (e.hoy || '') },
                    temporizador: null,
                    consulta: 0,
                };
            },

            computed: {
                bodegas() { return this.estado.bodegas || []; },
                bodega() { return this.bodegas.find(b => b.id === this.bodegaId) || { nombre: '', lugar: '', ciudades: [] }; },
                operadores() { return this.estado.operadores || []; },
                hoy() { return this.estado.hoy || ''; },
                ciudad() { return this.bodega.ciudades.find(c => c.zona_id === this.zonaId) || null; },

                tarifasDeCiudad() { return (this.estado.tarifas && this.estado.tarifas[this.zonaId]) || []; },

                operadoresConTarifa() {
                    const vistos = {};
                    this.tarifasDeCiudad.forEach(f => { vistos[f.operador_id] = f.operador; });

                    return Object.keys(vistos).map(id => ({ id: Number(id), nombre: vistos[id] }));
                },

                tarifasFiltradas() {
                    return this.tarifasDeCiudad.filter(f => (! this.filtroOperador || f.operador_id === this.filtroOperador) && (! this.filtroEstado || f.estado === this.filtroEstado));
                },

                tarifaValida() {
                    return this.nueva.operador_id > 0 && this.nueva.zona_id > 0 && /^[1-9]\d{0,6}$/.test(String(this.nueva.valor || '')) && /^\d{4}-\d{2}-\d{2}$/.test(this.nueva.vigente_desde || '');
                },
            },

            watch: {
                bodegaId() {
                    this.zonaId = this.bodega.ciudades.length ? this.bodega.ciudades[0].zona_id : null;
                    this.reiniciarBusqueda();
                    this.quitando = null;
                    this.mensaje = null;
                },

                zonaId(z) {
                    this.filtroOperador = 0;
                    this.filtroEstado = '';
                    this.nueva.zona_id = z;
                },
            },

            mounted() {
                this.nueva.zona_id = this.zonaId;
            },

            methods: {
                t(texto, datos) {
                    return String(texto).replace(/:(\w+)/g, (m, k) => (datos && datos[k] !== undefined ? datos[k] : m));
                },

                dinero(v) {
                    return window.ByLopezMoneda ? window.ByLopezMoneda.formatear(v) : '$ ' + Number(v).toLocaleString('es-CO');
                },

                fecha(iso) {
                    const p = String(iso || '').split('-');

                    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
                },

                colorEstado(e) {
                    return { vigente: 'lg-pill--verde', programada: 'lg-pill--azul', historica: 'lg-pill--gris' }[e] || 'lg-pill--gris';
                },

                elegirCiudad(id) {
                    this.zonaId = id;
                },

                yaHabilitada(r) {
                    return this.bodega.ciudades.some(c => c.municipio_codigo === r.municipio_codigo);
                },

                // ---- buscador (autocompletado) ----
                alEscribir() {
                    this.elegida = null;
                    this.activa = -1;
                    clearTimeout(this.temporizador);
                    const q = this.texto.trim();
                    this.abierto = q.length >= 2;

                    if (q.length < 2) {
                        this.resultados = [];
                        this.buscando = false;

                        return;
                    }

                    this.buscando = true;
                    this.temporizador = setTimeout(() => this.buscar(q), 250);
                },

                buscar(q) {
                    const n = ++this.consulta;

                    this.$axios.get(this.urlMunicipios, { params: { q } })
                        // Solo vale la respuesta de la ULTIMA consulta y del texto que sigue escrito (nunca una respuesta vieja).
                        .then(r => { if (n === this.consulta && q === this.texto.trim()) { this.resultados = r.data.resultados || []; this.buscando = false; this.activa = this.primeraLibre(0, 1); } })
                        .catch(() => { if (n === this.consulta) { this.resultados = []; this.buscando = false; } });
                },

                primeraLibre(desde, paso) {
                    for (let i = desde; i >= 0 && i < this.resultados.length; i += paso) {
                        if (! this.yaHabilitada(this.resultados[i])) {
                            return i;
                        }
                    }

                    return -1;
                },

                alTeclear(ev) {
                    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                        ev.preventDefault();
                        this.abierto = this.texto.trim().length >= 2;
                        const paso = ev.key === 'ArrowDown' ? 1 : -1;
                        const siguiente = this.primeraLibre(this.activa < 0 ? (paso > 0 ? 0 : this.resultados.length - 1) : this.activa + paso, paso);

                        if (siguiente >= 0) {
                            this.activa = siguiente;
                        }
                    } else if (ev.key === 'Enter') {
                        if (this.abierto && this.activa >= 0 && this.resultados[this.activa]) {
                            ev.preventDefault();
                            this.elegirResultado(this.resultados[this.activa]);
                        }
                    } else if (ev.key === 'Escape') {
                        this.abierto = false;
                    }
                },

                cerrarConRetraso() {
                    setTimeout(() => { this.abierto = false; }, 120);
                },

                elegirResultado(r) {
                    if (this.yaHabilitada(r)) {
                        return;
                    }

                    this.elegida = r;
                    this.texto = r.etiqueta;
                    this.abierto = false;
                },

                reiniciarBusqueda() {
                    this.texto = '';
                    this.resultados = [];
                    this.elegida = null;
                    this.abierto = false;
                    this.activa = -1;
                },

                // ---- cambios (el servidor valida de nuevo) ----
                url(plantilla, zona) {
                    return plantilla.replace('__O__', this.bodegaId).replace('__Z__', zona);
                },

                aplicar(r) {
                    this.estado = r.data.estado;
                    this.mensaje = { tipo: 'ok', texto: r.data.message };
                },

                fallo(e) {
                    const d = e && e.response && e.response.data;
                    this.mensaje = { tipo: 'err', texto: (d && d.message) || this.textos.error };
                },

                agregar() {
                    if (this.enviando || ! this.elegida) {
                        return;
                    }

                    this.enviando = true;
                    this.mensaje = null;

                    this.$axios.post(this.url(this.urlAgregar), { municipio_codigo: this.elegida.municipio_codigo })
                        .then(r => {
                            this.aplicar(r);
                            const nueva = this.bodega.ciudades.find(c => c.municipio_codigo === this.elegida.municipio_codigo);
                            this.zonaId = nueva ? nueva.zona_id : this.zonaId;
                            this.reiniciarBusqueda();
                        })
                        .catch(e => this.fallo(e))
                        .finally(() => { this.enviando = false; });
                },

                pedirQuitar(c) {
                    this.quitando = c;
                    this.mensaje = null;
                },

                quitar(c) {
                    if (this.enviando) {
                        return;
                    }

                    this.enviando = true;

                    this.$axios.post(this.url(this.urlQuitar, c.zona_id))
                        .then(r => {
                            this.aplicar(r);
                            this.quitando = null;

                            if (this.zonaId === c.zona_id) {
                                this.zonaId = this.bodega.ciudades.length ? this.bodega.ciudades[0].zona_id : null;
                            }
                        })
                        .catch(e => { this.fallo(e); this.quitando = null; })
                        .finally(() => { this.enviando = false; });
                },

                crearTarifa() {
                    if (this.enviando || ! this.tarifaValida) {
                        return;
                    }

                    this.enviando = true;
                    this.mensaje = null;

                    this.$axios.post(this.url(this.urlTarifa), {
                        operador_id: this.nueva.operador_id, zona_id: this.nueva.zona_id, valor: String(this.nueva.valor), vigente_desde: this.nueva.vigente_desde,
                    })
                        .then(r => {
                            this.aplicar(r);
                            this.zonaId = this.nueva.zona_id;
                            this.nueva.valor = '';
                        })
                        .catch(e => this.fallo(e))
                        .finally(() => { this.enviando = false; });
                },
            },
        });
    </script>
@endPushOnce
