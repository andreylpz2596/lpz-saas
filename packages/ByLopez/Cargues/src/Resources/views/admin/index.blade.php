@once
    @push('styles')
        @include('cargues::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('cargues::app.titulo')
    </x-slot>

    <div class="cg-page">
        <v-cargues-app></v-cargues-app>
    </div>

    @pushOnce('scripts')
        {{-- Configuración y textos desde el servidor (Blade). La plantilla de abajo está protegida de Blade: es solo Vue. --}}
        <script>
            window.__cargues = @json($config);
        </script>

        @verbatim
        <script type="text/x-template" id="v-cargues-app-template">
            <div>
                <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                    <div>
                        <h1 class="cg-title">{{ t('titulo') }}</h1>
                        <p class="cg-sub mt-1">{{ t('subtitulo') }}</p>
                    </div>

                    <a :href="cfg.rutas.configurar" class="cg-btn" style="border-color:#2563eb;color:#1d4ed8;">
                        <span class="icon-settings text-xl"></span> {{ t('configurar_tipos') }}
                    </a>
                </div>

                <div class="cg-tabs mt-4" role="tablist">
                    <button type="button" role="tab" class="cg-tab" :aria-selected="tab === 'nuevo'" @click="tab = 'nuevo'">{{ t('tab_nuevo') }}</button>
                    <button type="button" role="tab" class="cg-tab" :aria-selected="tab === 'historial'" @click="abrirHistorial">{{ t('tab_historial') }}</button>
                </div>

                <!-- ========================= NUEVO CARGUE ========================= -->
                <div v-show="tab === 'nuevo'" class="cg-layout mt-4">
                    <!-- Tipos de cargue -->
                    <div class="cg-panel p-4">
                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ t('tipos_titulo') }}</p>

                        <div class="relative mt-3">
                            <span class="icon-search pointer-events-none absolute left-3 top-2.5 text-xl text-gray-400"></span>
                            <input type="search" v-model="busqueda" class="cg-input" style="padding-left:38px" :placeholder="t('buscar_tipo')" :aria-label="t('buscar_tipo')">
                        </div>

                        <div v-if="tipos.length === 0" class="cg-vacio">
                            {{ t('sin_tipos') }}
                            <div class="mt-2"><a :href="cfg.rutas.configurar" class="cg-link">{{ t('configurar_tipos') }}</a></div>
                        </div>
                        <div v-else-if="tiposFiltrados.length === 0" class="cg-vacio">{{ t('sin_resultados') }}</div>

                        <div v-else class="mt-3 grid gap-3" role="radiogroup" :aria-label="t('tipos_titulo')">
                            <button v-for="tp in tiposFiltrados" :key="tp.id" type="button" role="radio" class="cg-card"
                                    :aria-checked="tipoId === tp.id" @click="elegirTipo(tp)">
                                <span class="cg-card-icon icon-product" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="cg-card-name block truncate">{{ tp.nombre }}</span>
                                    <span class="cg-card-desc block">{{ tp.descripcion || '' }}</span>
                                    <span class="cg-card-desc block" data-cg="version-tipo">{{ t('version_de').replace(':n', tp.version) }} · <span class="cg-mono">{{ tp.procedimiento }}</span></span>
                                </span>
                                <span class="cg-pill cg-pill--verde">{{ t('activo') }}</span>
                            </button>
                        </div>

                        <div class="cg-alert cg-alert--info mt-4">
                            <span class="icon-information text-2xl" aria-hidden="true"></span>
                            <div>
                                <p class="cg-alert-title">{{ t('antes_titulo') }}</p>
                                <p>{{ t('antes_texto') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Asistente -->
                    <div class="cg-panel">
                        <div class="cg-stepper" aria-label="Pasos">
                            <template v-for="(p, i) in pasos" :key="i">
                                <div v-if="i > 0" class="cg-step-line" :class="{ 'cg-step-line--hecho': paso > i }"></div>
                                <div class="cg-step" :class="{ 'cg-step--hecho': paso > i + 1, 'cg-step--activo': paso === i + 1 }" :aria-current="paso === i + 1 ? 'step' : null">
                                    <span class="cg-step-dot"><template v-if="paso > i + 1">&#10003;</template><template v-else>{{ i + 1 }}</template></span>
                                    <span class="max-sm:hidden">{{ (i === 0 || i === 1) ? (i + 1) + '. ' : '' }}{{ p }}</span>
                                </div>
                            </template>
                        </div>

                        <div class="p-5">
                            <div v-if="aviso" class="cg-alert cg-alert--error mb-4" role="alert">
                                <span class="icon-information text-2xl" aria-hidden="true"></span>
                                <div><p class="cg-alert-title">{{ aviso.titulo }}</p><p>{{ aviso.texto }}</p></div>
                            </div>

                            <!-- PASO 1: archivo -->
                            <div v-if="paso === 1">
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ tipo ? t('archivo_de') + ' ' + tipo.nombre : t('elija_tipo') }}</h2>

                                <div v-if="!tipo" class="cg-vacio">{{ t('elija_tipo_ayuda') }}</div>
                                <template v-else>
                                    <label class="cg-drop mt-4" :class="{ 'cg-drop--sobre': arrastrando }"
                                           @dragover.prevent="arrastrando = true" @dragleave.prevent="arrastrando = false" @drop.prevent="soltar">
                                        <span class="icon-folder text-4xl" aria-hidden="true"></span>
                                        <span class="font-semibold">{{ subiendo ? t('subiendo') : t('arrastre') }}</span>
                                        <span class="cg-sub">{{ t('formatos') }} · {{ t('maximo') }} {{ mb(tipo.limites.max_bytes) }} MB</span>
                                        <input type="file" class="sr-only" accept=".xlsx,.csv,.txt" :disabled="subiendo" @change="elegirArchivo">
                                    </label>
                                    <p v-if="subiendo" class="cg-sub mt-3"><span class="cg-spin"></span> {{ t('subiendo') }}</p>

                                    <div v-if="tipo.plantillas && tipo.plantillas.length" class="cg-alert cg-alert--info mt-4" data-cg="plantilla">
                                        <span class="icon-information text-2xl" aria-hidden="true"></span>
                                        <div>
                                            <p class="cg-alert-title">{{ t('plantilla_titulo') }}</p>
                                            <p>{{ t('plantilla_ayuda') }}</p>
                                            <span class="mt-2 flex flex-wrap gap-2">
                                                <a v-for="f in tipo.plantillas" :key="f" :href="plantillaUrl(f)" class="cg-btn">{{ t('plantilla_descargar') }} ({{ f.toUpperCase() }})</a>
                                            </span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- PASO 2: relacionar columnas -->
                            <div v-else-if="paso === 2 && carga">
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ t('archivo_de') }} {{ tipo ? tipo.nombre : '' }}</h2>

                                <div class="cg-file mt-3">
                                    <span class="cg-file-icon" :class="{ 'cg-file-icon--csv': carga.formato === 'csv' }">{{ carga.formato === 'csv' ? 'CSV' : 'X' }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-semibold text-gray-900 dark:text-white">{{ carga.archivo.nombre }}</p>
                                        <p class="cg-sub">{{ carga.formato.toUpperCase() }}<template v-if="carga.formato === 'xlsx' && lectura.hoja"> &bull; {{ t('hoja') }}: {{ lectura.hoja }}</template> &bull; {{ kb(carga.archivo.bytes) }}</p>
                                    </div>
                                    <button type="button" class="cg-btn" @click="cambiarArchivo"><span class="icon-repeat text-lg"></span> {{ t('cambiar_archivo') }}</button>
                                </div>

                                <div v-if="vistaPreviaPendiente" class="cg-alert cg-alert--info mt-4">
                                    <span class="cg-spin" aria-hidden="true"></span>
                                    <div><p class="cg-alert-title">{{ t('vp_preparando') }}</p><p>{{ t('vp_preparando_ayuda') }}</p></div>
                                </div>

                                <template v-else-if="columnas.length">
                                    <!-- Lectura -->
                                    <div class="cg-grid2 mt-4">
                                        <div v-if="carga.formato === 'xlsx'" class="cg-field">
                                            <label for="cg-hoja">{{ t('hoja_archivo') }}</label>
                                            <select id="cg-hoja" class="cg-input" v-model="lectura.hoja" @change="cambiarLectura">
                                                <option v-for="h in hojas" :key="h.nombre" :value="h.nombre">{{ h.nombre }}<template v-if="h.estado !== 'visible'"> ({{ t('oculta') }})</template></option>
                                            </select>
                                        </div>
                                        <div class="cg-field">
                                            <label for="cg-fila">{{ t('fila_encabezados') }}</label>
                                            <input id="cg-fila" type="number" min="1" max="1000" class="cg-input" v-model.number="lectura.fila_encabezado" @change="cambiarLectura">
                                        </div>
                                        <template v-if="carga.formato === 'csv'">
                                            <div class="cg-field">
                                                <label for="cg-delim">{{ t('delimitador') }}</label>
                                                <select id="cg-delim" class="cg-input" v-model="lectura.delimitador" @change="cambiarLectura">
                                                    <option value=";">{{ t('punto_y_coma') }} ( ; )</option><option value=",">{{ t('coma') }} ( , )</option>
                                                    <option value="&#9;">{{ t('tabulador') }}</option><option value="|">{{ t('barra') }} ( | )</option>
                                                </select>
                                            </div>
                                            <div class="cg-field">
                                                <label for="cg-encl">{{ t('entrecomillado') }}</label>
                                                <select id="cg-encl" class="cg-input" v-model="lectura.entrecomillado" @change="cambiarLectura">
                                                    <option value='"'>{{ t('comillas_dobles') }} ( " )</option><option value="'">{{ t('comillas_simples') }} ( ' )</option>
                                                </select>
                                            </div>
                                            <div class="cg-field">
                                                <label for="cg-cod">{{ t('codificacion') }}</label>
                                                <select id="cg-cod" class="cg-input" v-model="lectura.codificacion" @change="cambiarLectura">
                                                    <option value="UTF-8">UTF-8</option><option value="Windows-1252">Windows-1252 (Excel)</option><option value="ISO-8859-1">ISO-8859-1</option>
                                                </select>
                                                <p v-if="carga.encabezados.inspeccion.codificacion && carga.encabezados.inspeccion.codificacion !== lectura.codificacion" class="cg-sub mt-1">{{ t('se_detecto') }} {{ carga.encabezados.inspeccion.codificacion }}.</p>
                                            </div>
                                        </template>
                                    </div>

                                    <div v-for="(a, i) in advertencias" :key="'adv' + i" class="cg-alert cg-alert--aviso mt-3"><span class="icon-information text-xl" aria-hidden="true"></span><p>{{ a }}</p></div>

                                    <!-- Acción (solo si el tipo ofrece más de una) -->
                                    <div v-if="tipo && tipo.acciones.length > 1" class="cg-field mt-4" data-cg="accion">
                                        <label for="cg-accion">{{ t('accion_cargue') }}</label>
                                        <select id="cg-accion" class="cg-input" v-model="accionElegida">
                                            <option v-for="a in tipo.acciones" :key="a" :value="a">{{ (tipo.acciones_etiquetas && tipo.acciones_etiquetas[a]) || a }}</option>
                                        </select>
                                    </div>

                                    <hr class="my-5 border-gray-200 dark:border-gray-700">

                                    <!-- Relación -->
                                    <div class="flex items-start justify-between gap-3 max-sm:flex-wrap">
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ t('relacionar') }}</h3>
                                            <p class="cg-sub">{{ t('relacionar_ayuda') }}</p>
                                        </div>
                                        <span class="cg-pill" :class="faltanObligatorios.length ? 'cg-pill--amarillo' : 'cg-pill--verde'" aria-live="polite">{{ relacionados }} {{ t('de') }} {{ campos.length }} {{ t('campos_relacionados') }}</span>
                                    </div>

                                    <div class="cg-table-wrap mt-3">
                                        <table class="cg-table">
                                            <thead><tr><th>{{ t('campo_destino') }}</th><th>{{ t('columna_archivo') }}</th><th>{{ t('ejemplo') }}</th><th class="w-10"><span class="sr-only">{{ t('relacionado') }}</span></th></tr></thead>
                                            <tbody>
                                                <tr v-for="c in campos" :key="c.nombre">
                                                    <td class="font-semibold text-gray-900 dark:text-white">{{ c.etiqueta }}<span v-if="obligatorio(c)" class="cg-req" :title="t('obligatorio')" aria-label="obligatorio">*</span></td>
                                                    <td>
                                                        <select class="cg-input" v-model="relaciones[c.nombre]" :aria-label="t('columna_archivo') + ': ' + c.etiqueta">
                                                            <option value="">{{ obligatorio(c) ? t('elija_columna') : t('sin_relacionar') }}</option>
                                                            <option v-for="col in columnas" :key="col.indice" :value="col.indice" :disabled="usadaPorOtro(col.indice, c.nombre)">
                                                                {{ col.letra }} &middot; {{ col.texto || t('sin_encabezado') }}{{ col.ambiguo ? ' ⚠ ' + t('repetido') : (col.similar ? ' ≈ ' + t('parecido') : '') }}{{ usadaPorOtro(col.indice, c.nombre) ? ' (' + t('en_uso') + ')' : '' }}
                                                            </option>
                                                        </select>
                                                    </td>
                                                    <td><span class="cg-ejemplo" :class="{ 'cg-ejemplo--vacio': !ejemploDe(c) }" :title="ejemploDe(c) || ''">{{ ejemploDe(c) || (esRelacionado(c) ? t('sin_ejemplo') : '—') }}</span></td>
                                                    <td><span class="cg-check" :class="{ 'cg-check--no': !esRelacionado(c) }" :title="esRelacionado(c) ? t('relacionado') : t('sin_relacionar')">{{ esRelacionado(c) ? '✓' : '' }}</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <button type="button" class="cg-link" @click="abrirMapeos"><span class="icon-list text-lg" aria-hidden="true"></span> {{ t('usar_mapeo') }}</button>
                                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" v-model="guardarComparacion"> {{ t('guardar_relacion') }}</label>
                                    </div>

                                    <div v-if="guardarComparacion" class="cg-field mt-2"><label for="cg-nombre-comp">{{ t('nombre_relacion') }}</label><input id="cg-nombre-comp" class="cg-input" maxlength="191" v-model="nombreComparacion"></div>

                                    <div v-if="mostrarMapeos" class="cg-panel mt-3 overflow-hidden">
                                        <p class="px-3 py-2 text-sm font-bold">{{ t('mapeos_guardados') }}</p>
                                        <p v-if="comparaciones === null" class="cg-vacio"><span class="cg-spin"></span></p>
                                        <p v-else-if="comparaciones.length === 0" class="cg-vacio">{{ t('sin_mapeos') }}</p>
                                        <div v-for="m in comparaciones" :key="m.id" class="cg-list-row">
                                            <span>{{ m.nombre }} <span class="cg-sub">· {{ m.creada_por }} · {{ m.fecha }}</span>
                                                <span v-if="!m.mismos_encabezados" class="cg-pill cg-pill--amarillo ml-1">{{ t('encabezados_distintos') }}</span></span>
                                            <button type="button" class="cg-link" @click="verMapeo(m)">{{ t('ver_diferencias') }}</button>
                                        </div>
                                        <div v-if="diffMapeo" class="border-t border-gray-200 p-3 text-sm dark:border-gray-700">
                                            <p class="font-semibold">{{ diffMapeo.comparacion.nombre }}</p>
                                            <ul class="mt-1 list-disc pl-5">
                                                <li v-for="(r, campo) in diffMapeo.relaciones" :key="campo">{{ etiquetaCampo(campo) }}:
                                                    <template v-if="r.estado === 'igual'">{{ t('mapeo_igual') }}</template>
                                                    <template v-else-if="r.estado === 'movida'">{{ t('mapeo_movida') }} ({{ letraDe(r.antes) }} → {{ letraDe(r.indice) }})</template>
                                                    <template v-else-if="r.estado === 'no_existe'"><strong>{{ t('mapeo_no_existe') }}</strong> «{{ r.texto }}»</template>
                                                    <template v-else>{{ t('sin_relacionar') }}</template>
                                                </li>
                                            </ul>
                                            <button type="button" class="cg-btn cg-btn--primario mt-3" @click="aplicarMapeo">{{ t('aplicar_mapeo') }}</button>
                                        </div>
                                    </div>

                                    <div class="cg-foot mt-5">
                                        <span class="flex items-center gap-2"><span class="icon-information text-xl" aria-hidden="true"></span>
                                            <span v-if="razonNoValidar">{{ razonNoValidar }}</span>
                                            <span v-else>{{ t('pie_pendiente') }}</span>
                                        </span>
                                        <span class="flex gap-2">
                                            <button type="button" class="cg-btn" @click="cancelar">{{ t('cancelar') }}</button>
                                            <button type="button" class="cg-btn cg-btn--primario" :disabled="!!razonNoValidar || enviando" @click="validar"><span class="icon-tick text-lg"></span> {{ t('validar_archivo') }}</button>
                                        </span>
                                    </div>
                                </template>
                            </div>

                            <!-- PASO 3: validar (progreso, error o resumen) -->
                            <div v-else-if="paso >= 3 && carga">
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ carga.archivo.nombre }}</h2>
                                <p class="cg-sub">{{ tipo ? tipo.nombre : '' }} · {{ t('carga_n') }} {{ carga.id }}<template v-if="carga.version"> · <span data-cg="version-carga">{{ t('cabecera_version').replace(':n', carga.version.numero).replace(':p', carga.version.procedimiento) }}</span></template><template v-if="tipo && tipo.acciones.length > 1"> · <span data-cg="accion-carga">{{ t('accion_cargue') }}: {{ (tipo.acciones_etiquetas && tipo.acciones_etiquetas[carga.accion]) || carga.accion }}</span></template></p>

                                <!-- En cola / en proceso -->
                                <div v-if="progreso && progreso.en_curso" class="mt-4" aria-live="polite">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="flex items-center gap-2 font-semibold"><span class="cg-spin"></span> {{ etapaTexto }}</span>
                                        <span v-if="progreso.porcentaje !== null">{{ progreso.porcentaje }} %</span>
                                    </div>
                                    <div class="cg-bar mt-2" role="progressbar" :aria-valuenow="progreso.porcentaje || 0" aria-valuemin="0" aria-valuemax="100"><div :style="{ width: (progreso.porcentaje || (progreso.en_cola ? 3 : 10)) + '%' }"></div></div>
                                    <p class="cg-sub mt-2">
                                        <template v-if="progreso.filas_total">{{ progreso.filas_procesadas }} / {{ progreso.filas_total }} {{ t('filas') }} · </template>
                                        <template v-if="progreso.en_cola">{{ t('en_cola_desde') }} {{ progreso.en_cola_segundos }} s · </template>
                                        {{ t('puede_salir') }}
                                    </p>
                                    <div v-if="progreso.en_cola && progreso.en_cola_segundos > 45" class="cg-alert cg-alert--aviso mt-3"><span class="icon-information text-xl" aria-hidden="true"></span><p>{{ t('sin_worker') }}</p></div>
                                    <div v-if="progreso.sin_noticias" class="cg-alert cg-alert--aviso mt-3">
                                        <span class="icon-information text-xl" aria-hidden="true"></span>
                                        <div><p class="cg-alert-title">{{ t('sin_noticias') }}</p><p>{{ progreso.en_ejecucion ? t('sin_noticias_ejecucion') : t('sin_noticias_ayuda') }}</p>
                                            <button type="button" class="cg-btn mt-2" @click="progreso.en_ejecucion ? recuperar() : reintentar()">{{ progreso.en_ejecucion ? t('recuperar') : t('reintentar') }}</button></div>
                                    </div>
                                    <div v-else-if="progreso.puede_recuperar" class="cg-alert cg-alert--aviso mt-3">
                                        <span class="icon-information text-xl" aria-hidden="true"></span>
                                        <div><p>{{ progreso.mensaje }}</p><button type="button" class="cg-btn mt-2" @click="recuperar">{{ t('recuperar') }}</button></div>
                                    </div>
                                </div>

                                <!-- Falló -->
                                <div v-else-if="carga.estado.startsWith('fallida')" class="mt-4">
                                    <div class="cg-alert cg-alert--error" role="alert">
                                        <span class="icon-information text-2xl" aria-hidden="true"></span>
                                        <div><p class="cg-alert-title">{{ t('fallo_' + carga.estado) }}</p><p>{{ carga.mensaje }}</p></div>
                                    </div>
                                    <div class="mt-3 flex gap-2 flex-wrap">
                                        <template v-if="carga.estado === 'fallida_ejecucion'">
                                            <button type="button" class="cg-btn cg-btn--primario" :disabled="enviando || !resumen || !resumen.puede_ejecutar" @click="ejecutar">{{ t('ejecutar_de_nuevo') }}</button>
                                        </template>
                                        <template v-else>
                                            <button type="button" class="cg-btn cg-btn--primario" @click="reintentar">{{ t('reintentar') }}</button>
                                            <button type="button" class="cg-btn" @click="volverARelacionar">{{ t('volver_relacionar') }}</button>
                                        </template>
                                        <button type="button" class="cg-btn" @click="nuevoCargue">{{ t('nuevo_cargue') }}</button>
                                    </div>
                                    <p v-if="carga.estado === 'fallida_ejecucion'" class="cg-sub mt-2">{{ t('fallida_ejecucion_nota') }}</p>
                                </div>

                                <div v-else-if="carga.estado === 'cancelada'" class="mt-4">
                                    <div class="cg-alert cg-alert--aviso"><span class="icon-information text-xl"></span><p>{{ t('cancelada') }}</p></div>
                                    <button type="button" class="cg-btn mt-3" @click="nuevoCargue">{{ t('nuevo_cargue') }}</button>
                                </div>

                                <!-- Resumen de la validación -->
                                <div v-else-if="carga.estado === 'validada' && resumen" class="mt-4">
                                    <div class="cg-alert" :class="resumen.filas_rechazadas ? 'cg-alert--aviso' : 'cg-alert--ok'">
                                        <span class="icon-information text-2xl" aria-hidden="true"></span>
                                        <div><p class="cg-alert-title">{{ resumen.filas_rechazadas ? t('validada_con_rechazos') : t('validada_ok') }}</p><p>{{ t('no_se_aplico') }}</p></div>
                                    </div>

                                    <div class="cg-kpis mt-4">
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_total }}</div><div class="cg-kpi-l">{{ t('kpi_total') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_validas }}</div><div class="cg-kpi-l">{{ t('kpi_validas') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_rechazadas }}</div><div class="cg-kpi-l">{{ t('kpi_rechazadas') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_vacias }}</div><div class="cg-kpi-l">{{ t('kpi_vacias') }}</div></div>
                                    </div>
                                    <p v-if="resumen.filas_rechazadas" class="cg-sub mt-2">{{ t('rechazadas_motor') }}: {{ resumen.rechazadas_motor }} · {{ t('rechazadas_procedimiento') }}: {{ resumen.rechazadas_procedimiento }}</p>

                                    <div v-if="resumen.por_codigo.length" class="cg-table-wrap mt-4 cg-scroll-x">
                                        <table class="cg-table">
                                            <thead><tr><th>{{ t('codigo') }}</th><th>{{ t('tipo_incidencia') }}</th><th class="text-right">{{ t('col_filas_afectadas') }}</th><th class="text-right">{{ t('col_incidencias') }}</th></tr></thead>
                                            <tbody><tr v-for="(c, i) in resumen.por_codigo" :key="i"><td class="cg-mono">{{ c.codigo }}</td><td>{{ c.severidad === 'error' ? t('rechaza') : t('advierte') }}</td><td class="text-right">{{ c.filas }}</td><td class="text-right">{{ c.incidencias }}</td></tr></tbody>
                                        </table>
                                    </div>
                                    <p v-if="resumen.por_codigo.length" class="cg-sub mt-1" data-cg="nota-incidencias">{{ t('incidencias_nota') }}</p>

                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <a v-if="resumen.filas_rechazadas" :href="descarga('csv')" class="cg-btn">{{ t('descargar_csv') }}</a>
                                        <a v-if="resumen.filas_rechazadas" :href="descarga('xlsx')" class="cg-btn">{{ t('descargar_xlsx') }}</a>
                                    </div>

                                    <div class="cg-foot mt-5">
                                        <span class="flex items-center gap-2"><span class="icon-information text-xl" aria-hidden="true"></span>
                                            <span>{{ politicaTexto }}</span></span>
                                        <span class="flex gap-2">
                                            <button type="button" class="cg-btn" @click="nuevoCargue">{{ t('nuevo_cargue') }}</button>
                                            <button type="button" class="cg-btn cg-btn--primario" :disabled="!resumen.puede_ejecutar || enviando" :title="resumen.puede_ejecutar ? '' : resumen.motivo_bloqueo" @click="ejecutar">{{ t('ejecutar') }}</button>
                                        </span>
                                    </div>
                                    <p class="cg-sub mt-2">{{ t('ejecutar_nota') }}</p>
                                </div>

                                <!-- Resultado de la ejecución -->
                                <div v-else-if="carga.estado === 'completada' && resumen" class="mt-4">
                                    <div class="cg-alert" :class="(resumen.filas_rechazadas || resumen.filas_observadas || resumen.filas_no_ejecutadas) ? 'cg-alert--aviso' : 'cg-alert--ok'">
                                        <span class="icon-information text-2xl" aria-hidden="true"></span>
                                        <div><p class="cg-alert-title">{{ t('ejecutada_titulo') }}</p><p>{{ t('ejecutada_texto') }}</p></div>
                                    </div>

                                    <div class="cg-kpis mt-4">
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_total }}</div><div class="cg-kpi-l">{{ t('kpi_total') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_aplicadas }}</div><div class="cg-kpi-l">{{ t('kpi_aplicadas') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_sin_cambio }}</div><div class="cg-kpi-l">{{ t('kpi_sin_cambio') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_omitidas_antiguas }}</div><div class="cg-kpi-l">{{ t('kpi_antiguas') }}</div></div>
                                        <div class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_rechazadas }}</div><div class="cg-kpi-l">{{ t('kpi_rechazadas') }}</div></div>
                                        <div v-if="resumen.filas_no_ejecutadas" class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_no_ejecutadas }}</div><div class="cg-kpi-l">{{ t('kpi_no_ejecutadas') }}</div></div>
                                        <div v-if="resumen.filas_vacias" class="cg-kpi"><div class="cg-kpi-n">{{ resumen.filas_vacias }}</div><div class="cg-kpi-l">{{ t('kpi_vacias') }}</div></div>
                                    </div>
                                    <p class="cg-sub mt-2" data-cg="cuadre">{{ cuadreTexto }}</p>
                                    <p v-if="resumen.filas_observadas" class="cg-sub mt-1" data-cg="observadas">{{ resumen.filas_observadas }} {{ t('kpi_observadas').toLowerCase() }} — {{ t('kpi_observadas_ayuda') }} {{ t('pendientes_ayuda') }}</p>
                                    <p v-if="resumen.filas_rechazadas" class="cg-sub mt-1">{{ t('rechazadas_motor') }}: {{ resumen.rechazadas_motor }} · {{ t('rechazadas_procedimiento') }}: {{ resumen.rechazadas_procedimiento }} · {{ t('rechazadas_ejecucion') }}: {{ resumen.rechazadas_ejecucion }}</p>

                                    <div v-if="resumen.por_codigo.length" class="cg-table-wrap mt-4 cg-scroll-x">
                                        <table class="cg-table">
                                            <thead><tr><th>{{ t('codigo') }}</th><th>{{ t('tipo_incidencia') }}</th><th class="text-right">{{ t('col_filas_afectadas') }}</th><th class="text-right">{{ t('col_incidencias') }}</th></tr></thead>
                                            <tbody><tr v-for="(c, i) in resumen.por_codigo" :key="i"><td class="cg-mono">{{ c.codigo }}</td><td>{{ c.severidad === 'error' ? t('rechaza') : t('observa') }}</td><td class="text-right">{{ c.filas }}</td><td class="text-right">{{ c.incidencias }}</td></tr></tbody>
                                        </table>
                                    </div>
                                    <p v-if="resumen.por_codigo.length" class="cg-sub mt-1" data-cg="nota-incidencias">{{ t('incidencias_nota') }}</p>

                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <a :href="descargaResultado('csv')" class="cg-btn">{{ t('descargar_resultado_csv') }}</a>
                                        <a :href="descargaResultado('xlsx')" class="cg-btn">{{ t('descargar_resultado_xlsx') }}</a>
                                        <a v-if="resumen.filas_observadas" :href="descargaPendientes('csv')" class="cg-btn" data-cg="pendientes-csv">{{ t('descargar_pendientes_csv') }}</a>
                                        <a v-if="resumen.filas_observadas" :href="descargaPendientes('xlsx')" class="cg-btn" data-cg="pendientes-xlsx">{{ t('descargar_pendientes_xlsx') }}</a>
                                        <a v-if="resumen.filas_rechazadas" :href="descarga('csv')" class="cg-btn">{{ t('descargar_csv') }}</a>
                                        <a v-if="resumen.filas_rechazadas" :href="descarga('xlsx')" class="cg-btn">{{ t('descargar_xlsx') }}</a>
                                    </div>

                                    <div class="cg-foot mt-5">
                                        <span class="flex items-center gap-2"><span class="icon-information text-xl" aria-hidden="true"></span><span>{{ t('ejecutada_pie') }}</span></span>
                                        <button type="button" class="cg-btn cg-btn--primario" @click="nuevoCargue">{{ t('nuevo_cargue') }}</button>
                                    </div>
                                </div>

                                <div v-else class="cg-vacio"><span class="cg-spin"></span></div>

                                <!-- Revisión agrupada (opcional por tipo): p. ej. producto → variantes. Solo lectura de lo persistido. -->
                                <div v-if="mostrarFilas && tipo && tipo.agrupado" class="mt-6" data-cg="agrupado">
                                    <p class="text-base font-bold text-gray-900 dark:text-white">{{ agrupado.datos ? agrupado.datos.titulo : t('agrupado_titulo') }}</p>
                                    <p v-if="agrupado.datos && agrupado.datos.nota" class="cg-sub mt-1">{{ agrupado.datos.nota }}</p>
                                    <div v-if="agrupado.cargando" class="cg-vacio"><span class="cg-spin"></span> {{ t('cargando_filas') }}</div>
                                    <template v-else-if="agrupado.datos">
                                        <div class="cg-kpis mt-3">
                                            <div v-for="(k, i) in agrupado.datos.indicadores" :key="'ind' + i" class="cg-kpi"><div class="cg-kpi-n">{{ k.n }}</div><div class="cg-kpi-l">{{ k.etiqueta }}</div></div>
                                        </div>
                                        <div v-for="(s, i) in agrupado.datos.secciones" :key="'sec' + i" class="cg-alert cg-alert--aviso mt-3">
                                            <span class="icon-information text-xl" aria-hidden="true"></span>
                                            <div><p class="cg-alert-title">{{ s.titulo }}</p><ul class="list-disc pl-5"><li v-for="(x, j) in s.items" :key="j">{{ x }}</li></ul></div>
                                        </div>
                                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                            <span class="cg-sub">{{ gruposFiltrados.length }} {{ t('agrupado_productos') }}</span>
                                            <select v-model="agrupado.filtro" class="cg-input" style="width:auto" :aria-label="t('agrupado_filtro')">
                                                <option value="">{{ t('agrupado_todos') }}</option>
                                                <option v-for="f in agrupado.datos.filtros" :key="f.estado" :value="f.estado">{{ f.etiqueta }}</option>
                                            </select>
                                        </div>
                                        <div class="mt-2 grid gap-2">
                                            <div v-for="(g, i) in gruposFiltrados" :key="'g' + i" class="cg-panel p-3" :data-grupo="g.estado">
                                                <div class="flex items-start justify-between gap-2 max-sm:flex-wrap">
                                                    <div class="min-w-0"><p class="font-semibold text-gray-900 dark:text-white">{{ g.titulo }}</p><p class="cg-sub">{{ g.subtitulo }}</p></div>
                                                    <span class="flex flex-wrap items-center gap-2">
                                                        <a v-if="g.enlace" :href="g.enlace" target="_blank" rel="noopener" class="cg-link">{{ g.enlace_texto }}</a>
                                                        <span class="cg-pill" :class="'cg-pill--' + g.tono">{{ g.estado_texto }}</span>
                                                    </span>
                                                </div>
                                                <ul class="mt-2 text-sm">
                                                    <li v-for="(e, j) in g.elementos" :key="j" class="flex items-center justify-between gap-2 border-t border-gray-100 py-1 dark:border-gray-800">
                                                        <span class="min-w-0 break-words">{{ e.texto }}</span><span class="cg-pill" :class="'cg-pill--' + e.tono">{{ e.estado_texto }}</span>
                                                    </li>
                                                </ul>
                                                <ul v-if="g.motivos.length" class="mt-2 list-disc pl-5 text-sm text-red-700 dark:text-red-400"><li v-for="(m, j) in g.motivos" :key="j">{{ m }}</li></ul>
                                                <ul v-if="g.avisos.length" class="mt-2 list-disc pl-5 text-sm text-amber-700 dark:text-amber-400"><li v-for="(m, j) in g.avisos" :key="j">{{ m }}</li></ul>
                                            </div>
                                        </div>
                                        <p v-if="agrupado.datos.truncado" class="cg-sub mt-2">{{ t('agrupado_truncado') }}</p>
                                    </template>
                                </div>

                                <!-- Detalle por fila: lo PERSISTIDO (igual al recargar o abrir desde el Historial) -->
                                <div v-if="mostrarFilas" class="mt-6" data-cg="detalle-filas">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-base font-bold text-gray-900 dark:text-white">{{ t('detalle_filas') }}</p>
                                        <span class="flex flex-wrap items-center gap-2">
                                            <select v-model="filas.estado" class="cg-input" style="width:auto" :aria-label="t('col_resultado')" @change="$nextTick(() => cargarFilas(1))">
                                                <option v-for="f in filtrosFilas" :key="f" :value="f">{{ t('filtro_' + f) }}</option>
                                            </select>
                                            <input type="search" v-model="filas.q" class="cg-input" style="width:240px" :placeholder="t('buscar_fila')" :aria-label="t('buscar_fila')" @keyup.enter="cargarFilas(1)">
                                        </span>
                                    </div>

                                    <p class="cg-sub mt-1" data-cg="aviso-filas">{{ carga.estado === 'completada' ? t('resultado_persistido') : t('validada_aviso_filas') }}</p>
                                    <div v-if="filas.cargando" class="cg-vacio"><span class="cg-spin"></span> {{ t('cargando_filas') }}</div>
                                    <div v-else-if="!filas.datos || filas.datos.filas.length === 0" class="cg-vacio">{{ t('sin_filas_filtro') }}</div>
                                    <div v-else class="cg-table-wrap mt-3 cg-scroll-x">
                                        <table class="cg-table">
                                            <thead><tr><th>{{ t('col_fila') }}</th><th>{{ t('col_identificacion') }}</th><th>{{ t('col_resultado') }}</th><th>{{ t('col_motivo') }}</th><th></th></tr></thead>
                                            <tbody>
                                                <template v-for="f in filas.datos.filas" :key="f.fila_origen">
                                                    <tr :data-fila="f.fila_origen">
                                                        <td class="cg-mono">{{ f.fila_origen }}</td>
                                                        <td><template v-if="f.identificacion.length"><span v-for="(i, k) in f.identificacion" :key="k" class="block">{{ i.valor }}</span></template><template v-else>—</template></td>
                                                        <td><span class="cg-pill" :class="pillFila(f.estado)">{{ t('fila_estado_' + f.estado) }}</span><span v-if="f.detalle && f.estado !== 'rechazada'" class="cg-sub block">{{ f.detalle }}</span></td>
                                                        <td>
                                                            <template v-if="f.incidencias.length">
                                                                <span v-for="(i, k) in f.incidencias.slice(0, 2)" :key="k" class="block">{{ i.motivo }}</span>
                                                                <span v-if="f.incidencias.length > 2" class="cg-sub block">+ {{ f.incidencias.length - 2 }}</span>
                                                            </template>
                                                            <template v-else>—</template>
                                                        </td>
                                                        <td><button type="button" class="cg-link" @click="filas.abiertas[f.fila_origen] = !filas.abiertas[f.fila_origen]">{{ filas.abiertas[f.fila_origen] ? t('ocultar_fila') : t('ver_fila') }}</button></td>
                                                    </tr>
                                                    <tr v-if="filas.abiertas[f.fila_origen]" :data-fila-detalle="f.fila_origen">
                                                        <td colspan="5">
                                                            <div class="grid gap-4 md:grid-cols-3">
                                                                <div v-if="f.complemento.length">
                                                                    <p class="cg-alert-title">{{ t('comparacion') }}</p>
                                                                    <dl><div v-for="(p, k) in f.complemento" :key="k" class="mt-1"><dt class="cg-sub">{{ p.etiqueta }}</dt><dd>{{ p.valor }}</dd></div></dl>
                                                                </div>
                                                                <div v-if="f.incidencias.length">
                                                                    <p class="cg-alert-title">{{ t('avisos_fila') }}</p>
                                                                    <ul><li v-for="(i, k) in f.incidencias" :key="k" class="mt-1"><span class="cg-mono">{{ i.codigo }}</span> · {{ i.severidad === 'error' ? t('efecto_error') : t('efecto_advertencia') }}<template v-if="i.campo"> · {{ i.campo }}</template><span class="cg-sub block">{{ i.motivo }}</span></li></ul>
                                                                </div>
                                                                <div v-if="f.valores.length">
                                                                    <p class="cg-alert-title">{{ t('valores_leidos') }}</p>
                                                                    <dl><div v-for="(p, k) in f.valores" :key="k" class="mt-1"><dt class="cg-sub">{{ p.etiqueta }}</dt><dd>{{ p.valor }}</dd></div></dl>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div v-if="filas.datos && filas.datos.paginas > 1" class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                        <span class="cg-sub">{{ t('pagina_de').replace(':p', filas.datos.pagina).replace(':n', filas.datos.paginas).replace(':t', filas.datos.total) }}</span>
                                        <span class="flex gap-2">
                                            <button type="button" class="cg-btn" :disabled="filas.datos.pagina <= 1" @click="cargarFilas(filas.datos.pagina - 1)">{{ t('pag_anterior') }}</button>
                                            <button type="button" class="cg-btn" :disabled="filas.datos.pagina >= filas.datos.paginas" @click="cargarFilas(filas.datos.pagina + 1)">{{ t('pag_siguiente') }}</button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================= HISTORIAL ========================= -->
                <div v-show="tab === 'historial'" class="cg-panel mt-4 cg-scroll-x">
                    <table class="cg-table">
                        <thead><tr><th>#</th><th>{{ t('tipo') }}</th><th>{{ t('archivo') }}</th><th>{{ t('estado') }}</th><th class="text-right">{{ t('filas') }}</th><th>{{ t('rechazadas') }}</th><th>{{ t('usuario') }}</th><th>{{ t('fecha') }}</th><th></th></tr></thead>
                        <tbody>
                            <tr v-if="historial.length === 0"><td colspan="9" class="cg-vacio">{{ t('historial_vacio') }}</td></tr>
                            <tr v-for="h in historial" :key="h.id">
                                <td>{{ h.id }}</td><td>{{ h.tipo }}</td><td class="cg-ejemplo">{{ h.archivo }}</td>
                                <td><span class="cg-pill" :class="pillEstado(h.estado)">{{ estadoTexto(h.estado) }}</span></td>
                                <td class="text-right">{{ h.filas_total === null ? '—' : h.filas_total }}</td>
                                <td>{{ h.contadores ? h.contadores.filas_rechazadas : '—' }}</td>
                                <td>{{ h.admin }}</td><td>{{ h.fecha }}</td>
                                <td><button type="button" class="cg-link" @click="abrirCarga(h.id)">{{ t('abrir') }}</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </script>
        @endverbatim

        <script type="module">
            app.component('v-cargues-app', {
                template: '#v-cargues-app-template',

                data() {
                    const cfg = window.__cargues;

                    return {
                        cfg,
                        tab: 'nuevo',
                        tipos: cfg.tipos,
                        historial: cfg.historial,
                        busqueda: '',
                        tipoId: cfg.tipos.length === 1 ? cfg.tipos[0].id : null,
                        carga: null,
                        lectura: { hoja: null, fila_encabezado: 1, delimitador: ';', entrecomillado: '"', codificacion: 'UTF-8' },
                        relaciones: {},
                        guardarComparacion: false,
                        nombreComparacion: '',
                        mostrarMapeos: false,
                        comparaciones: null,
                        diffMapeo: null,
                        progreso: null,
                        timer: null,
                        subiendo: false,
                        enviando: false,
                        arrastrando: false,
                        forzarPaso2: false,
                        aviso: null,
                        filas: { estado: 'todas', q: '', datos: null, cargando: false, abiertas: {}, clave: null },
                        agrupado: { datos: null, cargando: false, filtro: '' },
                        accionElegida: null,
                    };
                },

                computed: {
                    pasos() { return [this.t('paso_archivo'), this.t('paso_relacionar'), this.t('paso_validar'), this.t('paso_resultado')]; },
                    tipo() { return this.tipos.find(x => x.id === this.tipoId) || null; },
                    tiposFiltrados() {
                        const q = this.busqueda.trim().toLowerCase();

                        return q ? this.tipos.filter(x => (x.nombre + ' ' + (x.descripcion || '')).toLowerCase().includes(q)) : this.tipos;
                    },
                    campos() { return this.tipo ? this.tipo.campos : []; },
                    columnas() { return (this.carga && this.carga.encabezados && this.carga.encabezados.columnas) || []; },
                    hojas() { return (this.carga && this.carga.encabezados && this.carga.encabezados.inspeccion.hojas) || []; },
                    advertencias() { return (this.carga && this.carga.encabezados && this.carga.encabezados.inspeccion.advertencias) || []; },
                    vistaPreviaPendiente() { return this.carga && !this.carga.encabezados && this.carga.progreso && this.carga.progreso.etapa === 'vista_previa'; },
                    resumen() { return this.carga ? this.carga.resumen : null; },
                    mostrarFilas() { return !!(this.carga && this.resumen && ['validada', 'completada'].includes(this.carga.estado)); },
                    gruposFiltrados() {
                        const d = this.agrupado.datos;

                        return d ? d.grupos.filter(g => !this.agrupado.filtro || g.estado === this.agrupado.filtro) : [];
                    },
                    filtrosFilas() {
                        const e = this.carga ? this.carga.estado : '';

                        return e === 'completada'
                            ? ['todas', 'aplicada', 'sin_cambio', 'omitida_antigua', 'rechazada', 'no_ejecutada', 'vacia', 'observadas']
                            : ['todas', 'pendiente', 'rechazada', 'vacia'];
                    },
                    // Las filas del archivo se REPARTEN entre estos grupos; "con observacion" es un subconjunto aparte.
                    cuadreTexto() {
                        const r = this.resumen;

                        if (!r) { return ''; }

                        const partes = [[r.filas_aplicadas, 'kpi_aplicadas'], [r.filas_sin_cambio, 'kpi_sin_cambio'], [r.filas_omitidas_antiguas, 'kpi_antiguas'], [r.filas_rechazadas, 'kpi_rechazadas'], [r.filas_no_ejecutadas, 'kpi_no_ejecutadas'], [r.filas_vacias, 'kpi_vacias']]
                            .filter(p => p[0] > 0).map(p => p[0] + ' ' + this.t(p[1]).toLowerCase());

                        return this.t('cuadre_filas').replace(':partes', partes.join(' + ') + ' = ' + r.filas_total);
                    },
                    paso() {
                        if (!this.carga) { return 1; }
                        if (this.forzarPaso2) { return 2; }

                        if (['lista', 'ejecutando', 'consolidando', 'completada', 'fallida_ejecucion'].includes(this.carga.estado)) { return 4; }

                        return ['recibida'].includes(this.carga.estado) ? 2 : 3;
                    },
                    relacionados() { return this.campos.filter(c => this.esRelacionado(c)).length; },
                    faltanObligatorios() { return this.campos.filter(c => this.obligatorio(c) && !this.esRelacionado(c)); },
                    razonNoValidar() {
                        if (this.faltanObligatorios.length) {
                            return this.t('falta_obligatorio') + ': ' + this.faltanObligatorios.map(c => c.etiqueta).join(', ');
                        }
                        if (this.guardarComparacion && !this.nombreComparacion.trim()) { return this.t('falta_nombre'); }
                        if (!this.columnas.length) { return this.t('sin_columnas'); }

                        return null;
                    },
                    etapaTexto() {
                        const e = this.progreso ? this.progreso.etapa : null;

                        if (this.progreso && this.progreso.en_ejecucion) { return this.t('etapa_' + (e || 'cola_ejecucion')); }

                        return this.t('etapa_' + (e || 'cola')) || this.t('etapa_cola');
                    },
                    politicaTexto() {
                        const r = this.resumen;

                        if (!r) { return ''; }
                        if (!r.puede_ejecutar) { return r.motivo_bloqueo; }
                        if (r.confirmar_rechazadas) { return this.t('politica_procesar').replace(':n', r.confirmar_rechazadas); }

                        return this.t('politica_listo');
                    },
                },

                watch: {
                    carga(c) {
                        if (c && ['validada', 'completada', 'fallida_ejecucion'].includes(c.estado) && this.tab === 'nuevo') { this.refrescarHistorial(); }

                        // Detalle por fila: se carga solo (tras validar y, automaticamente, al terminar la ejecucion o al abrir desde el Historial).
                        const clave = c && c.resumen && ['validada', 'completada'].includes(c.estado) ? c.id + ':' + c.estado : null;

                        if (clave !== this.filas.clave) {
                            this.filas.clave = clave;
                            this.filas.datos = null;
                            this.filas.abiertas = {};
                            this.filas.estado = 'todas';
                            this.filas.q = '';

                            if (clave) { this.cargarFilas(1); }

                            this.agrupado.datos = null;
                            this.agrupado.filtro = '';
                            if (clave && this.tipo && this.tipo.agrupado) { this.cargarAgrupado(); }
                        }
                    },
                },

                beforeUnmount() { this.detener(); },

                methods: {
                    t(k) { return this.cfg.t[k] || k; },
                    mb(b) { return (b / 1048576).toFixed(0); },
                    kb(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; },
                    cargaUrl(id, sufijo = '') { return this.cfg.rutas.carga.replace(/\/0$/, '/' + id) + sufijo; },
                    obligatorio(c) { return c.obligatorio_en.includes(this.carga ? this.carga.accion : (this.tipo ? this.tipo.accion : '')); },
                    esRelacionado(c) { const v = this.relaciones[c.nombre]; return v !== '' && v !== null && v !== undefined; },
                    usadaPorOtro(indice, campo) { return Object.keys(this.relaciones).some(k => k !== campo && this.relaciones[k] === indice); },
                    ejemploDe(c) { const v = this.relaciones[c.nombre]; return this.esRelacionado(c) && this.columnas[v] ? this.columnas[v].ejemplo : null; },
                    etiquetaCampo(n) { const c = this.campos.find(x => x.nombre === n); return c ? c.etiqueta : n; },
                    letraDe(i) { return this.columnas[i] ? this.columnas[i].letra : '?'; },
                    descarga(f) { return this.cargaUrl(this.carga.id, '/descargas/rechazadas.' + f); },
                    descargaResultado(f) { return this.cargaUrl(this.carga.id, '/descargas/resultado.' + f); },
                    descargaPendientes(f) { return this.cargaUrl(this.carga.id, '/descargas/pendientes.' + f); },
                    pillFila(e) { return { aplicada: 'cg-pill--verde', pendiente: 'cg-pill--azul', rechazada: 'cg-pill--rojo', no_ejecutada: 'cg-pill--rojo' }[e] || 'cg-pill--gris'; },
                    cargarFilas(pagina) {
                        if (!this.carga) { return; }

                        const id = this.carga.id;
                        this.filas.cargando = true;
                        this.$axios.get(this.cargaUrl(id, '/filas'), { params: { estado: this.filas.estado, q: this.filas.q || undefined, pagina } })
                            .then(r => { if (this.carga && this.carga.id === id) { this.filas.datos = r.data; } })
                            .catch(e => { this.filas.datos = null; this.notificar('error', this.mensajeError(e)); })
                            .finally(() => { this.filas.cargando = false; });
                    },
                    cargarAgrupado() {
                        const id = this.carga.id;
                        this.agrupado.cargando = true;
                        this.$axios.get(this.cargaUrl(id, '/agrupado'))
                            .then(r => { if (this.carga && this.carga.id === id) { this.agrupado.datos = r.data; } })
                            .catch(e => { this.agrupado.datos = null; this.notificar('error', this.mensajeError(e)); })
                            .finally(() => { this.agrupado.cargando = false; });
                    },
                    plantillaUrl(f) { return this.cfg.rutas.plantilla.replace(/\/0\/plantilla\.xlsx$/, '/' + this.tipo.id + '/plantilla.' + f); },
                    pillEstado(e) { return ['validada', 'completada'].includes(e) ? 'cg-pill--verde' : (e.startsWith('fallida') ? 'cg-pill--rojo' : (e === 'cancelada' ? 'cg-pill--gris' : 'cg-pill--azul')); },
                    estadoTexto(e) { return this.t('estado_' + e); },

                    mensajeError(e, porDefecto) {
                        const d = e && e.response && e.response.data;

                        return (d && d.mensaje) || (d && d.message) || porDefecto || this.t('error_generico');
                    },

                    notificar(tipo, mensaje) { this.$emitter.emit('add-flash', { type: tipo, message: mensaje }); },

                    elegirTipo(tp) {
                        if (this.tipoId === tp.id) { return; }
                        if (this.carga && !window.confirm(this.t('descartar_avance'))) { return; }

                        this.nuevoCargue(tp.id);
                    },

                    elegirArchivo(ev) { const f = ev.target.files[0]; ev.target.value = ''; if (f) { this.subir(f); } },
                    soltar(ev) { this.arrastrando = false; const f = ev.dataTransfer.files[0]; if (f && this.tipo) { this.subir(f); } },

                    subir(archivo) {
                        this.aviso = null;
                        this.subiendo = true;
                        const fd = new FormData();
                        fd.append('tipo_id', this.tipoId);
                        fd.append('archivo', archivo);

                        this.$axios.post(this.cfg.rutas.subir, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
                            .then(r => this.cargarCarga(r.data.carga))
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_subir'), texto: this.mensajeError(e) }; })
                            .finally(() => { this.subiendo = false; });
                    },

                    cargarCarga(c) {
                        this.carga = c;
                        this.forzarPaso2 = false;
                        this.tipoId = c.tipo_id;
                        this.lectura = Object.assign({}, this.lectura, c.lectura);
                        this.lectura.fila_encabezado = c.lectura.fila_encabezado || 1;
                        this.relaciones = this.relacionesIniciales(c);
                        this.accionElegida = c.accion || (this.tipo ? this.tipo.accion : null);
                        this.progreso = c.progreso;

                        if (c.progreso && (c.progreso.en_curso || c.progreso.etapa === 'vista_previa')) { this.vigilar(); }
                    },

                    /** Relaciones guardadas en la carga, o las sugeridas (solo coincidencias inequívocas). */
                    relacionesIniciales(c) {
                        const r = {};
                        const base = c.relaciones || (c.encabezados ? c.encabezados.sugerencias : {}) || {};
                        (this.tipos.find(x => x.id === c.tipo_id) || { campos: [] }).campos.forEach(f => { r[f.nombre] = base[f.nombre] === undefined || base[f.nombre] === null ? '' : base[f.nombre]; });

                        return r;
                    },

                    cambiarLectura() {
                        if (!this.carga) { return; }
                        const p = { fila_encabezado: this.lectura.fila_encabezado };
                        if (this.carga.formato === 'xlsx') { p.hoja = this.lectura.hoja; }
                        if (this.carga.formato === 'csv') { Object.assign(p, { delimitador: this.lectura.delimitador, entrecomillado: this.lectura.entrecomillado, codificacion: this.lectura.codificacion }); }

                        this.aviso = null;
                        this.$axios.post(this.cargaUrl(this.carga.id, '/lectura'), p)
                            .then(r => { this.cargarCarga(r.data.carga); })
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_leer'), texto: this.mensajeError(e) }; });
                    },

                    validar() {
                        this.aviso = null;
                        this.enviando = true;
                        const rel = {};
                        Object.keys(this.relaciones).forEach(k => { rel[k] = this.relaciones[k] === '' ? null : this.relaciones[k]; });
                        const p = { relaciones: rel, fila_encabezado: this.lectura.fila_encabezado };
                        if (this.carga.formato === 'xlsx') { p.hoja = this.lectura.hoja; }
                        if (this.carga.formato === 'csv') { Object.assign(p, { delimitador: this.lectura.delimitador, entrecomillado: this.lectura.entrecomillado, codificacion: this.lectura.codificacion }); }
                        if (this.guardarComparacion) { p.guardar_comparacion = true; p.nombre_comparacion = this.nombreComparacion; }
                        if (this.tipo && this.tipo.acciones.length > 1 && this.accionElegida) { p.accion = this.accionElegida; }

                        this.$axios.post(this.cargaUrl(this.carga.id, '/validar'), p)
                            .then(r => { this.forzarPaso2 = false; this.carga = r.data.carga; this.progreso = r.data.carga.progreso; this.guardarComparacion = false; this.vigilar(); })
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_validar'), texto: this.mensajeError(e) }; })
                            .finally(() => { this.enviando = false; });
                    },

                    reintentar() {
                        this.aviso = null;
                        this.$axios.post(this.cargaUrl(this.carga.id, '/reintentar'))
                            .then(r => { this.carga = r.data.carga; this.progreso = r.data.carga.progreso; this.vigilar(); })
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_reintentar'), texto: this.mensajeError(e) }; });
                    },

                    /** Ejecuta con la política del tipo: con rechazadas pide escribir el número exacto; si no, confirmación. */
                    ejecutar() {
                        const r = this.resumen;
                        const p = {};

                        if (r && r.confirmar_rechazadas) {
                            const v = window.prompt(this.t('confirmar_ejecutar_rechazadas').replace(':n', r.confirmar_rechazadas));
                            if (v === null) { return; }
                            p.confirmar_rechazadas = v.trim();
                        } else if (!window.confirm(this.t('confirmar_ejecutar'))) {
                            return;
                        }

                        this.aviso = null;
                        this.enviando = true;
                        this.$axios.post(this.cargaUrl(this.carga.id, '/ejecutar'), p)
                            .then(x => { this.carga = x.data.carga; this.progreso = x.data.carga.progreso; this.vigilar(); })
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_ejecutar'), texto: this.mensajeError(e) }; })
                            .finally(() => { this.enviando = false; });
                    },

                    recuperar() {
                        this.aviso = null;
                        this.$axios.post(this.cargaUrl(this.carga.id, '/recuperar'))
                            .then(x => { this.carga = x.data.carga; this.progreso = x.data.carga.progreso; if (this.progreso && this.progreso.en_curso) { this.vigilar(); } })
                            .catch(e => { this.aviso = { titulo: this.t('no_se_pudo_recuperar'), texto: this.mensajeError(e) }; });
                    },

                    volverARelacionar() { this.forzarPaso2 = true; this.aviso = null; },

                    cancelar() { this.cambiarArchivo(true); },

                    cambiarArchivo(descartar = false) {
                        if (!this.carga) { return; }
                        this.detener();
                        this.$axios.post(this.cargaUrl(this.carga.id, '/cancelar')).catch(() => {}).finally(() => { this.nuevoCargue(this.tipoId); });
                    },

                    nuevoCargue(tipoId) {
                        this.detener();
                        this.carga = null;
                        this.progreso = null;
                        this.relaciones = {};
                        this.aviso = null;
                        this.forzarPaso2 = false;
                        this.guardarComparacion = false;
                        this.nombreComparacion = '';
                        this.mostrarMapeos = false;
                        this.diffMapeo = null;
                        this.tipoId = typeof tipoId === 'number' ? tipoId : this.tipoId;
                        this.tab = 'nuevo';
                    },

                    // ----- Progreso -----
                    vigilar() {
                        this.detener();
                        this.timer = setInterval(() => this.consultar(), 2500);
                        this.consultar();
                    },

                    detener() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },

                    consultar() {
                        if (!this.carga) { return; }

                        this.$axios.get(this.cargaUrl(this.carga.id, '/progreso')).then(r => {
                            this.progreso = r.data;
                            const preparando = r.data.etapa === 'vista_previa';

                            if (!r.data.en_curso && !preparando) {
                                this.detener();
                                this.$axios.get(this.cargaUrl(this.carga.id)).then(d => { this.carga = d.data; this.refrescarHistorial(); });
                            } else if (preparando && !r.data.en_curso) {
                                this.$axios.get(this.cargaUrl(this.carga.id)).then(d => {
                                    if (d.data.encabezados) { this.detener(); this.cargarCarga(d.data); }
                                });
                            }
                        }).catch(() => {});
                    },

                    // ----- Mapeos guardados -----
                    abrirMapeos() {
                        this.mostrarMapeos = !this.mostrarMapeos;
                        this.diffMapeo = null;

                        if (this.mostrarMapeos) {
                            this.comparaciones = null;
                            this.$axios.get(this.cargaUrl(this.carga.id, '/comparaciones')).then(r => { this.comparaciones = r.data.comparaciones; }).catch(() => { this.comparaciones = []; });
                        }
                    },

                    verMapeo(m) {
                        this.$axios.get(this.cargaUrl(this.carga.id, '/comparaciones/' + m.id)).then(r => { this.diffMapeo = r.data; }).catch(e => this.notificar('error', this.mensajeError(e)));
                    },

                    aplicarMapeo() {
                        const r = {};
                        this.campos.forEach(f => { const x = this.diffMapeo.relaciones[f.nombre]; r[f.nombre] = x && x.indice !== null && x.indice !== undefined ? x.indice : ''; });
                        this.relaciones = r;
                        this.mostrarMapeos = false;
                        this.diffMapeo = null;
                    },

                    // ----- Historial -----
                    abrirHistorial() { this.tab = 'historial'; this.refrescarHistorial(); },

                    refrescarHistorial() {
                        this.$axios.get(this.cfg.rutas.historial).then(r => { this.historial = r.data.historial; }).catch(() => {});
                    },

                    abrirCarga(id) {
                        this.$axios.get(this.cargaUrl(id)).then(r => { this.tab = 'nuevo'; this.cargarCarga(r.data); }).catch(e => this.notificar('error', this.mensajeError(e)));
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
