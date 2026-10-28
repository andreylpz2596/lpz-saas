{{--
    COMPONENTE UNICO «Despachar» (drawer). Lo incluyen, una vez por pantalla, la bandeja Logistica › Despachos (BODEGA) y
    el listado de Pedidos de Ventas Asesor (Administrador): la misma interfaz, los mismos endpoints y los mismos servicios.

    Los botones de las filas son HTML de servidor con `data-logistica-despacho`, `data-pedido` y, para «Ver envio»,
    `data-modo="ver"`; este componente los atiende por delegacion de eventos (igual que la revision de pagos). El servidor
    valida todo otra vez: aqui solo se ahorran viajes y clics.

    Al guardar, cierra el drawer, avisa y REEMPLAZA solo las filas del pedido (y los indicadores `data-kpi`) con el HTML
    fresco de la propia pantalla, sin recargar ni navegar. Las filas deben llevar `data-pedido="ID"`.
--}}
{{-- Formato y mascara monetaria compartidos de ByLopez (ByLopezMoneda / <v-moneda>): se incluye una sola vez por pantalla. --}}
@include('corteconfeccion::admin.partials.moneda')
@php
    // Fuera del atributo: el @json de Blade corta un argumento con arreglos anidados largos.
    $nombresDespacho = [
        'pagadores' => trans('logistica::app.pagadores'),
        'origenes' => trans('logistica::app.origenes_guia'),
        'recaudo' => trans('logistica::app.estados_recaudo'),
        'tipos' => trans('logistica::app.tipos'),
    ];
@endphp
{{--
    Estilos PROPIOS de la modal (prefijo lgm-). El Tailwind compilado del admin solo escanea Webkul: utilidades como
    col-span-2, max-sm:grid-cols-1 o dark:text-gray-400 NO existen en el build, asi que el layout y los estados de esta
    modal no dependen de ellas. Solo presentacion.
--}}
@pushOnce('styles', 'lgm-despacho')
    <style>
        .lgm-sec { display: grid; gap: 10px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
        .lgm-sec--apoyo { background: #f8fafc; }
        .dark .lgm-sec { border-color: #1f2937; }
        .dark .lgm-sec--apoyo { background: #030712; }

        .lgm-tit { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #475569; }
        .lgm-tit [class^="icon-"] { font-size: 18px; line-height: 1; color: #64748b; }
        .dark .lgm-tit { color: #9ca3af; }
        .dark .lgm-tit [class^="icon-"] { color: #9ca3af; }
        .lgm-tit-fila { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; }

        .lgm-cabecera { margin: 0; font-size: 13px; color: #64748b; }
        .dark .lgm-cabecera { color: #9ca3af; }

        .lgm-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 16px; }
        .lgm-grid--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lgm-span2, .lgm-full { grid-column: span 2 / span 2; }
        .lgm-grid--2 > .lgm-full { grid-column: 1 / -1; }
        .lgm-lbl { margin: 0; font-size: 12px; color: #64748b; }
        .lgm-val { margin: 0; font-weight: 600; color: #1f2937; overflow-wrap: anywhere; }
        .lgm-val--normal { font-weight: 500; }
        .dark .lgm-lbl { color: #9ca3af; }
        .dark .lgm-val { color: #fff; }

        .lgm-nota { margin: 0; font-size: 12px; color: #64748b; }
        .lgm-nota--linea { display: flex; flex-wrap: wrap; gap: 0 8px; margin-top: 4px; }
        .lgm-nota--azul { margin-top: 2px; color: #1d4ed8; }
        .lgm-nota--cursiva { font-style: italic; }
        .dark .lgm-nota { color: #9ca3af; }
        /* Dinero reportado vs saldo, y reparto del recaudo entre guias: pares «etiqueta … valor», nunca solo por color. */
        .lgm-estado-pago, .lgm-reparto { display: grid; gap: 4px; margin: 6px 0 0; font-size: 13px; }
        /* F6: opciones de la decision de cobro (texto + color; el radio se ve en claro y oscuro). */
        .lgm-opciones { display: grid; gap: 6px; margin: 8px 0 0; border: 0; padding: 0; }
        .lgm-opcion { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; color: #334155; cursor: pointer; }
        .lgm-opcion input { margin-top: 3px; }
        .lgm-opcion small { color: #64748b; }
        .lgm-opcion:focus-within { outline: 2px solid #2563eb; outline-offset: 1px; }
        .lgm-opcion--off { opacity: .65; cursor: not-allowed; }
        .lgm-nota--ok { padding: 8px 10px; border-radius: 6px; background: #ecfdf5; color: #065f46; }
        .dark .lgm-opcion { border-color: #374151; color: #e5e7eb; }
        .dark .lgm-opcion small { color: #9ca3af; }
        .dark .lgm-nota--ok { background: #064e3b; color: #d1fae5; }
        .lgm-estado-pago > div, .lgm-reparto > div { display: flex; justify-content: space-between; gap: 12px; }
        .lgm-estado-pago dt, .lgm-reparto dt { color: #475569; }
        .lgm-estado-pago dd, .lgm-reparto dd { margin: 0; font-weight: 700; font-variant-numeric: tabular-nums; color: #0f172a; white-space: nowrap; }
        .dark .lgm-estado-pago dt, .dark .lgm-reparto dt { color: #9ca3af; }
        .dark .lgm-estado-pago dd, .dark .lgm-reparto dd { color: #f9fafb; }
        .lgm-reparto { padding-top: 6px; border-top: 1px dashed #cbd5e1; }
        .lgm-reparto .lgm-reparto-esta dt, .lgm-reparto .lgm-reparto-esta dd { font-weight: 800; }
        .lgm-sec :is(a, button, input, select, textarea, summary):focus-visible, .lgm-pie :is(a, button):focus-visible, .lgm-cobro :is(a, button, input, select):focus-visible, .lgm-fila :is(a, button):focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .dark .lgm-nota--azul { color: #93c5fd; }

        .lgm-enlace { padding: 0; border: 0; background: none; font-size: 13px; font-weight: 600; color: #2563eb; cursor: pointer; }
        .lgm-enlace:hover { text-decoration: underline; }
        .lgm-enlace--chico { font-size: 12px; }
        .lgm-enlace--rojo { color: #dc2626; flex-shrink: 0; }
        .lgm-enlace--inicio { justify-self: start; }

        .lgm-lista { display: grid; gap: 8px; }
        .lgm-prod { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; }
        .lgm-prod-nombre { min-width: 0; flex: 1 1 160px; }
        .lgm-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .lgm-chip { display: inline-flex; align-items: baseline; gap: 6px; padding: 2px 9px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f1f5f9; font-size: 13px; font-variant-numeric: tabular-nums; }
        .lgm-chip b { font-weight: 700; color: #0f172a; }
        .lgm-chip i { font-style: normal; font-weight: 700; color: #334155; }
        .lgm-chip--cero { opacity: .5; }
        .dark .lgm-chip { border-color: #374151; background: #1f2937; }
        .dark .lgm-chip b, .dark .lgm-chip i { color: #f9fafb; }
        .lgm-total { margin: 0; font-size: 14px; font-weight: 700; color: #1f2937; }
        .dark .lgm-total { color: #fff; }

        .lgm-fila { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 10px; }
        .dark .lgm-fila { border-color: #1f2937; }
        .lgm-fila-completa { flex-basis: 100%; }

        .lgm-badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border: 1px solid; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.4; }
        .lgm-badge::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
        .lgm-badge--ok { background: #dcfce7; border-color: #86efac; color: #166534; }
        .lgm-badge--aviso { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
        .dark .lgm-badge--ok { background: #052e16; border-color: #166534; color: #86efac; }
        .dark .lgm-badge--aviso { background: #451a03; border-color: #92400e; color: #fcd34d; }

        .lgm-alerta { padding: 10px 12px; border: 1px solid #f59e0b; border-radius: 10px; background: #fffbeb; color: #78350f; }
        .lgm-alerta-tit { display: flex; align-items: center; gap: 6px; margin: 0 0 2px; font-weight: 700; }
        .dark .lgm-alerta { border-color: #b45309; background: #451a03; color: #fef3c7; }
        .lgm-alerta .lgm-nota { color: inherit; }

        .lgm-label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #1f2937; }
        .lgm-label--principal { font-size: 13px; font-weight: 700; }
        .dark .lgm-label { color: #fff; }
        select.lgm-principal { padding-top: 10px; padding-bottom: 10px; font-size: 14px; font-weight: 600; }
        .lgm-invalido { border-color: #e11d48 !important; box-shadow: 0 0 0 1px #e11d48; }
        .lgm-error { display: flex; align-items: flex-start; gap: 4px; margin: 4px 0 0; font-size: 12px; font-weight: 600; color: #be123c; }
        .lgm-error [class^="icon-"] { font-size: 15px; line-height: 1.2; flex-shrink: 0; }
        .dark .lgm-error { color: #fda4af; }

        .lgm-condicion { padding: 8px 10px; border-radius: 8px; background: #f8fafc; }
        .lgm-condicion-tit { margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; }
        .dark .lgm-condicion { background: #030712; }
        .dark .lgm-condicion-tit { color: #fff; }
        .lgm-pila { display: grid; gap: 8px; margin-top: 8px; }

        .lgm-par { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; }
        .lgm-par-valor { font-weight: 600; font-variant-numeric: tabular-nums; }
        .lgm-par-valor--fuerte { font-weight: 800; }

        .lgm-cobro { display: grid; gap: 4px; padding: 12px; border: 2px solid #fbbf24; border-radius: 10px; background: #fffbeb; }
        .lgm-cobro--no { border-color: #cbd5e1; background: #f8fafc; }
        .lgm-tit--cobro { color: #92400e; }
        .lgm-tit--cobro [class^="icon-"] { color: #b45309; }
        .lgm-cobro-fila { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 2px 12px; }
        .lgm-cobro-fila--sec { font-size: 14px; font-weight: 700; color: #78350f; }
        .lgm-cobro-et { font-size: 13px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #92400e; }
        .lgm-cobro-monto { font-size: 26px; font-weight: 800; line-height: 1.1; color: #78350f; font-variant-numeric: tabular-nums; }
        .lgm-cobro-no { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #334155; }
        .dark .lgm-cobro { border-color: #b45309; background: #451a03; }
        .dark .lgm-cobro--no { border-color: #374151; background: #030712; }
        .dark .lgm-tit--cobro, .dark .lgm-cobro-et { color: #fcd34d; }
        .dark .lgm-cobro-monto, .dark .lgm-cobro-fila--sec { color: #fef3c7; }
        .dark .lgm-cobro-no { color: #e5e7eb; }

        .lgm-archivo { display: flex; align-items: center; gap: 8px; }
        .lgm-archivo-input { width: 100%; min-width: 0; font-size: 13px; }
        .lgm-pdf-ok { display: flex; align-items: flex-start; gap: 8px; margin-top: 6px; padding: 6px 10px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #166534; }
        .lgm-pdf-ok [class^="icon-"] { font-size: 18px; line-height: 1.2; }
        .lgm-pdf-ok-tit { margin: 0; font-size: 13px; font-weight: 700; }
        .lgm-pdf-nombre { margin: 0; font-size: 12px; overflow-wrap: anywhere; }
        .dark .lgm-pdf-ok { border-color: #166534; background: #052e16; color: #86efac; }

        .lgm-pie { border-top: 1px solid #e5e7eb; padding: 10px 16px; display: grid; gap: 8px; }
        .dark .lgm-pie { border-color: #1f2937; }
        .lgm-pie-botones { display: flex; justify-content: flex-end; gap: 8px; }
        .lgm-listo { display: flex; flex-wrap: wrap; gap: 2px 14px; margin: 0; padding: 0; list-style: none; font-size: 12px; font-weight: 600; }
        .lgm-listo li { display: inline-flex; align-items: center; gap: 4px; }
        .lgm-listo li [class^="icon-"] { font-size: 15px; line-height: 1; }
        .lgm-listo-ok { color: #166534; }
        .lgm-listo-falta { color: #b45309; }
        .lgm-listo-neutro { color: #64748b; font-weight: 500; }
        .dark .lgm-listo-ok { color: #86efac; }
        .dark .lgm-listo-falta { color: #fcd34d; }
        .dark .lgm-listo-neutro { color: #9ca3af; }

        @media (max-width: 524px) {
            .lgm-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .lgm-grid--2 { grid-template-columns: minmax(0, 1fr); }
            .lgm-cobro-monto { font-size: 24px; }
            .lgm-pie { padding: 10px 12px; }
        }
    </style>
@endPushOnce

<v-logistica-despacho
    url-contexto="{{ route('admin.logistica.despachos.contexto', ['pedido' => '__ID__']) }}"
    url-despachar="{{ route('admin.logistica.despachos.despachar', ['pedido' => '__ID__']) }}"
    url-bodega="{{ route('admin.logistica.despachos.entregar_bodega', ['pedido' => '__ID__']) }}"
    url-disposicion="{{ route('admin.logistica.despachos.disposicion_cobro', ['pedido' => '__ID__']) }}"
    url-cancelar-pendiente="{{ route('admin.logistica.despachos.cancelar_pendiente', ['pedido' => '__ID__']) }}"
    url-archivo-ver="{{ route('admin.logistica.guias.archivo', ['guia' => '__ID__', 'modo' => 'ver']) }}"
    url-archivo-descargar="{{ route('admin.logistica.guias.archivo', ['guia' => '__ID__', 'modo' => 'descargar']) }}"
    url-archivo-guardar="{{ route('admin.logistica.guias.archivo.guardar', ['guia' => '__ID__']) }}"
    url-corregir="{{ route('admin.logistica.guias.corregir', ['guia' => '__ID__']) }}"
    url-stock-solicitar="{{ route('admin.logistica.stock.solicitar', ['pedido' => '__ID__']) }}"
    url-stock-directa="{{ route('admin.logistica.stock.directa', ['pedido' => '__ID__']) }}"
    url-incidencia="{{ route('admin.logistica.incidencias.detalle', ['incidencia' => '__ID__']) }}"
    url-incidencia-regularizar="{{ route('admin.logistica.incidencias.regularizar', ['incidencia' => '__ID__']) }}"
    url-incidencia-rechazar="{{ route('admin.logistica.incidencias.rechazar', ['incidencia' => '__ID__']) }}"
    :textos='@json(trans('logistica::app.despachos'))'
    :nombres='@json($nombresDespacho)'
></v-logistica-despacho>

@pushOnce('scripts')
    <script type="text/x-template" id="v-logistica-despacho-template">
        <div>
            <x-admin::drawer ref="drawer" width="560px" @close="alCerrar">
                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ titulo }}</p>

                    <p v-if="ctx" class="lgm-cabecera" data-logistica="cabecera">@{{ cabecera }}</p>
                </x-slot:header>

                <x-slot:content>
                    <div v-if="cargando" class="p-3 text-sm text-gray-500">@{{ textos.modal.cargando }}</div>

                    <div v-else-if="errorCarga" class="p-3 text-sm font-medium text-red-600 dark:text-red-400">@{{ errorCarga }}</div>

                    <div v-else-if="ctx" class="grid gap-4 text-sm" data-logistica="contenido">
                        {{-- ===== 1. Pedido: apoyo (nivel 3). Cabecera del drawer ya trae #pedido · unidades · destino ===== --}}
                        <div class="lgm-sec lgm-sec--apoyo" data-logistica="resumen">
                            <p class="lgm-tit"><span class="icon-customer"></span>@{{ textos.modal['sec-pedido'] }}</p>

                            <div class="lgm-grid">
                                <div class="lgm-span2">
                                    <p class="lgm-lbl">@{{ textos.modal.cliente }}</p>
                                    <p class="lgm-val" data-logistica="cliente">@{{ ctx.pedido.cliente || '—' }}</p>
                                </div>

                                <div>
                                    <p class="lgm-lbl">@{{ textos.modal.telefono }}</p>
                                    <p class="lgm-val">@{{ ctx.pedido.telefono || '—' }}</p>
                                </div>

                                <div class="lgm-span2">
                                    <p class="lgm-lbl">@{{ textos.modal.direccion }}</p>
                                    <p class="lgm-val lgm-val--normal">@{{ ctx.pedido.direccion || '—' }}</p>
                                </div>

                                <div>
                                    <p class="lgm-lbl">@{{ textos.modal.destino }}</p>
                                    <p class="lgm-val">@{{ ctx.pedido.ciudad || '—' }}</p>
                                </div>

                                <div>
                                    <p class="lgm-lbl">@{{ textos.modal.entrega }}</p>
                                    <p class="lgm-val lgm-val--normal">@{{ ctx.pedido.tipo_entrega || '—' }}</p>
                                </div>

                                <div>
                                    <p class="lgm-lbl">@{{ textos.modal.pago }}</p>
                                    {{-- Titulo configurado del metodo, tal cual, pero corto: «Transferencia de dinero(NEQUI,…)» → nombre + medios admitidos aparte. --}}
                                    <p class="lgm-val lgm-val--normal" data-logistica="tipo-pago">@{{ pagoPartido.nombre || '—' }}</p>
                                    <p v-if="pagoPartido.medios" class="lgm-nota" data-logistica="medios-pago">@{{ textos.modal['medios-admitidos'] }}: @{{ pagoPartido.medios }}</p>
                                </div>

                                <div v-if="ctx.bodega">
                                    <p class="lgm-lbl">@{{ textos.modal.bodega }}</p>
                                    <p class="lgm-val lgm-val--normal" data-logistica="bodega">@{{ ctx.bodega }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- ===== Despachar / completar / entregar en bodega ===== --}}
                        <template v-if="vistaModo !== 'ver'">
                            {{-- ===== 2. Productos a despachar: tallas como chips. «Ajustar» cambia unidades a despachar, nunca el valor de la venta ===== --}}
                            <div class="lgm-sec" data-logistica="unidades">
                                <p v-if="ctx.unidades.despachadas > 0" class="lgm-nota">
                                    @{{ t(textos.modal['ya-despachadas'], { n: ctx.unidades.despachadas }) }}
                                </p>

                                <div class="lgm-tit-fila">
                                    <p class="lgm-tit"><span class="icon-product"></span>@{{ textos.modal['sec-productos'] }}</p>

                                    <button type="button" class="lgm-enlace" data-logistica="ajustar" @click="ajustar = ! ajustar">
                                        @{{ ajustar ? textos.modal['ocultar-ajuste'] : textos.modal.ajustar }}
                                    </button>
                                </div>

                                <div v-if="! ajustar" class="lgm-lista" data-logistica="resumen-productos">
                                    <div v-for="p in productosResumen" :key="p.clave" class="lgm-prod">
                                        <div class="lgm-prod-nombre">
                                            <p class="lgm-val">@{{ p.nombre }}</p>
                                            <p class="lgm-nota">@{{ p.sku }}</p>
                                        </div>

                                        <div class="lgm-chips">
                                            <span v-for="f in p.filas" :key="f.id" class="lgm-chip" :class="f.cantidad === 0 ? 'lgm-chip--cero' : ''" data-logistica="resumen-talla">
                                                <b v-if="f.talla">@{{ f.talla }}</b><i>@{{ f.cantidad }}</i>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <p class="lgm-total" data-logistica="total-unidades">
                                    @{{ t(textos.modal['total-unidades'], { n: totalSeleccionado }) }}
                                </p>

                                {{-- Sin ninguna unidad despachable (stock 0): nada que registrar. Con stock parcial: se despacha lo que hay. --}}
                                <p v-if="sinStockTotal" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400" data-logistica="sin-stock-total">@{{ textos.modal['sin-stock-total'] }}</p>
                                <p v-else-if="quedanPendientes > 0" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300" data-logistica="quedaran">
                                    @{{ t(textos.modal.quedaran, { n: quedanPendientes }) }}
                                </p>

                                <div v-if="ajustar" class="mt-2 grid gap-2 rounded-lg border p-2 dark:border-gray-800" data-logistica="ajuste">
                                    <div v-for="l in ctx.unidades.lineas" :key="l.id" class="flex items-center justify-between gap-3" :data-linea-fila="l.id">
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-gray-800 dark:text-white">@{{ l.sku }}</p>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">@{{ l.nombre }} &middot; @{{ t(textos.modal['pendientes-linea'], { n: l.pendientes }) }}</p>
                                            <p v-if="l.maximo === 0" class="text-xs font-semibold text-red-600 dark:text-red-400" data-logistica="sin-stock">@{{ textos.modal['sin-stock'] }}</p>
                                            <p v-else-if="l.maximo < l.pendientes" class="text-xs font-medium text-amber-700 dark:text-amber-300" data-logistica="stock-parcial">
                                                @{{ t(textos.modal['stock-parcial'], { n: l.maximo, pendientes: l.pendientes }) }}
                                            </p>
                                        </div>

                                        <input
                                            type="number" min="0" :max="l.maximo" step="1"
                                            v-model.number="cantidades[l.id]"
                                            :disabled="l.maximo === 0"
                                            :data-linea="l.id"
                                            class="w-20 rounded-md border px-2 py-1.5 text-right text-sm disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:disabled:bg-gray-950"
                                            @change="clamparCantidad(l)"
                                            @blur="clamparCantidad(l)"
                                        >
                                    </div>

                                    <button type="button" class="justify-self-start text-sm font-medium text-blue-600 hover:underline" @click="todoPendiente">
                                        @{{ textos.modal.todo }}
                                    </button>
                                </div>
                            </div>

                            {{-- Stock insuficiente: BODEGA solicita autorizacion con el conteo fisico; el Administrador regulariza o rechaza. Nunca se despacha por encima del stock. --}}
                            <div v-if="ctx.stock && (ctx.stock.hay_faltante || ctx.stock.abierta)" class="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950" data-logistica="stock">
                                <p class="font-semibold text-amber-900 dark:text-amber-100">@{{ textos.stock.titulo }}</p>
                                <p class="text-xs text-amber-900 dark:text-amber-200">@{{ textos.stock.ayuda }}</p>

                                <div v-for="l in ctx.stock.lineas" :key="l.id" class="flex items-center justify-between gap-2 text-xs text-amber-900 dark:text-amber-100" data-logistica="stock-linea">
                                    <span class="truncate font-medium">@{{ l.sku }}</span>
                                    <span>@{{ textos.stock.necesita }} @{{ l.necesaria }} &middot; @{{ textos.stock.sistema }} @{{ l.stock_sistema }}</span>
                                </div>

                                <p v-if="ctx.stock.abierta && ! ctx.stock.puede_regularizar" class="rounded-md bg-white px-3 py-2 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200" data-logistica="stock-pendiente">
                                    @{{ t(textos.stock.pendiente, { quien: ctx.stock.abierta.solicitante, fecha: ctx.stock.abierta.fecha }) }}
                                </p>

                                <p v-if="! ctx.stock.abierta && ctx.stock.ultima && ctx.stock.ultima.estado === 'rechazada'" class="rounded-md bg-white px-3 py-2 text-xs font-medium text-red-700 dark:bg-gray-900 dark:text-red-300" data-logistica="stock-rechazada">
                                    @{{ t(textos.stock.rechazada, { quien: ctx.stock.ultima.resolutor, fecha: ctx.stock.ultima.fecha, motivo: ctx.stock.ultima.motivo }) }}
                                </p>

                                <div v-if="ctx.stock.abierta && ctx.stock.puede_regularizar" class="text-xs text-amber-900 dark:text-amber-100" data-logistica="stock-solicitud-abierta">
                                    @{{ textos.stock.solicitante }}: @{{ ctx.stock.abierta.solicitante }} (@{{ ctx.stock.abierta.fecha }}) &middot; @{{ ctx.stock.abierta.nota }}
                                </div>

                                <div v-if="! stockForm.abierto" class="flex flex-wrap gap-2">
                                    <button v-if="ctx.stock.puede_solicitar" type="button" class="secondary-button" data-logistica="stock-solicitar" @click="abrirStock('solicitar')">@{{ textos.stock.solicitar }}</button>
                                    <button v-if="ctx.stock.puede_regularizar" type="button" class="secondary-button" data-logistica="stock-regularizar" @click="abrirStock('regularizar')">
                                        @{{ ctx.stock.abierta ? textos.stock.revisar : textos.stock.regularizar }}
                                    </button>
                                </div>

                                <div v-else class="grid gap-2 rounded-lg bg-white p-2 dark:bg-gray-900" data-logistica="stock-form">
                                    <p v-if="stockForm.cargando" class="text-xs text-gray-500">@{{ textos.stock.cargando }}</p>

                                    <div v-for="f in stockFilas" :key="f.clave" class="grid gap-1 border-b pb-2 last:border-b-0 dark:border-gray-800" :data-stock-fila="f.clave">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white">@{{ f.sku }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    @{{ textos.stock.necesita }} @{{ f.necesaria }} &middot; @{{ f.vivo !== null ? textos.stock['stock-ahora'] : textos.stock.sistema }} @{{ f.vivo !== null ? f.vivo : f.sistema }}
                                                    <template v-if="f.declarado !== null"> &middot; @{{ textos.stock.contado }} @{{ f.declarado }}</template>
                                                </p>
                                            </div>

                                            <input type="number" min="0" step="1" v-model="stockForm.conteos[f.clave]" :data-conteo="f.clave" :placeholder="textos.stock.conteo" :aria-label="textos.stock.conteo"
                                                class="w-24 rounded-md border px-2 py-1.5 text-right text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                        </div>

                                        <details v-if="f.revision" class="text-xs text-gray-600 dark:text-gray-300" data-logistica="stock-revision">
                                            <summary class="cursor-pointer">@{{ textos.stock.reservado }} @{{ f.revision.reservado }} &middot; @{{ textos.stock.otros }}: @{{ f.revision.otros_pedidos }}</summary>
                                            <p class="mt-1 font-medium">@{{ textos.stock.movimientos }}</p>
                                            <p v-if="! f.revision.movimientos.length">@{{ textos.stock['sin-movimientos'] }}</p>
                                            <p v-for="m in f.revision.movimientos" :key="m.id">#@{{ m.id }} &middot; @{{ m.tipo_movimiento }} &middot; @{{ m.cantidad_anterior }} → @{{ m.cantidad_nueva }} &middot; @{{ m.nombre_usuario_snapshot }}</p>
                                            <p v-if="f.revision.despachos_recientes.length" class="mt-1 font-medium">@{{ textos.stock.despachos }}</p>
                                            <p v-for="d in f.revision.despachos_recientes" :key="d.envio">#@{{ d.pedido }} &middot; @{{ d.qty }} &middot; @{{ fecha(d.created_at) }}</p>
                                        </details>
                                    </div>

                                    <div v-if="stockForm.tipo === 'solicitar'">
                                        <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">@{{ textos.stock.nota }}</label>
                                        <textarea rows="2" maxlength="500" v-model="stockForm.nota" data-logistica="stock-nota" :placeholder="textos.stock['nota-ph']" :class="clasesInput"></textarea>
                                    </div>

                                    <template v-else>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">@{{ stockForm.rechazando ? textos.stock['motivo-rechazo'] : textos.stock.motivo }}</label>
                                            <textarea rows="2" maxlength="500" v-model="stockForm.motivo" data-logistica="stock-motivo" :placeholder="textos.stock['motivo-ph']" :class="clasesInput"></textarea>
                                        </div>

                                        <label v-if="! stockForm.rechazando" class="flex items-start gap-2 text-xs text-gray-800 dark:text-white">
                                            <input type="checkbox" v-model="stockForm.confirma" data-logistica="stock-confirma" class="mt-0.5">
                                            <span>@{{ textos.stock.confirma }}</span>
                                        </label>

                                        <p v-if="! stockForm.rechazando" class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.stock['solo-sube'] }}</p>
                                    </template>

                                    <p v-if="stockForm.error" class="text-xs font-medium text-red-600 dark:text-red-400" data-logistica="stock-error">@{{ stockForm.error }}</p>

                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button type="button" class="transparent-button" :disabled="stockForm.enviando" @click="cerrarStock">@{{ textos.stock.cancelar }}</button>

                                        <button v-if="stockForm.tipo === 'regularizar' && ctx.stock.abierta && ! stockForm.rechazando" type="button" class="secondary-button" data-logistica="stock-rechazar" @click="stockForm.rechazando = true">
                                            @{{ textos.stock.rechazar }}
                                        </button>

                                        <button type="button" class="primary-button" :disabled="stockForm.enviando || ! stockListo" data-logistica="stock-enviar" @click="enviarStock">
                                            @{{ stockForm.enviando ? textos.stock.enviando : textoBotonStock }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Estado FINANCIERO del pedido en Cartera: informativo y de SOLO LECTURA. NO es lo que cobra quien entrega (eso es «Cobrar al cliente»). --}}
                            <div v-if="estadoPago" class="lgm-fila" data-logistica="estado-pago">
                                <span class="lgm-lbl">@{{ textos.modal['estado-pago'] }}</span>
                                <span v-if="estadoPago === 'pagado'" class="lgm-badge lgm-badge--ok" data-logistica="pago-pagado">@{{ textos.modal['pago-pagado'] }}</span>
                                <span v-else-if="estadoPago === 'pendiente'" class="lgm-badge lgm-badge--aviso" data-logistica="pago-saldo">@{{ t(textos.modal['pago-pendiente'], { valor: precio(ctx.envio.pago_pendiente.saldo) }) }}</span>
                                <span v-else class="lgm-badge lgm-badge--aviso" data-logistica="pago-validacion">@{{ textos.modal['pago-pendiente-titulo'] }}</span>

                                {{-- Dinero reportado vs saldo: se muestran por separado. Lo reportado sin revisar YA esta descontado del saldo de Cartera aunque aun no este confirmado. --}}
                                <dl v-if="estadoPago === 'en_validacion' || (estadoPago === 'pendiente' && pagoConfirmado > 0)" class="lgm-fila-completa lgm-estado-pago" data-logistica="pago-desglose">
                                    <div v-if="ctx.envio.pago_pendiente.sin_revisar > 0">
                                        <dt>@{{ textos.modal['pago-reportado'] }}</dt>
                                        <dd data-logistica="pago-reportado">@{{ precio(ctx.envio.pago_pendiente.sin_revisar) }}</dd>
                                    </div>
                                    {{-- F6: dinero CONFIRMADO por Cartera (no se suma a lo reportado ni al saldo: son partes de la misma cuenta). --}}
                                    <div v-if="pagoConfirmado > 0">
                                        <dt>@{{ textos.modal['pago-confirmado'] }}</dt>
                                        <dd data-logistica="pago-confirmado">@{{ precio(pagoConfirmado) }}</dd>
                                    </div>
                                    <div v-if="ctx.envio.pago_pendiente.saldo !== null">
                                        <dt>@{{ textos.modal['pago-saldo-cartera'] }}</dt>
                                        <dd data-logistica="pago-saldo-cartera">@{{ precio(ctx.envio.pago_pendiente.saldo) }}</dd>
                                    </div>
                                    <p v-if="ctx.envio.pago_pendiente.sin_revisar > 0" class="lgm-nota">@{{ textos.modal['pago-descontado-nota'] }}</p>
                                </dl>

                                <p v-if="estadoPago === 'pendiente' && vistaModo === 'entregar_bodega'" class="lgm-nota lgm-fila-completa">@{{ textos.modal['pago-deuda-ayuda'] }}</p>
                                <p v-if="estadoPago === 'en_validacion' && ! bloqueoPago" class="lgm-nota lgm-fila-completa" data-logistica="pago-validacion-aviso">
                                    @{{ textos.modal['pago-validacion-aviso'] }}
                                </p>
                            </div>

                            {{-- Pago en validacion que cambia lo que cobra quien entrega: no se despacha (el servidor tambien lo bloquea). --}}
                            <div v-if="bloqueoPago" class="lgm-alerta" data-logistica="pago-pendiente">
                                <p class="lgm-alerta-tit"><span class="icon-information"></span>@{{ textos.modal['pago-pendiente-titulo'] }}</p>
                                <p class="lgm-nota">@{{ t(textos.modal['pago-pendiente-texto'], { valor: precio(ctx.envio.pago_pendiente.sin_revisar) }) }}</p>
                            </div>

                            {{-- F6: pedido que NO es contra entrega con dinero sin confirmar. No sale con «no cobrar nada» sin una decision vigente
                                 (el servidor la exige al despachar). Esperar = no despachar todavia; cobrar al entregar; credito (solo con permiso). --}}
                            <div v-if="disposicion && disposicion.requiere && vistaModo !== 'entregar_bodega'" class="lgm-sec" data-logistica="disposicion-cobro">
                                <p v-if="disposicion.vigente" class="lgm-nota lgm-nota--ok" role="status" data-logistica="disposicion-vigente">
                                    @{{ t(textos.disposicion['vigente-' + disposicion.vigente.tipo], { actor: disposicion.vigente.actor, fecha: disposicion.vigente.fecha, motivo: disposicion.vigente.motivo || '' }) }}
                                </p>

                                <template v-else>
                                    <div class="lgm-alerta" role="alert">
                                        <p class="lgm-alerta-tit"><span class="icon-information"></span>@{{ textos.disposicion.titulo }}</p>
                                        <p class="lgm-nota">@{{ t(textos.disposicion.explicacion, { monto: precio(disposicion.por_confirmar) }) }}</p>
                                        <p v-if="disposicion.anterior" class="lgm-nota" data-logistica="disposicion-anterior">
                                            @{{ t(textos.disposicion.anterior, { tipo: textos.disposicion['tipo-' + disposicion.anterior.tipo] }) }}
                                        </p>
                                    </div>

                                    <fieldset class="lgm-opciones" data-logistica="disposicion-opciones">
                                        <legend class="lgm-label">@{{ textos.disposicion.titulo }}</legend>
                                        <label class="lgm-opcion">
                                            <input type="radio" value="esperar" v-model="dispo.tipo" data-logistica="disposicion-esperar">
                                            <span><b>@{{ textos.disposicion['opcion-esperar'] }}</b><br><small>@{{ textos.disposicion['opcion-esperar-ayuda'] }}</small></span>
                                        </label>
                                        <label class="lgm-opcion">
                                            <input type="radio" value="cobrar_al_entregar" v-model="dispo.tipo" :disabled="! disposicion.permisos.cobrar_al_entregar" data-logistica="disposicion-cobrar">
                                            <span><b>@{{ textos.disposicion['opcion-cobrar'] }}</b><br><small>@{{ textos.disposicion['opcion-cobrar-ayuda'] }}</small></span>
                                        </label>
                                        <label class="lgm-opcion" :class="{ 'lgm-opcion--off': ! disposicion.permisos.credito }">
                                            <input type="radio" value="credito_autorizado" v-model="dispo.tipo" :disabled="! disposicion.permisos.credito" data-logistica="disposicion-credito">
                                            <span>
                                                <b>@{{ textos.disposicion['opcion-credito'] }}</b><br><small>@{{ textos.disposicion['opcion-credito-ayuda'] }}</small>
                                                <small v-if="! disposicion.permisos.credito" class="lgm-sub">@{{ textos.disposicion['sin-permiso-credito'] }}</small>
                                            </span>
                                        </label>
                                    </fieldset>

                                    <div v-if="dispo.tipo === 'credito_autorizado'">
                                        <label class="lgm-label" for="lgm-dispo-motivo">@{{ textos.disposicion.motivo }}</label>
                                        <textarea id="lgm-dispo-motivo" rows="2" maxlength="500" v-model="dispo.motivo" data-logistica="disposicion-motivo" :class="clasesInput"></textarea>
                                    </div>

                                    <p v-if="dispo.tipo === 'esperar'" class="lgm-nota" data-logistica="disposicion-esperando">@{{ textos.disposicion.esperando }}</p>
                                    <p v-if="dispo.error" class="lgm-error" role="alert" data-logistica="disposicion-error">@{{ dispo.error }}</p>

                                    <div v-if="dispo.tipo !== 'esperar'" class="flex justify-end">
                                        <button type="button" class="secondary-button" :disabled="dispo.enviando" data-logistica="disposicion-guardar" @click="guardarDisposicion">
                                            @{{ dispo.enviando ? textos.disposicion.guardando : textos.disposicion.guardar }}
                                        </button>
                                    </div>
                                </template>
                            </div>

                            {{-- Cancelar lo pendiente: libera la reserva, no toca el stock fisico ni lo ya despachado. Solo con permiso y motivo. --}}
                            <div v-if="cancelacion && cancelacion.disponible && cancelacion.permiso && cancelacion.pendientes > 0 && ! cancelacion.bloqueo" class="lgm-sec" data-logistica="cancelar-pendiente">
                                <details>
                                    <summary class="lgm-label" style="cursor:pointer">@{{ textos.cancelacion.titulo }}</summary>
                                    <p class="lgm-nota">@{{ t(textos.cancelacion.explicacion, { unidades: cancelacion.pendientes, valor: precio(cancelacion.valor_pendiente) }) }}</p>
                                    <label class="lgm-label" for="lgm-canc-motivo">@{{ textos.cancelacion.motivo }}</label>
                                    <textarea id="lgm-canc-motivo" rows="2" maxlength="500" v-model="canc.motivo" data-logistica="cancelacion-motivo" :class="clasesInput"></textarea>
                                    <p v-if="canc.error" class="lgm-error" role="alert" data-logistica="cancelacion-error">@{{ canc.error }}</p>
                                    <div class="flex justify-end">
                                        <button type="button" class="secondary-button" :disabled="canc.enviando" data-logistica="cancelacion-confirmar" @click="cancelarPendiente">
                                            @{{ canc.enviando ? textos.cancelacion.cancelando : textos.cancelacion.confirmar }}
                                        </button>
                                    </div>
                                </details>
                            </div>

                            {{-- Recogida en bodega: sin transportadora, guia ni PDF. Ni la deuda ni un pago en validacion impiden entregar. --}}
                            <template v-if="vistaModo === 'entregar_bodega'">
                                <div class="lgm-sec">
                                    <p class="lgm-nota" data-logistica="recogida-sin-costo">@{{ textos.modal['recogida-sin-costo'] }}</p>

                                    <div>
                                        <label class="lgm-label">@{{ textos.modal['quien-retira'] }}</label>
                                        <input type="text" maxlength="500" v-model="form.quien_retira" data-logistica="quien-retira" :class="clasesInput">
                                    </div>
                                </div>
                            </template>

                            <template v-else>
                                {{-- ===== 3. Envio y transportadora (nivel 2): la transportadora es el campo principal ===== --}}
                                <div class="lgm-sec" data-logistica="envio">
                                    <p class="lgm-tit"><span class="icon-ship"></span>@{{ textos.modal['sec-envio'] }}</p>

                                    <div>
                                        <label class="lgm-label lgm-label--principal">@{{ textos.modal.transportadora }} *</label>
                                        <select v-model="form.transportadora" data-logistica="transportadora" :class="[clasesInput, 'lgm-principal', errores.transportadora ? 'lgm-invalido' : '']" @change="elegirTransportadora">
                                            <option value="">@{{ textos.modal['transportadora-elegir'] }}</option>
                                            <option v-for="t in ctx.transportadoras" :key="t.codigo" :value="t.codigo">@{{ t.nombre }}</option>
                                        </select>
                                        <p v-if="errores.transportadora" class="lgm-error" data-logistica="error-transportadora"><span class="icon-information"></span>@{{ errores.transportadora }}</p>

                                        {{-- Tipo y capacidades de la transportadora elegida (nunca se deduce nada del nombre). --}}
                                        <p v-if="transportadoraSel" class="lgm-nota lgm-nota--linea" data-logistica="capacidades">
                                            <span>@{{ t(textos.modal['transportado-por'], { nombre: transportadoraSel.nombre }) }}</span>
                                            <span v-if="transportadoraSel.tipo">&middot; @{{ nombres.tipos[transportadoraSel.tipo] || transportadoraSel.tipo }}</span>
                                            <span>&middot; @{{ transportadoraSel.liquida_cuenta_corriente ? textos.modal['cuenta-corriente'] : textos.modal['sin-cuenta-corriente'] }}</span>
                                        </p>
                                        <p v-if="esElegidoVenta" class="lgm-nota lgm-nota--azul" data-logistica="domiciliario-elegido-venta">@{{ textos.modal['domiciliario-elegido-venta'] }}</p>
                                        <p v-else-if="esSugerido" class="lgm-nota lgm-nota--azul" data-logistica="domiciliario-sugerido">@{{ t(textos.modal['domiciliario-sugerido'], { zona: zonaNombre }) }}</p>
                                    </div>

                                    {{-- Condicion del envio: deducida del metodo; cambiar el pagador es una excepcion con motivo. --}}
                                    <div class="lgm-condicion" data-logistica="condicion">
                                        <p class="lgm-lbl">@{{ textos.modal.condicion }}</p>
                                        <p class="lgm-condicion-tit">@{{ textos.modal.condiciones[ctx.envio.condicion] }}</p>
                                        <p class="lgm-nota" data-logistica="asume">@{{ textoAsume }}</p>

                                        <button v-if="ctx.envio.pagador_deducido && ! cambiarPagador" type="button" class="lgm-enlace lgm-enlace--chico" data-logistica="cambiar-pagador" @click="cambiarPagador = true">
                                            @{{ textos.modal['cambiar-pagador'] }}
                                        </button>

                                        <div v-if="mostrarPagador" class="lgm-pila">
                                            <select v-model="form.flete_pagador" data-logistica="flete-pagador" :class="[clasesInput, errores.flete_pagador ? 'lgm-invalido' : '']">
                                                <option value="">@{{ textos.modal['flete-pagador'] }}</option>
                                                <option v-for="p in ctx.pagadores" :key="p" :value="p">@{{ nombres.pagadores[p] }}</option>
                                            </select>
                                            <p v-if="errores.flete_pagador" class="lgm-error" data-logistica="error-flete-pagador"><span class="icon-information"></span>@{{ errores.flete_pagador }}</p>

                                            <input v-if="pagadorCambiado" type="text" maxlength="500" v-model="form.motivo_pagador" data-logistica="motivo-pagador" :placeholder="textos.modal['motivo-pagador-ph']" :aria-label="textos.modal['motivo-pagador']" :class="[clasesInput, errores.motivo_pagador ? 'lgm-invalido' : '']">
                                            <p v-if="errores.motivo_pagador" class="lgm-error" data-logistica="error-motivo-pagador"><span class="icon-information"></span>@{{ errores.motivo_pagador }}</p>
                                        </div>
                                    </div>
                                </div>

                                {{-- ===== 4. Zona y costo LOGISTICO del servicio (transportadora): tarifa sugerida + adicional = costo final. No es el precio de la venta. ===== --}}
                                <div class="lgm-sec" data-logistica="costo">
                                    <div class="lgm-tit-fila">
                                        <p class="lgm-tit"><span class="icon-location"></span>@{{ textos.modal['sec-costo'] }}</p>

                                        <span v-if="ctx.envio.zona_auto" class="lgm-badge lgm-badge--ok" data-logistica="zona-auto">@{{ ctx.envio.zona_auto.nombre }} &middot; @{{ textos.modal['zona-auto'] }}</span>
                                        <span v-else-if="destinoCobertura === 'fuera_cobertura'" class="lgm-badge lgm-badge--aviso" data-logistica="destino-fuera-cobertura">@{{ textos.modal['destino-fuera'] }}</span>
                                        <span v-else-if="destinoCobertura === 'no_identificado'" class="lgm-badge lgm-badge--aviso" data-logistica="destino-no-identificado">@{{ textos.modal['destino-no-identificado'] }}</span>
                                        <span v-else class="lgm-badge lgm-badge--aviso" data-logistica="sin-zona-auto">@{{ textos.modal['zona-sin-auto'] }}</span>
                                    </div>

                                    {{-- Destino reconocido FUERA de la cobertura local: no hay zona ni tarifa sugerida y no se sugiere elegir una zona local (Bogota/Soacha). --}}
                                    <p v-if="! ctx.envio.zona_auto && destinoCobertura === 'fuera_cobertura'" class="lgm-nota" data-logistica="destino-fuera-ayuda">@{{ textos.modal['destino-fuera-ayuda'] }}</p>

                                    <template v-if="! ctx.envio.zona_auto && destinoCobertura !== 'fuera_cobertura'">
                                        <div>
                                            <label class="lgm-label">@{{ textos.modal.zona }}</label>
                                            <select v-model="form.zona_id" data-logistica="zona" :class="clasesInput" @change="cambiarZona">
                                                <option value="">@{{ textos.modal['zona-sin'] }}</option>
                                                <option v-for="z in ctx.envio.zonas" :key="z.id" :value="String(z.id)">@{{ z.nombre }}</option>
                                            </select>
                                            <p class="lgm-nota">@{{ destinoCobertura === 'no_identificado' ? textos.modal['destino-no-identificado-ayuda'] : textos.modal['zona-ayuda'] }}</p>
                                        </div>
                                    </template>

                                    <template v-if="tarifaSugerida !== null">
                                        <div class="lgm-par"><span>@{{ textos.modal['tarifa-sugerida'] }}</span><span class="lgm-par-valor" data-logistica="tarifa">@{{ precio(tarifaSugerida) }}</span></div>
                                        <div class="lgm-par"><span>@{{ textos.modal['costo-servicio'] }}</span><span class="lgm-par-valor lgm-par-valor--fuerte" data-logistica="costo-final">@{{ precio(costoFinal || 0) }}</span></div>

                                        <button v-if="! mostrarAjusteCosto" type="button" class="lgm-enlace lgm-enlace--chico" data-logistica="ajustar-costo" @click="ajustarCosto = true">
                                            @{{ textos.modal['ajustar-costo'] }}
                                        </button>

                                        <div v-else>
                                            <label class="lgm-label">@{{ textos.modal['costo-adicional'] }}</label>
                                            <input type="number" step="0.01" v-model="form.costo_adicional" data-logistica="costo-adicional" :class="[clasesInput, errores.costo_adicional ? 'lgm-invalido' : '']">
                                            <p v-if="errores.costo_adicional" class="lgm-error" data-logistica="error-costo-adicional"><span class="icon-information"></span>@{{ errores.costo_adicional }}</p>
                                        </div>
                                    </template>

                                    <template v-else>
                                        <p v-if="form.transportadora" class="lgm-nota">@{{ textos.modal['tarifa-no-hay'] }}</p>
                                        <div>
                                            <label class="lgm-label">@{{ textos.modal['costo-servicio'] }}<span v-if="exigeCosto"> *</span></label>
                                            <v-moneda ref="flete" :max-enteros="9" v-model="form.valor_flete" data-logistica="valor-flete" :aria-label="textos.modal['costo-servicio']" :class="[clasesInput, errores.valor_flete ? 'lgm-invalido' : '']"></v-moneda>
                                            <p v-if="errores.valor_flete" class="lgm-error" data-logistica="error-valor-flete"><span class="icon-information"></span>@{{ errores.valor_flete }}</p>
                                        </div>
                                    </template>

                                    <div v-if="difiereTarifa">
                                        <input type="text" maxlength="500" v-model="form.motivo_costo" data-logistica="motivo-costo" :placeholder="textos.modal['motivo-costo-ph']" :aria-label="textos.modal['motivo-costo']" :class="[clasesInput, errores.motivo_costo ? 'lgm-invalido' : '']">
                                        <p v-if="errores.motivo_costo" class="lgm-error" data-logistica="error-motivo-costo"><span class="icon-information"></span>@{{ errores.motivo_costo }}</p>
                                    </div>

                                    <p class="lgm-nota lgm-nota--cursiva" data-logistica="costo-logistico-nota">@{{ textos.modal['costo-logistico-nota'] }}</p>
                                </div>

                                {{-- ===== 5. Estado de cobro (nivel 1): UNA instruccion para quien entrega (recaudo contra entrega + domicilio por pagar), nunca el saldo
                                     de Cartera. SOLO LECTURA: el valor de la venta no se modifica desde Despacho. ===== --}}
                                <div class="lgm-cobro" :class="(cobroPedido > 0 || cobroDomicilio > 0) ? '' : 'lgm-cobro--no'" data-logistica="cobro">
                                    <p class="lgm-tit lgm-tit--cobro"><span class="icon-sales"></span>@{{ textos.modal['sec-cobro'] }}</p>

                                    <template v-if="cobroPedido > 0 || cobroDomicilio > 0">
                                        <div class="lgm-cobro-fila">
                                            <span class="lgm-cobro-et">@{{ textos.modal['cobrar-cliente'] }}</span>
                                            <span v-if="cobroPedido > 0" class="lgm-cobro-monto" data-logistica="cobro-pedido">@{{ precio(cobroPedido) }}</span>
                                        </div>
                                        {{-- Despacho parcial: saldo del pedido / ya comprometido en otras guias / lo que cobra ESTA guia (valores del servidor). --}}
                                        <dl v-if="repartoRecaudo" class="lgm-reparto" data-logistica="recaudo-reparto">
                                            <div><dt>@{{ textos.modal['reparto-saldo'] }}</dt><dd data-logistica="reparto-saldo">@{{ precio(repartoRecaudo.saldo) }}</dd></div>
                                            <div v-if="repartoRecaudo.comprometido > 0"><dt>@{{ textos.modal['reparto-comprometido'] }}</dt><dd data-logistica="reparto-comprometido">@{{ precio(repartoRecaudo.comprometido) }}</dd></div>
                                            <div class="lgm-reparto-esta"><dt>@{{ textos.modal['reparto-esta'] }}</dt><dd data-logistica="reparto-esta">@{{ precio(repartoRecaudo.esta) }}</dd></div>
                                        </dl>
                                        <p v-if="recaudoGuiaNota" class="lgm-nota" data-logistica="cobro-nota">@{{ recaudoGuiaNota }}</p>
                                        <div v-if="cobroDomicilio > 0" class="lgm-cobro-fila lgm-cobro-fila--sec" data-logistica="cobra-domicilio">
                                            <span>@{{ textos.modal['cobrar-domicilio'] }}</span><span class="lgm-cobro-dom">@{{ precio(cobroDomicilio) }}</span>
                                        </div>
                                    </template>
                                    <p v-else class="lgm-cobro-no" data-logistica="no-cobrar">@{{ textos.modal['no-cobrar'] }}</p>

                                    <p class="lgm-nota" data-logistica="cobro-solo-lectura">@{{ textos.modal['cobro-solo-lectura'] }}</p>

                                    {{-- C1: el sistema PROPONE el recaudo de esta guia; solo una excepcion lo cambia, y entonces pide motivo. --}}
                                    <template v-if="admiteRecaudo">
                                        <button v-if="! ajustarRecaudo" type="button" class="lgm-enlace lgm-enlace--chico" data-logistica="ajustar-recaudo" @click="abrirAjusteRecaudo">
                                            @{{ textos.modal['ajustar-recaudo'] }}
                                        </button>
                                        <div v-else class="grid gap-2">
                                            <label class="lgm-label">@{{ textos.modal['recaudo-l'] }}</label>
                                            <v-moneda :max-enteros="9" v-model="form.recaudo_esperado" data-logistica="recaudo-esperado" :aria-label="textos.modal['recaudo-l']" :class="[clasesInput, errores.recaudo_esperado ? 'lgm-invalido' : '']"></v-moneda>
                                            <p v-if="errores.recaudo_esperado" class="lgm-error" data-logistica="error-recaudo-esperado"><span class="icon-information"></span>@{{ errores.recaudo_esperado }}</p>
                                            <template v-if="difiereRecaudo">
                                                <input type="text" maxlength="500" v-model="form.motivo_recaudo" data-logistica="motivo-recaudo" :placeholder="textos.modal['motivo-recaudo-ph']" :aria-label="textos.modal['motivo-recaudo']" :class="[clasesInput, errores.motivo_recaudo ? 'lgm-invalido' : '']">
                                                <p v-if="errores.motivo_recaudo" class="lgm-error" data-logistica="error-motivo-recaudo"><span class="icon-information"></span>@{{ errores.motivo_recaudo }}</p>
                                            </template>
                                            <button type="button" class="lgm-enlace lgm-enlace--chico" data-logistica="usar-recaudo-sugerido" @click="cerrarAjusteRecaudo">@{{ textos.modal['usar-recaudo-sugerido'] }}</button>
                                        </div>
                                    </template>
                                </div>

                                {{-- ===== 6. Guia y observaciones ===== --}}
                                <div class="lgm-sec" data-logistica="guia-bloque">
                                    <p class="lgm-tit"><span class="icon-clip"></span>@{{ textos.modal['sec-guia'] }}</p>

                                    <div class="lgm-grid lgm-grid--2">
                                        <div :class="guiaInterna ? 'lgm-full' : ''">
                                            <label class="lgm-label">@{{ textos.modal.guia }}<template v-if="! guiaInterna"> *</template></label>

                                            {{-- Guia INTERNA (GI-000001): el servidor asigna el numero al confirmar; aqui no se escribe nada. --}}
                                            <template v-if="guiaInterna">
                                                <input type="text" disabled readonly :value="textos.modal['guia-interna-auto']" data-logistica="guia-interna-auto" :class="clasesInput">
                                                <p class="lgm-nota" data-logistica="guia-interna-nota">@{{ textos.modal['guia-interna-nota'] }}</p>
                                            </template>

                                            {{-- Guia EXTERNA: numero que da la transportadora o plataforma (flujo de siempre). --}}
                                            <template v-else>
                                                <input
                                                    ref="guia" type="text" maxlength="100" autocomplete="off"
                                                    v-model="form.numero_guia" data-logistica="numero-guia"
                                                    :placeholder="textos.modal['guia-ph']"
                                                    :class="[clasesInput, errores.numero_guia ? 'lgm-invalido' : '']"
                                                >
                                                <p v-if="errores.numero_guia" class="lgm-error" data-logistica="error-numero-guia"><span class="icon-information"></span>@{{ errores.numero_guia }}</p>
                                            </template>
                                        </div>

                                        {{-- Origen de la guia y PDF externo: solo para transportadoras con guia propia. --}}
                                        <div v-if="! guiaInterna">
                                            <label class="lgm-label">@{{ textos.modal.origen }}</label>
                                            <select v-model="form.origen_guia" data-logistica="origen-guia" :class="clasesInput">
                                                <option v-for="o in ctx.origenes" :key="o" :value="o">@{{ nombres.origenes[o] }}</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div v-if="! guiaInterna">
                                        <label class="lgm-label">@{{ textos.modal.tracking }}</label>
                                        <input type="text" maxlength="500" v-model="form.tracking" data-logistica="tracking" :placeholder="textos.modal['tracking-ph']" :class="clasesInput">
                                    </div>

                                    <div v-if="! guiaInterna">
                                        <label class="lgm-label">@{{ textos.modal.pdf }}</label>

                                        <div class="lgm-archivo">
                                            <input ref="pdf" type="file" accept="application/pdf,.pdf" data-logistica="pdf" class="lgm-archivo-input" @change="alElegirPdf">
                                            <button v-if="archivo" type="button" class="lgm-enlace lgm-enlace--rojo" @click="quitarPdf">@{{ textos.modal['pdf-quitar'] }}</button>
                                        </div>

                                        <div v-if="archivo && pdfFirma === 'ok'" class="lgm-pdf-ok" data-logistica="pdf-elegido">
                                            <span class="icon-tick"></span>
                                            <div>
                                                <p class="lgm-pdf-ok-tit">@{{ t(textos.modal['pdf-valido'], { tam: tamanoLegible(archivo.size) }) }}</p>
                                                <p class="lgm-pdf-nombre">@{{ archivo.name }}</p>
                                            </div>
                                        </div>
                                        <p v-if="errorPdf" class="lgm-error" data-logistica="error-pdf"><span class="icon-information"></span>@{{ errorPdf }}</p>

                                        <p class="lgm-nota">@{{ textos.modal['pdf-ayuda'] }}</p>
                                    </div>

                                    <button v-if="! verObservacion && ! form.observacion" type="button" class="lgm-enlace lgm-enlace--inicio" data-logistica="agregar-observacion" @click="verObservacion = true">
                                        @{{ textos.modal['agregar-observacion'] }}
                                    </button>
                                    <div v-else>
                                        <label class="lgm-label">@{{ textos.modal.observacion }}</label>
                                        <textarea rows="2" maxlength="500" v-model="form.observacion" data-logistica="observacion" :class="clasesInput"></textarea>
                                    </div>
                                </div>
                            </template>

                            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 font-medium text-red-700 dark:bg-red-950 dark:text-red-300" data-logistica="error">@{{ error }}</p>
                        </template>

                        {{-- ===== Ver envio: guias, PDF y su historial ===== --}}
                        <template v-if="vistaModo === 'ver' || (ctx.guias.length && vistaModo === 'completar')">
                            <div data-logistica="guias">
                                <p class="mb-2 font-semibold text-gray-800 dark:text-white">@{{ textos.modal.guias }}</p>

                                <p v-if="! ctx.guias.length" class="text-gray-500 dark:text-gray-400">@{{ textos.modal['sin-guias'] }}</p>

                                <p v-if="vistaModo === 'ver' && ctx.unidades.despachadas > 0" class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                    @{{ t(textos.modal['entregas-resumen'], { entregadas: ctx.unidades.entregadas, despachadas: ctx.unidades.despachadas }) }}
                                </p>

                                <div v-for="g in ctx.guias" :key="g.id" class="mb-3 grid gap-1.5 rounded-lg border p-3 dark:border-gray-800" :data-guia="g.id">
                                    <p class="font-bold text-gray-800 dark:text-white">@{{ g.transportadora }} &middot; @{{ g.numero_guia }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">@{{ nombres.origenes[g.origen_guia] }} &middot; @{{ t(textos.modal.registrada, { nombre: g.registrada_por, fecha: fecha(g.registrada_en) }) }}</p>

                                    <p v-if="g.tracking" class="text-xs">
                                        @{{ textos.modal['tracking-l'] }}:
                                        <a v-if="g.tracking_es_url" :href="g.tracking" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">@{{ g.tracking }}</a>
                                        <span v-else>@{{ g.tracking }}</span>
                                    </p>

                                    <p class="text-xs">
                                        @{{ textos.modal['flete-l'] }}: <span v-if="g.valor_flete !== null">@{{ precio(g.valor_flete) }}</span><span v-else>—</span>
                                        (@{{ nombres.pagadores[g.flete_pagador] }})
                                    </p>

                                    <p v-if="g.recaudo_esperado > 0" class="text-xs">
                                        @{{ textos.modal['recaudo-l'] }}: @{{ precio(g.recaudo_esperado) }} &middot; @{{ nombres.recaudo[g.recaudo_estado] }}
                                    </p>

                                    <p v-if="g.observacion" class="text-xs text-gray-600 dark:text-gray-300">@{{ g.observacion }}</p>

                                    {{-- PDF: documento asociado a la guia (aparte del numero) --}}
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <template v-if="g.tiene_pdf && ctx.permisos.ver_guia">
                                            <a :href="urlArchivoVer.replace('__ID__', g.id)" target="_blank" rel="noopener noreferrer" class="secondary-button" data-logistica="ver-pdf">@{{ textos.modal['ver-pdf'] }}</a>
                                            <a :href="urlArchivoDescargar.replace('__ID__', g.id)" class="secondary-button" data-logistica="descargar-pdf">@{{ textos.modal['descargar-pdf'] }}</a>
                                        </template>

                                        <span v-else-if="! g.tiene_pdf" class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.modal['sin-pdf'] }}</span>

                                        <button
                                            v-if="ctx.permisos.archivo && reemplazo.guiaId !== g.id"
                                            type="button" class="text-sm font-medium text-blue-600 hover:underline"
                                            data-logistica="abrir-reemplazo" @click="abrirReemplazo(g)"
                                        >
                                            @{{ g.tiene_pdf ? textos.modal['reemplazar-pdf'] : textos.modal['adjuntar-pdf'] }}
                                        </button>
                                    </div>

                                    <div v-if="reemplazo.guiaId === g.id" class="mt-1 grid gap-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-950" data-logistica="reemplazo">
                                        <input type="file" accept="application/pdf,.pdf" data-logistica="reemplazo-archivo" class="text-sm" @change="alElegirReemplazo">
                                        <input v-if="g.tiene_pdf" type="text" maxlength="500" v-model="reemplazo.motivo" data-logistica="reemplazo-motivo" :placeholder="textos.modal['motivo-reemplazo']" :class="clasesInput">
                                        <p v-if="reemplazo.error" class="text-xs font-medium text-red-600 dark:text-red-400">@{{ reemplazo.error }}</p>

                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="transparent-button" @click="reemplazo.guiaId = null">@{{ textos.modal.cancelar }}</button>
                                            <button type="button" class="primary-button" :disabled="reemplazo.enviando || ! reemplazo.archivo" data-logistica="guardar-reemplazo" @click="guardarReemplazo(g)">
                                                @{{ reemplazo.enviando ? textos.modal.guardando : textos.modal['guardar-pdf'] }}
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Corregir guia: accion explicita y auditada; no toca el despacho, el inventario ni Cartera. --}}
                                    <button
                                        v-if="ctx.permisos.corregir && correccion.guiaId !== g.id"
                                        type="button" class="justify-self-start text-sm font-medium text-blue-600 hover:underline"
                                        data-logistica="abrir-correccion" @click="abrirCorreccion(g)"
                                    >
                                        @{{ textos.modal.corregir }}
                                    </button>

                                    <div v-if="correccion.guiaId === g.id" class="mt-1 grid gap-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-950" data-logistica="correccion">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.modal['corregir-aviso'] }}</p>

                                        <div class="grid grid-cols-2 gap-2 max-sm:grid-cols-1">
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">@{{ textos.modal['corregir-transportadora'] }}</label>
                                                <select v-model="correccion.transportadora" data-logistica="correccion-transportadora" :class="clasesInput">
                                                    <option v-if="! ctx.transportadoras.some(t => t.codigo === g.transportadora_codigo)" :value="g.transportadora_codigo">@{{ g.transportadora }}</option>
                                                    <option v-for="t in transportadorasCorregibles(g)" :key="t.codigo" :value="t.codigo">@{{ t.nombre }}</option>
                                                </select>
                                                <p class="lgm-nota">@{{ textos.modal['corregir-solo-mismo-tipo'] }}</p>
                                            </div>

                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">@{{ textos.modal['corregir-numero'] }}</label>
                                                <input type="text" maxlength="100" autocomplete="off" v-model="correccion.numero" data-logistica="correccion-numero" :disabled="g.origen_guia === 'interna'" :class="clasesInput">
                                                <p v-if="g.origen_guia === 'interna'" class="lgm-nota" data-logistica="correccion-numero-interna">@{{ textos.modal['corregir-numero-interna'] }}</p>
                                            </div>
                                        </div>

                                        <input type="text" maxlength="500" v-model="correccion.motivo" data-logistica="correccion-motivo" :placeholder="textos.modal['corregir-motivo']" :class="clasesInput">
                                        <p v-if="correccion.error" class="text-xs font-medium text-red-600 dark:text-red-400" data-logistica="correccion-error">@{{ correccion.error }}</p>

                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="transparent-button" @click="correccion.guiaId = null">@{{ textos.modal.cancelar }}</button>
                                            <button type="button" class="primary-button" :disabled="correccion.enviando || ! correccion.motivo.trim()" data-logistica="guardar-correccion" @click="guardarCorreccion(g)">
                                                @{{ correccion.enviando ? textos.modal.guardando : textos.modal['corregir-guardar'] }}
                                            </button>
                                        </div>
                                    </div>

                                    <details v-if="g.correcciones && g.correcciones.length" class="text-xs text-gray-600 dark:text-gray-300" data-logistica="historial-correcciones">
                                        <summary class="cursor-pointer">@{{ textos.modal.correcciones }} (@{{ g.correcciones.length }})</summary>
                                        <ul class="mt-1 grid gap-1">
                                            <li v-for="(c, i) in g.correcciones" :key="i">
                                                @{{ t(textos.modal['correccion-item'], { anterior: c.numero_anterior, nuevo: c.numero_nuevo }) }}
                                                <span v-if="c.transportadora_anterior !== c.transportadora_nueva"> &middot; @{{ c.transportadora_anterior }} → @{{ c.transportadora_nueva }}</span>
                                                &middot; @{{ c.motivo }} &middot; @{{ t(textos.modal['correccion-por'], { nombre: c.actor_name_snapshot, fecha: fecha(c.created_at) }) }}
                                            </li>
                                        </ul>
                                    </details>

                                    <details v-if="g.versiones && g.versiones.length > 1" class="text-xs text-gray-600 dark:text-gray-300">
                                        <summary class="cursor-pointer">@{{ textos.modal.historial }} (@{{ g.versiones.length }})</summary>
                                        <ul class="mt-1 grid gap-1">
                                            <li v-for="v in g.versiones" :key="v.version">
                                                @{{ t(textos.modal.version, { n: v.version }) }} &middot; @{{ v.vigente ? textos.modal.vigente : textos.modal.reemplazada }}
                                                &middot; @{{ v.subido_por_name_snapshot }} &middot; @{{ fecha(v.created_at) }}
                                                <span v-if="v.motivo_reemplazo"> &middot; @{{ v.motivo_reemplazo }}</span>
                                            </li>
                                        </ul>
                                    </details>
                                </div>
                            </div>
                        </template>

                        <p v-if="vistaModo === 'ver' && ! ctx.unidades.pendientes_total && ! ctx.guias.length" class="text-gray-500">@{{ textos.modal['sin-pendientes'] }}</p>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div v-if="ctx && vistaModo !== 'ver'" class="lgm-pie">
                        {{-- Que esta listo y que falta: refleja las MISMAS condiciones que ya usa la modal (no es otra validacion). --}}
                        <ul v-if="vistaModo !== 'entregar_bodega'" class="lgm-listo" data-logistica="preparacion">
                            <li v-for="p in preparacion" :key="p.clave" :class="'lgm-listo-' + p.estado" :data-prep="p.clave" :data-estado="p.estado">
                                <span :class="p.estado === 'ok' ? 'icon-tick' : (p.estado === 'falta' ? 'icon-information' : 'icon-dot')"></span>@{{ p.texto }}
                            </li>
                        </ul>

                        <div class="lgm-pie-botones">
                            <button type="button" class="transparent-button" :disabled="enviando" @click="cerrar">@{{ textos.modal.cancelar }}</button>

                            <button type="button" class="primary-button" :disabled="enviando || ! totalSeleccionado || bloqueoPago || bloqueoDisposicion" data-logistica="guardar" @click="enviar">
                                @{{ enviando ? textos.modal.guardando : textos.modal['guardar-' + vistaModo] }}
                            </button>
                        </div>
                    </div>

                    <div v-else-if="ctx" class="flex justify-end border-t p-3 dark:border-gray-800 max-sm:px-4">
                        <button type="button" class="transparent-button" @click="cerrar">@{{ textos.modal.cerrar }}</button>
                    </div>
                </x-slot:footer>
            </x-admin::drawer>
        </div>
    </script>

    <script type="module">
        const LIMITE_PDF = 5 * 1024 * 1024;

        const guardarPreferencia = (clave, valor) => { try { window.localStorage.setItem('logistica.' + clave, valor || ''); } catch (e) {} };
        const olvidarPreferencia = (clave) => { try { window.localStorage.removeItem('logistica.' + clave); } catch (e) {} };
        const leerPreferencia = (clave) => { try { return window.localStorage.getItem('logistica.' + clave) || ''; } catch (e) { return ''; } };

        app.component('v-logistica-despacho', {
            template: '#v-logistica-despacho-template',

            props: ['urlContexto', 'urlDespachar', 'urlBodega', 'urlDisposicion', 'urlCancelarPendiente', 'urlArchivoVer', 'urlArchivoDescargar', 'urlArchivoGuardar', 'urlCorregir', 'urlStockSolicitar', 'urlStockDirecta', 'urlIncidencia', 'urlIncidenciaRegularizar', 'urlIncidenciaRechazar', 'textos', 'nombres'],

            data() {
                return {
                    pedidoId: null,
                    forzarVer: false,
                    cargando: false,
                    errorCarga: null,
                    ctx: null,
                    enviando: false,
                    error: null,
                    ajustar: false,
                    cantidades: {},
                    archivo: null,
                    errorPdf: null,
                    pdfFirma: null,
                    // Errores por CAMPO (presentacion de los mismos errores de siempre: cliente en validarEnvioCampo, servidor por `codigo`).
                    errores: {},
                    clave: null,
                    // F1: la operaria pidio cambiar el pagador deducido (excepcion con motivo).
                    cambiarPagador: false,
                    // Excepciones que se abren solo a pedido: costo adicional, corregir el cobro, observacion.
                    ajustarCosto: false,
                    // C1: recaudo propuesto por el sistema; solo se escribe a mano como excepcion (con motivo).
                    ajustarRecaudo: false,
                    corregirCobro: false,
                    verObservacion: false,
                    // La operaria eligio la transportadora a mano: un cambio de zona ya no la reemplaza por la sugerida.
                    transportadoraElegida: false,
                    form: this.formVacio(),
                    reemplazo: { guiaId: null, archivo: null, motivo: '', enviando: false, error: null },
                    correccion: this.correccionVacia(),
                    stockForm: this.stockVacio(),
                    // F6: decision de cobro (esperar / cobrar al entregar / credito). Esperar no se envia: es no despachar todavia.
                    dispo: { tipo: 'esperar', motivo: '', enviando: false, error: null },
                    canc: { motivo: '', enviando: false, error: null, confirmar: false },
                    clasesInput: 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300',
                };
            },

            computed: {
                // ---- Domicilios locales F1: todo se DEDUCE del contexto del servidor, que vuelve a validarlo al despachar ----
                // Cabecera del drawer: #pedido · unidades a despachar · destino (solo lo esencial).
                cabecera() {
                    if (! this.ctx) {
                        return '';
                    }

                    const partes = ['#' + this.ctx.pedido.numero];

                    if (this.vistaModo !== 'ver' && this.totalSeleccionado > 0) {
                        partes.push(this.totalSeleccionado === 1 ? this.textos.modal['cab-unidad'] : this.t(this.textos.modal['cab-unidades'], { n: this.totalSeleccionado }));
                    }

                    if (this.ctx.pedido.ciudad) {
                        partes.push(this.ctx.pedido.ciudad);
                    }

                    return partes.join(' · ');
                },

                // Que esta listo y que falta ANTES de despachar. No decide nada: refleja las condiciones que ya tiene la modal (unidades,
                // transportadora y numero de guia, que el servidor exige, y validarEnvioCampo, que es la validacion del cliente).
                preparacion() {
                    if (! this.ctx) {
                        return [];
                    }

                    const m = this.textos.modal;
                    const lista = [];
                    const hay = (clave, ok, bien, mal) => lista.push({ clave: clave, estado: ok ? 'ok' : 'falta', texto: ok ? bien : mal });

                    hay('unidades', this.totalSeleccionado > 0, this.t(m['prep-unidades'], { n: this.totalSeleccionado }), m['prep-sin-unidades']);
                    hay('transportadora', !! this.form.transportadora, m['prep-transportadora'], m['prep-falta-transportadora']);
                    if (this.guiaInterna) {
                        lista.push({ clave: 'guia', estado: 'ok', texto: m['prep-guia-auto'] });
                    } else {
                        hay('guia', String(this.form.numero_guia || '').trim() !== '', m['prep-guia'], m['prep-falta-guia']);
                    }

                    if (! this.guiaInterna) {
                        lista.push(this.archivo && this.pdfFirma === 'ok'
                            ? { clave: 'pdf', estado: 'ok', texto: m['prep-pdf'] }
                            : { clave: 'pdf', estado: 'neutro', texto: m['prep-sin-pdf'] });
                    }

                    const pendiente = this.validarEnvioCampo();

                    lista.push(pendiente ? { clave: 'envio', estado: 'falta', texto: pendiente.mensaje } : { clave: 'envio', estado: 'ok', texto: m['prep-envio'] });

                    return lista;
                },

                // Resumen para BODEGA: lo que se va a despachar agrupado por producto y talla (solo muestra; las cantidades siguen siendo `cantidades`).
                productosResumen() {
                    const grupos = {};

                    this.ctx.unidades.lineas.forEach(l => {
                        const clave = l.producto_id || l.nombre;

                        grupos[clave] = grupos[clave] || { clave: clave, nombre: l.nombre, sku: l.sku, filas: [] };
                        grupos[clave].filas.push({ id: l.id, talla: l.talla || '', cantidad: this.cantidadDe(l) });
                    });

                    return Object.values(grupos);
                },

                transportadoraSel() {
                    return this.ctx ? (this.ctx.transportadoras.find(t => t.codigo === this.form.transportadora) || null) : null;
                },

                // La guia la generara ByLopez (F2): sin origen, tracking ni PDF externo. Capacidad, nunca nombre.
                guiaInterna() {
                    return !! (this.transportadoraSel && this.transportadoraSel.genera_guia_interna);
                },

                // Pago EN VALIDACION que cambia lo que cobra quien entrega (lo decide el servidor): no se despacha hasta que Cartera
                // decida. Sin recaudo es solo aviso; el saldo pendiente nunca bloquea.
                bloqueoPago() {
                    return !! (this.ctx && this.ctx.envio && this.ctx.envio.pago_pendiente && this.ctx.envio.pago_pendiente.bloquea);
                },

                // F6: situacion de la decision de cobro (null en solo lectura o sin Cartera).
                cancelacion() {
                    return (this.ctx && this.ctx.cancelacion) || null;
                },

                disposicion() {
                    return (this.ctx && this.ctx.disposicion_cobro) || null;
                },

                // F6: falta la decision (o quedo vieja porque el pago cambio): no se despacha. El servidor lo exige igual.
                bloqueoDisposicion() {
                    return !! (this.disposicion && this.disposicion.requiere && ! this.disposicion.resuelta && this.vistaModo !== 'entregar_bodega');
                },

                // Dinero CONFIRMADO por Cartera (verificado). Valor del servidor; aqui no se calcula nada.
                pagoConfirmado() {
                    const p = this.ctx && this.ctx.envio ? this.ctx.envio.pago_pendiente : null;

                    return p && p.verificado ? Number(p.verificado) : 0;
                },

                // pagado | pendiente | en_validacion | null (fuera de Cartera).
                estadoPago() {
                    return (this.ctx && this.ctx.envio && this.ctx.envio.pago_pendiente && this.ctx.envio.pago_pendiente.estado) || null;
                },

                // El selector de pagador solo aparece si no se pudo deducir o si la operaria pidio cambiarlo.
                mostrarPagador() {
                    return !! this.ctx && (! this.ctx.envio.pagador_deducido || this.cambiarPagador);
                },

                pagadorCambiado() {
                    return !! this.ctx && (! this.ctx.envio.pagador_deducido || this.form.flete_pagador !== this.ctx.envio.pagador_deducido);
                },

                zonaId() {
                    if (! this.ctx) {
                        return null;
                    }

                    if (this.ctx.envio.zona_auto) {
                        return this.ctx.envio.zona_auto.id;
                    }

                    return this.form.zona_id ? parseInt(this.form.zona_id, 10) : null;
                },

                tarifaSugerida() {
                    if (! this.ctx || ! this.form.transportadora || this.zonaId === null) {
                        return null;
                    }

                    const porZona = this.ctx.envio.tarifas[this.form.transportadora] || {};
                    const valor = porZona[this.zonaId];

                    return valor === undefined ? null : Number(valor);
                },

                // Con tarifa: sugerida + adicional. Sin tarifa: lo que escriba la operaria. null = sin costo escrito.
                costoFinal() {
                    if (this.tarifaSugerida !== null) {
                        const adicional = parseFloat(this.form.costo_adicional);

                        return Math.round((this.tarifaSugerida + (Number.isFinite(adicional) ? adicional : 0)) * 100) / 100;
                    }

                    const valor = parseFloat(this.form.valor_flete);

                    return Number.isFinite(valor) ? valor : null;
                },

                difiereTarifa() {
                    return this.tarifaSugerida !== null && this.costoFinal !== null && Math.abs(this.costoFinal - this.tarifaSugerida) > 0.001;
                },

                // El adicional se muestra solo a pedido, o si ya se aparta de la tarifa (para no esconder un motivo pendiente).
                mostrarAjusteCosto() {
                    return this.ajustarCosto || this.difiereTarifa;
                },

                // Domiciliario sugerido (lo calcula el servidor): 1) el elegido por el asesor en la venta; 2) el ultimo domiciliario
                // REALMENTE usado en la zona actual (automatica o elegida a mano); 3) ninguno. Solo si el metodo admite domicilio local.
                sugeridoZona() {
                    if (! this.ctx || ! this.ctx.envio.admite_domicilio_local) {
                        return null;
                    }

                    const existe = (c) => !! c && this.ctx.transportadoras.some(t => t.codigo === c);

                    if (existe(this.ctx.envio.elegido_venta)) {
                        return this.ctx.envio.elegido_venta;
                    }

                    const codigo = this.zonaId === null ? null : (this.ctx.envio.ultimo_por_zona || {})[this.zonaId];

                    return existe(codigo) ? codigo : null;
                },

                esSugerido() {
                    return !! this.form.transportadora && this.form.transportadora === this.sugeridoZona;
                },

                // La preseleccion viene de la venta (asesor) y no del historial.
                esElegidoVenta() {
                    return this.esSugerido && this.form.transportadora === (this.ctx && this.ctx.envio.elegido_venta);
                },

                zonaNombre() {
                    const zona = this.ctx ? this.ctx.envio.zonas.find(z => z.id === this.zonaId) : null;

                    return zona ? zona.nombre : '';
                },

                // ---- Instruccion de cobro: SOLO recaudo (contra entrega) y domicilio por pagar. Nunca el saldo de Cartera. ----
                admiteRecaudo() {
                    return !! this.ctx && (this.ctx.pedido.es_contraentrega || this.ctx.recaudo_sugerido > 0);
                },

                // C1: recaudo que el sistema propone para ESTA guia con las unidades elegidas (misma regla que el servidor, que recalcula al
                // despachar): despacho unico = saldo exigible; parcial = valor historico de sus unidades (+ domicilio si es la primera guia),
                // sin pasar de lo que aun no comprometen las otras guias.
                recaudoSugeridoGuia() {
                    const r = this.ctx ? this.ctx.recaudo_guia : null;

                    if (! r || ! r.contra_entrega) {
                        return 0;
                    }

                    const lineas = this.ctx.unidades.lineas;
                    const completa = lineas.every(l => this.cantidadDe(l) === l.pendientes);
                    const mercancia = lineas.reduce((s, l) => s + Math.round((r.unitarios[l.id] || 0) * this.cantidadDe(l) * 100) / 100, 0);
                    const bruto = (! r.hay_previas && completa) ? r.exigible : mercancia + r.flete;

                    return Math.round(Math.max(0, Math.min(r.disponible, bruto)) * 100) / 100;
                },

                // Valor a cobrar de esta guia: el propuesto, o el escrito como excepcion.
                recaudoGuia() {
                    if (! this.ajustarRecaudo) {
                        return this.recaudoSugeridoGuia;
                    }

                    const valor = parseFloat(this.form.recaudo_esperado);

                    return Number.isFinite(valor) ? valor : null;
                },

                difiereRecaudo() {
                    return this.ajustarRecaudo && this.recaudoGuia !== null && Math.abs(this.recaudoGuia - this.recaudoSugeridoGuia) > 0.01;
                },

                // Despacho parcial: aclara que esta guia no cobra todo el saldo del pedido.
                recaudoGuiaNota() {
                    const r = this.ctx ? this.ctx.recaudo_guia : null;

                    if (! r || ! r.contra_entrega || r.exigible <= 0 || this.recaudoGuia === null || Math.abs(this.recaudoGuia - r.exigible) < 0.01) {
                        return '';
                    }

                    return this.t(this.textos.modal['recaudo-parcial-nota'], { guia: this.precio(this.recaudoGuia), saldo: this.precio(r.exigible) });
                },

                cobroPedido() {
                    const valor = this.recaudoGuia;

                    return this.admiteRecaudo && valor !== null && valor > 0 ? valor : 0;
                },

                cobroDomicilio() {
                    return this.ctx && this.ctx.envio.condicion === 'por_pagar' && this.costoFinal > 0 ? this.costoFinal : 0;
                },

                // Titulo del metodo de pago tal como esta configurado, partido SOLO para leerlo: «Nombre(MEDIO1,MEDIO2)» → nombre + medios.
                // No se renombra ni se interpreta (si el titulo incluye efectivo, se dice): el codigo y las reglas de cobro no cambian.
                pagoPartido() {
                    const titulo = String((this.ctx && this.ctx.pedido && this.ctx.pedido.tipo_pago) || '').trim();
                    const m = /^(.*?)\s*\((.+)\)\s*$/.exec(titulo);

                    if (! m) {
                        return { nombre: titulo, medios: '' };
                    }

                    const medios = m[2].split(',').map(x => x.trim()).filter(Boolean)
                        .map(x => x.charAt(0).toUpperCase() + x.slice(1).toLowerCase()).join(', ');

                    return { nombre: m[1].trim() || titulo, medios };
                },

                // Quien paga el servicio a la transportadora (no cambia) + si el cliente YA lo pago: solo se afirma con el pedido pagado;
                // con el pago pendiente o en validacion solo se dice que el flete esta incluido en el total del pedido.
                textoAsume() {
                    const c = this.ctx ? this.ctx.envio.condicion : null;

                    if (c === 'cobrado_en_pedido' && this.estadoPago !== 'pagado') {
                        return this.textos.modal['asume-incluido'];
                    }

                    return c ? this.textos.modal.asume[c] : '';
                },

                // Cobertura del destino segun el servidor (municipio reconocido o no): local | fuera_cobertura | no_identificado | null (sin dato).
                destinoCobertura() {
                    return (this.ctx && this.ctx.envio && this.ctx.envio.destino_cobertura) || null;
                },

                // Despacho parcial: saldo del pedido, lo ya comprometido en otras guias y lo que cobra ESTA. Todo viene del servidor
                // (`recaudo_guia`) o del mismo calculo que ya propone el cobro; aqui no se resta nada nuevo.
                repartoRecaudo() {
                    const r = this.ctx ? this.ctx.recaudo_guia : null;

                    if (! r || ! r.contra_entrega || ! this.admiteRecaudo || ! (r.hay_previas || r.comprometido > 0.001 || this.recaudoGuiaNota)) {
                        return null;
                    }

                    return { saldo: r.exigible, comprometido: r.comprometido, esta: this.cobroPedido };
                },

                // Transportadora con cuenta corriente: su costo real es obligatorio (lo pide tambien el servidor).
                exigeCosto() {
                    return !! (this.transportadoraSel && this.transportadoraSel.liquida_cuenta_corriente);
                },

                vistaModo() {
                    if (! this.ctx) {
                        return 'ver';
                    }

                    return this.forzarVer ? 'ver' : this.ctx.modo;
                },

                titulo() {
                    return this.textos.modal['titulo-' + this.vistaModo] || this.textos.modal['titulo-despachar'];
                },

                // Unidades a despachar: la suma de las cantidades (precargadas con el MAXIMO de cada linea), siempre acotadas.
                totalSeleccionado() {
                    if (! this.ctx) {
                        return 0;
                    }

                    return this.ctx.unidades.lineas.reduce((suma, l) => suma + this.cantidadDe(l), 0);
                },

                // Lo que quedara pendiente tras este envio (> 0 = despacho parcial).
                quedanPendientes() {
                    return this.ctx ? Math.max(0, this.ctx.unidades.pendientes_total - this.totalSeleccionado) : 0;
                },

                // Ninguna linea tiene stock: no hay nada que despachar (el boton queda bloqueado).
                sinStockTotal() {
                    return !! this.ctx && this.ctx.unidades.lineas.length > 0 && this.ctx.unidades.lineas.every(l => l.maximo === 0);
                },

                // Lineas del formulario de stock. Solicitud nueva / regularizacion directa: las lineas con faltante (clave = linea del pedido).
                // Revision de una solicitud abierta: las lineas de la incidencia (clave = linea de la incidencia), con el stock EN VIVO.
                stockFilas() {
                    if (! this.ctx || ! this.ctx.stock) {
                        return [];
                    }

                    if (this.stockForm.tipo === 'regularizar' && this.ctx.stock.abierta) {
                        const det = this.stockForm.detalle;

                        return (det ? det.lineas : this.ctx.stock.abierta.lineas).map(l => ({
                            clave: l.id, sku: l.sku, necesaria: det ? l.necesaria : '', sistema: '—',
                            vivo: det ? l.stock_vivo : null, declarado: l.conteo_declarado, revision: det ? l : null,
                        }));
                    }

                    return this.ctx.stock.lineas.map(l => ({ clave: l.id, sku: l.sku, necesaria: l.necesaria, sistema: l.stock_sistema, vivo: null, declarado: null, revision: null }));
                },

                stockListo() {
                    const f = this.stockForm;
                    const conteo = (v) => v !== undefined && v !== null && String(v).trim() !== '';

                    if (f.tipo === 'solicitar') {
                        return f.nota.trim() !== '' && this.stockFilas.some(l => conteo(f.conteos[l.clave]));
                    }

                    if (f.rechazando) {
                        return f.motivo.trim() !== '';
                    }

                    return f.motivo.trim() !== '' && f.confirma && this.stockFilas.length > 0 && this.stockFilas.every(l => conteo(f.conteos[l.clave]));
                },

                textoBotonStock() {
                    if (this.stockForm.tipo === 'solicitar') {
                        return this.textos.stock['enviar-solicitud'];
                    }

                    return this.stockForm.rechazando ? this.textos.stock['confirmar-rechazo'] : this.textos.stock.aplicar;
                },
            },

            mounted() {
                document.addEventListener('click', this.alClic);
            },

            beforeUnmount() {
                document.removeEventListener('click', this.alClic);
            },

            watch: {
                'form.transportadora'() { this.limpiarError('transportadora'); },
                'form.numero_guia'() { this.limpiarError('numero_guia'); },
                'form.flete_pagador'() { this.limpiarError('flete_pagador'); },
                'form.motivo_pagador'() { this.limpiarError('motivo_pagador'); },
                'form.valor_flete'() { this.limpiarError('valor_flete'); },
                'form.costo_adicional'() { this.limpiarError('costo_adicional'); },
                'form.motivo_costo'() { this.limpiarError('motivo_costo'); },
                'form.recaudo_esperado'() { this.limpiarError('recaudo_esperado'); },
                'form.motivo_recaudo'() { this.limpiarError('motivo_recaudo'); },
            },

            methods: {
                // Al cambiar transportadora o zona la tarifa sugerida se vuelve a aplicar (sin adicional).
                aplicarTarifa() {
                    this.form.costo_adicional = '';
                    this.form.motivo_costo = '';
                    this.ajustarCosto = false;

                    if (this.tarifaSugerida !== null) {
                        this.form.valor_flete = '';
                    }
                },

                // Elegir a mano reemplaza la sugerencia (y la deja fija aunque luego cambie la zona).
                elegirTransportadora() {
                    this.transportadoraElegida = true;
                    this.aplicarTarifa();
                },

                // Zona elegida a mano: si la operaria no eligio transportadora, se sugiere la ultima usada en esa zona (o ninguna).
                cambiarZona() {
                    if (! this.transportadoraElegida && this.ctx.envio.admite_domicilio_local) {
                        this.form.transportadora = this.sugeridoZona || '';
                    }

                    this.aplicarTarifa();
                },

                // Ayuda de interfaz: los mismos motivos que exige el servidor, antes de enviar.
                // Primera condicion del cliente que impide despachar: { campo, mensaje } o null. Es la misma validacion de siempre,
                // con el campo al que pertenece para mostrar el error junto a el.
                validarEnvioCampo() {
                    const m = this.textos.modal;

                    if (this.bloqueoPago) {
                        return { campo: 'pago', mensaje: m['pago-pendiente-titulo'] };
                    }

                    if (this.bloqueoDisposicion) {
                        return { campo: 'disposicion', mensaje: this.textos.disposicion.titulo };
                    }

                    if (! this.form.flete_pagador) {
                        return { campo: 'flete_pagador', mensaje: m['error-pagador-requerido'] };
                    }

                    if (this.pagadorCambiado && ! String(this.form.motivo_pagador || '').trim()) {
                        return { campo: 'motivo_pagador', mensaje: m['error-motivo-pagador'] };
                    }

                    if (this.exigeCosto && this.costoFinal === null) {
                        return { campo: this.tarifaSugerida !== null ? 'costo_adicional' : 'valor_flete', mensaje: m['error-costo-requerido'] };
                    }

                    if (this.difiereTarifa && ! String(this.form.motivo_costo || '').trim()) {
                        return { campo: 'motivo_costo', mensaje: m['error-motivo-costo'] };
                    }

                    if (this.ajustarRecaudo && (this.recaudoGuia === null || this.recaudoGuia < 0)) {
                        return { campo: 'recaudo_esperado', mensaje: m['error-recaudo-invalido'] };
                    }

                    if (this.ajustarRecaudo && this.ctx.recaudo_guia && this.recaudoGuia > this.ctx.recaudo_guia.disponible + 0.001) {
                        return { campo: 'recaudo_esperado', mensaje: this.t(m['error-recaudo-supera'], { maximo: this.precio(this.ctx.recaudo_guia.disponible) }) };
                    }

                    if (this.difiereRecaudo && ! String(this.form.motivo_recaudo || '').trim()) {
                        return { campo: 'motivo_recaudo', mensaje: m['error-motivo-recaudo'] };
                    }

                    return null;
                },

                validarEnvio() {
                    const e = this.validarEnvioCampo();

                    return e ? e.mensaje : null;
                },

                limpiarError(campo) {
                    if (this.errores && this.errores[campo]) {
                        const resto = Object.assign({}, this.errores);

                        delete resto[campo];
                        this.errores = resto;
                    }
                },

                // Lleva a la vista el campo con error (la modal es larga y el boton esta en el pie).
                irAlCampo(campo) {
                    this.$nextTick(() => {
                        const raiz = this.$el && this.$el.querySelector ? this.$el : null;
                        const destino = raiz ? raiz.querySelector('[data-logistica="' + String(campo).replace(/_/g, '-') + '"]') : null;

                        if (destino && destino.scrollIntoView) {
                            destino.scrollIntoView({ block: 'center' });
                        }
                    });
                },
                correccionVacia() {
                    return { guiaId: null, numero: '', transportadora: '', motivo: '', enviando: false, error: null };
                },

                stockVacio() {
                    return { abierto: false, tipo: null, conteos: {}, nota: '', motivo: '', confirma: false, rechazando: false, detalle: null, cargando: false, enviando: false, error: null, clave: null };
                },

                formVacio() {
                    return { transportadora: '', numero_guia: '', origen_guia: 'manual', flete_pagador: '', valor_flete: '', recaudo_esperado: '', motivo_recaudo: '', tracking: '', observacion: '', quien_retira: '', zona_id: '', costo_adicional: '', motivo_pagador: '', motivo_costo: '' };
                },

                t(texto, valores) {
                    return Object.keys(valores || {}).reduce((s, k) => s.split(':' + k).join(valores[k]), texto || '');
                },

                // Moneda de ByLopez («$ 8.500»), igual que el resto de pantallas. Solo presentacion.
                precio(valor) {
                    return window.ByLopezMoneda.precio(valor);
                },

                fecha(valor) {
                    return valor ? String(valor).replace('T', ' ').substring(0, 16) : '';
                },

                // Botones del servidor (filas de la bandeja y del listado de Pedidos): se atienden por delegacion.
                alClic(evento) {
                    const boton = evento.target.closest('[data-logistica-despacho]');

                    if (! boton) {
                        return;
                    }

                    evento.preventDefault();
                    this.abrir(parseInt(boton.dataset.pedido), boton.dataset.modo === 'ver');
                },

                abrir(pedidoId, forzarVer) {
                    this.pedidoId = pedidoId;
                    this.forzarVer = forzarVer;
                    this.ctx = null;
                    this.dispo = { tipo: 'esperar', motivo: '', enviando: false, error: null };
                    this.errorCarga = null;
                    this.error = null;
                    this.ajustar = false;
                    this.archivo = null;
                    this.errorPdf = null;
                    this.pdfFirma = null;
                    this.errores = {};
                    this.enviando = false;
                    this.cantidades = {};
                    this.form = this.formVacio();
                    this.cambiarPagador = false;
                    this.ajustarCosto = false;
                    this.ajustarRecaudo = false;
                    this.corregirCobro = false;
                    this.verObservacion = false;
                    this.transportadoraElegida = false;
                    this.reemplazo ={ guiaId: null, archivo: null, motivo: '', enviando: false, error: null };
                    this.correccion = this.correccionVacia();
                    this.stockForm = this.stockVacio();
                    this.clave = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'k' + Date.now() + Math.random().toString(36).slice(2);
                    this.cargando = true;
                    this.$refs.drawer.open();

                    this.cargarContexto();
                },

                cargarContexto() {
                    this.$axios.get(this.urlContexto.replace('__ID__', this.pedidoId))
                        .then(r => this.preparar(r.data.contexto))
                        .catch(e => { this.errorCarga = (e.response && e.response.data && e.response.data.mensaje) || this.textos.modal['error-carga']; })
                        .finally(() => { this.cargando = false; });
                },

                preparar(ctx) {
                    this.ctx = ctx;
                    this.cantidades = {};
                    // Precarga lo que realmente se puede enviar: min(pendiente, stock permitido por Bagisto). Nunca 0 si hay unidades.
                    ctx.unidades.lineas.forEach(l => { this.cantidades[l.id] = l.maximo; });

                    const codigos = ctx.transportadoras.map(t => t.codigo);
                    // La transportadora recordada en este navegador solo vale para envios NACIONALES. En domicilio local la sugerencia sale
                    // SOLO del historial real de la zona (servidor); sin zona o sin historial queda sin seleccionar.
                    const recordada = ctx.envio.admite_domicilio_local ? null : leerPreferencia('transportadora');

                    this.form.transportadora = [ctx.sugerencias.transportadora, recordada].find(c => c && codigos.includes(c)) || '';
                    this.transportadoraElegida = false;
                    this.form.origen_guia = leerPreferencia('origen') || 'manual';
                    // El pagador del flete NO se recuerda: arranca en el que DEDUCE el servidor de la condicion de envio (vacio si el metodo
                    // es desconocido), para que una excepcion anterior no contamine el siguiente pedido. Se borra lo que guardaban versiones previas.
                    olvidarPreferencia('pagador');
                    this.form.flete_pagador = ctx.sugerencias.flete_pagador || '';
                    this.cambiarPagador = false;
                    this.aplicarTarifa();
                    // C1: el recaudo se propone (recaudoSugeridoGuia); solo se escribe a mano como excepcion.
                    this.form.recaudo_esperado = '';
                    this.form.motivo_recaudo = '';
                    this.ajustarRecaudo = false;

                    // Caso normal en pocos segundos: el cursor queda en el numero de guia (o se lee con el lector).
                    this.$nextTick(() => { if (this.$refs.guia) { this.$refs.guia.focus(); } });
                },

                // ---- Stock insuficiente: solicitar (BODEGA) / regularizar o rechazar (Administrador) ----
                nuevaClave() {
                    return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'k' + Date.now() + Math.random().toString(36).slice(2);
                },

                abrirStock(tipo) {
                    this.stockForm = Object.assign(this.stockVacio(), { abierto: true, tipo: tipo, clave: this.nuevaClave() });

                    const abierta = this.ctx.stock.abierta;

                    if (tipo === 'regularizar' && abierta) {
                        // Revision: parte del conteo que declaro la bodega y trae el stock en vivo y el contexto para decidir.
                        abierta.lineas.forEach(l => { this.stockForm.conteos[l.id] = l.conteo_declarado; });
                        this.stockForm.cargando = true;

                        this.$axios.get(this.urlIncidencia.replace('__ID__', abierta.id))
                            .then(r => { this.stockForm.detalle = r.data.incidencia; })
                            .catch(e => { this.stockForm.error = this.mensajeDeError(e); })
                            .finally(() => { this.stockForm.cargando = false; });
                    }
                },

                cerrarStock() {
                    this.stockForm = this.stockVacio();
                },

                enviarStock() {
                    const f = this.stockForm;

                    if (f.enviando || ! this.stockListo) {
                        return;
                    }

                    const abierta = this.ctx.stock.abierta;
                    const conteos = {};

                    this.stockFilas.forEach(l => {
                        const v = f.conteos[l.clave];

                        if (v !== undefined && v !== null && String(v).trim() !== '') {
                            conteos[l.clave] = v;
                        }
                    });

                    let url;
                    let cuerpo;

                    if (f.tipo === 'solicitar') {
                        url = this.urlStockSolicitar.replace('__ID__', this.pedidoId);
                        cuerpo = { idempotency_key: f.clave, conteos: conteos, nota: f.nota };
                    } else if (f.rechazando) {
                        url = this.urlIncidenciaRechazar.replace('__ID__', abierta.id);
                        cuerpo = { motivo: f.motivo };
                    } else if (abierta) {
                        url = this.urlIncidenciaRegularizar.replace('__ID__', abierta.id);
                        cuerpo = { conteos: conteos, motivo: f.motivo, confirma_conteo_fisico: f.confirma ? 1 : 0 };
                    } else {
                        url = this.urlStockDirecta.replace('__ID__', this.pedidoId);
                        cuerpo = { idempotency_key: f.clave, conteos: conteos, motivo: f.motivo, confirma_conteo_fisico: f.confirma ? 1 : 0 };
                    }

                    f.enviando = true;
                    f.error = null;

                    this.$axios.post(url, cuerpo)
                        .then(r => {
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.stockForm = this.stockVacio();
                            this.recargarContexto();
                            this.refrescar(this.pedidoId, false);
                        })
                        .catch(e => {
                            f.error = this.mensajeDeError(e);
                            f.enviando = false;
                        });
                },

                // F6: registra «cobrar al entregar» o «credito autorizado». El servidor valida permiso, motivo y vigencia; al volver se
                // recarga el contexto (el recaudo propuesto cambia con la decision).
                guardarDisposicion() {
                    const d = this.dispo;

                    if (d.tipo === 'esperar' || d.enviando) {
                        return;
                    }

                    d.enviando = true;
                    d.error = null;

                    this.$axios.post(this.urlDisposicion.replace('__ID__', this.pedidoId), { tipo: d.tipo, motivo: d.motivo })
                        .then(r => {
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.dispo = { tipo: 'esperar', motivo: '', enviando: false, error: null };
                            this.recargarContexto();
                        })
                        .catch(e => {
                            d.error = this.mensajeDeError(e);
                            d.enviando = false;
                        });
                },

                cancelarPendiente() {
                    const c = this.canc;

                    if (c.enviando) {
                        return;
                    }

                    c.enviando = true;
                    c.error = null;

                    this.$axios.post(this.urlCancelarPendiente.replace('__ID__', this.pedidoId), { motivo: c.motivo })
                        .then(r => {
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.canc = { motivo: '', enviando: false, error: null, confirmar: false };
                            this.recargarContexto();
                        })
                        .catch(e => {
                            c.error = this.mensajeDeError(e);
                            c.enviando = false;
                        });
                },

                // Tras regularizar: recalcula maximos y faltante SIN perder lo ya escrito (guia, flete, observacion...).
                recargarContexto() {
                    this.$axios.get(this.urlContexto.replace('__ID__', this.pedidoId))
                        .then(r => {
                            this.ctx = r.data.contexto;
                            this.cantidades = {};
                            this.ctx.unidades.lineas.forEach(l => { this.cantidades[l.id] = l.maximo; });
                        })
                        .catch(e => { this.error = this.mensajeDeError(e); });
                },

                // Cantidad valida de una linea: entera y dentro de [0, maximo]. El servidor la vuelve a validar siempre.
                cantidadDe(l) {
                    const n = parseInt(this.cantidades[l.id], 10);

                    return Number.isFinite(n) ? Math.min(Math.max(n, 0), l.maximo) : 0;
                },

                clamparCantidad(l) {
                    this.cantidades[l.id] = this.cantidadDe(l);
                },

                abrirAjusteRecaudo() {
                    this.form.recaudo_esperado = String(this.recaudoSugeridoGuia);
                    this.form.motivo_recaudo = '';
                    this.ajustarRecaudo = true;
                },

                cerrarAjusteRecaudo() {
                    this.ajustarRecaudo = false;
                    this.form.recaudo_esperado = '';
                    this.form.motivo_recaudo = '';
                    this.errores = {};
                },

                todoPendiente() {
                    this.ctx.unidades.lineas.forEach(l => { this.cantidades[l.id] = l.maximo; });
                },

                // Validacion previa del PDF, con la razon exacta (el servidor la repite por CONTENIDO: firma, tipo y tamano).
                motivoPdfInvalido(archivo) {
                    const limite = this.limiteServidorBytes();

                    if (archivo.type !== 'application/pdf' && archivo.type !== 'application/x-pdf' && ! /\.pdf$/i.test(archivo.name)) {
                        return this.textos.modal['error-pdf-tipo'];
                    }

                    if (archivo.size > LIMITE_PDF) {
                        return this.textos.modal['error-pdf-grande'];
                    }

                    if (limite !== null && archivo.size > limite) {
                        return this.t(this.textos.modal['error-limite-servidor'], { limite: this.ctx.limite_subida.archivo });
                    }

                    return null;
                },

                // `upload_max_filesize` llega como texto de php.ini («2M», «512K», «1G»); null si no se conoce.
                limiteServidorBytes() {
                    const texto = this.ctx && this.ctx.limite_subida ? String(this.ctx.limite_subida.archivo || '').trim() : '';
                    const m = /^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i.exec(texto);

                    if (! m) {
                        return null;
                    }

                    return Math.round(parseFloat(m[1]) * ({ '': 1, K: 1024, M: 1024 * 1024, G: 1024 * 1024 * 1024 }[m[2].toUpperCase()]));
                },

                tamanoLegible(bytes) {
                    return bytes >= 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
                },

                alElegirPdf(evento) {
                    const archivo = evento.target.files[0] || null;

                    this.error = null;
                    this.errorPdf = null;
                    this.archivo = null;

                    if (! archivo) {
                        return;
                    }

                    this.errorPdf = this.motivoPdfInvalido(archivo);

                    if (this.errorPdf) {
                        evento.target.value = '';

                        return;
                    }

                    this.archivo = archivo;
                    this.comprobarFirmaPdf(archivo, evento.target);
                },

                // Misma regla de firma que el servidor (`%PDF-` al inicio, tolerando BOM/espacios/saltos de linea) para dar el motivo
                // antes de enviar. El servidor sigue siendo quien decide; si el navegador no puede leer el archivo, no bloquea.
                comprobarFirmaPdf(archivo, campo) {
                    this.pdfFirma = null;

                    if (typeof archivo.slice !== 'function' || typeof FileReader === 'undefined') {
                        this.pdfFirma = 'ok';

                        return;
                    }

                    const lector = new FileReader();

                    lector.onload = () => {
                        if (this.archivo !== archivo) {
                            return;
                        }

                        if (/^(?:\xEF\xBB\xBF)?[\t\n\r\f ]*%PDF-/.test(String(lector.result))) {
                            this.pdfFirma = 'ok';

                            return;
                        }

                        this.archivo = null;
                        this.errorPdf = this.textos.modal['error-pdf-invalido'];

                        if (campo) {
                            campo.value = '';
                        }
                    };

                    lector.onerror = () => { this.pdfFirma = 'ok'; };
                    lector.readAsBinaryString(archivo.slice(0, 1024));
                },

                quitarPdf() {
                    this.pdfFirma = null;
                    this.archivo = null;
                    this.errorPdf = null;

                    if (this.$refs.pdf) {
                        this.$refs.pdf.value = '';
                    }
                },

                // Siempre se mandan las cantidades explicitas, ya acotadas al maximo: sin tocar nada son «todo lo pendiente»; con stock
                // insuficiente, lo que haya (despacho parcial). El servidor las valida otra vez antes de crear el Shipment.
                unidadesAEnviar() {
                    const unidades = {};

                    this.ctx.unidades.lineas.forEach(l => { unidades[l.id] = this.cantidadDe(l); });

                    return unidades;
                },

                enviar() {
                    if (this.enviando) {
                        return;
                    }

                    if (this.vistaModo === 'entregar_bodega' && ! window.confirm(this.textos.modal['confirmar-bodega'])) {
                        return;
                    }

                    const falta = this.vistaModo === 'entregar_bodega' ? null : this.validarEnvioCampo();

                    this.error = falta ? falta.mensaje : null;
                    this.errores = falta ? { [falta.campo]: falta.mensaje } : {};

                    if (falta) {
                        this.irAlCampo(falta.campo);

                        return;
                    }

                    this.enviando = true;

                    const datos = new FormData();
                    const unidades = this.unidadesAEnviar();

                    datos.append('idempotency_key', this.clave);

                    Object.keys(unidades).forEach(id => datos.append('unidades[' + id + ']', unidades[id]));

                    if (this.vistaModo === 'entregar_bodega') {
                        datos.append('quien_retira', this.form.quien_retira || '');
                    } else {
                        (this.guiaInterna
                            ? ['transportadora', 'flete_pagador', 'observacion']
                            : ['transportadora', 'numero_guia', 'origen_guia', 'flete_pagador', 'tracking', 'observacion'])
                            .forEach(campo => datos.append(campo, this.form[campo] === null ? '' : this.form[campo]));

                        // F1: costo FINAL (sugerida + adicional, o el escrito), zona elegida a mano y motivos de las excepciones.
                        datos.append('valor_flete', this.costoFinal === null ? '' : this.costoFinal);
                        datos.append('zona_id', this.ctx.envio.zona_auto ? '' : (this.form.zona_id || ''));
                        datos.append('motivo_pagador', this.pagadorCambiado ? (this.form.motivo_pagador || '') : '');
                        datos.append('motivo_costo', this.difiereTarifa ? (this.form.motivo_costo || '') : '');

                        // C1: sin ajuste NO se manda recaudo: el servidor propone y registra el suyo (autoridad). Con ajuste va el valor y su motivo.
                        if (this.ajustarRecaudo) {
                            datos.append('recaudo_esperado', this.recaudoGuia === null ? '' : this.recaudoGuia);
                            datos.append('motivo_recaudo', this.difiereRecaudo ? (this.form.motivo_recaudo || '') : '');
                        }

                        if (this.archivo && ! this.guiaInterna) {
                            datos.append('archivo', this.archivo);
                        }
                    }

                    const url = (this.vistaModo === 'entregar_bodega' ? this.urlBodega : this.urlDespachar).replace('__ID__', this.pedidoId);

                    this.$axios.post(url, datos)
                        .then(r => this.alExito(r.data))
                        .catch(e => this.alFallo(e))
                        .finally(() => { this.enviando = false; });
                },

                alExito(datos) {
                    if (this.vistaModo !== 'entregar_bodega') {
                        guardarPreferencia('transportadora', this.form.transportadora);

                        if (! this.guiaInterna) {
                            guardarPreferencia('origen', this.form.origen_guia);
                        }
                    }

                    const pedido = this.pedidoId;

                    this.$refs.drawer.close();
                    this.$emitter.emit('add-flash', { type: 'success', message: datos.mensaje });
                    this.refrescar(pedido, true);
                },

                // Mensaje del servidor; si PHP/Laravel cortaron la peticion por tamano (413, sin nuestro JSON), el limite vigente.
                mensajeDeError(e) {
                    const respuesta = e.response || {};
                    const datos = respuesta.data || {};

                    if (datos.mensaje) {
                        return datos.mensaje;
                    }

                    if (respuesta.status === 413) {
                        return this.t(this.textos.modal['error-limite-servidor'], { limite: this.ctx && this.ctx.limite_subida ? this.ctx.limite_subida.archivo : '' });
                    }

                    return (datos.errors ? Object.values(datos.errors)[0][0] : null) || this.textos.modal['error-generico'];
                },

                // Campo al que pertenece cada rechazo del servidor (solo para mostrarlo junto al campo; las reglas siguen siendo del servidor).
                campoDeErrorServidor(codigo) {
                    return {
                        transportadora_requerida: ['transportadora'],
                        transportadora_invalida: ['transportadora'],
                        guia_requerida: ['numero_guia'],
                        guia_duplicada: ['numero_guia'],
                        pagador_flete_invalido: ['flete_pagador'],
                        flete_invalido: ['valor_flete', 'costo_adicional'],
                        flete_requerido: ['valor_flete', 'costo_adicional'],
                        recaudo_invalido: ['recaudo_esperado'],
                        recaudo_supera_saldo: ['recaudo_esperado'],
                        motivo_recaudo_requerido: ['motivo_recaudo'],
                    }[codigo] || [];
                },

                alFallo(e) {
                    const mensaje = this.mensajeDeError(e);
                    const codigo = ((e.response || {}).data || {}).codigo || '';

                    // Un rechazo del PDF se explica junto al campo (y se lleva a la vista), no solo al final de la modal.
                    if (codigo.indexOf('archivo_') === 0) {
                        this.errorPdf = mensaje;
                        this.error = null;
                        this.irAlCampo('pdf');

                        return;
                    }

                    const campos = this.campoDeErrorServidor(codigo);

                    this.errores = campos.reduce((acc, c) => Object.assign(acc, { [c]: mensaje }), {});
                    this.error = mensaje;

                    if (campos.length) {
                        this.irAlCampo(campos[0]);
                    }
                },
                cerrar() {
                    this.$refs.drawer.close();
                },

                alCerrar() {
                    this.reemplazo.guiaId = null;
                    this.correccion.guiaId = null;
                },

                // ---- Corregir guia: numero y/o transportadora, con motivo (auditado en el servidor) ----
                // Corregir no convierte el tipo de guia: se ofrecen las transportadoras con el mismo tipo (por el ORIGEN guardado en la guia) y la vigente.
                transportadorasCorregibles(guia) {
                    const interna = guia.origen_guia === 'interna';

                    return this.ctx.transportadoras.filter(t => t.codigo === guia.transportadora_codigo || !! t.genera_guia_interna === interna);
                },

                abrirCorreccion(guia) {
                    this.correccion = { guiaId: guia.id, numero: guia.numero_guia, transportadora: guia.transportadora_codigo, motivo: '', enviando: false, error: null };
                },

                guardarCorreccion(guia) {
                    if (this.correccion.enviando || ! this.correccion.motivo.trim()) {
                        return;
                    }

                    this.correccion.enviando = true;
                    this.correccion.error = null;

                    this.$axios.post(this.urlCorregir.replace('__ID__', guia.id), {
                        numero_guia: this.correccion.numero,
                        transportadora: this.correccion.transportadora,
                        motivo: this.correccion.motivo,
                    })
                        .then(r => {
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.correccion = this.correccionVacia();
                            this.cargarContexto();
                            this.refrescar(this.pedidoId, false);
                        })
                        .catch(e => {
                            this.correccion.error = this.mensajeDeError(e);
                            this.correccion.enviando = false;
                        });
                },

                // ---- Ver envio: adjuntar o reemplazar el PDF de una guia (auditado en el servidor) ----
                abrirReemplazo(guia) {
                    this.reemplazo = { guiaId: guia.id, archivo: null, motivo: '', enviando: false, error: null };
                },

                alElegirReemplazo(evento) {
                    const archivo = evento.target.files[0] || null;

                    this.reemplazo.error = null;
                    this.reemplazo.archivo = null;

                    const motivo = archivo ? this.motivoPdfInvalido(archivo) : null;

                    if (motivo) {
                        this.reemplazo.error = motivo;
                        evento.target.value = '';

                        return;
                    }

                    this.reemplazo.archivo = archivo;
                },

                guardarReemplazo(guia) {
                    if (this.reemplazo.enviando || ! this.reemplazo.archivo) {
                        return;
                    }

                    this.reemplazo.enviando = true;
                    this.reemplazo.error = null;

                    const datos = new FormData();

                    datos.append('archivo', this.reemplazo.archivo);
                    datos.append('motivo', this.reemplazo.motivo || '');

                    this.$axios.post(this.urlArchivoGuardar.replace('__ID__', guia.id), datos)
                        .then(r => {
                            this.$emitter.emit('add-flash', { type: 'success', message: r.data.mensaje });
                            this.reemplazo = { guiaId: null, archivo: null, motivo: '', enviando: false, error: null };
                            this.cargarContexto();
                            this.refrescar(this.pedidoId, false);
                        })
                        .catch(e => {
                            this.reemplazo.error = this.mensajeDeError(e);
                            this.reemplazo.enviando = false;
                        });
                },

                /*
                 * Reemplaza SOLO las filas del pedido (escritorio y tarjeta movil), los indicadores y las zonas marcadas
                 * `data-refrescable` (detalle del pedido) con el HTML fresco de la propia pantalla (mismos filtros y pagina).
                 * Si el pedido ya no cumple el filtro, la fila desaparece. `avanzar`: lleva la vista a la siguiente fila con
                 * accion, para seguir sin buscar.
                 */
                refrescar(pedidoId, avanzar) {
                    const selector = '[data-pedido="' + pedidoId + '"]';
                    const orden = Array.from(document.querySelectorAll('tr[data-pedido]')).map(f => f.dataset.pedido);

                    fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                        .then(r => r.text())
                        .then(html => {
                            const doc = new DOMParser().parseFromString(html, 'text/html');
                            // Zonas que la pantalla marca como refrescables (detalle del pedido: estados, productos y guias).
                            doc.querySelectorAll('[data-refrescable]').forEach(nuevo => {
                                const actual = document.querySelector('[data-refrescable="' + nuevo.dataset.refrescable + '"]');

                                if (actual) {
                                    actual.replaceWith(document.importNode(nuevo, true));
                                }
                            });

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

                            if (avanzar) {
                                this.irAlSiguiente(pedidoId, orden);
                            }
                        })
                        .catch(() => { /* la fila se actualizara al recargar; el despacho ya quedo registrado */ });
                },

                irAlSiguiente(pedidoId, orden) {
                    const despues = orden.slice(orden.indexOf(String(pedidoId)) + 1);

                    for (const id of despues) {
                        const boton = document.querySelector('tr[data-pedido="' + id + '"] [data-logistica-despacho]:not([data-modo="ver"])');

                        if (boton) {
                            boton.scrollIntoView({ block: 'center', behavior: 'smooth' });
                            boton.focus({ preventScroll: true });

                            return;
                        }
                    }
                },
            },
        });
    </script>
@endPushOnce
