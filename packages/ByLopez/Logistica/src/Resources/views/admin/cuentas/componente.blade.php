{{--
    DRAWER de la CUENTA CORRIENTE de una transportadora (Cuenta actual · Requiere revision · Cuadrar/Liquidar · Ajuste · Historico),
    sin salir del listado. Capa de presentacion: el contexto sale de ConsultaCuentasService (GET JSON) y cada accion llama a su
    servicio (LiquidarCuentaService, AjusteCuentaService), que vuelve a calcular saldo, movimientos incluidos, permisos e
    idempotencia. Aqui no se calcula ningun saldo que se envie: solo se muestra la vista previa del cuadre.

    Los botones del listado llevan `data-logistica-cuenta` + `data-cuenta-id`. Tras guardar se reemplazan solo la fila/tarjeta de
    la cuenta (`[data-cuenta]`) y los indicadores (`[data-kpi]`) con el HTML fresco del propio listado.
--}}
<v-logistica-cuentas
    url-contexto="{{ route('admin.logistica.cuentas.contexto', ['transportadora' => '__ID__']) }}"
    url-liquidar="{{ route('admin.logistica.cuentas.liquidar', ['transportadora' => '__ID__']) }}"
    url-propuesta="{{ route('admin.logistica.cuentas.propuesta', ['transportadora' => '__ID__']) }}"
    url-cobro="{{ route('admin.logistica.cuentas.cobro', ['transportadora' => '__ID__']) }}"
    url-ajustar="{{ route('admin.logistica.cuentas.ajustar', ['transportadora' => '__ID__']) }}"
    url-revision="{{ route('admin.logistica.cuentas.revision', ['transportadora' => '__ID__', 'envio' => '__ENVIO__']) }}"
    url-liquidacion="{{ route('admin.logistica.cuentas.liquidacion', ['liquidacion' => '__ID__']) }}"
    :textos='@json(trans('logistica::app.cuentas.drawer'))'
    :conceptos='@json(trans('logistica::app.cuentas.conceptos'))'
    prefijo="{{ \ByLopez\Logistica\Support\Moneda::prefijo() }} "
></v-logistica-cuentas>

@pushOnce('scripts')
    <script type="text/x-template" id="v-logistica-cuentas-template">
        <div>
            <x-admin::drawer ref="drawer" width="760px" @close="alCerrar">
                <x-slot:header>
                    <div v-if="ctx" class="flex flex-wrap items-center gap-2" data-logistica="cabecera">
                        <p class="lge-titulo">@{{ ctx.transportadora.nombre }}</p>
                        <span class="lg-pill lg-pill--azul">@{{ textos.badge }}</span>
                    </div>
                    <p v-else class="lge-titulo">@{{ textos.cargando }}</p>

                    <p v-if="ctx" class="lge-sub" data-logistica="periodo">
                        @{{ ctx.periodo.desde ? t(textos.periodo, { desde: ctx.periodo.desde }) : textos.periodo_vacio }}
                    </p>
                </x-slot:header>

                <x-slot:content>
                    <div v-if="cargando && ! ctx" class="lge-nota">@{{ textos.cargando }}</div>

                    <div v-else-if="errorCarga" class="lge-error">@{{ errorCarga }}</div>

                    <div v-else-if="ctx" class="lge-pila" data-logistica="contenido">
                        {{-- ===== Pestañas: cuenta actual / historico ===== --}}
                        <div v-if="modo === 'cuenta' || modo === 'historico'" class="lgc-tabs" role="tablist">
                            <button type="button" class="lgc-tab" :class="modo === 'cuenta' ? 'lgc-tab--activa' : ''" role="tab" :aria-selected="modo === 'cuenta'" data-logistica="tab-cuenta" @click="entrar('cuenta')">@{{ textos.tab_cuenta }}</button>
                            <button type="button" class="lgc-tab" :class="modo === 'historico' ? 'lgc-tab--activa' : ''" role="tab" :aria-selected="modo === 'historico'" data-logistica="tab-historico" @click="entrar('historico')">@{{ textos.tab_historico }} (@{{ ctx.liquidaciones.length }})</button>
                        </div>

                        {{-- ===== Saldo principal (en palabras; el color solo acompaña) ===== --}}
                        <div v-if="modo !== 'detalle'" class="lgc-hero" :class="'lgc-hero--' + ctx.saldo.sentido" data-logistica="saldo" :data-sentido="ctx.saldo.sentido">
                            <p class="lgc-hero-monto">@{{ dinero(ctx.saldo.valor) }}</p>
                            <p class="lgc-hero-texto">@{{ ctx.saldo.texto }}</p>
                        </div>

                        {{-- ======================= CUENTA ACTUAL ======================= --}}
                        <template v-if="modo === 'cuenta'">
                            <div class="lg-panel lgc-resumen" data-logistica="resumen">
                                <div class="lgc-resumen-fila"><span>@{{ textos.resumen_recaudos }}</span><b data-resumen="recaudos">@{{ dinero(ctx.resumen.recaudos) }}</b></div>
                                <div class="lgc-resumen-fila"><span>@{{ textos.resumen_servicios }}</span><b data-resumen="servicios">@{{ dinero(ctx.resumen.servicios) }}</b></div>
                                <div class="lgc-resumen-fila"><span>@{{ textos.resumen_ajustes }}</span><b data-resumen="ajustes">@{{ firmado(ctx.resumen.ajustes) }}</b></div>
                                <div class="lgc-resumen-fila lgc-resumen-total"><span>@{{ textos['total_' + ctx.saldo.sentido] }}</span><b data-resumen="total">@{{ dinero(ctx.resumen.saldo) }}</b></div>
                            </div>

                            {{-- Requiere revision: el sistema no deduce el valor con seguridad; lo confirma un administrador. --}}
                            <section v-if="ctx.revisiones.length" data-logistica="revisiones">
                                <p class="lge-sec"><span class="icon-information"></span>@{{ textos.sec_revision }} (@{{ ctx.revisiones.length }})</p>
                                <p class="lge-nota mb-2">@{{ textos.revision_ayuda }}</p>

                                <div class="grid gap-2">
                                    <div v-for="r in ctx.revisiones" :key="r.shipment_id + r.concepto" class="lge-banner lge-banner--aviso" :data-revision="r.shipment_id + ':' + r.concepto">
                                        <div class="lge-banner-cuerpo">
                                            <p class="lge-banner-tit">#@{{ r.pedido }} &middot; @{{ r.guia }} &middot; @{{ r.concepto_texto }}</p>
                                            <p class="lge-nota">@{{ r.motivo_texto }}</p>
                                            <p class="lge-nota">
                                                @{{ t(textos.rev_unidades, { e: r.unidades.entregadas, r: r.unidades.retornadas }) }}
                                                &middot; @{{ r.concepto === 'recaudo' ? t(textos.rev_esperado, { v: r.recaudo_texto }) : t(textos.rev_flete, { v: r.flete_texto || '—' }) }}
                                            </p>

                                            {{-- Entrega parcial con devolucion: desglose con el precio historico de cada linea devuelta. --}}
                                            <dl v-if="r.recaudo_sugerido !== null && r.recaudo_sugerido !== undefined" class="lge-desglose mt-2" data-logistica="rev-desglose">
                                                <div><dt>@{{ textos.rev_original }}</dt><dd>@{{ dinero(r.recaudo_esperado) }}</dd></div>
                                                <div><dt>@{{ textos.rev_devueltas }}</dt><dd data-logistica="rev-devueltas">−@{{ dinero(r.devoluciones.valor) }}</dd></div>
                                                <div v-for="l in r.devoluciones.lineas" :key="l.order_item_id" class="lge-desglose-linea">
                                                    <dt>@{{ l.nombre }}</dt><dd>@{{ t(textos.rev_linea, { n: l.cantidad, p: dinero(l.valor_unitario) }) }} = @{{ dinero(l.valor) }}</dd>
                                                </div>
                                                <div class="lge-desglose-total"><dt>@{{ textos.rev_sugerido }}</dt><dd data-logistica="rev-sugerido">@{{ dinero(r.recaudo_sugerido) }}</dd></div>
                                            </dl>

                                            <div v-if="ctx.permisos.ajustar" class="lge-grid2 mt-2">
                                                <div>
                                                    <label class="lge-label">@{{ r.concepto === 'recaudo' ? textos.rev_valor_recaudo : textos.rev_valor_servicio }} (@{{ prefijo.trim() }})</label>
                                                    <v-moneda sin-prefijo v-model="revision(r).valor" :max-enteros="8" class="lg-input" data-logistica="rev-valor"></v-moneda>
                                                </div>
                                                <div>
                                                    <label class="lge-label">@{{ tieneSugerido(r) ? textos.rev_motivo_cambio : textos.rev_motivo }}</label>
                                                    <input type="text" maxlength="500" v-model="revision(r).motivo" class="lg-input" data-logistica="rev-motivo">
                                                </div>
                                                <p v-if="revision(r).error" class="lge-error lge-error--caja" style="grid-column: 1 / -1">@{{ revision(r).error }}</p>
                                                <div style="grid-column: 1 / -1">
                                                    <button type="button" class="lg-btn lg-btn--aviso" :disabled="revision(r).enviando" data-logistica="rev-confirmar" @click="confirmarRevision(r)">@{{ textos.rev_confirmar }}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {{-- Movimientos con filtros; agrupados por envio (pedido + guia) para leer cada entrega de un vistazo. --}}
                            <section data-logistica="movimientos">
                                <p class="lge-sec"><span class="icon-list"></span>@{{ textos.sec_movimientos }}</p>

                                <div class="lgc-filtros mb-3" data-logistica="filtros">
                                    <select v-model="filtros.estado" class="lg-input" data-filtro="estado">
                                        <option value="abiertos">@{{ textos.f_abiertos }}</option>
                                        <option value="liquidados">@{{ textos.f_liquidados }}</option>
                                        <option value="todos">@{{ textos.f_todos }}</option>
                                    </select>
                                    <select v-model="filtros.concepto" class="lg-input" data-filtro="concepto" :aria-label="textos.f_concepto">
                                        <option value="">@{{ textos.f_todos_conceptos }}</option>
                                        <option v-for="(nombre, codigo) in conceptos" :key="codigo" :value="codigo">@{{ nombre }}</option>
                                    </select>
                                    <input type="date" v-model="filtros.desde" class="lg-input" :aria-label="textos.f_desde" :title="textos.f_desde" data-filtro="desde">
                                    <input type="date" v-model="filtros.hasta" class="lg-input" :aria-label="textos.f_hasta" :title="textos.f_hasta" data-filtro="hasta">
                                    <input type="search" v-model="filtros.q" maxlength="60" class="lg-input" :placeholder="textos.f_buscar" data-filtro="q" @keyup.enter="aplicarFiltros">
                                    <div class="flex gap-2">
                                        <button type="button" class="lg-btn" data-logistica="aplicar" @click="aplicarFiltros"><span class="icon-filter"></span>@{{ textos.aplicar }}</button>
                                        <button type="button" class="lge-enlace" @click="limpiarFiltros">@{{ textos.limpiar }}</button>
                                    </div>
                                </div>

                                <p v-if="! grupos.length" class="lge-nota" data-logistica="sin-movimientos">@{{ textos.sin_movimientos }}</p>

                                <div class="grid gap-3">
                                    <div v-for="g in grupos" :key="g.clave" class="lgc-grupo" :data-grupo="g.clave">
                                        <div class="lgc-grupo-cab">
                                            <b v-if="g.shipment_id">#@{{ g.pedido || '—' }} &middot; @{{ g.guia || t(textos.envio, { id: g.shipment_id }) }}</b>
                                            <b v-else>@{{ textos.otros }}</b>
                                            <span class="lgc-monto" :class="claseValor(g.total)">@{{ firmado(g.total) }}</span>
                                        </div>

                                        <div class="lgc-mov lgc-mov--cab">
                                            <span>@{{ textos.col_fecha }}</span><span>@{{ textos.col_concepto }}</span><span class="lg-num">@{{ textos.col_valor }}</span><span>@{{ textos.col_efecto }}</span><span>@{{ textos.col_estado }}</span>
                                        </div>

                                        <div v-for="m in g.filas" :key="m.id" class="lgc-mov" :data-movimiento="m.id" :data-concepto="m.concepto">
                                            <span class="lgc-mov-fecha">@{{ m.fecha_texto }}</span>
                                            <span class="lgc-mov-concepto">
                                                <b>@{{ m.concepto_texto }}</b>
                                                <span v-if="m.reverso" class="lg-pill lg-pill--gris">@{{ textos.tag_reverso }}</span>
                                                <span v-if="m.revision" class="lg-pill lg-pill--lila">@{{ textos.tag_revision }}</span>
                                                <span v-if="m.descripcion && (m.reverso || ! m.shipment_id || m.revision)" class="lge-detalle">@{{ m.descripcion }}</span>
                                                <span v-if="m.referencia" class="lge-detalle">@{{ m.referencia }}</span>
                                            </span>
                                            <span class="lgc-mov-valor lg-num lgc-monto" :class="claseValor(m.importe)">@{{ firmado(m.importe) }}</span>
                                            <span class="lgc-mov-efecto">@{{ textos['efecto_' + m.efecto] }}</span>
                                            <span class="lgc-mov-estado">
                                                <span class="lg-pill" :class="m.estado === 'abierto' ? 'lg-pill--amarillo' : 'lg-pill--verde'">@{{ m.estado === 'abierto' ? textos.estado_abierto : textos.estado_liquidado }}</span>
                                                <span v-if="m.liquidacion" class="lge-detalle">@{{ m.liquidacion }}</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <p v-if="ctx.movimientos.truncado" class="lge-nota mt-2">@{{ textos.truncado }}</p>
                            </section>
                        </template>

                        {{-- ======================= CUADRAR / LIQUIDAR ======================= --}}
                        <template v-if="modo === 'liquidar'">
                            <p class="lge-sec"><span class="icon-done"></span>@{{ textos.liq_titulo }}</p>

                            <p v-if="! ctx.liquidable.n" class="lge-nota" data-logistica="liq-nada">@{{ textos.liq_nada }}</p>

                            <template v-else>
                                <div class="lg-panel lgc-resumen" data-logistica="liq-incluir">
                                    <p class="lg-caption">@{{ textos.liq_incluir }} &middot; @{{ t(textos.liq_n, { n: ctx.liquidable.n }) }}</p>
                                    <div class="lgc-resumen-fila"><span>@{{ textos.resumen_recaudos }}</span><b>@{{ dinero(ctx.resumen.recaudos) }}</b></div>
                                    <div class="lgc-resumen-fila"><span>@{{ textos.resumen_servicios }}</span><b>@{{ dinero(ctx.resumen.servicios) }}</b></div>
                                    <div class="lgc-resumen-fila"><span>@{{ textos.resumen_ajustes }}</span><b>@{{ firmado(ctx.resumen.ajustes) }}</b></div>
                                    <div class="lgc-resumen-fila lgc-resumen-total"><span>@{{ textos.liq_neto }}</span><b data-logistica="liq-neto">@{{ dinero(ctx.resumen.saldo) }}</b></div>
                                    <p class="lge-nota">@{{ ctx.saldo.texto }}</p>
                                </div>

                                <p v-if="ctx.revisiones.length" class="lge-error lge-error--caja" data-logistica="liq-revision">@{{ textos.liq_revision }}</p>

                                <template v-if="ctx.permisos.liquidar">
                                    <div v-if="prop" class="lg-panel lgc-resumen" data-logistica="liq-propuesta">
                                        <div class="lgc-resumen-fila"><span>@{{ textos.liq_custodia }}</span><b data-liq="custodia">@{{ dinero(prop.totales.cobrado_en_custodia) }}</b></div>
                                        <div class="lgc-resumen-fila"><span>@{{ textos.liq_servicios_comp }}</span><b data-liq="servicios">&minus;@{{ dinero(prop.totales.servicios_a_compensar) }}</b></div>
                                        <div class="lgc-resumen-fila lgc-resumen-total"><span>@{{ prop.sentido === 'bylopez_paga' ? textos.liq_efectivo_pagar : textos.liq_efectivo_sugerido }}</span><b data-liq="sugerido">@{{ dinero(prop.sentido === 'bylopez_paga' ? prop.totales.efectivo_a_pagar_bylopez : prop.totales.efectivo_sugerido) }}</b></div>
                                    </div>

                                    <div v-if="ctx.saldo.sentido !== 'al_dia'">
                                        <label class="lge-label">@{{ textos['liq_valor_' + ctx.saldo.sentido] }} (@{{ prefijo.trim() }})</label>
                                        <v-moneda sin-prefijo v-model="liq.valor" :max-enteros="8" class="lg-input lge-cant" data-logistica="liq-valor"></v-moneda>
                                    </div>

                                    <div class="lge-resumen" :class="hayDiferencia ? 'lge-resumen--dif' : ''" data-logistica="liq-cuadre">
                                        <div class="lge-resumen-fila"><span>@{{ textos.liq_esperado }}</span><b>@{{ dinero(ctx.resumen.saldo) }}</b></div>
                                        <div class="lge-resumen-fila"><span>@{{ ctx.saldo.sentido === 'bylopez_paga' ? textos.liq_pagado : textos.liq_recibido }}</span><b>@{{ dinero(valorLiq) }}</b></div>
                                        <div class="lge-resumen-fila" :class="hayDiferencia ? 'lge-resumen-dif' : 'lge-resumen-ok'"><span>@{{ textos.liq_diferencia }}</span><b data-logistica="liq-diferencia">@{{ dinero(diferencia) }}</b></div>
                                        <p v-if="hayDiferencia" class="lge-nota" data-logistica="liq-diferencia-texto">@{{ textoDiferencia }} &middot; @{{ textos.liq_diferencia_ayuda }}</p>
                                        <p v-else class="lge-nota">@{{ textos.liq_cuadra }}</p>
                                    </div>

                                    {{-- C6: que significa lo entregado (efectivo y compensacion por separado) y que queda pendiente DEL TRANSPORTADOR --}}
                                    <div v-if="prop" class="lg-panel lgc-resumen" data-logistica="liq-resumen-c6">
                                        <div class="lgc-resumen-fila"><span>@{{ textos.liq_cobrado }}</span><b data-liq="cobrado">@{{ dinero(prop.totales.cobrado_en_custodia) }}</b></div>
                                        <div class="lgc-resumen-fila"><span>@{{ textos.liq_compensado }}</span><b data-liq="compensado">@{{ dinero(prop.totales.compensado) }}</b></div>
                                        <div class="lgc-resumen-fila"><span>@{{ prop.sentido === 'bylopez_paga' ? textos.liq_efectivo_pagado : textos.liq_efectivo_recibido }}</span><b data-liq="efectivo">@{{ dinero(prop.totales.efectivo) }}</b></div>
                                        <div class="lgc-resumen-fila lgc-resumen-total"><span>@{{ textos.liq_conciliado }}</span><b data-liq="conciliado">@{{ dinero(prop.totales.conciliado) }}</b></div>
                                        <div class="lgc-resumen-fila"><span>@{{ textos.liq_pendiente_t }}</span><b data-liq="pendiente">@{{ dinero(prop.totales.pendiente_transportador) }}</b></div>
                                        <p v-if="prop.totales.pendiente_transportador > 0.004" class="lge-error lge-error--caja" data-logistica="liq-pendiente-aviso">@{{ t(textos.liq_pendiente_aviso, { nombre: ctx.transportadora.nombre, monto: dinero(prop.totales.pendiente_transportador) }) }}</p>
                                        <p v-if="prop.totales.pendiente_bylopez > 0.004" class="lge-nota" data-logistica="liq-pendiente-bylopez">@{{ t(textos.liq_pendiente_bylopez_aviso, { nombre: ctx.transportadora.nombre, monto: dinero(prop.totales.pendiente_bylopez) }) }}</p>
                                    </div>

                                    <div v-if="prop && prop.filas.length" class="lg-panel" data-logistica="liq-atribucion">
                                        <p class="lge-label">@{{ textos.liq_atribucion }}</p>
                                        <p class="lge-nota">@{{ textos.liq_atribucion_ayuda }}</p>
                                        <div class="lgc-tabla-envolvente" style="overflow-x:auto">
                                            <table class="lgc-tabla">
                                                <thead><tr><th>@{{ textos.liq_col_pedido }}</th><th>@{{ textos.liq_col_concepto }}</th><th>@{{ textos.liq_col_restante }}</th><th>@{{ textos.liq_col_compensado }}</th><th>@{{ textos.liq_col_efectivo }}</th><th>@{{ textos.liq_col_pendiente }}</th></tr></thead>
                                                <tbody>
                                                    <tr v-for="f in prop.filas" :key="f.movimiento_id" :data-atribucion="f.movimiento_id">
                                                        <td>@{{ f.pedido ? '#' + f.pedido : '—' }}<span v-if="f.guia" class="lge-detalle"> · @{{ f.guia }}</span></td>
                                                        <td>@{{ f.concepto_texto }}</td>
                                                        <td>@{{ dinero(f.restante) }}</td>
                                                        <td>@{{ dinero(f.compensado) }}</td>
                                                        <td>
                                                            <input v-if="atribMod && f.tipo === 'obligacion'" type="text" inputmode="decimal" v-model="atrib[f.movimiento_id]" class="lg-input" style="width:7rem" :data-atrib-efectivo="f.movimiento_id" @input="reproponer">
                                                            <template v-else>@{{ dinero(f.efectivo) }}</template>
                                                        </td>
                                                        <td :class="f.pendiente > 0.004 ? 'lgc-menos' : ''">@{{ dinero(f.pendiente) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div v-if="! atribMod && prop.filas.some(f => f.tipo === 'obligacion' && f.pendiente + f.efectivo > 0.004)" class="mt-2">
                                            <button type="button" class="lg-btn" data-logistica="liq-cambiar-atrib" @click="cambiarAtrib">@{{ textos.liq_cambiar_atrib }}</button>
                                        </div>
                                        <div v-if="atribMod" class="mt-2">
                                            <label class="lge-label">@{{ textos.liq_motivo_atrib }} *</label>
                                            <textarea v-model="motivoAtrib" maxlength="500" rows="2" class="lg-input" data-logistica="liq-motivo-atrib"></textarea>
                                            <button type="button" class="lg-btn mt-2" data-logistica="liq-restaurar-atrib" @click="restaurarAtrib">@{{ textos.liq_restaurar_atrib }}</button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="lge-label">@{{ textos.liq_observacion }}<template v-if="hayDiferencia"> * <span class="lge-nota">(@{{ textos.liq_observacion_req }})</span></template></label>
                                        <textarea v-model="liq.observacion" maxlength="500" rows="2" class="lg-input" data-logistica="liq-observacion"></textarea>
                                    </div>
                                    <div>
                                        <label class="lge-label">@{{ textos.liq_referencia }}</label>
                                        <input type="text" v-model="liq.referencia" maxlength="120" class="lg-input" data-logistica="liq-referencia">
                                    </div>

                                    <p class="lge-nota">@{{ textos.liq_aviso }}</p>
                                </template>

                                <p v-else class="lge-nota" data-logistica="liq-solo-admin">@{{ textos.liq_solo_admin }}</p>
                            </template>

                            <p v-if="error" class="lge-error lge-error--caja" data-logistica="error">@{{ error }}</p>
                        </template>

                        {{-- ======================= COBRO REPORTADO (C6, solo administrador) ======================= --}}
                        <template v-if="modo === 'cobro'">
                            <p class="lge-sec"><span class="icon-done"></span>@{{ textos.cobro_titulo }}</p>
                            <p class="lge-nota">@{{ textos.cobro_ayuda }}</p>
                            <p v-if="! ctx.guias_cobro.length" class="lge-nota" data-logistica="cobro-sin-guias">@{{ textos.cobro_sin_guias }}</p>
                            <template v-else>
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_guia }} *</label>
                                    <select v-model="cobro.guia_id" class="lg-input" data-logistica="cobro-guia" @change="sugerirCobro">
                                        <option value="">@{{ textos.aj_guia_elegir }}</option>
                                        <option v-for="g in ctx.guias_cobro" :key="g.id" :value="g.id">@{{ t(textos.cobro_opcion, { pedido: '#' + g.pedido, guia: g.guia, esperado: dinero(g.esperado), cobrado: dinero(g.cobrado) }) }}</option>
                                    </select>
                                </div>
                                <div class="lge-grid2">
                                    <div>
                                        <label class="lge-label">@{{ textos.cobro_valor }} (@{{ prefijo.trim() }}) *</label>
                                        <v-moneda sin-prefijo v-model="cobro.valor" :max-enteros="8" class="lg-input" data-logistica="cobro-valor"></v-moneda>
                                    </div>
                                    <div>
                                        <label class="lge-label">@{{ textos.cobro_fecha }}</label>
                                        <input type="date" v-model="cobro.fecha" :max="ctx.hoy" class="lg-input" data-logistica="cobro-fecha">
                                    </div>
                                </div>
                                <div>
                                    <label class="lge-label">@{{ textos.cobro_referencia }}</label>
                                    <input type="text" v-model="cobro.referencia" maxlength="100" class="lg-input" data-logistica="cobro-referencia">
                                </div>
                            </template>

                            <p v-if="error" class="lge-error lge-error--caja" data-logistica="error">@{{ error }}</p>
                        </template>

                        {{-- ======================= AJUSTE (solo administrador) ======================= --}}
                        <template v-if="modo === 'ajuste'">
                            <p class="lge-sec"><span class="icon-edit"></span>@{{ textos.aj_titulo }}</p>
                            <p class="lge-nota">@{{ textos.aj_ayuda }}</p>

                            <div class="lge-grid2">
                                <div>
                                    <label class="lge-label">@{{ textos.aj_concepto }}</label>
                                    <select v-model="aj.concepto" class="lg-input" data-logistica="aj-concepto">
                                        <option value="ajuste">@{{ textos.aj_ajuste }}</option>
                                        <option value="costo_devolucion">@{{ textos.aj_costo_devolucion }}</option>
                                    </select>
                                </div>
                                <div v-if="aj.concepto === 'ajuste'">
                                    <label class="lge-label">@{{ textos.aj_tipo }}</label>
                                    <select v-model="aj.tipo" class="lg-input" data-logistica="aj-tipo">
                                        <option value="a_favor_bylopez">@{{ textos.aj_a_favor_bylopez }}</option>
                                        <option value="a_favor_transportador">@{{ t(textos.aj_a_favor_transportador, { nombre: ctx.transportadora.nombre }) }}</option>
                                    </select>
                                </div>
                                <div v-else>
                                    <label class="lge-label">@{{ textos.aj_guia }}</label>
                                    <select v-if="ctx.guias_devolucion.length" v-model="aj.guia_id" class="lg-input" data-logistica="aj-guia">
                                        <option value="">@{{ textos.aj_guia_elegir }}</option>
                                        <option v-for="g in ctx.guias_devolucion" :key="g.id" :value="g.id">@{{ g.texto }}</option>
                                    </select>
                                    <p v-else class="lge-nota">@{{ textos.aj_sin_guias }}</p>
                                </div>
                                <div>
                                    <label class="lge-label">@{{ textos.aj_valor }} (@{{ prefijo.trim() }})</label>
                                    <v-moneda sin-prefijo v-model="aj.valor" :max-enteros="8" class="lg-input" data-logistica="aj-valor"></v-moneda>
                                </div>
                                <div>
                                    <label class="lge-label">@{{ textos.aj_referencia }}</label>
                                    <input type="text" v-model="aj.referencia" maxlength="120" class="lg-input" data-logistica="aj-referencia">
                                </div>
                            </div>
                            <div>
                                <label class="lge-label">@{{ textos.aj_motivo }} *</label>
                                <textarea v-model="aj.motivo" maxlength="500" rows="2" class="lg-input" data-logistica="aj-motivo"></textarea>
                            </div>

                            <p v-if="error" class="lge-error lge-error--caja" data-logistica="error">@{{ error }}</p>
                        </template>

                        {{-- ======================= HISTORICO (solo lectura) ======================= --}}
                        <template v-if="modo === 'historico'">
                            <p v-if="! ctx.liquidaciones.length" class="lge-nota" data-logistica="hist-vacio">@{{ textos.hist_vacio }}</p>

                            <div class="grid gap-3">
                                <div v-for="l in ctx.liquidaciones" :key="l.id" class="lg-panel lgc-hist" :data-liquidacion="l.id">
                                    <div class="lgc-hist-cab">
                                        <b class="lgc-nombre">@{{ l.numero }}</b>
                                        <span class="lg-sub">@{{ l.desde }}<template v-if="l.hasta && l.hasta !== l.desde"> – @{{ l.hasta }}</template> &middot; @{{ t(textos.hist_movimientos, { n: l.movimientos }) }}</span>
                                    </div>
                                    <div class="lgc-cifras">
                                        <div>@{{ textos.resumen_recaudos }}<b>@{{ dinero(l.recaudos) }}</b></div>
                                        <div>@{{ textos.resumen_servicios }}<b>@{{ dinero(l.servicios) }}</b></div>
                                        <div>@{{ textos.hist_neto }}<b>@{{ dinero(l.saldo.valor) }}</b></div>
                                        <div>@{{ textos.hist_valor }}<b>@{{ dinero(l.valor) }}</b></div>
                                    </div>
                                    <p class="lge-nota">@{{ l.saldo.texto }}</p>
                                    <p v-if="l.diferencia_texto" class="lge-nota"><b>@{{ textos.hist_diferencia }}:</b> @{{ l.diferencia_texto }}</p>
                                    <div class="lge-fila-titulo">
                                        <span class="lg-sub">@{{ t(textos.hist_cerrada, { actor: l.actor }) }} &middot; @{{ l.cerrada_texto }}</span>
                                        <button type="button" class="lge-enlace" data-logistica="hist-ver" @click="verDetalle(l)">@{{ textos.hist_ver }}</button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- ======================= DETALLE DE UNA LIQUIDACION ======================= --}}
                        <template v-if="modo === 'detalle' && detalle">
                            <div class="lgc-hero" :class="'lgc-hero--' + detalle.liquidacion.saldo.sentido">
                                <p class="lg-caption">@{{ detalle.liquidacion.numero }} &middot; @{{ detalle.liquidacion.cerrada_texto }}</p>
                                <p class="lgc-hero-monto">@{{ dinero(detalle.liquidacion.saldo.valor) }}</p>
                                <p class="lgc-hero-texto">@{{ detalle.liquidacion.saldo.texto }}</p>
                            </div>
                            <p class="lge-nota">@{{ textos.hist_solo_lectura }}</p>
                            <div class="lgc-cifras">
                                <div>@{{ textos.resumen_recaudos }}<b>@{{ dinero(detalle.liquidacion.recaudos) }}</b></div>
                                <div>@{{ textos.resumen_servicios }}<b>@{{ dinero(detalle.liquidacion.servicios) }}</b></div>
                                <div>@{{ textos.resumen_ajustes }}<b>@{{ firmado(detalle.liquidacion.ajustes) }}</b></div>
                                <div>@{{ textos.hist_valor }}<b>@{{ dinero(detalle.liquidacion.valor) }}</b></div>
                            </div>
                            <p v-if="detalle.liquidacion.diferencia_texto" class="lge-nota"><b>@{{ textos.hist_diferencia }}:</b> @{{ detalle.liquidacion.diferencia_texto }}</p>
                            <p v-if="detalle.liquidacion.observacion" class="lge-nota"><b>@{{ textos.hist_observacion }}:</b> @{{ detalle.liquidacion.observacion }}</p>
                            <p v-if="detalle.liquidacion.referencia" class="lge-nota"><b>@{{ textos.hist_referencia }}:</b> @{{ detalle.liquidacion.referencia }}</p>
                            <p class="lg-sub">@{{ t(textos.hist_cerrada, { actor: detalle.liquidacion.actor }) }}</p>

                            <div class="lgc-grupo">
                                <div v-for="m in detalle.movimientos.filas" :key="m.id" class="lgc-mov" :data-movimiento="m.id">
                                    <span class="lgc-mov-fecha">@{{ m.fecha_texto }}</span>
                                    <span class="lgc-mov-concepto"><b>@{{ m.concepto_texto }}</b><span class="lge-detalle">@{{ m.pedido ? '#' + m.pedido : '' }} @{{ m.guia || '' }}</span></span>
                                    <span class="lgc-mov-valor lg-num lgc-monto" :class="claseValor(m.importe)">@{{ firmado(m.importe) }}</span>
                                    <span class="lgc-mov-efecto">@{{ textos['efecto_' + m.efecto] }}</span>
                                    <span class="lgc-mov-estado lg-sub">@{{ m.actor }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div v-if="ctx" class="lge-pie" data-logistica="pie">
                        <template v-if="modo === 'cuenta' || modo === 'historico'">
                            <button v-if="ctx.permisos.cobrar" type="button" class="lg-btn" data-logistica="ir-cobro" @click="entrar('cobro')"><span class="icon-done"></span>@{{ textos.tab_cobro }}</button>
                            <button v-if="ctx.permisos.ajustar" type="button" class="lg-btn" data-logistica="ir-ajuste" @click="entrar('ajuste')"><span class="icon-edit"></span>@{{ textos.btn_ajuste }}</button>
                            <button v-if="ctx.liquidable.n" type="button" class="lg-btn lg-btn--ok lge-principal" data-logistica="ir-liquidar" @click="entrar('liquidar')"><span class="icon-done"></span>@{{ textos.btn_liquidar }}</button>
                            <button v-else type="button" class="lg-btn" @click="cerrar">@{{ textos.cerrar }}</button>
                        </template>

                        <template v-else-if="modo === 'liquidar'">
                            <button type="button" class="lg-btn" @click="entrar('cuenta')"><span class="icon-arrow-left"></span>@{{ textos.volver }}</button>
                            <button v-if="ctx.permisos.liquidar && ctx.liquidable.n" type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="liq-confirmar" @click="liquidar">@{{ textos.liq_confirmar }}</button>
                        </template>

                        <template v-else-if="modo === 'cobro'">
                            <button type="button" class="lg-btn" @click="entrar('cuenta')"><span class="icon-arrow-left"></span>@{{ textos.volver }}</button>
                            <button v-if="ctx.guias_cobro.length" type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="cobro-confirmar" @click="registrarCobro">@{{ textos.cobro_guardar }}</button>
                        </template>

                        <template v-else-if="modo === 'ajuste'">
                            <button type="button" class="lg-btn" @click="entrar('cuenta')"><span class="icon-arrow-left"></span>@{{ textos.volver }}</button>
                            <button type="button" class="lg-btn lg-btn--ok lge-principal" :disabled="enviando" data-logistica="aj-confirmar" @click="ajustar">@{{ textos.aj_confirmar }}</button>
                        </template>

                        <template v-else-if="modo === 'detalle'">
                            <button type="button" class="lg-btn" @click="entrar('historico')"><span class="icon-arrow-left"></span>@{{ textos.tab_historico }}</button>
                        </template>
                    </div>
                </x-slot:footer>
            </x-admin::drawer>
        </div>
    </script>

    <script type="module">
        app.component('v-logistica-cuentas', {
            template: '#v-logistica-cuentas-template',

            props: ['urlContexto', 'urlLiquidar', 'urlPropuesta', 'urlCobro', 'urlAjustar', 'urlRevision', 'urlLiquidacion', 'textos', 'conceptos', 'prefijo'],

            data() {
                return {
                    cuentaId: null,
                    ctx: null,
                    cargando: false,
                    errorCarga: null,
                    modo: 'cuenta',
                    filtros: this.filtrosVacios(),
                    enviando: false,
                    error: null,
                    clave: null,
                    liq: { valor: '', observacion: '', referencia: '' },
                    // C6: propuesta de conciliacion del servidor, reasignacion manual (antes de confirmar) y cobro reportado.
                    prop: null,
                    atribMod: false,
                    atrib: {},
                    compBase: {},
                    motivoAtrib: '',
                    cobro: { guia_id: '', valor: '', fecha: '', referencia: '' },
                    temporizador: null,
                    aj: { concepto: 'ajuste', tipo: 'a_favor_bylopez', valor: '', motivo: '', referencia: '', guia_id: '' },
                    revisiones: {},
                    detalle: null,
                };
            },

            computed: {
                // Movimientos agrupados por envio (pedido + guia) en el orden en que llegan; ajustes y liquidaciones aparte.
                grupos() {
                    if (! this.ctx) {
                        return [];
                    }

                    const grupos = [];
                    const indice = {};

                    this.ctx.movimientos.filas.forEach(m => {
                        const clave = m.shipment_id ? 'e' + m.shipment_id : 'otros';

                        if (! indice[clave]) {
                            indice[clave] = { clave: clave, shipment_id: m.shipment_id, pedido: m.pedido, guia: m.guia, filas: [], total: 0 };
                            grupos.push(indice[clave]);
                        }

                        indice[clave].filas.push(m);
                        indice[clave].total = Math.round((indice[clave].total + m.importe) * 100) / 100;
                    });

                    return grupos;
                },

                // Vista previa del cuadre (el servidor lo vuelve a calcular todo al confirmar).
                valorLiq() {
                    const n = parseFloat(this.liq.valor);

                    return Number.isFinite(n) && n > 0 ? n : 0;
                },

                diferencia() {
                    if (! this.ctx) {
                        return 0;
                    }

                    const saldo = this.ctx.resumen.saldo;
                    const pago = this.ctx.saldo.sentido === 'bylopez_paga' ? this.valorLiq : -this.valorLiq;

                    return Math.round((saldo + pago) * 100) / 100;
                },

                hayDiferencia() {
                    return Math.abs(this.diferencia) >= 0.005;
                },

                // La diferencia en palabras: quien queda debiendo y cuanto (se traslada a la proxima cuenta).
                textoDiferencia() {
                    const nombre = this.ctx ? this.ctx.transportadora.nombre : '';
                    const monto = this.dinero(this.diferencia);

                    return this.diferencia > 0
                        ? nombre + ' · ' + this.textos.total_debe_entregar + ' ' + monto
                        : this.textos.total_bylopez_paga + ' · ' + nombre + ' ' + monto;
                },
            },

            watch: {
                // Cambiar el efectivo entregado invalida cualquier reasignacion manual y recalcula la propuesta.
                'liq.valor'() {
                    if (this.modo !== 'liquidar') {
                        return;
                    }

                    this.atribMod = false;
                    this.motivoAtrib = '';
                    clearTimeout(this.temporizador);
                    this.temporizador = setTimeout(() => this.cargarPropuesta(), 350);
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

                // Formato central ByLopez ($ 420.000); el valor se lee siempre en positivo y el sentido va en palabras.
                dinero(v) {
                    const n = Math.round(Math.abs(Number(v) || 0) * 100) / 100;

                    return window.ByLopezMoneda
                        ? window.ByLopezMoneda.formatear(String(n), { prefijo: this.prefijo })
                        : this.prefijo + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 }).format(n);
                },

                firmado(v) {
                    const n = Number(v) || 0;

                    return (n > 0.004 ? '+' : (n < -0.004 ? '-' : '')) + this.dinero(n);
                },

                claseValor(v) {
                    return v > 0.004 ? 'lgc-mas' : (v < -0.004 ? 'lgc-menos' : '');
                },

                filtrosVacios() {
                    return { estado: 'abiertos', concepto: '', desde: '', hasta: '', q: '' };
                },

                nuevaClave() {
                    this.clave = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'k' + Date.now() + Math.random().toString(36).slice(2);
                },

                // ---- apertura ----
                alClic(evento) {
                    const boton = evento.target.closest('[data-logistica-cuenta]');

                    if (! boton) {
                        return;
                    }

                    evento.preventDefault();
                    this.abrir(parseInt(boton.dataset.cuentaId, 10));
                },

                abrir(id) {
                    this.cuentaId = id;
                    this.ctx = null;
                    this.errorCarga = null;
                    this.detalle = null;
                    this.filtros = this.filtrosVacios();
                    this.$refs.drawer.open();
                    this.cargar().then(() => { if (this.ctx) { this.entrar('cuenta'); } });
                },

                cargar() {
                    this.cargando = true;

                    return this.$axios.get(this.urlContexto.replace('__ID__', this.cuentaId), { params: this.filtros })
                        .then(r => { this.ctx = r.data.contexto; this.prepararRevisiones(); this.errorCarga = null; })
                        .catch(e => { this.errorCarga = this.mensajeDeError(e, this.textos.error_carga); })
                        .finally(() => { this.cargando = false; });
                },

                aplicarFiltros() {
                    this.cargar();
                },

                limpiarFiltros() {
                    this.filtros = this.filtrosVacios();
                    this.cargar();
                },

                entrar(modo) {
                    this.error = null;
                    this.nuevaClave();

                    if (modo === 'ajuste' && ! this.ctx.permisos.ajustar) {
                        modo = 'cuenta';
                    }

                    if (modo === 'liquidar') {
                        // Por defecto, el valor esperado completo: si coincide, la diferencia es 0.
                        this.liq = { valor: this.ctx.saldo.sentido === 'al_dia' ? '0' : String(Math.abs(this.ctx.resumen.saldo)), observacion: '', referencia: '' };
                        this.atribMod = false;
                        this.motivoAtrib = '';
                        this.prop = null;
                        this.cargarPropuesta();
                    }

                    if (modo === 'cobro') {
                        this.cobro = { guia_id: '', valor: '', fecha: this.ctx.hoy, referencia: '' };
                    }

                    if (modo === 'ajuste') {
                        this.aj = { concepto: 'ajuste', tipo: 'a_favor_bylopez', valor: '', motivo: '', referencia: '', guia_id: '' };
                    }

                    this.modo = modo;
                },

                // Estado del formulario de cada envio en revision; se prepara al cargar (nunca durante el render).
                prepararRevisiones() {
                    const nuevas = {};

                    (this.ctx ? this.ctx.revisiones : []).forEach(r => {
                        const clave = r.shipment_id + ':' + r.concepto;

                        // Precarga el recaudo sugerido (pedido menos prendas devueltas); el Administrador solo confirma o corrige.
                        nuevas[clave] = this.revisiones[clave] || { valor: this.tieneSugerido(r) ? String(r.recaudo_sugerido) : '', motivo: '', enviando: false, error: null, clave: (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'r' + Date.now() + Math.random().toString(36).slice(2) };
                    });

                    this.revisiones = nuevas;
                },

                revision(r) {
                    return this.revisiones[r.shipment_id + ':' + r.concepto] || {};
                },

                tieneSugerido(r) {
                    return r.recaudo_sugerido !== null && r.recaudo_sugerido !== undefined;
                },

                // ---- C6: propuesta de conciliacion, atribucion manual y cobro reportado ----
                cargarPropuesta() {
                    const datos = { valor: this.ctx.saldo.sentido === 'al_dia' ? '0' : String(this.liq.valor).trim() };

                    if (this.atribMod) {
                        datos.atribucion = this.construirAtribucion();
                    }

                    return this.$axios.post(this.urlPropuesta.replace('__ID__', this.cuentaId), datos)
                        .then(r => { this.prop = r.data.propuesta; this.error = null; })
                        .catch(e => { this.error = this.mensajeDeError(e, this.textos.error_generico); });
                },

                construirAtribucion() {
                    return Object.keys(this.atrib).map(id => ({ movimiento_id: Number(id), efectivo: String(this.atrib[id] || '0').trim(), compensado: this.compBase[id] || '0' }));
                },

                cambiarAtrib() {
                    this.atrib = {};
                    this.compBase = {};
                    this.prop.filas.forEach(f => { this.atrib[f.movimiento_id] = String(f.efectivo); this.compBase[f.movimiento_id] = String(f.compensado); });
                    this.atribMod = true;
                },

                restaurarAtrib() {
                    this.atribMod = false;
                    this.motivoAtrib = '';
                    this.cargarPropuesta();
                },

                reproponer() {
                    clearTimeout(this.temporizador);
                    this.temporizador = setTimeout(() => this.cargarPropuesta(), 350);
                },

                sugerirCobro() {
                    const g = this.ctx.guias_cobro.find(x => String(x.id) === String(this.cobro.guia_id));

                    if (g && ! this.cobro.valor) {
                        this.cobro.valor = String(Math.max(0, Math.round((g.esperado - g.cobrado) * 100) / 100));
                    }
                },

                registrarCobro() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    if (! this.cobro.guia_id) {
                        this.error = this.textos.err_guia_cobro;

                        return;
                    }

                    if (! /^\d+(\.\d{1,2})?$/.test(String(this.cobro.valor).trim()) || parseFloat(this.cobro.valor) <= 0) {
                        this.error = this.textos.err_valor;

                        return;
                    }

                    this.publicar(this.urlCobro.replace('__ID__', this.cuentaId), Object.assign({}, this.cobro, { valor: String(this.cobro.valor).trim(), idempotency_key: this.clave }));
                },

                // ---- acciones ----
                liquidar() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    if (this.ctx.saldo.sentido !== 'al_dia' && ! /^\d+(\.\d{1,2})?$/.test(String(this.liq.valor).trim())) {
                        this.error = this.textos.err_valor;

                        return;
                    }

                    if (this.hayDiferencia && ! this.liq.observacion.trim()) {
                        this.error = this.textos.err_observacion;

                        return;
                    }

                    if (this.atribMod && ! this.motivoAtrib.trim()) {
                        this.error = this.textos.err_motivo_atrib;

                        return;
                    }

                    this.publicar(this.urlLiquidar.replace('__ID__', this.cuentaId), {
                        atribucion: this.atribMod ? this.construirAtribucion() : undefined,
                        motivo_atribucion: this.atribMod ? this.motivoAtrib : undefined,
                        movimientos: this.ctx.liquidable.ids,
                        valor: this.ctx.saldo.sentido === 'al_dia' ? '0' : String(this.liq.valor).trim(),
                        observacion: this.liq.observacion,
                        referencia: this.liq.referencia,
                        idempotency_key: this.clave,
                    });
                },

                ajustar() {
                    if (this.enviando) {
                        return;
                    }

                    this.error = null;

                    if (! /^\d+(\.\d{1,2})?$/.test(String(this.aj.valor).trim()) || parseFloat(this.aj.valor) <= 0) {
                        this.error = this.textos.err_valor;

                        return;
                    }

                    if (this.aj.concepto === 'costo_devolucion' && ! this.aj.guia_id) {
                        this.error = this.textos.err_guia;

                        return;
                    }

                    if (! this.aj.motivo.trim()) {
                        this.error = this.textos.err_motivo;

                        return;
                    }

                    this.publicar(this.urlAjustar.replace('__ID__', this.cuentaId), Object.assign({}, this.aj, { valor: String(this.aj.valor).trim(), idempotency_key: this.clave }));
                },

                confirmarRevision(r) {
                    const estado = this.revision(r);

                    if (estado.enviando) {
                        return;
                    }

                    estado.error = null;

                    if (! /^\d+(\.\d{1,2})?$/.test(String(estado.valor).trim())) {
                        estado.error = this.textos.err_valor;

                        return;
                    }

                    // Igual al sugerido basta confirmar; cualquier otro valor (o sin sugerido) exige motivo. El servidor lo vuelve a validar.
                    const coincide = this.tieneSugerido(r) && Math.abs(Number(String(estado.valor).trim()) - r.recaudo_sugerido) < 0.005;

                    if (! coincide && ! estado.motivo.trim()) {
                        estado.error = this.tieneSugerido(r) ? this.textos.err_motivo_cambio : this.textos.err_motivo;

                        return;
                    }

                    estado.enviando = true;

                    this.$axios.post(this.urlRevision.replace('__ID__', this.cuentaId).replace('__ENVIO__', r.shipment_id), {
                        concepto: r.concepto, valor: String(estado.valor).trim(), motivo: estado.motivo, idempotency_key: estado.clave,
                    })
                        .then(res => this.alExito(res.data))
                        .catch(e => { estado.error = this.mensajeDeError(e, this.textos.error_generico); })
                        .finally(() => { estado.enviando = false; });
                },

                verDetalle(l) {
                    this.$axios.get(this.urlLiquidacion.replace('__ID__', l.id))
                        .then(r => { this.detalle = r.data; this.modo = 'detalle'; })
                        .catch(e => { this.$emitter.emit('add-flash', { type: 'error', message: this.mensajeDeError(e, this.textos.error_carga) }); });
                },

                publicar(url, datos) {
                    this.enviando = true;

                    this.$axios.post(url, datos)
                        .then(r => this.alExito(r.data))
                        .catch(e => { this.error = this.mensajeDeError(e, this.textos.error_generico); })
                        .finally(() => { this.enviando = false; });
                },

                alExito(datos) {
                    this.ctx = datos.contexto;
                    this.prepararRevisiones();
                    this.filtros = this.filtrosVacios();
                    this.$emitter.emit('add-flash', { type: 'success', message: datos.mensaje });
                    this.entrar('cuenta');
                    this.refrescar();
                },

                mensajeDeError(e, porDefecto) {
                    const datos = (e.response || {}).data || {};

                    return datos.mensaje || (datos.errors ? Object.values(datos.errors)[0][0] : null) || porDefecto;
                },

                cerrar() {
                    this.$refs.drawer.close();
                },

                alCerrar() {
                    this.detalle = null;
                },

                // Reemplaza SOLO la fila/tarjeta de la cuenta y los indicadores con el HTML fresco del listado. Sin recargar.
                refrescar() {
                    const selector = '[data-cuenta="' + this.cuentaId + '"]';

                    fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                        .then(r => r.text())
                        .then(html => {
                            const doc = new DOMParser().parseFromString(html, 'text/html');
                            const nuevos = Array.from(doc.querySelectorAll(selector));

                            Array.from(document.querySelectorAll(selector)).forEach((viejo, i) => {
                                if (nuevos[i]) {
                                    viejo.replaceWith(document.importNode(nuevos[i], true));
                                }
                            });

                            doc.querySelectorAll('[data-kpi]').forEach(nuevo => {
                                const actual = document.querySelector('[data-kpi="' + nuevo.dataset.kpi + '"]');

                                if (actual) {
                                    actual.textContent = nuevo.textContent;
                                }
                            });

                            const aviso = doc.querySelector('[data-kpi-revision]');
                            const avisoActual = document.querySelector('[data-kpi-revision]');

                            if (aviso && avisoActual) {
                                avisoActual.innerHTML = aviso.innerHTML;
                            }
                        })
                        .catch(() => { /* la fila se actualizara al recargar; lo registrado ya quedo guardado */ });
                },
            },
        });
    </script>
@endPushOnce
