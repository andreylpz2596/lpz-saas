{{--
    DRAWER UNICO de Entregas (Ver · Registrar entrega · Registrar devolucion · Recibir devolucion), sin salir de la bandeja.

    Es una capa de presentacion: el contexto sale de `ConsultaEntregaService` (GET JSON) y cada guardado llama al MISMO servicio
    de siempre (RegistrarEntregaService, RegistrarRetornoTransportadoraService, RecibirDevolucionService, AnularEventoLogistico).
    El servidor vuelve a validar permisos, idempotencia y cantidades: aqui solo se ahorran viajes y clics. Las paginas clasicas
    (`?envio=ID`, `/envios/ID/entregar`, `/envios/ID/retorno`) siguen existiendo como respaldo.

    Los botones de la bandeja son enlaces con `data-logistica-entregas`, `data-envio` y `data-modo` (ver | entregar | devolver):
    sin JavaScript navegan a la pagina clasica; con JavaScript abren el drawer. Al guardar se reemplazan solo la fila/tarjeta del
    envio (`[data-envio]`) y los indicadores (`[data-kpi]`) con el HTML fresco de la propia bandeja.
--}}
{{-- Formato y mascara monetaria compartidos de ByLopez (ByLopezMoneda / <v-moneda>): se incluye una sola vez por pantalla. --}}
@include('corteconfeccion::admin.partials.moneda')
@php
    $nombresDrawer = ['fuentes' => trans('logistica::app.fuentes')];
@endphp
<v-logistica-entregas
    url-contexto="{{ route('admin.logistica.entregas.drawer.contexto', ['envio' => '__ID__']) }}"
    url-entregar="{{ route('admin.logistica.entregas.drawer.entregar', ['envio' => '__ID__']) }}"
    url-devolver="{{ route('admin.logistica.entregas.drawer.devolver', ['envio' => '__ID__']) }}"
    url-recibir="{{ route('admin.logistica.entregas.recepcion.store', ['retorno' => '__ID__']) }}"
    url-anular-entrega="{{ route('admin.logistica.entregas.drawer.anular_entrega', ['entrega' => '__ID__']) }}"
    url-anular-devolucion="{{ route('admin.logistica.entregas.drawer.anular_devolucion', ['retorno' => '__ID__']) }}"
    url-anular-recepcion="{{ route('admin.logistica.entregas.drawer.anular_recepcion', ['recepcion' => '__ID__']) }}"
    url-validar="{{ route('admin.logistica.entregas.drawer.validar', ['envio' => '__ID__']) }}"
    url-cobrar="{{ route('admin.logistica.entregas.drawer.cobrar', ['envio' => '__ID__']) }}"
    url-anular-validacion="{{ route('admin.logistica.entregas.drawer.anular_validacion', ['validacion' => '__ID__']) }}"
    :textos='@json(trans('logistica::app.drawer'))'
    :estados='@json(["entrega" => trans("logistica::app.entregas.ee"), "validacion" => trans("logistica::app.entregas.val")])'
></v-logistica-entregas>

@pushOnce('scripts')
    <script type="text/x-template" id="v-logistica-entregas-template">
        <div>
            <x-admin::drawer ref="drawer" width="600px" @close="alCerrar">
                <x-slot:header>
                    <p class="lge-titulo" data-logistica="titulo">@{{ titulo }}</p>

                    <p v-if="ctx" class="lge-sub" data-logistica="cabecera">
                        @{{ t(textos.cabecera, { pedido: ctx.envio.pedido, envio: ctx.envio.id }) }}
                        <template v-if="ctx.envio.cliente"> &middot; @{{ ctx.envio.cliente }}</template>
                    </p>
                </x-slot:header>

                <x-slot:content>
                    <div v-if="cargando" class="lge-nota">@{{ textos.cargando }}</div>

                    <div v-else-if="errorCarga" class="lge-error">@{{ errorCarga }}</div>

                    <div v-else-if="ctx" class="lge-pila" data-logistica="contenido">
                        {{-- ===== Estados + resumen: siempre visibles, en todos los modos ===== --}}
                        <div class="lge-estados" data-logistica="estados">
                            <span class="lg-pill lg-pill--punto" :class="'lg-pill--' + colorEntrega" :data-estado-entrega="ctx.estado_entrega">@{{ estados.entrega[ctx.estado_entrega] }}</span>
                            <span class="lg-pill lg-pill--punto" :class="'lg-pill--' + colorValidacion" :data-validacion="ctx.validacion">@{{ textos.chip_bylopez }}: @{{ estados.validacion[ctx.validacion] }}</span>
                            <span v-if="ctx.estado_devolucion" class="lg-pill lg-pill--punto" :class="'lg-pill--' + colorDevolucion(ctx.estado_devolucion)" :data-devolucion="ctx.estado_devolucion">@{{ textos.dev[ctx.estado_devolucion] }}</span>
                        </div>

                        <div class="lg-stats lg-stats--grande" data-cifras>
                            <div class="lg-stat" :class="ctx.resumen.despachadas === 0 ? 'lg-stat--cero' : ''"><b>@{{ ctx.resumen.despachadas }}</b><span>@{{ textos.abr_desp_largo }}</span></div>
                            <div class="lg-stat" :class="ctx.resumen.entregadas === 0 ? 'lg-stat--cero' : ''"><b>@{{ ctx.resumen.entregadas }}</b><span>@{{ textos.abr_entr_largo }}</span></div>
                            <div class="lg-stat" :class="ctx.resumen.devueltas === 0 ? 'lg-stat--cero' : ''"><b>@{{ ctx.resumen.devueltas }}</b><span>@{{ textos.abr_dev_largo }}</span></div>
                            <div class="lg-stat" :class="ctx.resumen.pendientes > 0 ? 'lg-stat--pend' : 'lg-stat--cero'"><b>@{{ ctx.resumen.pendientes }}</b><span>@{{ textos.abr_pend_largo }}</span></div>
                        </div>

                        {{-- ======================= MODO VER ======================= --}}
                        <template v-if="modo === 'ver'">
                            {{-- Devolucion pendiente de recibir: nivel 1 (requiere accion de Bodega). --}}
                            <div v-for="d in devolucionesPendientes" :key="'p' + d.id" class="lge-banner lge-banner--aviso" data-logistica="banner-pendiente">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo">
                                    <p class="lge-banner-tit">@{{ t(textos.banner_pendiente, { id: d.id, n: d.esperadas }) }}</p>
                                    <p class="lge-nota">@{{ d.fecha_texto }} &middot; @{{ d.motivo }}</p>
                                </div>
                                <button v-if="ctx.permisos.recibir" type="button" class="lg-btn lg-btn--aviso" data-logistica="recibir" @click="entrarRecibir(d)">@{{ textos.recibir }}</button>
                            </div>

                            <div v-for="d in devolucionesRevision" :key="'r' + d.id" class="lge-banner lge-banner--rojo" data-logistica="banner-revision">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo">
                                    <p class="lge-banner-tit">@{{ t(textos.banner_revision_tit, { id: d.id }) }}</p>
                                    <p class="lge-nota">@{{ textos.banner_revision }}</p>
                                </div>
                            </div>

                            {{-- Validacion y cierre: la entrega REGISTRADA no es la entrega VALIDADA; el pago solo lo cambia Cartera. --}}
                            <section v-if="ctx.permisos.validar || ctx.validada" data-logistica="cierre">
                                <p class="lge-sec"><span class="icon-done"></span>@{{ textos.sec_cierre }}</p>
                                <dl class="lg-panel lg-info" data-cierre>
                                    <div class="lg-span2"><dt>@{{ textos.cierre_validacion }}</dt><dd data-cierre-validacion>
                                        <template v-if="ctx.validada">@{{ t(textos.validada_por, { actor: ctx.validada.actor, cuando: ctx.validada.cuando_texto }) }}</template>
                                        <template v-else>@{{ ctx.lista_para_validar ? textos.por_validar : textos.no_lista }}</template>
                                    </dd></div>
                                    <div><dt>@{{ textos.cierre_pedido }}</dt><dd data-cierre-pedido>@{{ textos.pedido_estado[ctx.cierre.pedido_estado] || ctx.cierre.pedido_estado }}</dd></div>
                                    <div><dt>@{{ textos.cierre_factura }}</dt><dd data-cierre-factura>@{{ ctx.cierre.factura_estado ? (textos.factura_estado[ctx.cierre.factura_estado] || ctx.cierre.factura_estado) : '—' }}</dd></div>
                                    <div v-if="ctx.cierre.cobro.saldo !== null" class="lg-span2"><dt>@{{ textos.cierre_saldo }}</dt><dd data-cierre-saldo>@{{ dinero(ctx.cierre.cobro.saldo) }}<template v-if="ctx.cierre.cobro.sin_revisar > 0"> &middot; @{{ t(textos.cobro_sin_revisar, { n: dinero(ctx.cierre.cobro.sin_revisar) }) }}</template></dd></div>
                                </dl>
                                <p v-if="ctx.lista_para_validar && ctx.permisos.validar" class="lge-nota" data-logistica="ayuda-validar">@{{ textos.ayuda_validar }}</p>
                                <p v-if="ctx.permisos.validar && ctx.cierre.cobro.motivo && ['fuera_cartera', 'requiere_revision', 'sin_cartera'].includes(ctx.cierre.cobro.motivo)" class="lge-nota" data-logistica="cobro-no-disponible">@{{ textos.cobro_motivo[ctx.cierre.cobro.motivo] }}</p>
                                <p v-if="ctx.cierre.cobro.motivo === 'sin_saldo' && ctx.validada && ! ctx.cierre.cerrado" class="lge-nota">@{{ textos.cobro_motivo.sin_saldo }}</p>
                                <div class="lge-acciones" v-if="ctx.cierre.cobro.disponible">
                                    <button type="button" class="lg-btn" data-logistica="ir-cobrar" @click="entrar('cobrar')">@{{ textos.registrar_cobro }}</button>
                                </div>
                            </section>

                            {{-- Informacion general --}}
                            <section>
                                <p class="lge-sec"><span class="icon-customer"></span>@{{ textos.sec_general }}</p>
                                <dl class="lg-panel lg-info" data-general>
                                    <div><dt>@{{ textos.pedido }}</dt><dd>#@{{ ctx.envio.pedido }}</dd></div>
                                    <div><dt>@{{ textos.envio }}</dt><dd>#@{{ ctx.envio.id }}</dd></div>
                                    <div><dt>@{{ textos.despachado }}</dt><dd>@{{ ctx.envio.despachado_texto || '—' }}</dd></div>
                                    <div class="lg-span2"><dt>@{{ textos.cliente }}</dt><dd>@{{ ctx.envio.cliente || '—' }}</dd></div>
                                    <div><dt>@{{ textos.telefono }}</dt><dd>@{{ ctx.envio.telefono || '—' }}</dd></div>
                                    <div class="lg-span2"><dt>@{{ textos.direccion }}</dt><dd>@{{ ctx.envio.direccion || '—' }}</dd></div>
                                    <div><dt>@{{ textos.destino }}</dt><dd>@{{ ctx.envio.destino || '—' }}</dd></div>
                                    <div><dt>@{{ textos.transportadora }}</dt><dd>@{{ ctx.envio.transportadora.nombre || ctx.envio.transportadora.titulo || '—' }}</dd></div>
                                    <div><dt>@{{ textos.guia }}</dt><dd>@{{ ctx.envio.guia || textos.sin_guia }}</dd></div>
                                    <div><dt>@{{ textos.tracking }}</dt><dd>@{{ ctx.envio.tracking || '—' }}</dd></div>
                                    <div><dt>@{{ textos.metodo }}</dt><dd>@{{ ctx.envio.metodo || '—' }}</dd></div>
                                    <div><dt>@{{ textos.bodega }}</dt><dd>@{{ ctx.envio.bodega || '—' }}</dd></div>
                                </dl>
                                <p v-if="ctx.cita_recogida" class="lge-nota" data-cita-recogida>@{{ t(textos.cita_recogida, ctx.cita_recogida) }}</p>
                            </section>

                            {{-- Productos y tallas: AQUI si van las tallas (la bandeja no las muestra). --}}
                            <section>
                                <p class="lge-sec"><span class="icon-product"></span>@{{ textos.sec_productos }}</p>
                                <div class="lg-prods" data-productos>
                                    <div v-for="p in ctx.productos" :key="p.clave" class="lg-panel lge-prod" :data-producto="p.clave">
                                        <div class="lg-prod-cab">
                                            <p class="lge-prod-nombre">@{{ p.nombre }}</p>
                                            <p class="lg-sub">@{{ p.sku }}<template v-if="p.color"> &middot; @{{ textos.color }}: @{{ p.color }}</template></p>
                                        </div>
                                        <div class="lg-tallas">
                                            <div v-for="f in p.filas" :key="f.shipment_item_id" class="lg-talla" :class="f.pendientes > 0 ? 'lg-talla--pend' : ''" :data-item="f.shipment_item_id">
                                                <b>@{{ f.talla }}</b>
                                                <span><small>@{{ textos.abr_desp }}</small>@{{ f.despachadas }}</span>
                                                <span><small>@{{ textos.abr_entr }}</small>@{{ f.entregadas }}</span>
                                                <span><small>@{{ textos.abr_dev }}</small>@{{ f.devueltas }}</span>
                                                <span><small>@{{ textos.abr_pend }}</small>@{{ f.pendientes }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {{-- Estado REPORTADO por el proveedor intermediario (Cargues). No es entrega registrada/validada ni pago. --}}
                            <section data-logistica="reporte-proveedor">
                                <p class="lge-sec"><span class="icon-information"></span>@{{ textos.sec_proveedor }}</p>
                                <template v-if="ctx.reporte_proveedor">
                                    <p class="mb-2"><span class="lg-pill lg-pill--azul" data-rp="cruzada">@{{ textos.rp_cruzada }}</span></p>
                                    <dl class="lg-panel lg-info" data-rp-info>
                                        <div><dt>@{{ textos.rp_proveedor }}</dt><dd>@{{ textos.rp_proveedor_nombre }}</dd></div>
                                        <div><dt>@{{ textos.rp_transportadora }}</dt><dd data-rp="transportadora">@{{ ctx.reporte_proveedor.transportadora_nombre || ctx.reporte_proveedor.transportadora_codigo }}</dd></div>
                                        <div><dt>@{{ textos.rp_estado }}</dt><dd data-rp="estado"><b>@{{ ctx.reporte_proveedor.estado }}</b></dd></div>
                                        <div><dt>@{{ textos.rp_fecha_estado }}</dt><dd data-rp="fecha-estado">@{{ ctx.reporte_proveedor.fecha_estado_texto || '—' }}</dd></div>
                                        <div><dt>@{{ textos.rp_fecha_envio }}</dt><dd>@{{ ctx.reporte_proveedor.fecha_envio_texto || '—' }}</dd></div>
                                        <div><dt>@{{ textos.rp_ultimo_cruce }}</dt><dd data-rp="ultimo-cruce">@{{ ctx.reporte_proveedor.ultimo_cruce_texto || '—' }}</dd></div>
                                        <div class="lg-span2"><dt>@{{ textos.rp_referencia }}</dt><dd data-rp="referencia">@{{ t(textos.rp_referencia_texto, { carga: ctx.reporte_proveedor.carga_id || '—', fila: ctx.reporte_proveedor.fila_origen || '—', n: ctx.reporte_proveedor.reportes }) }}</dd></div>
                                        <div><dt>@{{ textos.rp_recaudo_reportado }}</dt><dd data-rp="recaudo-reportado">@{{ ctx.reporte_proveedor.recaudo.reportado === null ? '—' : dinero(ctx.reporte_proveedor.recaudo.reportado) }}</dd></div>
                                        <div><dt>@{{ textos.rp_recaudo_interno }}</dt><dd data-rp="recaudo-interno">@{{ ctx.reporte_proveedor.recaudo.interno === null ? '—' : dinero(ctx.reporte_proveedor.recaudo.interno) }}</dd></div>
                                        <div><dt>@{{ textos.rp_diferencia }}</dt><dd data-rp="diferencia">@{{ ctx.reporte_proveedor.recaudo.diferencia === null ? '—' : dinero(ctx.reporte_proveedor.recaudo.diferencia) }}</dd></div>
                                        <div><dt>@{{ textos.rp_conciliacion }}</dt><dd><span class="lg-pill" :class="ctx.reporte_proveedor.recaudo.conciliacion === 'coincide' ? 'lg-pill--verde' : (ctx.reporte_proveedor.recaudo.conciliacion === 'difiere' ? 'lg-pill--amarillo' : 'lg-pill--gris')" data-rp="conciliacion">@{{ textos.rp_conc[ctx.reporte_proveedor.recaudo.conciliacion] }}</span></dd></div>
                                        <div><dt>@{{ textos.rp_valor_comercial }}</dt><dd>@{{ ctx.reporte_proveedor.valor_comercial === null ? '—' : dinero(ctx.reporte_proveedor.valor_comercial) }}</dd></div>
                                        <div><dt>@{{ textos.rp_valor_servicio }}</dt><dd data-rp="valor-servicio">@{{ ctx.reporte_proveedor.valor_servicio === null ? '—' : dinero(ctx.reporte_proveedor.valor_servicio) }}</dd></div>
                                        <div><dt>@{{ textos.rp_seguro }}</dt><dd data-rp="seguro">@{{ ctx.reporte_proveedor.valor_seguro === null ? '—' : dinero(ctx.reporte_proveedor.valor_seguro) }}<template v-if="ctx.reporte_proveedor.seguro"> &middot; @{{ ctx.reporte_proveedor.seguro }}</template></dd></div>
                                    </dl>
                                    <p class="lge-nota">@{{ textos.rp_cargos_nota }}</p>
                                    <p class="lge-nota" data-rp="aviso">@{{ textos.rp_aviso }}</p>
                                    <p class="lge-sec mt-3"><span class="icon-list"></span>@{{ textos.rp_historial }}</p>
                                    <ol class="lge-hist" data-rp="historial">
                                        <li v-for="h in ctx.reporte_proveedor.historial" :key="h.id" class="lge-hist-item">
                                            <p><b>@{{ h.estado_proveedor }}</b> &middot; @{{ h.fecha_estado_texto }}</p>
                                            <p class="lge-nota"><template v-if="h.estado_anterior">@{{ t(textos.rp_hist_ant, { estado: h.estado_anterior }) }} &middot; </template>@{{ t(textos.rp_hist_cruce, { cuando: h.cruce_texto }) }}</p>
                                        </li>
                                    </ol>
                                </template>
                                <template v-else>
                                    <p class="lg-panel p-3"><span class="lg-pill lg-pill--gris" data-rp="sin-reporte">@{{ textos.rp_sin_reporte }}</span> <span class="lge-nota">@{{ textos.rp_sin_reporte_ayuda }}</span></p>
                                </template>
                            </section>

                            {{-- Historial: solo eventos que existen (despacho, entrega, devolucion, recepcion, anulacion). --}}
                            <section>
                                <p class="lge-sec"><span class="icon-list"></span>@{{ textos.sec_historial }}</p>
                                <ol class="lge-hist" data-historial>
                                    <li v-for="h in ctx.historial" :key="h.tipo + h.id + (h.de || '')" class="lge-hist-item" :class="'lge-hist-' + h.tipo + (h.vigente ? '' : ' lge-hist-anulado')" :data-evento="h.tipo + ':' + h.id">
                                        <div class="lge-hist-cab">
                                            <b>@{{ textos['h_' + h.tipo] }}<template v-if="h.tipo === 'anulacion'"> (@{{ textos['h_' + h.de] }} #@{{ h.id }})</template><template v-else> #@{{ h.id }}</template></b>
                                            <span v-if="h.tipo !== 'anulacion' && h.tipo !== 'despacho' && ! h.vigente" class="lg-pill lg-pill--gris">@{{ textos.anulado }}</span>
                                            <span class="lg-sub">@{{ h.cuando_texto }}</span>
                                        </div>
                                        <p v-if="h.tipo === 'despacho'" class="lg-sub">@{{ t(textos.h_unidades, { n: h.cantidad }) }}<template v-if="h.detalle"> &middot; @{{ textos.guia }} @{{ h.detalle }}</template></p>
                                        <p v-else-if="h.tipo === 'recepcion'" class="lg-sub">@{{ t(textos.h_detalle_recepcion, { recibidas: h.cantidad, aptas: h.aptas, novedad: h.novedad, faltantes: h.faltantes }) }}<template v-if="h.reintegradas"> &middot; @{{ t(textos.h_reintegradas, { n: h.reintegradas }) }}</template></p>
                                        <p v-else-if="h.tipo !== 'anulacion'" class="lg-sub">@{{ t(textos.h_unidades, { n: h.cantidad }) }}<template v-if="h.fecha_real_texto"> &middot; @{{ h.fecha_real_texto }}</template></p>
                                        <p v-if="h.actor" class="lg-sub">@{{ t(textos.h_por, { actor: h.actor }) }}<template v-if="h.fuente"> &middot; @{{ t(textos.h_fuente, { fuente: h.fuente }) }}</template></p>
                                        <p v-if="h.detalle && h.tipo !== 'despacho'" class="lge-detalle">@{{ h.detalle }}</p>

                                        {{-- Anulacion por correccion (solo supervision); una recepcion ya reintegrada NO se anula. --}}
                                        <template v-if="(h.tipo === 'validacion' ? ctx.permisos.validar : ctx.permisos.anular) && h.vigente && h.tipo !== 'despacho' && h.tipo !== 'anulacion'">
                                            <button v-if="! (anulacion && anulacion.tipo === h.tipo && anulacion.id === h.id) && puedeAnular(h)" type="button" class="lge-enlace lge-enlace--rojo" data-logistica="anular" @click="abrirAnular(h)">@{{ textos.anular }}</button>
                                            <p v-else-if="! puedeAnular(h)" class="lge-nota" data-logistica="anular-bloqueada">@{{ motivoNoAnulable(h) }}</p>

                                            <div v-if="anulacion && anulacion.tipo === h.tipo && anulacion.id === h.id" class="lge-anular">
                                                <input type="text" maxlength="500" v-model="anulacion.motivo" data-logistica="anular-motivo" class="lg-input" :placeholder="textos.anular_motivo_ph">
                                                <p v-if="anulacion.error" class="lge-error">@{{ anulacion.error }}</p>
                                                <div class="lge-acciones">
                                                    <button type="button" class="lg-btn" @click="anulacion = null">@{{ textos.cancelar }}</button>
                                                    <button type="button" class="lg-btn lg-btn--no" :disabled="anulacion.enviando || ! anulacion.motivo.trim()" data-logistica="anular-confirmar" @click="confirmarAnular">@{{ anulacion.enviando ? textos.guardando : textos.anular_confirmar }}</button>
                                                </div>
                                            </div>
                                        </template>
                                    </li>
                                </ol>
                            </section>
                        </template>

                        {{-- ======================= MODO ENTREGAR / DEVOLVER ======================= --}}
                        <template v-else-if="modo === 'entregar' || modo === 'devolver'">
                            <div v-if="modo === 'devolver'" class="lge-banner lge-banner--rojo" data-logistica="aviso-devolucion">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo"><p class="lge-banner-tit">@{{ textos.aviso_devolucion }}</p><p class="lge-nota">@{{ textos.aviso_devolucion_2 }}</p></div>
                            </div>

                            <div class="lge-grid2">
                                <div>
                                    <label class="lge-label">@{{ modo === 'entregar' ? textos.fecha_entrega : textos.fecha_devolucion }}</label>
                                    <input type="date" v-model="form.fecha" :min="ctx.form.fecha_min" :max="ctx.form.hoy" data-logistica="fecha" class="lg-input">
                                </div>
                                <div v-if="ctx.form.requiere_transportadora">
                                    <label class="lge-label">@{{ textos.transportadora }} *</label>
                                    <select v-model="form.carrier_code" data-logistica="carrier" class="lg-input">
                                        <option value="">@{{ textos.transportadora_elegir }}</option>
                                        <option v-for="t in ctx.form.transportadoras" :key="t.codigo" :value="t.codigo">@{{ t.nombre }}</option>
                                    </select>
                                </div>
                            </div>

                            <section>
                                <div class="lge-fila-titulo">
                                    <p class="lge-sec"><span class="icon-product"></span>@{{ modo === 'entregar' ? textos.a_entregar : textos.a_devolver }}</p>
                                    <div class="lge-acciones">
                                        <button type="button" class="lge-enlace" data-logistica="todo" @click="llenarTodo">@{{ modo === 'entregar' ? textos.entregar_todo : textos.devolver_todo }}</button>
                                        <button type="button" class="lge-enlace" data-logistica="ninguna" @click="llenarNada">@{{ textos.ninguna }}</button>
                                    </div>
                                </div>

                                <div class="lg-prods">
                                    <div v-for="p in productosConPendientes" :key="p.clave" class="lg-panel lge-prod">
                                        <div class="lg-prod-cab">
                                            <p class="lge-prod-nombre">@{{ p.nombre }}</p>
                                            <p class="lg-sub">@{{ p.sku }}<template v-if="p.color"> &middot; @{{ p.color }}</template></p>
                                        </div>
                                        <div class="lge-lineas">
                                            <div v-for="f in p.filas" :key="f.shipment_item_id" class="lge-linea" :data-linea="f.shipment_item_id">
                                                <b class="lge-linea-talla">@{{ f.talla }}</b>
                                                <span class="lge-linea-pend"><small>@{{ textos.abr_pend }}</small>@{{ f.pendientes }}</span>
                                                <input type="number" min="0" :max="f.pendientes" step="1" inputmode="numeric" v-model.number="cantidades[f.shipment_item_id]" :data-cantidad="f.shipment_item_id" class="lg-input lge-cant" :aria-label="p.nombre + ' ' + f.talla" @blur="acotar(f)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="! productosConPendientes.length" class="lge-nota">@{{ textos.sin_pendientes }}</p>
                            </section>

                            <div v-if="modo === 'devolver'">
                                <label class="lge-label">@{{ textos.motivo_devolucion }} *</label>
                                <input type="text" maxlength="500" v-model="form.motivo" data-logistica="motivo" class="lg-input" :placeholder="textos.motivo_devolucion_ph">
                            </div>

                            <div>
                                <label class="lge-label">@{{ textos.evidencia }}</label>
                                <input type="text" maxlength="500" v-model="form.evidencia" data-logistica="evidencia" class="lg-input" :placeholder="textos.evidencia_ph">
                            </div>
                        </template>

                        {{-- ======================= MODO RECIBIR DEVOLUCION ======================= --}}
                        <template v-else-if="modo === 'recibir' && retornoSel">
                            <div class="lge-banner lge-banner--aviso">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo">
                                    <p class="lge-banner-tit">@{{ t(textos.recibiendo, { id: retornoSel.id, n: retornoSel.esperadas }) }}</p>
                                    <p class="lge-nota">@{{ retornoSel.fecha_texto }} &middot; @{{ retornoSel.motivo }}</p>
                                    <p class="lge-nota">@{{ t(textos.bodega_destino, { bodega: ctx.envio.bodega || '—' }) }}</p>
                                </div>
                            </div>

                            <div class="lge-grid2">
                                <div>
                                    <label class="lge-label">@{{ textos.fecha_recepcion }}</label>
                                    <input type="date" v-model="form.fecha" :min="retornoSel.fecha" :max="ctx.form.hoy" data-logistica="fecha" class="lg-input">
                                </div>
                                <div class="lge-alinear-fin">
                                    <button type="button" class="lg-btn" data-logistica="marcar-todo" @click="marcarTodoApto">@{{ textos.marcar_todo }}</button>
                                </div>
                            </div>

                            <section>
                                <p class="lge-sec"><span class="icon-product"></span>@{{ textos.sec_recepcion }}</p>
                                <div class="lg-prods">
                                    <div v-for="g in gruposRecepcion" :key="g.clave" class="lg-panel lge-prod">
                                        <div class="lg-prod-cab">
                                            <p class="lge-prod-nombre">@{{ g.producto }}</p>
                                            <p class="lg-sub">@{{ g.sku }}<template v-if="g.color"> &middot; @{{ g.color }}</template></p>
                                        </div>
                                        <div class="lge-recep">
                                            <div v-for="l in g.lineas" :key="l.retorno_item_id" class="lge-recep-linea" :class="lineaConDiferencia(l) ? 'lge-recep-dif' : ''" :data-retorno-item="l.retorno_item_id">
                                                <div class="lge-recep-cab"><b>@{{ l.etiqueta.talla }}</b><span class="lg-sub">@{{ textos.esperadas }}: @{{ l.esperadas }}</span></div>
                                                <div class="lge-recep-campos">
                                                    <label><small>@{{ textos.recibidas }}</small>
                                                        <input type="number" min="0" :max="l.esperadas" step="1" inputmode="numeric" v-model.number="recibido[l.retorno_item_id].recibidas" :data-recibidas="l.retorno_item_id" class="lg-input lge-cant" @input="alCambiarRecibidas(l)" @blur="acotarRecepcion(l)">
                                                    </label>
                                                    <label><small>@{{ textos.aptas }}</small>
                                                        <input type="number" min="0" :max="recibido[l.retorno_item_id].recibidas" step="1" inputmode="numeric" v-model.number="recibido[l.retorno_item_id].aptas" :data-aptas="l.retorno_item_id" class="lg-input lge-cant" @input="acotarRecepcion(l)" @blur="acotarRecepcion(l)">
                                                    </label>
                                                    <div class="lge-calc"><small>@{{ textos.novedad }}</small><b :data-novedad="l.retorno_item_id">@{{ novedadDe(l) }}</b></div>
                                                    <div class="lge-calc"><small>@{{ textos.faltantes }}</small><b :data-faltantes="l.retorno_item_id">@{{ faltantesDe(l) }}</b></div>
                                                </div>
                                                <div v-if="lineaConDiferencia(l)" class="grid gap-2">
                                                    {{-- C5: las defectuosas NO vuelven al inventario vendible; se clasifican (motivo ≠ origen probable). --}}
                                                    <template v-if="novedadDe(l) > 0">
                                                        <p class="lge-nota" data-logistica="nota-defectuosas">@{{ textos.nota_defectuosas }}</p>
                                                        <div class="lge-recep-campos">
                                                            <label><small>@{{ textos.defecto_motivo }} *</small>
                                                                <select v-model="recibido[l.retorno_item_id].defecto_motivo" :data-defecto-motivo="l.retorno_item_id" @change="errorDefecto = null; error = null" class="lg-input" :class="errorDefecto === l.retorno_item_id ? 'lge-invalido' : ''">
                                                                    <option value="">@{{ textos.defecto_motivo_ph }}</option>
                                                                    <option v-for="m in ctx.catalogo_defectos.motivos" :key="m.codigo" :value="m.codigo">@{{ m.nombre }}</option>
                                                                </select>
                                                            </label>
                                                            <label><small>@{{ textos.defecto_origen }}</small>
                                                                <select v-model="recibido[l.retorno_item_id].defecto_origen" :data-defecto-origen="l.retorno_item_id" class="lg-input">
                                                                    <option v-for="o in ctx.catalogo_defectos.origenes" :key="o.codigo" :value="o.codigo">@{{ o.nombre }}</option>
                                                                </select>
                                                            </label>
                                                        </div>
                                                        <input v-if="detalleDefectoRequerido(l)" type="text" maxlength="500" v-model="recibido[l.retorno_item_id].defecto_detalle" :data-defecto-detalle="l.retorno_item_id" @input="errorDefecto = null; error = null" class="lg-input" :class="errorDefecto === l.retorno_item_id ? 'lge-invalido' : ''" :placeholder="textos.defecto_detalle_ph">
                                                        <p v-if="errorDefecto === l.retorno_item_id" class="lge-error" data-logistica="error-defecto">@{{ textos.err_defecto }}</p>
                                                    </template>
                                                    <input type="text" maxlength="500" v-model="recibido[l.retorno_item_id].motivo" :data-motivo="l.retorno_item_id" class="lg-input" :class="errorLinea === l.retorno_item_id ? 'lge-invalido' : ''" :placeholder="faltantesDe(l) > 0 ? textos.motivo_novedad_ph : textos.observacion_linea_ph">
                                                    <p v-if="errorLinea === l.retorno_item_id" class="lge-error">@{{ textos.err_motivo_linea }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <div>
                                <label class="lge-label">@{{ textos.observacion }}</label>
                                <input type="text" maxlength="500" v-model="form.observacion" data-logistica="observacion" class="lg-input" :placeholder="textos.observacion_ph">
                            </div>

                            {{-- Resumen ANTES de confirmar: las diferencias se resaltan. --}}
                            <div class="lge-resumen" :class="hayDiferencias ? 'lge-resumen--dif' : ''" data-logistica="resumen-recepcion">
                                <p class="lge-sec">@{{ textos.resumen_recepcion }}</p>
                                <div class="lge-resumen-fila"><span>@{{ textos.esperadas }}</span><b>@{{ totalesRecepcion.esperadas }}</b></div>
                                <div class="lge-resumen-fila"><span>@{{ textos.recibidas }}</span><b>@{{ totalesRecepcion.recibidas }}</b></div>
                                <div class="lge-resumen-fila lge-resumen-ok"><span>@{{ textos.aptas }}</span><b data-total-aptas>@{{ totalesRecepcion.aptas }}</b></div>
                                <div class="lge-resumen-fila" :class="totalesRecepcion.novedad ? 'lge-resumen-dif' : ''"><span>@{{ textos.con_novedad }}</span><b data-total-novedad>@{{ totalesRecepcion.novedad }}</b></div>
                                <div class="lge-resumen-fila" :class="totalesRecepcion.faltantes ? 'lge-resumen-dif' : ''"><span>@{{ textos.faltantes }}</span><b data-total-faltantes>@{{ totalesRecepcion.faltantes }}</b></div>
                                <p class="lge-nota lge-nota-reingreso" data-logistica="ayuda-reingreso">@{{ textos.ayuda_reingreso }}</p>
                            </div>
                        </template>

                        {{-- ======================= MODO VALIDAR ENTREGA ======================= --}}
                        <template v-else-if="modo === 'validar'">
                            <div class="lge-banner lge-banner--aviso" data-logistica="aviso-validar">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo"><p class="lge-banner-tit">@{{ textos.validar_aviso }}</p><p class="lge-nota">@{{ textos.validar_aviso_2 }}</p></div>
                            </div>
                            <div class="lg-panel lg-info" data-validar-resumen>
                                <div><dt>@{{ textos.abr_entr_largo }}</dt><dd>@{{ ctx.resumen.entregadas }}</dd></div>
                                <div><dt>@{{ textos.abr_dev_largo }}</dt><dd>@{{ ctx.resumen.devueltas }}</dd></div>
                            </div>
                            <div>
                                <label class="lge-label">@{{ textos.observacion }}</label>
                                <input type="text" maxlength="500" v-model="form.observacion" data-logistica="observacion" class="lg-input" :placeholder="textos.validar_observacion_ph">
                            </div>
                        </template>

                        {{-- ======================= MODO COBRAR (contra entrega, por Cartera) ======================= --}}
                        <template v-else-if="modo === 'cobrar'">
                            <div class="lge-banner lge-banner--aviso" data-logistica="aviso-cobro">
                                <span class="icon-information"></span>
                                <div class="lge-banner-cuerpo"><p class="lge-banner-tit">@{{ t(textos.cobro_aviso, { saldo: dinero(ctx.cierre.cobro.saldo) }) }}</p><p class="lge-nota">@{{ textos.cobro_aviso_2 }}</p></div>
                            </div>
                            <div class="lge-grid2">
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_monto }}</label>
                                    <v-moneda :max-enteros="9" v-model="cobro.monto" data-logistica="cobro-monto" class="lg-input" :aria-label="textos.cobro_monto"></v-moneda>
                                </div>
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_medio }}</label>
                                    <select v-model="cobro.medio_cobro" data-logistica="cobro-medio" class="lg-input">
                                        <option v-for="m in ctx.cierre.cobro.medios" :key="m.codigo" :value="m.codigo">@{{ m.etiqueta }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="lge-grid2">
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_fecha }}</label>
                                    <input type="date" v-model="cobro.fecha_pago" :max="ctx.form.hoy" data-logistica="cobro-fecha" class="lg-input">
                                </div>
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_referencia }}<template v-if="medioCobro && medioCobro.referencia === 'requerida'"> *</template></label>
                                    <input type="text" maxlength="100" v-model="cobro.referencia" data-logistica="cobro-referencia" class="lg-input">
                                </div>
                            </div>
                        </template>

                        <p v-if="error" class="lge-error lge-error--caja" data-logistica="error">@{{ error }}</p>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div v-if="ctx" class="lge-pie">
                        <template v-if="modo === 'ver'">
                            <button type="button" class="lg-btn" @click="cerrar">@{{ textos.cerrar }}</button>
                            <button v-if="ctx.permisos.registrar && ctx.resumen.pendientes > 0" type="button" class="lg-btn lg-btn--no" data-logistica="ir-devolver" @click="entrar('devolver')">@{{ textos.devolucion }}</button>
                            <button v-if="ctx.permisos.registrar && ctx.resumen.pendientes > 0" type="button" class="lg-btn lg-btn--ok lge-principal" data-logistica="ir-entregar" @click="entrar('entregar')">@{{ textos.registrar_entrega }}</button>
                            <button v-if="ctx.permisos.validar && ctx.lista_para_validar" type="button" class="lg-btn lg-btn--ok lge-principal" data-logistica="ir-validar" @click="entrar('validar')">@{{ textos.validar_entrega }}</button>
                        </template>

                        <template v-else>
                            <button type="button" class="lg-btn" :disabled="enviando" data-logistica="volver" @click="entrar('ver')">@{{ textos.volver }}</button>
                            <button v-if="modo === 'entregar'" type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando || ! totalForm" data-logistica="guardar" @click="enviar">@{{ enviando ? textos.guardando : t(textos.guardar_entrega, { n: totalForm }) }}</button>
                            <button v-else-if="modo === 'validar'" type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="guardar" @click="confirmarValidacion">@{{ enviando ? textos.guardando : textos.confirmar_validacion }}</button>
                            <button v-else-if="modo === 'cobrar'" type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="guardar" @click="confirmarCobro">@{{ enviando ? textos.guardando : textos.confirmar_cobro }}</button>
                            <button v-else-if="modo === 'devolver'" type="button" class="lg-btn lg-btn--no lge-principal" :disabled="enviando || ! totalForm" data-logistica="guardar" @click="enviar">@{{ enviando ? textos.guardando : t(textos.guardar_devolucion, { n: totalForm }) }}</button>
                            <button v-else type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="guardar" @click="confirmarRecepcion">@{{ enviando ? textos.guardando : textos.confirmar_recepcion }}</button>
                        </template>
                    </div>
                </x-slot:footer>
            </x-admin::drawer>
        </div>
    </script>

    <script type="module">
        app.component('v-logistica-entregas', {
            template: '#v-logistica-entregas-template',

            props: ['urlContexto', 'urlEntregar', 'urlDevolver', 'urlRecibir', 'urlAnularEntrega', 'urlAnularDevolucion', 'urlAnularRecepcion', 'urlValidar', 'urlCobrar', 'urlAnularValidacion', 'textos', 'estados'],

            data() {
                return {
                    envioId: null,
                    modo: 'ver',
                    cargando: false,
                    errorCarga: null,
                    ctx: null,
                    enviando: false,
                    error: null,
                    clave: null,
                    form: { fecha: '', carrier_code: '', motivo: '', evidencia: '', observacion: '' },
                    cantidades: {},
                    // Recepcion: retorno_item_id => { recibidas, aptas, motivo }. `novedad` y `faltantes` se DERIVAN (nunca incoherentes).
                    recibido: {},
                    // C5: linea con la clasificacion de defectuosas incompleta.
                    errorDefecto: null,
                    retornoId: null,
                    errorLinea: null,
                    anulacion: null,
                    cobro: { monto: '', medio_cobro: '', referencia: '', fecha_pago: '' },
                };
            },

            computed: {
                titulo() {
                    return this.textos['titulo_' + this.modo] || this.textos.titulo_ver;
                },

                colorEntrega() {
                    return { pendiente: 'amarillo', parcial: 'amarillo', entregado: 'verde', retornado: 'rojo', entregado_retorno: 'azul' }[this.ctx.estado_entrega] || 'gris';
                },

                colorValidacion() {
                    return { sin_registro: 'gris', registrada: 'verde', anulada: 'gris', pendiente_confirmar: 'lila', confirmado: 'verde', requiere_revision: 'rojo' }[this.ctx.validacion] || 'gris';
                },

                medioCobro() {
                    return this.ctx ? (this.ctx.cierre.cobro.medios.find(m => m.codigo === this.cobro.medio_cobro) || null) : null;
                },

                devolucionesPendientes() {
                    return this.ctx ? this.ctx.devoluciones.filter(d => d.vigente && d.estado === 'pendiente_recibir') : [];
                },

                devolucionesRevision() {
                    return this.ctx ? this.ctx.devoluciones.filter(d => d.vigente && d.estado === 'requiere_revision') : [];
                },

                retornoSel() {
                    return this.ctx && this.retornoId ? (this.ctx.devoluciones.find(d => d.id === this.retornoId) || null) : null;
                },

                // Productos con filas por entregar / devolver (solo lo que tiene pendiente).
                productosConPendientes() {
                    if (! this.ctx) {
                        return [];
                    }

                    return this.ctx.productos
                        .map(p => Object.assign({}, p, { filas: p.filas.filter(f => f.pendientes > 0) }))
                        .filter(p => p.filas.length);
                },

                totalForm() {
                    return Object.keys(this.cantidades).reduce((s, id) => s + (parseInt(this.cantidades[id], 10) || 0), 0);
                },

                // Lineas de la devolucion a recibir, agrupadas por producto/color para leerlas igual que en «Ver».
                gruposRecepcion() {
                    if (! this.retornoSel) {
                        return [];
                    }

                    const grupos = {};

                    this.retornoSel.lineas.forEach(l => {
                        const clave = (l.etiqueta.producto || '') + '|' + (l.etiqueta.color || '');

                        grupos[clave] = grupos[clave] || { clave: clave, producto: l.etiqueta.producto, color: l.etiqueta.color, sku: l.etiqueta.sku, lineas: [] };
                        grupos[clave].lineas.push(l);
                    });

                    return Object.values(grupos);
                },

                totalesRecepcion() {
                    const t = { esperadas: 0, recibidas: 0, aptas: 0, novedad: 0, faltantes: 0 };

                    if (! this.retornoSel) {
                        return t;
                    }

                    this.retornoSel.lineas.forEach(l => {
                        const r = this.recibido[l.retorno_item_id];

                        if (! r) {
                            return;
                        }

                        t.esperadas += l.esperadas;
                        t.recibidas += this.num(r.recibidas);
                        t.aptas += this.num(r.aptas);
                        t.novedad += this.novedadDe(l);
                        t.faltantes += this.faltantesDe(l);
                    });

                    return t;
                },

                hayDiferencias() {
                    return this.totalesRecepcion.novedad > 0 || this.totalesRecepcion.faltantes > 0;
                },
            },

            mounted() {
                document.addEventListener('click', this.alClic);
            },

            beforeUnmount() {
                document.removeEventListener('click', this.alClic);
            },

            methods: {
                t(texto, valores) {
                    return Object.keys(valores || {}).reduce((s, k) => s.split(':' + k).join(valores[k]), texto || '');
                },

                num(v) {
                    const n = parseInt(v, 10);

                    return Number.isFinite(n) && n > 0 ? n : 0;
                },

                colorDevolucion(estado) {
                    return { pendiente_recibir: 'lila', requiere_revision: 'rojo', recibida: 'verde', recibida_novedades: 'amarillo', reintegrada: 'verde', reintegrada_parcial: 'amarillo', anulada: 'gris' }[estado] || 'gris';
                },

                // ---- apertura ----
                alClic(evento) {
                    const boton = evento.target.closest('[data-logistica-entregas]');

                    if (! boton) {
                        return;
                    }

                    evento.preventDefault();
                    this.abrir(parseInt(boton.dataset.envio, 10), boton.dataset.modo || 'ver');
                },

                abrir(envioId, modo) {
                    this.envioId = envioId;
                    this.ctx = null;
                    this.errorCarga = null;
                    this.error = null;
                    this.anulacion = null;
                    this.enviando = false;
                    this.modo = 'ver';
                    this.cargando = true;
                    this.$refs.drawer.open();

                    this.cargarContexto().then(() => { if (this.ctx) { this.entrar(modo); } });
                },

                cargarContexto() {
                    return this.$axios.get(this.urlContexto.replace('__ID__', this.envioId))
                        .then(r => { this.ctx = r.data.contexto; })
                        .catch(e => { this.errorCarga = (e.response && e.response.data && e.response.data.mensaje) || this.textos.error_carga; })
                        .finally(() => { this.cargando = false; });
                },

                nuevaClave() {
                    this.clave = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'k' + Date.now() + Math.random().toString(36).slice(2);
                },

                // ---- modos ----
                entrar(modo) {
                    this.error = null;
                    this.anulacion = null;
                    this.errorLinea = null;
                    this.nuevaClave();

                    // Un modo que no corresponde (sin permiso o sin pendientes) cae a «Ver»: el servidor igual lo rechazaria.
                    const puedeActuar = this.ctx.permisos.registrar && this.ctx.resumen.pendientes > 0;

                    if ((modo === 'entregar' || modo === 'devolver') && ! puedeActuar) {
                        modo = 'ver';
                    }

                    this.modo = modo;
                    this.form = { fecha: this.ctx.form.hoy, carrier_code: '', motivo: '', evidencia: '', observacion: '' };

                    // Validar y cobrar solo existen con permiso y cuando corresponden; el servidor igual lo vuelve a exigir.
                    if (modo === 'validar' && ! (this.ctx.permisos.validar && this.ctx.lista_para_validar)) {
                        this.modo = 'ver';
                    }

                    if (modo === 'cobrar') {
                        if (this.ctx.permisos.validar && this.ctx.cierre.cobro.disponible) {
                            const medios = this.ctx.cierre.cobro.medios;

                            this.cobro = { monto: String(this.ctx.cierre.cobro.saldo), medio_cobro: (medios.find(m => m.codigo === 'contraentrega_recaudo') || medios[0] || {}).codigo || '', referencia: '', fecha_pago: this.ctx.form.hoy };
                        } else {
                            this.modo = 'ver';
                        }
                    }

                    if (modo === 'entregar' || modo === 'devolver') {
                        this.cantidades = {};
                        this.productosConPendientes.forEach(p => p.filas.forEach(f => { this.cantidades[f.shipment_item_id] = modo === 'entregar' ? f.pendientes : 0; }));
                    }
                },

                entrarRecibir(devolucion) {
                    this.entrar('ver');
                    this.modo = 'recibir';
                    this.retornoId = devolucion.id;
                    this.form.fecha = this.ctx.form.hoy;
                    this.recibido = {};
                    this.marcarTodoApto();
                },

                // ---- entregar / devolver ----
                acotar(f) {
                    const n = this.num(this.cantidades[f.shipment_item_id]);

                    this.cantidades[f.shipment_item_id] = Math.min(n, f.pendientes);
                },

                llenarTodo() {
                    this.productosConPendientes.forEach(p => p.filas.forEach(f => { this.cantidades[f.shipment_item_id] = f.pendientes; }));
                },

                llenarNada() {
                    Object.keys(this.cantidades).forEach(id => { this.cantidades[id] = 0; });
                },

                enviar() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    if (! this.totalForm) {
                        this.error = this.textos.err_sin_unidades;

                        return;
                    }

                    if (! this.form.fecha) {
                        this.error = this.textos.err_fecha;

                        return;
                    }

                    if (this.modo === 'devolver' && ! this.form.motivo.trim()) {
                        this.error = this.textos.err_motivo;

                        return;
                    }

                    if (this.ctx.form.requiere_transportadora && ! this.form.carrier_code) {
                        this.error = this.textos.err_transportadora;

                        return;
                    }

                    const items = {};

                    Object.keys(this.cantidades).forEach(id => { const n = this.num(this.cantidades[id]); if (n > 0) { items[id] = n; } });

                    const url = (this.modo === 'entregar' ? this.urlEntregar : this.urlDevolver).replace('__ID__', this.envioId);

                    this.publicar(url, {
                        order_id: this.ctx.envio.pedido_id,
                        fecha: this.form.fecha,
                        items: items,
                        idempotency_key: this.clave,
                        carrier_code: this.form.carrier_code,
                        evidencia_referencia: this.form.evidencia,
                        motivo: this.form.motivo,
                    });
                },

                // ---- validar entrega y cobrar (cierre) ----
                dinero(v) {
                    // Moneda de ByLopez («$ 69.500»): conserva los decimales reales (no redondea el importe que se muestra).
                    return window.ByLopezMoneda.precio(Number(v) || 0);
                },

                confirmarValidacion() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    this.publicar(this.urlValidar.replace('__ID__', this.envioId), { idempotency_key: this.clave, observacion: this.form.observacion });
                },

                confirmarCobro() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    const monto = parseFloat(String(this.cobro.monto).replace(',', '.'));

                    if (! (monto > 0) || monto > Number(this.ctx.cierre.cobro.saldo) + 0.0001) {
                        this.error = this.t(this.textos.err_cobro_monto, { saldo: this.dinero(this.ctx.cierre.cobro.saldo) });

                        return;
                    }

                    if (this.medioCobro && this.medioCobro.referencia === 'requerida' && ! String(this.cobro.referencia).trim()) {
                        this.error = this.textos.err_cobro_referencia;

                        return;
                    }

                    this.publicar(this.urlCobrar.replace('__ID__', this.envioId), {
                        monto: String(monto), medio_cobro: this.cobro.medio_cobro, referencia: this.cobro.referencia, fecha_pago: this.cobro.fecha_pago, idempotency_key: this.clave,
                    });
                },

                // ---- recibir devolucion ----
                novedadDe(l) {
                    const r = this.recibido[l.retorno_item_id];

                    return r ? Math.max(0, this.num(r.recibidas) - this.num(r.aptas)) : 0;
                },

                faltantesDe(l) {
                    const r = this.recibido[l.retorno_item_id];

                    return r ? Math.max(0, l.esperadas - this.num(r.recibidas)) : 0;
                },

                // C5: «otro» (y cualquier motivo marcado en el catalogo) exige descripcion.
                detalleDefectoRequerido(l) {
                    const r = this.recibido[l.retorno_item_id];
                    const m = r ? this.ctx.catalogo_defectos.motivos.find(x => x.codigo === r.defecto_motivo) : null;

                    return !! (m && m.requiere_detalle);
                },

                lineaConDiferencia(l) {
                    return this.novedadDe(l) > 0 || this.faltantesDe(l) > 0;
                },

                // El sistema propone (todo recibido y apto); Bodega solo trabaja las excepciones.
                marcarTodoApto() {
                    const nuevo = {};

                    this.retornoSel.lineas.forEach(l => {
                        const previo = this.recibido[l.retorno_item_id] || {};

                        nuevo[l.retorno_item_id] = {
                            recibidas: l.esperadas, aptas: l.esperadas, motivo: previo.motivo || '',
                            defecto_motivo: previo.defecto_motivo || '', defecto_origen: previo.defecto_origen || 'por_determinar', defecto_detalle: previo.defecto_detalle || '',
                        };
                    });

                    this.recibido = nuevo;
                    this.errorLinea = null;
                },

                alCambiarRecibidas(l) {
                    const r = this.recibido[l.retorno_item_id];

                    // Si baja lo recibido, lo apto nunca puede superarlo.
                    if (this.num(r.aptas) > this.num(r.recibidas)) {
                        r.aptas = this.num(r.recibidas);
                    }

                    this.errorLinea = null;
                },

                acotarRecepcion(l) {
                    const r = this.recibido[l.retorno_item_id];

                    r.recibidas = Math.min(this.num(r.recibidas), l.esperadas);
                    r.aptas = Math.min(this.num(r.aptas), r.recibidas);
                },

                confirmarRecepcion() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;
                    this.errorLinea = null;

                    this.errorDefecto = null;

                    // C5 (mismo requisito que el servidor): toda defectuosa lleva motivo del catalogo; «otro» lleva descripcion.
                    const sinClasificar = this.retornoSel.lineas.find(l => this.novedadDe(l) > 0 && (! this.recibido[l.retorno_item_id].defecto_motivo
                        || (this.detalleDefectoRequerido(l) && ! String(this.recibido[l.retorno_item_id].defecto_detalle || '').trim())));

                    if (sinClasificar) {
                        this.errorDefecto = sinClasificar.retorno_item_id;
                        this.error = this.textos.err_defecto;

                        return;
                    }

                    // Faltantes: motivo por linea (las defectuosas clasificadas ya traen el suyo).
                    const sinMotivo = this.retornoSel.lineas.find(l => this.faltantesDe(l) > 0 && ! String(this.recibido[l.retorno_item_id].motivo || '').trim());

                    if (sinMotivo) {
                        this.errorLinea = sinMotivo.retorno_item_id;
                        this.error = this.textos.err_motivo_linea;

                        return;
                    }

                    const t = this.totalesRecepcion;
                    const lineas = {};

                    this.retornoSel.lineas.forEach(l => {
                        const r = this.recibido[l.retorno_item_id];

                        lineas[l.retorno_item_id] = {
                            recibidas: this.num(r.recibidas), aptas: this.num(r.aptas), novedad: this.novedadDe(l), motivo: r.motivo || '',
                            defectos: this.novedadDe(l) > 0
                                ? [{ cantidad: this.novedadDe(l), motivo: r.defecto_motivo, origen: r.defecto_origen || 'por_determinar', detalle: r.defecto_detalle || '', observacion: r.motivo || '' }]
                                : [],
                        };
                    });

                    // Confirmacion con el modal del proyecto (no el confirm() del navegador): dice que entra al inventario vendible y que no.
                    // Cancelar no escribe nada; solo «Confirmar recepción» publica.
                    const bodega = this.ctx.envio.bodega || '';
                    const partes = [this.t(this.textos.confirmar_resumen, { recibidas: t.recibidas, esperadas: t.esperadas })];

                    partes.push(t.aptas > 0 ? this.t(this.textos.confirmar_aptas, { n: t.aptas, bodega: bodega }) : this.textos.confirmar_sin_aptas);

                    if (t.novedad > 0) {
                        partes.push(this.t(this.textos.confirmar_novedad, { n: t.novedad }));
                    }

                    if (t.faltantes > 0) {
                        partes.push(this.t(this.textos.confirmar_faltantes, { n: t.faltantes }));
                    }

                    const url = this.urlRecibir.replace('__ID__', this.retornoSel.id);
                    const cuerpo = { fecha: this.form.fecha, lineas: lineas, observacion: this.form.observacion, idempotency_key: this.clave };

                    this.$emitter.emit('open-confirm-modal', {
                        title: this.textos.confirmar_titulo,
                        message: partes.join(' '),
                        options: { btnDisagree: this.textos.cancelar, btnAgree: this.textos.confirmar_recepcion },
                        agree: () => this.publicar(url, cuerpo),
                    });
                },

                // ---- envio comun ----
                publicar(url, datos) {
                    this.enviando = true;

                    this.$axios.post(url, datos)
                        .then(r => this.alExito(r.data))
                        .catch(e => { this.error = this.mensajeDeError(e); })
                        .finally(() => { this.enviando = false; });
                },

                alExito(datos) {
                    this.ctx = datos.contexto;
                    this.$emitter.emit('add-flash', { type: 'success', message: datos.mensaje });

                    if (datos.aviso) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: datos.aviso });
                    }

                    this.entrar('ver');
                    this.refrescar(this.envioId);
                },

                mensajeDeError(e) {
                    const datos = (e.response || {}).data || {};

                    return datos.mensaje || (datos.errors ? Object.values(datos.errors)[0][0] : null) || this.textos.error_generico;
                },

                // ---- anulaciones (solo supervision; una recepcion reintegrada no se anula) ----
                puedeAnular(h) {
                    if (h.tipo === 'validacion') {
                        return true;
                    }

                    // Con la entrega validada nada de lo que la sostiene se anula: primero se anula la validacion.
                    if (this.ctx.validada) {
                        return false;
                    }

                    if (h.tipo === 'recepcion') {
                        const d = this.ctx.devoluciones.find(x => x.recepcion && x.recepcion.id === h.id);

                        return !! (d && d.recepcion.anulable);
                    }

                    if (h.tipo === 'devolucion') {
                        const d = this.ctx.devoluciones.find(x => x.id === h.id);

                        return ! (d && d.recepcion);
                    }

                    return true;
                },

                motivoNoAnulable(h) {
                    if (this.ctx.validada) {
                        return this.textos.anular_validada_bloqueada;
                    }

                    return h.tipo === 'recepcion' ? this.textos.anular_recepcion_bloqueada : this.textos.anular_devolucion_bloqueada;
                },

                abrirAnular(h) {
                    this.anulacion = { tipo: h.tipo, id: h.id, motivo: '', enviando: false, error: null };
                },

                confirmarAnular() {
                    const a = this.anulacion;
                    const url = { entrega: this.urlAnularEntrega, devolucion: this.urlAnularDevolucion, recepcion: this.urlAnularRecepcion, validacion: this.urlAnularValidacion }[a.tipo];

                    a.enviando = true;
                    a.error = null;

                    this.$axios.post(url.replace('__ID__', a.id), { motivo: a.motivo })
                        .then(r => {
                            this.ctx = r.data.contexto;
                            this.anulacion = null;
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.refrescar(this.envioId);
                        })
                        .catch(e => { a.error = this.mensajeDeError(e); a.enviando = false; });
                },

                // ---- cierre y actualizacion de la bandeja ----
                cerrar() {
                    this.$refs.drawer.close();
                },

                alCerrar() {
                    this.anulacion = null;
                },

                /*
                 * Reemplaza SOLO la fila/tarjeta del envio y los indicadores con el HTML fresco de la propia bandeja (mismos filtros y
                 * pagina); si el envio ya no cumple el filtro, desaparece. Sin recargar la pagina.
                 */
                refrescar(envioId) {
                    const selector = '[data-envio="' + envioId + '"]';

                    fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                        .then(r => r.text())
                        .then(html => {
                            const doc = new DOMParser().parseFromString(html, 'text/html');
                            const nuevos = Array.from(doc.querySelectorAll(selector));

                            Array.from(document.querySelectorAll(selector)).forEach((viejo, i) => {
                                if (nuevos[i]) {
                                    viejo.replaceWith(document.importNode(nuevos[i], true));
                                } else {
                                    viejo.remove();
                                }
                            });

                            doc.querySelectorAll('[data-kpi]').forEach(nuevo => {
                                const actual = document.querySelector('[data-kpi="' + nuevo.dataset.kpi + '"]');

                                if (actual) {
                                    actual.textContent = nuevo.textContent;
                                }
                            });
                        })
                        .catch(() => { /* la fila se actualizara al recargar; lo registrado ya quedo guardado */ });
                },
            },
        });
    </script>
@endPushOnce
