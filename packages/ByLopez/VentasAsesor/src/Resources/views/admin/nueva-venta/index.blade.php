@php
    // Doc. 33 P3: la MISMA vista sirve a asesores y al cliente de autoservicio; solo cambia el juego de rutas.
    $nv = ($modoCliente ?? false) ? 'admin.autoservicio.' : 'admin.ventas_asesor.nueva_venta.';
    $rutaNv = fn (string $nombre, array $parametros = []) => \Illuminate\Support\Facades\Route::has($nv.$nombre) ? route($nv.$nombre, $parametros) : '#';
@endphp
<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.nueva-venta.title')
    </x-slot>

    @include('ventasasesor::admin.asesores.aviso-sin-asociacion')

    {{-- Formato monetario compartido (ByLopezMoneda.precio): «$ 8.500» siempre agrupado, igual que el resto de ByLopez. --}}
    @include('corteconfeccion::admin.partials.moneda')

    <v-nueva-venta></v-nueva-venta>

    {{-- Boton «Ver tallas»: entrada al activarse (una vez, ~0.5 s) y micro-pulso al cambiar la seleccion. Nunca infinito. --}}
    @pushOnce('styles')
        <style>
            /* Cotizacion: panel bajo «Confirmar venta». Tailwind no escanea ByLopez, por eso estas reglas son propias. */
            .nv-cot { margin-top: 12px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
            .nv-cot-fila { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
            .nv-cot-num { font-weight: 700; }
            .nv-cot-meta { margin-top: 2px; color: #6b7280; font-size: 12px; }
            .nv-cot-acciones { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
            .nv-cot-badge { display: inline-block; border-radius: 999px; padding: 1px 8px; font-size: 11px; font-weight: 700; background: #dcfce7; color: #166534; }
            .nv-cot-badge--vencida, .nv-cot-badge--reemplazada { background: #f3f4f6; color: #4b5563; }
            .nv-cot-badge--convertida { background: #dbeafe; color: #1e40af; }
            .nv-cot-error { margin-top: 6px; color: #b91c1c; font-size: 12px; }

            /* Doc. 34: dictar pedido. */
            .nv-dic-fila { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
            .nv-dic-ayuda { margin-top: 8px; color: #6b7280; font-size: 12px; }
            .nv-dic-texto { margin-top: 8px; width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 10px; font-size: 14px; background: transparent; }
            .nv-dic-acciones { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
            .nv-dic-error { margin-top: 8px; color: #b91c1c; font-size: 13px; }
            .nv-dic-lista { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
            .nv-dic-linea { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; font-size: 13px; }
            .nv-dic-linea--ok { border-color: #86efac; background: #f0fdf4; }
            .nv-dic-linea--aviso { border-color: #fcd34d; background: #fffbeb; }
            .nv-dic-linea--error { border-color: #fca5a5; background: #fef2f2; }
            .nv-dic-producto { flex: 1 1 200px; font-weight: 600; color: #111827; }
            .nv-dic-sku { color: #6b7280; font-weight: 400; font-size: 12px; }
            .nv-dic-dato { font-weight: 600; color: #111827; white-space: nowrap; }
            .nv-dic-dijo { margin-top: 2px; color: #6b7280; font-size: 12px; }
            .nv-dic-mensaje { margin-top: 2px; color: #92400e; font-size: 12px; }
            .nv-dic-linea--error .nv-dic-mensaje { color: #b91c1c; }
            .nv-dic-linea--omitida { border-color: #e5e7eb; background: #f9fafb; opacity: .7; }
            .nv-dic-acciones--linea { margin-top: 6px; }
            .nv-dic-acciones--linea button { padding: 4px 10px; font-size: 12px; }
            .nv-dic-editor { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #d1d5db; }
            .nv-dic-editor .nv-dic-acciones, .nv-dic-editor .nv-dic-error { grid-column: 1 / -1; margin-top: 0; }
            .nv-dic-campo { display: flex; flex-direction: column; gap: 2px; font-size: 12px; color: #4b5563; }
            .nv-dic-campo select, .nv-dic-campo input { border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 8px; font-size: 14px; background: #fff; color: #111827; min-height: 38px; }
            .nv-cot-aviso { margin-top: 6px; border-radius: 6px; background: #fffbeb; color: #92400e; padding: 4px 8px; font-size: 12px; }
            html.dark .nv-cot { border-color: #374151; }
            html.dark .nv-cot-meta { color: #9ca3af; }

            @keyframes nv-cta-entrada {
                0% { transform: scale(.92); box-shadow: 0 0 0 0 rgba(37, 99, 235, .45); }
                60% { transform: scale(1.04); box-shadow: 0 0 0 6px rgba(37, 99, 235, .18); }
                100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
            }

            @keyframes nv-cta-pulso {
                0% { transform: scale(1); }
                50% { transform: scale(1.03); }
                100% { transform: scale(1); }
            }

            .nv-cta-entrada { animation: nv-cta-entrada 500ms ease-out 1; }

            .nv-cta-pulso { animation: nv-cta-pulso 280ms ease-out 1; }

            @media (prefers-reduced-motion: reduce) {
                .nv-cta-entrada, .nv-cta-pulso { animation: none; }
            }

            /* Iconos de Entrega y Metodo de envio: discretos, no dominan la tarjeta. */
            .nv-ico { width: 20px; height: 20px; flex: none; margin-top: 1px; color: #6b7280; }
            .nv-ico--on { color: #2563eb; }

            /* Resumen de venta: solo Productos, Envio y Total se destacan (fondo azul muy suave, sin sombras ni degradados). */
            .nv-res-dest, .nv-res-total { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; border-radius: 8px; background: #eff6ff; padding: 6px 10px; }
            .nv-res-dest dt { font-weight: 600; color: #1e3a8a; }
            .nv-res-dest dd { font-weight: 600; color: #111827; text-align: right; white-space: nowrap; }
            .nv-res-total { margin-top: 10px; padding: 10px 12px; border: 1px solid #bfdbfe; }
            .nv-res-total dt { font-size: 1rem; font-weight: 700; color: #1e3a8a; }
            .nv-res-total dd { font-size: 1.25rem; line-height: 1.75rem; font-weight: 800; color: #111827; text-align: right; white-space: nowrap; }
            /* Foco visible en todo el flujo (teclado): el build de Tailwind del admin no trae las utilidades `focus-visible:*` de ByLopez. */
            .nv-raiz :is(button, a, input, select, textarea, summary, [tabindex]):focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
            :is(.dark) .nv-raiz :is(button, a, input, select, textarea, summary, [tabindex]):focus-visible { outline-color: #93c5fd; }

            /* Busqueda de colores. */
            .nv-busqueda { width: 100%; max-width: 20rem; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px 12px; font-size: .875rem; color: #374151; background: #fff; }
            .nv-busqueda:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .2); }
            :is(.dark) .nv-busqueda { background: #111827; border-color: #1f2937; color: #e5e7eb; }

            /* Marca «Ya en la venta» / «Por agregar»: dos estados distintos, ademas del color van con texto. */
            .nv-etq { display: inline-block; margin-right: 4px; border-radius: 9999px; padding: 1px 8px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
            .nv-etq--guardado { background: #d1fae5; color: #065f46; }
            .nv-etq--elegido { background: #dbeafe; color: #1e40af; }
            :is(.dark) .nv-etq--guardado { background: rgba(6, 95, 70, .45); color: #a7f3d0; }
            :is(.dark) .nv-etq--elegido { background: rgba(30, 64, 175, .45); color: #bfdbfe; }

            /* Avisos junto a la accion (resultado del guardado). */
            .nv-aviso { border-radius: 8px; padding: 10px; font-size: .875rem; }
            .nv-aviso--error { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; }
            .nv-aviso--ambar { background: #fffbeb; color: #92400e; }
            :is(.dark) .nv-aviso--error { border-color: rgba(185, 28, 28, .5); background: rgba(127, 29, 29, .35); color: #fecaca; }
            :is(.dark) .nv-aviso--ambar { background: rgba(120, 53, 15, .35); color: #fde68a; }

            /* Senal «en otras ventas…»: se lee como accion (abre el detalle), no como texto suelto. */
            .nv-senal { margin-left: 4px; text-align: left; font-size: .75rem; color: #b45309; text-decoration: underline dotted; cursor: pointer; background: none; border: 0; padding: 0; }
            :is(.dark) .nv-senal { color: #fbbf24; }
            .nv-deshabilitada { margin-top: 2px; max-width: 16rem; font-size: .75rem; line-height: 1rem; font-weight: 500; color: #b45309; }
            :is(.dark) .nv-deshabilitada { color: #fbbf24; }
            :is(.dark) .nv-res-dest, :is(.dark) .nv-res-total { background: rgba(30, 58, 138, .28); border-color: rgba(59, 130, 246, .35); }
            :is(.dark) .nv-res-dest dt, :is(.dark) .nv-res-total dt { color: #bfdbfe; }
            :is(.dark) .nv-res-dest dd, :is(.dark) .nv-res-total dd { color: #fff; }
            :is(.dark) .nv-ico--on { color: #93c5fd; }
        </style>
    @endPushOnce

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-nueva-venta-template"
        >
            <div class="nv-raiz">
                <!-- Cabecera compacta -->
                <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <div class="flex items-center gap-3">
                        <p class="text-xl font-bold text-gray-800 dark:text-white">
                            @lang('ventasasesor::app.nueva-venta.title')
                        </p>

                        <span
                            v-if="clienteActual"
                            class="text-sm font-semibold text-blue-700 dark:text-blue-300"
                        >
                            · @{{ clienteActual.nombre }} @{{ clienteActual.apellido }}
                            <template v-if="clienteActual.telefono_principal || clienteActual.telefonoPrincipal">
                                — @{{ clienteActual.telefono_principal || clienteActual.telefonoPrincipal }}
                            </template>
                            <template v-if="grupoAplicadoNombre">
                                · Grupo: @{{ grupoAplicadoNombre }}
                                <span
                                    v-if="grupoVenta && grupoVenta.override"
                                    class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-900 dark:text-amber-100"
                                >Temporal</span>
                            </template>
                        </span>

                        <span
                            v-if="creditoCliente && creditoCliente.visible && creditoCliente.total_disponible > 0"
                            class="rounded-md bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"
                            data-credito-disponible-nv
                        >
                            Saldo a favor disponible: @{{ precio(creditoCliente.total_disponible) }}
                        </span>

                        <span
                            v-if="estado"
                            class="rounded-md px-2 py-1 text-xs font-semibold"
                            :class="estado.confirmable ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-200'"
                        >
                            @{{ estado.confirmable ? 'Lista para confirmar' : 'En progreso' }} · #@{{ estado.borrador.id }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            class="transparent-button border-2 border-gray-200 dark:border-gray-800"
                            @click="abrirVentasEnCurso"
                        >
                            <span class="icon-orders text-2xl"></span>
                            Ventas en curso
                        </button>

                        <button
                            v-if="borradorId || clienteActual"
                            type="button"
                            class="secondary-button"
                            @click="reiniciar"
                        >
                            + Nueva venta
                        </button>
                    </div>
                </div>

                <!-- Doc. 33 P4: pedido del cliente confirmado. Solo lo que le corresponde: numero, total, entrega y pago pendiente. -->
                <div v-if="confirmacionCliente" class="mt-3 rounded-lg border border-green-300 bg-green-50 p-4 text-green-900 dark:bg-green-950 dark:text-green-100" data-pedido-confirmado role="status">
                    <p class="text-base font-bold">¡Pedido #@{{ confirmacionCliente.numero }} confirmado!</p>
                    <p class="mt-1 text-sm">Total @{{ precio(confirmacionCliente.total) }} · @{{ confirmacionCliente.entrega }} · Pago: @{{ confirmacionCliente.pago }}.</p>
                    <p class="mt-1 text-sm">Te avisaremos cuando esté listo. El pago se confirma cuando recibamos tu transferencia.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('admin.autoservicio.pedidos.index') }}" class="primary-button">Ver mis pedidos</a>
                        <button type="button" class="secondary-button" @click="otroPedido">Hacer otro pedido</button>
                    </div>
                </div>

                <!-- Indicador de progreso (no es wizard: solo informa y permite saltar con scroll) -->
                <div
                    v-if="borradorId"
                    class="mt-3 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs font-semibold"
                >
                    <template v-for="(paso, i) in pasos" :key="paso.clave">
                        <button
                            type="button"
                            class="flex items-center gap-1 rounded-full px-2.5 py-1 transition-colors"
                            :class="{
                                'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200': paso.estado === 'completo',
                                'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200': paso.estado === 'actual',
                                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': paso.estado === 'pendiente',
                            }"
                            @click="irASeccion(paso.clave)"
                        >
                            <span v-if="paso.estado === 'completo'">✓</span>
                            <span v-else>@{{ i + 1 }}</span>
                            @{{ paso.etiqueta }}
                        </button>

                        <span
                            v-if="i < pasos.length - 1"
                            class="text-gray-300 dark:text-gray-700"
                        >→</span>
                    </template>
                </div>

                <!-- Estado REAL del borrador (backend): solo `activo` se puede editar. Un borrador
                     `confirmando` / `confirmado` / `descartado` se muestra como tal, nunca como editable. -->
                <div
                    v-if="bannerBorrador"
                    data-banner-borrador
                    class="mt-4 rounded-lg border p-3 text-sm"
                    :class="bannerBorrador.clase"
                >
                    <p class="font-semibold">@{{ bannerBorrador.titulo }}</p>
                    <p class="mt-0.5">@{{ bannerBorrador.detalle }}</p>

                    <div class="mt-2 flex flex-wrap gap-2">
                        <a
                            v-if="bannerBorrador.orderId"
                            :href="urlMisVentas(bannerBorrador.orderId)"
                            class="secondary-button"
                        >
                            Ver en Mis ventas
                        </a>

                        <button
                            v-if="bannerBorrador.verificar"
                            type="button"
                            class="secondary-button"
                            :disabled="cargando.estado"
                            @click="verificarBorrador"
                        >
                            Verificar estado
                        </button>

                        <button
                            v-if="bannerBorrador.reintentar"
                            type="button"
                            class="primary-button"
                            :disabled="cargando.confirmar"
                            @click="confirmar"
                        >
                            Reintentar confirmación
                        </button>

                        <button
                            type="button"
                            class="transparent-button"
                            @click="reiniciar"
                        >
                            Nueva venta
                        </button>
                    </div>
                </div>

                <!-- Layout principal: contenido (izq) + resumen sticky (der) -->
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[70%_1fr]">
                    <!-- ==================== COLUMNA PRINCIPAL ==================== -->
                    <div
                        class="grid gap-4"
                        :class="estado && ! ventaEditable ? 'pointer-events-none select-none opacity-60' : ''"
                        :aria-disabled="estado && ! ventaEditable ? 'true' : 'false'"
                    >
                        <!-- ---------- CLIENTE ---------- -->
                        <!-- Desaparece al iniciar la venta: el cliente ya se muestra en la cabecera. -->
                        <div
                            v-if="! borradorId && ! modoCliente"
                            id="seccion-cliente"
                            class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                        >
                            <template v-if="! clienteActual">
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                                        Cliente
                                    </p>

                                    <button
                                        type="button"
                                        class="secondary-button"
                                        @click="abrirCrearCliente"
                                    >
                                        + Crear cliente
                                    </button>
                                </div>

                                <div class="relative">
                                    <input
                                        ref="buscadorCliente"
                                        type="text"
                                        class="block w-full rounded-lg border bg-white py-2.5 text-base leading-6 text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 ltr:pl-4 ltr:pr-10 rtl:pl-10 rtl:pr-4"
                                        placeholder="Buscar cliente por teléfono, nombre o correo"
                                        v-model.lazy="busquedaCliente"
                                        v-debounce="500"
                                        @keydown.enter="buscarCliente"
                                    />

                                    <span
                                        v-if="cargando.cliente"
                                        class="absolute top-3 flex h-5 w-5 animate-spin items-center rounded-full border-2 border-blue-600 border-t-transparent ltr:right-3 rtl:left-3"
                                    ></span>

                                    <span
                                        v-else
                                        class="icon-search cursor-pointer absolute top-2.5 text-2xl text-gray-400 ltr:right-3 rtl:left-3"
                                        @click="buscarCliente"
                                    ></span>
                                </div>

                                <!-- Resultado: un cliente exacto -->
                                <div
                                    v-if="resultadoBusqueda && resultadoBusqueda.tipo !== 'candidatos_nombre' && resultadoBusqueda.tipo !== 'sin_resultados'"
                                    class="mt-3"
                                >
                                    <template v-if="resultadoBusqueda.cliente">
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between rounded-lg border p-3 text-left hover:border-blue-500 dark:border-gray-800"
                                            @click="seleccionarClienteEncontrado(resultadoBusqueda.cliente)"
                                        >
                                            <span>
                                                <span class="block font-semibold text-gray-800 dark:text-white">
                                                    @{{ resultadoBusqueda.cliente.nombre }} @{{ resultadoBusqueda.cliente.apellido }}
                                                </span>
                                                <span class="block text-sm text-gray-500 dark:text-gray-300">
                                                    @{{ resultadoBusqueda.cliente.telefonoPrincipal || 'Sin teléfono' }} · @{{ resultadoBusqueda.cliente.grupoNombre || 'Sin grupo' }}
                                                </span>
                                            </span>

                                            <span class="primary-button">Seleccionar</span>
                                        </button>

                                        {{-- Clientes historicos: el cliente YA existe y trae antecedentes reportados por el proveedor (hechos, no una calificacion). --}}
                                        <p
                                            v-if="resultadoBusqueda.cliente.antecedentes"
                                            class="mt-2 text-sm text-gray-600 dark:text-gray-300"
                                            data-antecedentes-existente
                                        >
                                            @{{ 'Historial reportado por 99 Envíos — Entregados: ' + resultadoBusqueda.cliente.antecedentes.entregados + ' | Devoluciones: ' + resultadoBusqueda.cliente.antecedentes.devoluciones }}
                                            ·
                                            <a :href="'{{ route('admin.customers.customers.view', ['id' => '__ID__']) }}'.replace('__ID__', resultadoBusqueda.cliente.customerId)" target="_blank" class="text-blue-600">Ver ficha</a>
                                            <template v-if="resultadoBusqueda.cliente.antecedentes.datos_pendientes > 0">
                                                · <span class="font-semibold text-amber-700" data-dato-pendiente-nv>Datos pendientes de completar (apellido)</span>
                                            </template>
                                            <template v-if="resultadoBusqueda.cliente.antecedentes.direcciones_pendientes > 0">
                                                · <span class="font-semibold text-amber-700" data-direccion-pendiente-nv>Dirección pendiente de completar</span>
                                            </template>
                                        </p>
                                    </template>

                                    <p
                                        v-else-if="resultadoBusqueda.tipo === 'conflicto'"
                                        class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
                                    >
                                        Hay un conflicto de identidad (@{{ resultadoBusqueda.conflicto }}). Revisa el caso manualmente.
                                    </p>
                                </div>

                                <!-- Resultado: candidatos por nombre -->
                                <div
                                    v-if="resultadoBusqueda && resultadoBusqueda.tipo === 'candidatos_nombre'"
                                    class="mt-3 grid gap-2"
                                >
                                    <button
                                        type="button"
                                        v-for="candidato in resultadoBusqueda.candidatos"
                                        :key="candidato.customerId"
                                        class="flex w-full items-center justify-between rounded-lg border p-3 text-left hover:border-blue-500 dark:border-gray-800"
                                        @click="seleccionarClienteEncontrado(candidato)"
                                    >
                                        <span>
                                            <span class="block font-semibold text-gray-800 dark:text-white">
                                                @{{ candidato.nombre }} @{{ candidato.apellido }}
                                            </span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-300">
                                                @{{ candidato.telefonoPrincipal || 'Sin teléfono' }} · @{{ candidato.grupoNombre || 'Sin grupo' }}
                                            </span>
                                        </span>
                                    </button>
                                </div>

                                <!-- Sin resultados: crear cliente -->
                                <div
                                    v-if="resultadoBusqueda && resultadoBusqueda.tipo === 'sin_resultados'"
                                    class="mt-3 flex items-center justify-between rounded-lg border border-dashed p-3 dark:border-gray-700"
                                >
                                    <span class="text-sm text-gray-600 dark:text-gray-300">
                                        No se encontró ningún cliente.
                                    </span>

                                    <button
                                        type="button"
                                        class="secondary-button"
                                        @click="abrirCrearCliente"
                                    >
                                        + Crear cliente
                                    </button>
                                </div>
                            </template>

                            <!-- Cliente ya seleccionado pero venta aun no iniciada: canal + iniciar venta.
                                 El nombre/telefono/grupo ya se muestran en la cabecera (arriba, junto a
                                 "Nueva Venta"); esta tarjeta desaparece del todo una vez hay borrador,
                                 para dejar el espacio a los pasos siguientes. -->
                            <template v-else-if="! borradorId">
                                <div class="flex items-center justify-between">
                                    <span
                                        v-if="clienteActual && clienteActual.clienteConfirmable === false"
                                        class="rounded-md bg-red-100 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-950 dark:text-red-300"
                                    >
                                        Cliente no habilitado para confirmar venta
                                    </span>

                                    <button
                                        type="button"
                                        class="text-sm text-blue-600 underline dark:text-blue-300"
                                        @click="reiniciar"
                                    >
                                        Cambiar cliente
                                    </button>
                                </div>

                                <!-- Selector de canal -->
                                <div class="mt-1 flex items-center gap-2.5">
                                    <label class="text-sm text-gray-600 dark:text-gray-300">Canal:</label>

                                    <select
                                        v-model="canalSeleccionado"
                                        class="rounded-lg border bg-white px-3 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    >
                                        <option
                                            v-for="canal in canales"
                                            :key="canal"
                                            :value="canal"
                                        >
                                            @{{ canal }}
                                        </option>
                                    </select>

                                    <!-- P7: la venta en curso nace al agregar la primera prenda; consultar productos no guarda nada. -->
                                    <span class="text-xs text-gray-500 dark:text-gray-400" data-venta="venta-se-guarda-al-agregar">
                                        La venta se guarda al agregar el primer producto.
                                    </span>
                                </div>
                            </template>
                        </div>

                        <!-- ---------- ACCESOS RÁPIDOS, LÍNEA/COLOR Y MATRIZ (solo con borrador) ---------- -->
                        <template v-if="borradorId || clienteActual">
                            <!-- Productos y Colores -->
                            <div class="flex flex-col gap-4">
                                @if ($rutaNv('dictado.interpretar') !== '#' && config('ventasasesor.dictado.activo', true))
                                    <!-- Doc. 34: pedido por voz. El navegador transcribe; el servidor interpreta y valida; se agrega con «aplicar tallas». -->
                                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-venta="dictado">
                                        <div class="nv-dic-fila">
                                            <p class="text-base font-semibold text-gray-800 dark:text-white">Dictar pedido</p>
                                            <button type="button" class="secondary-button" data-venta="dictado-abrir" @click="dictado.abierto = ! dictado.abierto">
                                                @{{ dictado.abierto ? 'Cerrar' : 'Dictar' }}
                                            </button>
                                        </div>

                                        <template v-if="dictado.abierto">
                                            <p class="nv-dic-ayuda">
                                                Di color, dama/caballero/niño, talla y cantidad. Ej.: «roja 2-4, 2 unidades; azul rey talla S de dama, 15 unidades».
                                            </p>

                                            <textarea
                                                v-model="dictado.texto"
                                                rows="3"
                                                class="nv-dic-texto"
                                                data-venta="dictado-texto"
                                                placeholder="Toca «Hablar» o escribe / dicta con el teclado…"
                                            ></textarea>

                                            <div class="nv-dic-acciones">
                                                <button type="button" :class="dictado.escuchando ? 'primary-button' : 'secondary-button'" data-venta="dictado-hablar" @click="dictadoEscuchar">
                                                    @{{ dictado.escuchando ? '■ Detener' : '🎤 Hablar' }}
                                                </button>
                                                <button type="button" class="primary-button" data-venta="dictado-interpretar" :disabled="dictado.cargando || ! dictado.texto.trim()" @click="dictadoInterpretar">
                                                    @{{ dictado.cargando ? 'Interpretando…' : 'Interpretar' }}
                                                </button>
                                                <button type="button" class="transparent-button" @click="dictadoLimpiar">Limpiar</button>
                                            </div>

                                            <p v-if="dictado.error" class="nv-dic-error" data-venta="dictado-error">@{{ dictado.error }}</p>

                                            <div v-if="dictado.lineas.length" class="nv-dic-lista" data-venta="dictado-lineas">
                                                <div
                                                    v-for="(l, i) in dictado.lineas"
                                                    :key="i"
                                                    class="nv-dic-linea"
                                                    :class="l.omitida ? 'nv-dic-linea--omitida' : 'nv-dic-linea--' + l.estado"
                                                    :data-dictado-linea="i"
                                                >
                                                    <div class="nv-dic-fila">
                                                        <span class="nv-dic-estado">@{{ l.omitida ? '⏭️' : (l.estado === 'ok' ? '✅' : (l.estado === 'aviso' ? '⚠️' : '❌')) }}</span>
                                                        <span class="nv-dic-producto">
                                                            <template v-if="l.nombre">@{{ l.nombre }} <span class="nv-dic-sku">@{{ l.sku }}</span></template>
                                                            <template v-else>@{{ l.color || 'Sin color' }}</template>
                                                        </span>
                                                        <span class="nv-dic-dato">Talla @{{ l.talla_etiqueta || '—' }}</span>
                                                        <span class="nv-dic-dato">× @{{ l.cantidad ?? '—' }}</span>
                                                    </div>
                                                    <p v-if="l.texto" class="nv-dic-dijo">Dijo: «@{{ l.texto }}»<template v-if="l.corregida"> · corregida</template></p>
                                                    <p v-if="l.omitida" class="nv-dic-mensaje">Omitida: no se agrega.</p>
                                                    <p v-else-if="l.mensaje" class="nv-dic-mensaje">@{{ l.mensaje }}</p>

                                                    <!-- Corregir con selectores: color, tipo, talla y cantidad; se valida otra vez contra el catalogo. -->
                                                    <div v-if="l.editando" class="nv-dic-editor" data-venta="dictado-editor">
                                                        <label class="nv-dic-campo">
                                                            <span>Color</span>
                                                            <select v-model="l.form.color" data-venta="dictado-color">
                                                                <option value="">Elige…</option>
                                                                <optgroup v-if="l.candidatos && l.candidatos.length" label="Lo que dijiste">
                                                                    <option v-for="c in l.candidatos" :key="'c' + c" :value="c">@{{ c }}</option>
                                                                </optgroup>
                                                                <optgroup label="Todos los colores">
                                                                    <option v-for="c in dictado.colores" :key="c" :value="c">@{{ c }}</option>
                                                                </optgroup>
                                                            </select>
                                                        </label>
                                                        <label class="nv-dic-campo">
                                                            <span>Tipo</span>
                                                            <select v-model="l.form.tipo" data-venta="dictado-tipo" @change="dictadoAjustarTalla(l)">
                                                                <option value="D">Dama</option>
                                                                <option value="H">Caballero</option>
                                                                <option value="N">Niño</option>
                                                                <option value="TP">Crop top</option>
                                                            </select>
                                                        </label>
                                                        <label class="nv-dic-campo">
                                                            <span>Talla</span>
                                                            <select v-model="l.form.talla" data-venta="dictado-talla">
                                                                <option value="">Elige…</option>
                                                                <option v-for="t in dictadoTallasDe(l.form.tipo)" :key="t" :value="t">@{{ dictado.tallas[t] || t }}</option>
                                                            </select>
                                                        </label>
                                                        <label class="nv-dic-campo nv-dic-campo--cantidad">
                                                            <span>Cantidad</span>
                                                            <input v-model.number="l.form.cantidad" type="number" min="1" max="9999" inputmode="numeric" data-venta="dictado-cantidad">
                                                        </label>
                                                        <p v-if="l.errorEdicion" class="nv-dic-error">@{{ l.errorEdicion }}</p>
                                                        <div class="nv-dic-acciones">
                                                            <button type="button" class="primary-button" data-venta="dictado-validar" :disabled="l.validando" @click="dictadoValidarCorreccion(i)">
                                                                @{{ l.validando ? 'Validando…' : 'Validar' }}
                                                            </button>
                                                            <button type="button" class="transparent-button" @click="l.editando = false">Cancelar</button>
                                                        </div>
                                                    </div>

                                                    <div v-else class="nv-dic-acciones nv-dic-acciones--linea">
                                                        <button v-if="! l.omitida" type="button" class="secondary-button" data-venta="dictado-corregir" @click="dictadoEditar(l)">Corregir</button>
                                                        <button type="button" class="transparent-button" data-venta="dictado-omitir" @click="l.omitida = ! l.omitida">
                                                            @{{ l.omitida ? 'Incluir de nuevo' : 'Omitir' }}
                                                        </button>
                                                    </div>
                                                </div>

                                                <p v-if="dictadoPendientes" class="nv-dic-error" data-venta="dictado-pendientes">
                                                    @{{ dictadoPendientes === 1 ? 'Hay 1 línea con ❌' : 'Hay ' + dictadoPendientes + ' líneas con ❌' }}: corrígela(s) u omítela(s) para poder agregar.
                                                </p>

                                                <div v-if="dictado.resultado" class="nv-dic-error">
                                                    <p v-if="dictado.resultado.guardados.length">Agregados: @{{ dictado.resultado.guardados.join(', ') }}.</p>
                                                    <p v-for="p in dictado.resultado.pendientes">No se agregó @{{ p.nombre }}: @{{ p.mensaje }}</p>
                                                </div>

                                                <button
                                                    v-if="dictadoValidas.length"
                                                    type="button"
                                                    class="primary-button"
                                                    data-venta="dictado-agregar"
                                                    :disabled="dictado.agregando || dictadoPendientes > 0 || dictado.lineas.some((x) => x.editando)"
                                                    @click="dictadoAgregar"
                                                >
                                                    @{{ dictado.agregando ? 'Agregando…' : 'Agregar ' + dictadoValidas.length + (dictadoValidas.length === 1 ? ' línea' : ' líneas') + ' a la venta' }}
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                @endif

                                <div
                                    id="seccion-productos"
                                    class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                                >
                                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">
                                        Productos
                                    </p>

                                    <div class="flex flex-wrap gap-2.5">
                                        <button
                                            type="button"
                                            v-for="acceso in accesosRapidos"
                                            :key="acceso.clave"
                                            class="flex items-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-semibold transition-all"
                                            :class="identidadAcceso(acceso, lineaSeleccionada(acceso.clave))"
                                            :disabled="! acceso.categoriaId"
                                            :title="! acceso.categoriaId ? 'No configurado: ' + acceso.motivo : ''"
                                            @click="toggleLinea(acceso)"
                                        >
                                            <span
                                                v-if="lineaSeleccionada(acceso.clave)"
                                                class="icon-check-circle text-base"
                                            ></span>
                                            @{{ acceso.etiqueta }}
                                            <span
                                                v-if="! acceso.categoriaId"
                                                class="text-xs text-red-500"
                                            >
                                                ⚠
                                            </span>
                                        </button>
                                    </div>
                                </div>
                                <!-- Colores (unión de las líneas seleccionadas, selección múltiple) -->
                                <div
                                    v-if="lineasSeleccionadas.length"
                                    class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                                >
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <!-- El titulo dice A QUE producto corresponde lo que se ve (nunca colores de otra linea). -->
                                            <p class="text-base font-semibold text-gray-800 dark:text-white" data-venta="colores-titulo">
                                                Colores
                                                <span class="font-normal text-gray-500 dark:text-gray-400">&middot; @{{ etiquetasLineasActivas }}</span>
                                            </p>

                                            <p
                                                v-if="coloresColapsados && resumenColores"
                                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                                data-venta="colores-resumen"
                                            >
                                                <span
                                                    class="nv-etq"
                                                    :class="resumenColores.tipo === 'guardados' ? 'nv-etq--guardado' : 'nv-etq--elegido'"
                                                >@{{ resumenColores.tipo === 'guardados' ? 'Ya en la venta' : 'Por agregar' }}</span>
                                                @{{ resumenColores.texto }}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            class="transparent-button shrink-0 px-2.5 py-1.5 text-xs"
                                            :aria-expanded="! coloresColapsados"
                                            @click="alternarColores"
                                        >
                                            @{{ coloresColapsados ? 'Editar colores' : 'Minimizar' }}
                                        </button>
                                    </div>

                                    <!-- Consulta las matrices de la seleccion y SOLO si todas cargan abre la modal lateral. Arriba del
                                         listado para no perderse con muchos colores; en movil queda fija bajo la cabecera mientras
                                         haya colores elegidos. -->
                                    <div
                                        class="mb-3 flex flex-wrap items-center gap-2.5"
                                        :class="tarjetasPorColor.length ? 'max-lg:sticky max-lg:top-[60px] max-lg:z-10 max-lg:-mx-4 max-lg:border-b max-lg:bg-white max-lg:px-4 max-lg:py-2 max-lg:dark:border-gray-800 max-lg:dark:bg-gray-900' : ''"
                                        data-venta="cta-tallas"
                                    >
                                        <button
                                            type="button"
                                            class="primary-button inline-flex items-center gap-2 max-sm:w-full max-sm:justify-center"
                                            :class="ctaAnimacion ? 'nv-cta-' + ctaAnimacion : ''"
                                            :disabled="cargando.matrices || cargando.colores || ! tarjetasPorColor.length"
                                            @click="verTallas"
                                        >
                                            <span
                                                v-if="cargando.matrices"
                                                class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                                            ></span>
                                            @{{ textoCtaTallas }}
                                        </button>

                                        <p
                                            v-if="! tarjetasPorColor.length && ! cargando.colores"
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                            data-venta="ayuda-colores"
                                        >
                                            @{{ ayudaColores }}
                                        </p>

                                        <p
                                            v-if="avisoSinTallas"
                                            class="text-sm text-amber-700 dark:text-amber-300"
                                            data-venta="sin-tallas"
                                        >
                                            @{{ avisoSinTallas }}
                                        </p>

                                        <p
                                            v-if="errorMatrices"
                                            class="text-sm text-red-600 dark:text-red-300"
                                        >
                                            @{{ errorMatrices }} Puedes intentarlo de nuevo.
                                        </p>
                                    </div>

                                    <div v-show="! coloresColapsados">

                                    <div
                                        v-if="cargando.colores"
                                        class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-300"
                                        role="status"
                                        data-venta="cargando-colores"
                                    >
                                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" aria-hidden="true"></span>
                                        Cargando colores…
                                    </div>

                                    <template v-else>
                                    <!-- Busqueda por nombre: solo cuando hay muchos colores. Filtrar NO toca la seleccion. -->
                                    <div
                                        v-if="mostrarBusquedaColor"
                                        class="mb-3 flex flex-wrap items-center gap-2"
                                        data-venta="busqueda-colores"
                                    >
                                        <label for="nv-buscar-color" class="sr-only">Buscar color por nombre</label>
                                        <input
                                            id="nv-buscar-color"
                                            type="search"
                                            v-model="busquedaColor"
                                            autocomplete="off"
                                            placeholder="Buscar color…"
                                            class="nv-busqueda"
                                            @keydown.esc="busquedaColor = ''"
                                        />

                                        <button
                                            v-if="busquedaColor"
                                            type="button"
                                            class="transparent-button px-2.5 py-1.5 text-xs"
                                            @click="busquedaColor = ''"
                                        >
                                            Limpiar búsqueda
                                        </button>

                                        <span
                                            v-if="coloresSeleccionadosOcultos"
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                            role="status"
                                        >
                                            @{{ coloresSeleccionadosOcultos }} @{{ coloresSeleccionadosOcultos === 1 ? 'color elegido sigue' : 'colores elegidos siguen' }} seleccionado@{{ coloresSeleccionadosOcultos === 1 ? '' : 's' }} fuera de esta búsqueda.
                                        </span>
                                    </div>

                                    <p
                                        v-for="(mensaje, claveLinea) in erroresColoresLinea"
                                        :key="'err-' + claveLinea"
                                        class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-red-50 p-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
                                        role="alert"
                                    >
                                        @{{ mensaje }}
                                        <button type="button" class="font-semibold underline" @click="cargarColoresDeLinea(claveLinea)">Reintentar</button>
                                    </p>

                                    <p
                                        v-if="busquedaColor && coloresUnion.length && ! coloresFiltrados.length"
                                        class="text-sm text-gray-500 dark:text-gray-300"
                                        role="status"
                                        data-venta="sin-coincidencias-color"
                                    >
                                        Ningún color coincide con «@{{ busquedaColor }}».
                                        <button type="button" class="font-semibold text-blue-600 underline dark:text-blue-300" @click="busquedaColor = ''">Limpiar búsqueda</button>
                                    </p>

                                    <div
                                        class="flex flex-wrap gap-2.5"
                                    >
                                        <button
                                            type="button"
                                            v-for="color in coloresFiltrados"
                                            :key="color.clave"
                                            :aria-pressed="colorSeleccionadoClave(color.clave)"
                                            class="flex items-center gap-2 rounded-lg border-2 px-4 py-2 text-sm font-medium transition-all"
                                            :class="[colorSeleccionadoClave(color.clave)
                                                ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950'
                                                : 'border-gray-200 text-gray-700 hover:border-blue-400 dark:border-gray-800 dark:text-gray-200',
                                                colorBloqueado(color) ? 'cursor-not-allowed opacity-60 hover:border-gray-200' : '']"
                                            :disabled="colorBloqueado(color)"
                                            :data-disponibilidad="disponibilidadColor(color).estado"
                                            @click="toggleColor(color)"
                                        >
                                            <span
                                                class="h-4 w-4 shrink-0 rounded-full border border-gray-300 dark:border-gray-600"
                                                :style="color.swatch ? { backgroundColor: color.swatch } : {}"
                                            ></span>

                                            <span
                                                v-if="colorSeleccionadoClave(color.clave)"
                                                class="icon-check-circle text-sm"
                                            ></span>

                                            <span class="flex flex-col items-start leading-tight">
                                                <span>@{{ color.etiqueta }}</span>
                                                <span
                                                    v-if="disponibilidadColor(color).texto"
                                                    class="text-[10px] font-bold uppercase"
                                                    :class="disponibilidadColor(color).estado === 'agotado' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                                >@{{ disponibilidadColor(color).texto }}</span>
                                                <span
                                                    v-if="colorEnVenta(color)"
                                                    class="text-[10px] font-bold uppercase text-emerald-700 dark:text-emerald-300"
                                                >En la venta</span>
                                            </span>
                                        </button>

                                        <p
                                            v-if="! coloresUnion.length && ! hayErroresColores"
                                            class="text-sm text-gray-500 dark:text-gray-300"
                                        >
                                            No hay colores disponibles para @{{ etiquetasLineasActivas }}.
                                        </p>
                                    </div>
                                    </template>

                                    </div>
                                </div>

                                <!-- Accesos rápidos (selección múltiple) -->
                                <div
                                    id="seccion-productos-duplicado"
                                    class="hidden"
                                >
                                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">
                                        Productos
                                    </p>

                                    <div class="flex flex-wrap gap-2.5">
                                        <button
                                            type="button"
                                            v-for="acceso in accesosRapidos"
                                            :key="acceso.clave"
                                            class="flex items-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-semibold transition-all"
                                            :class="identidadAcceso(acceso, lineaSeleccionada(acceso.clave))"
                                            :disabled="! acceso.categoriaId"
                                            :title="! acceso.categoriaId ? 'No configurado: ' + acceso.motivo : ''"
                                            @click="toggleLinea(acceso)"
                                        >
                                            <span
                                                v-if="lineaSeleccionada(acceso.clave)"
                                                class="icon-check-circle text-base"
                                            ></span>
                                            @{{ acceso.etiqueta }}
                                            <span
                                                v-if="! acceso.categoriaId"
                                                class="text-xs text-red-500"
                                            >
                                                ⚠
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!--
                                Modal lateral IZQUIERDA con las matrices (patron "Buscar productos" de
                                Bagisto, x-admin::drawer position="left"). Solo se abre desde verTallas()
                                cuando TODAS las matrices de la seleccion cargaron. Una seccion por color,
                                una tabla por linea que SI tiene ese color.
                            -->
                            <x-admin::drawer
                                ref="drawerMatrices"
                                position="left"
                                width="680px"
                                @close="alCerrarMatrices"
                            >
                                <x-slot:header>
                                    <p class="text-xl font-medium text-gray-800 dark:text-white">
                                        @{{ edicionUnica ? 'Editar tallas' : 'Tallas y cantidades' }}
                                    </p>

                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        @{{ edicionUnica ? 'Cambia las cantidades de este producto. Los demás productos de la venta no se tocan.' : 'Escribe las cantidades y agrégalas todas de una vez.' }}
                                    </p>
                                </x-slot:header>

                                <x-slot:content>
                                    <p
                                        v-if="avisoModal"
                                        class="mb-3 whitespace-pre-line rounded-lg bg-amber-50 p-2 text-sm text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                    >
                                        @{{ avisoModal }}
                                    </p>

                                    <div
                                        v-for="grupoColor in gruposModal"
                                        :key="grupoColor.clave"
                                        class="mb-5 last:mb-0"
                                    >
                                        <p class="mb-2 flex items-center gap-2 text-base font-semibold uppercase text-gray-800 dark:text-white">
                                            <span
                                                class="h-4 w-4 shrink-0 rounded-full border border-gray-300 dark:border-gray-600"
                                                :style="grupoColor.swatch ? { backgroundColor: grupoColor.swatch } : {}"
                                            ></span>
                                            @{{ grupoColor.etiqueta }}
                                        </p>

                                <div
                                    v-for="tarjeta in grupoColor.tarjetas"
                                    :key="tarjeta.productId"
                                    class="mb-3 rounded-lg border p-3 last:mb-0 dark:border-gray-800"
                                >
                                    <p class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        @{{ tarjeta.etiquetaLinea }}
                                    </p>

                                    <table
                                        v-if="matricesPorProducto[tarjeta.productId]"
                                        class="w-full text-left text-sm"
                                    >
                                        <thead>
                                            <tr class="border-b bg-gray-50 text-xs uppercase text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                                <th class="py-2">Talla</th>
                                                <th class="py-2">Stock</th>
                                                <th class="py-2">Precio</th>
                                                <th class="py-2">Cantidad</th>
                                                <th class="py-2"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <template
                                                v-for="(celda, indiceCelda) in matricesPorProducto[tarjeta.productId].celdas"
                                                :key="celda.variante.productId"
                                            >
                                                <tr
                                                    class="border-b dark:border-gray-800"
                                                    :class="[precioCambia(matricesPorProducto[tarjeta.productId].celdas, indiceCelda) ? 'border-t-4 border-t-amber-300 dark:border-t-amber-700' : '', celda.variante.stockVendible === 0 ? 'bg-red-50 text-gray-400 dark:bg-red-950/40 dark:text-gray-500' : cantidadDe(tarjeta.productId, celda.variante.productId) > celda.variante.stockVendible ? 'bg-red-50 dark:bg-red-950' : '']"
                                                >
                                                    <td class="py-2 text-base font-bold text-gray-900 dark:text-white">
                                                        @{{ tallaDe(celda.variante) }}
                                                        <span v-if="precioCambia(matricesPorProducto[tarjeta.productId].celdas, indiceCelda)" class="block text-[10px] font-semibold uppercase text-amber-600 dark:text-amber-400">Otro precio</span>
                                                    </td>

                                                    <td
                                                        class="py-2 text-sm"
                                                        :class="celda.variante.stockVendible === 0 ? 'font-semibold text-red-600 dark:text-red-300' : celda.variante.stockVendible <= 5 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-gray-600 dark:text-gray-300'"
                                                    >
                                                        <span
                                                            class="text-lg font-bold leading-none"
                                                            :class="celda.variante.stockVendible === 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'"
                                                        >
                                                            @{{ celda.variante.stockVendible }}
                                                        </span>
                                                        <span
                                                            class="ml-1 text-xs font-semibold"
                                                            :class="celda.variante.stockVendible === 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'"
                                                        >
                                                            @{{ celda.variante.stockVendible === 0 ? 'No disponible' : 'disponible' }}
                                                        </span>

                                                        <button
                                                            type="button"
                                                            v-if="celda.senal && (celda.senal.en_otras_ventas_sin_confirmar || celda.senal.confirmados_sin_despachar)"
                                                            class="nv-senal"
                                                            :aria-expanded="detalleSenalAbierto === celda.variante.productId"
                                                            :title="textoAyudaSenal(celda.senal)"
                                                            data-venta="senal-otras-ventas"
                                                            @click="toggleDetalleSenal(celda.variante.productId)"
                                                        >
                                                            <template v-if="celda.senal.en_otras_ventas_sin_confirmar">
                                                                · en otras ventas sin confirmar: @{{ celda.senal.en_otras_ventas_sin_confirmar }}
                                                            </template>
                                                            <template v-if="celda.senal.confirmados_sin_despachar">
                                                                · pedidos sin despachar: @{{ celda.senal.confirmados_sin_despachar }}
                                                            </template>
                                                        </button>
                                                    </td>

                                                    <td
                                                        class="py-2"
                                                        :class="precioCambia(matricesPorProducto[tarjeta.productId].celdas, indiceCelda) ? 'font-bold text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-300'"
                                                    >
                                                        @{{ celda.variante.precioUnitario !== null ? precio(celda.variante.precioUnitario) : '—' }}
                                                    </td>

                                                    <td class="py-2">
                                                        <div class="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                class="h-7 w-7 shrink-0 rounded-lg border text-sm leading-none text-gray-600 hover:border-blue-400 disabled:opacity-40 dark:border-gray-800 dark:text-gray-300"
                                                                :disabled="! celda.variante.activa || celda.variante.stockVendible === 0 || ! cantidadDe(tarjeta.productId, celda.variante.productId)"
                                                                @click="ajustarCantidad(tarjeta.productId, celda.variante.productId, -1)"
                                                            >−</button>

                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="1"
                                                                class="w-16 rounded-lg border px-2 py-1 text-center dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                                                :disabled="! celda.variante.activa || celda.variante.stockVendible === 0"
                                                                v-model.number="cantidadesPorProducto[tarjeta.productId][celda.variante.productId]"
                                                                @focus="$event.target.select()"
                                                                @click="$event.target.select()"
                                                                @mouseup.prevent
                                                                @keydown.enter="agregarAlCarrito"
                                                            />

                                                            <button
                                                                type="button"
                                                                class="h-7 w-7 shrink-0 rounded-lg border text-sm leading-none text-gray-600 hover:border-blue-400 disabled:opacity-40 dark:border-gray-800 dark:text-gray-300"
                                                                :disabled="! celda.variante.activa || celda.variante.stockVendible === 0"
                                                                @click="ajustarCantidad(tarjeta.productId, celda.variante.productId, 1)"
                                                            >+</button>

                                                            <button
                                                                type="button"
                                                                v-if="cantidadAplicada(celda.variante.productId)"
                                                                title="Quitar esta talla de la venta"
                                                                class="ml-1 text-base text-gray-400 hover:text-red-600 disabled:opacity-40 dark:text-gray-500 dark:hover:text-red-400"
                                                                :disabled="cargando.tallas"
                                                                @click="quitarTalla(tarjeta.productId, celda.variante.productId)"
                                                            >🗑</button>
                                                        </div>

                                                        <p
                                                            v-if="cantidadDe(tarjeta.productId, celda.variante.productId) > celda.variante.stockVendible"
                                                            class="mt-0.5 text-xs font-medium text-red-600 dark:text-red-300"
                                                        >
                                                            ⚠ Stock insuficiente (disponible @{{ celda.variante.stockVendible }})
                                                        </p>

                                                        <p
                                                            v-if="! celda.variante.activa"
                                                            class="nv-deshabilitada"
                                                            data-venta="variante-deshabilitada"
                                                        >
                                                            <template v-if="celda.variante.stockVendible > 0">
                                                                ⚠ Talla deshabilitada en el catálogo. Tiene @{{ celda.variante.stockVendible }} en inventario: pide al administrador que la habilite.
                                                            </template>
                                                            <template v-else>
                                                                ⚠ Talla deshabilitada en el catálogo.
                                                            </template>
                                                        </p>
                                                    </td>

                                                    <td class="py-2 text-xs text-red-600 dark:text-red-300">
                                                        <template v-if="erroresPorVariante[celda.variante.productId]">
                                                            @{{ erroresPorVariante[celda.variante.productId] }}
                                                        </template>
                                                    </td>
                                                </tr>

                                                <tr v-if="detalleSenalAbierto === celda.variante.productId">
                                                    <td
                                                        colspan="5"
                                                        class="bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-300"
                                                    >
                                                        <p v-if="celda.senal.otras_ventas.length">
                                                            Unidades en ventas de otras asesoras que aún no se confirman (borradores):
                                                            <span
                                                                v-for="(otra, i) in celda.senal.otras_ventas"
                                                                :key="i"
                                                            >
                                                                @{{ otra.asesorNombre || otra.asesor_nombre }} — @{{ otra.cantidad }}@{{ i < celda.senal.otras_ventas.length - 1 ? ', ' : '' }}
                                                            </span>
                                                        </p>

                                                        <p v-if="celda.senal.confirmados_sin_despachar">
                                                            Unidades de pedidos ya confirmados que todavía no se despachan: @{{ celda.senal.confirmados_sin_despachar }}
                                                        </p>

                                                        <p class="mt-1 text-gray-500 dark:text-gray-400">
                                                            @{{ textoAyudaSenal(celda.senal) }}
                                                        </p>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                    </div>
                                </x-slot:content>

                                <!-- Footer fijo: UNA sola accion para todas las matrices de la seleccion. -->
                                <x-slot:footer class="border-t px-3 pt-3 dark:border-gray-800">
                                    <!-- El resultado de la accion vive JUNTO al boton (no arriba de una lista larga que obliga a desplazarse). -->
                                    <div
                                        v-if="resultadoGuardado"
                                        class="nv-aviso nv-aviso--error mb-3"
                                        role="alert"
                                        data-venta="error-guardado"
                                    >
                                        <p class="font-semibold">No se guardó todo. Revisa lo pendiente antes de continuar.</p>

                                        <p v-if="resultadoGuardado.guardados.length" class="mt-1">
                                            <span class="font-semibold">Ya está en la venta:</span> @{{ resultadoGuardado.guardados.join(', ') }}.
                                        </p>

                                        <ul class="mt-1 grid gap-0.5">
                                            <li v-for="p in resultadoGuardado.pendientes" :key="p.nombre">
                                                <span class="font-semibold">Pendiente:</span> @{{ p.nombre }} — @{{ p.verificar ? 'no pudimos confirmar si se guardó. Mira el resumen de la venta antes de volver a intentarlo.' : p.mensaje }}
                                            </li>
                                        </ul>

                                        <p class="mt-1 text-xs">Tus cantidades siguen aquí. Reintentar no duplica productos: solo se envía lo que todavía no está en la venta.</p>
                                    </div>

                                    <p
                                        v-else-if="avisoAccion"
                                        class="nv-aviso nv-aviso--ambar mb-3"
                                        role="alert"
                                        data-venta="aviso-accion"
                                    >
                                        @{{ avisoAccion }}
                                    </p>

                                    <p
                                        v-if="cargando.tallas"
                                        class="mb-2 flex items-center justify-end gap-2 text-sm font-medium text-blue-700 dark:text-blue-300"
                                        role="status"
                                        data-venta="guardando-productos"
                                    >
                                        <span class="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" aria-hidden="true"></span>
                                        Guardando productos…
                                    </p>

                                    <div class="flex items-center justify-end gap-2.5">
                                        <button
                                            type="button"
                                            class="transparent-button"
                                            :disabled="cargando.tallas"
                                            @click="$refs.drawerMatrices.close()"
                                        >
                                            Cancelar
                                        </button>

                                        <button
                                            type="button"
                                            class="primary-button"
                                            :disabled="cargando.tallas"
                                            @click="agregarAlCarrito"
                                        >
                                            <span
                                                v-if="cargando.tallas"
                                                class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                                            ></span>
                                            <svg
                                                v-else
                                                class="h-4 w-4"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13 5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17M17 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM9 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"
                                                />
                                            </svg>
                                            @{{ resultadoGuardado ? 'Reintentar lo pendiente' : (edicionUnica ? 'Guardar cambios' : 'Agregar productos al carrito') }}
                                        </button>
                                    </div>
                                </x-slot:footer>
                            </x-admin::drawer>

                            <!-- ---------- ENTREGA ----------
                                 Progresion (no wizard): Entrega → Direccion (solo domicilio) → Metodo de envio → Pago.
                                 Cada opcion es un radio grande al estilo del checkout de Bagisto. La recogida aplica
                                 SOLA el carrier real `pickup` sobre la bodega real (D-41): no se vuelve a preguntar. -->
                            <div
                                v-if="estado && estado.lineas.length"
                                id="seccion-entrega"
                                class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                            >
                                <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-base font-semibold text-gray-800 dark:text-white">
                                    Entrega

                                    <span
                                        v-if="guardandoEntrega"
                                        class="flex items-center gap-1.5 text-xs font-medium text-blue-700 dark:text-blue-300"
                                        role="status"
                                        data-venta="guardando-entrega"
                                    >
                                        <span class="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" aria-hidden="true"></span>
                                        Guardando entrega…
                                    </span>
                                </p>

                                <p
                                    v-if="estado.requiere_revision_entrega"
                                    class="mb-3 rounded-lg bg-amber-50 p-2 text-sm text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                >
                                    Revisar entrega: el método anterior ya no está disponible y no se eligió otro automáticamente.
                                </p>

                                <div class="grid gap-2.5">
                                    <!-- Recogida en tienda (carrier pickup de Bagisto) -->
                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all hover:border-blue-400"
                                        :class="entregaForm.tipo === 'punto_recogida'
                                            ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-950'
                                            : 'border-gray-200 dark:border-gray-800'"
                                    >
                                        <input
                                            type="radio"
                                            class="mt-1 h-4 w-4 shrink-0 accent-blue-600"
                                            name="tipo-entrega"
                                            :key="'tipo-pickup-' + versionVista"
                                            :checked="entregaForm.tipo === 'punto_recogida'"
                                            :disabled="ocupado || ! ventaEditable"
                                            @change="elegirTipoEntrega('punto_recogida')"
                                        />

                                        <svg class="nv-ico" :class="entregaForm.tipo === 'punto_recogida' ? 'nv-ico--on' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-venta="icono-recogida"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v1.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9z"/><path d="M5 13v7h14v-7"/><path d="M10 20v-4h4v4"/></svg>

                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold text-gray-800 dark:text-white">@{{ recogida.titulo }}</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ recogida.descripcion }}</span>

                                            <span
                                                v-if="tipoAplicado === 'punto_recogida' && estado.entrega.envio"
                                                class="mt-1 block text-xs text-gray-700 dark:text-gray-300"
                                            >
                                                📍 @{{ estado.entrega.envio.empresa }} — @{{ (estado.entrega.envio.lineas || []).join(', ') }}, @{{ estado.entrega.envio.ciudad }}
                                            </span>
                                        </span>

                                        <span
                                            v-if="tipoAplicado === 'punto_recogida' && estado.entrega.metodo_seleccionado"
                                            class="whitespace-nowrap text-sm font-semibold text-gray-800 dark:text-white"
                                        >
                                            @{{ precio(estado.entrega.metodo_seleccionado.precio) }}
                                        </span>
                                    </label>

                                    <!-- Envio a domicilio: abre Direccion y luego los metodos reales de Bagisto -->
                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all hover:border-blue-400"
                                        :class="entregaForm.tipo === 'domicilio'
                                            ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-950'
                                            : 'border-gray-200 dark:border-gray-800'"
                                    >
                                        <input
                                            type="radio"
                                            class="mt-1 h-4 w-4 shrink-0 accent-blue-600"
                                            name="tipo-entrega"
                                            :key="'tipo-domicilio-' + versionVista"
                                            :checked="entregaForm.tipo === 'domicilio'"
                                            :disabled="ocupado || ! ventaEditable"
                                            @change="elegirTipoEntrega('domicilio')"
                                        />

                                        <svg class="nv-ico" :class="entregaForm.tipo === 'domicilio' ? 'nv-ico--on' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-venta="icono-domicilio"><path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="7.5" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg>

                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold text-gray-800 dark:text-white">Envío a domicilio</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ modoCliente ? 'Te lo enviamos a tu dirección. Después eliges el método de envío.' : 'Se entrega en una dirección del cliente. Después eliges el método de envío.' }}</span>
                                        </span>
                                    </label>
                                </div>

                                <!-- Recogida sin facturacion resoluble (D-30): Bagisto exige una direccion de
                                     facturacion REAL; la bodega nunca es la facturacion del cliente. -->
                                <div
                                    v-if="entregaForm.tipo === 'punto_recogida' && estado.requiere_direccion_facturacion"
                                    class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950"
                                >
                                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Dirección de facturación del cliente</p>
                                    <p class="mb-2 text-xs text-amber-700 dark:text-amber-300">
                                        Para registrar el pedido se necesita una dirección real del cliente (no se usa la bodega). No se usa como destino.
                                    </p>

                                    <div v-if="direccionesCliente.length" class="grid gap-2">
                                        <label
                                            v-for="dir in direccionesCliente"
                                            :key="'fact-' + dir.id"
                                            class="flex cursor-pointer items-start gap-3 rounded-lg border bg-white p-2.5 text-sm dark:bg-gray-900"
                                            :class="direccionFacturacionId === dir.id ? 'border-blue-600 ring-1 ring-blue-600' : 'border-gray-200 dark:border-gray-800'"
                                        >
                                            <input type="radio" name="direccion-facturacion" :key="'fact-' + dir.id + '-' + versionVista" class="mt-1 h-4 w-4 shrink-0 accent-blue-600" :checked="direccionFacturacionId === dir.id" :disabled="ocupado || ! ventaEditable" @change="seleccionarFacturacion(dir)" />
                                            <span class="min-w-0">
                                                <span class="block font-medium text-gray-800 dark:text-white">@{{ dir.nombre }} @{{ dir.apellido }}</span>
                                                <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ dir.direccion }} · @{{ dir.ciudad }}, @{{ nombreDepartamento(dir.departamento) }}</span>
                                            </span>
                                            <button
                                                type="button"
                                                class="ml-auto shrink-0 text-xs font-semibold text-blue-600 hover:underline disabled:opacity-50 dark:text-blue-300"
                                                v-if="! modoCliente"
                                                :disabled="ocupado || ! ventaEditable"
                                                @click.prevent.stop="abrirFormDireccion('facturacion', dir)"
                                            >
                                                Editar
                                            </button>
                                        </label>
                                    </div>

                                    <button
                                        type="button"
                                        class="secondary-button mt-2"
                                        @click="abrirFormDireccion('facturacion')"
                                    >
                                        + Agregar dirección
                                    </button>
                                </div>

                                <p v-if="cargando.entrega" class="mt-2 text-xs text-gray-500 dark:text-gray-400">Guardando entrega…</p>
                            </div>

                            <!-- ---------- DIRECCIÓN (solo envío a domicilio) ---------- -->
                            <div
                                v-if="estado && estado.lineas.length && entregaForm.tipo === 'domicilio'"
                                id="seccion-direccion"
                                class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                            >
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <p class="text-base font-semibold text-gray-800 dark:text-white">Dirección de entrega</p>

                                    <button
                                        v-if="direccionesCliente.length"
                                        type="button"
                                        class="text-sm font-semibold text-blue-600 dark:text-blue-300"
                                        @click="abrirFormDireccion('envio')"
                                    >
                                        + Agregar dirección
                                    </button>
                                </div>

                                <p v-if="cargando.direccion" class="text-sm text-gray-500 dark:text-gray-400">Cargando direcciones del cliente…</p>

                                <div
                                    v-else-if="direccionesCargadas && ! direccionesCliente.length"
                                    class="rounded-lg border border-dashed border-gray-300 p-4 text-center dark:border-gray-700"
                                >
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">@{{ modoCliente ? 'Aún no tienes una dirección de entrega.' : 'Este cliente no tiene una dirección de entrega.' }}</p>
                                    <button type="button" class="primary-button mx-auto mt-2" @click="abrirFormDireccion('envio')">+ Agregar dirección</button>
                                </div>

                                <div v-if="direccionesCliente.length" class="grid gap-2.5 sm:grid-cols-2">
                                    <label
                                        v-for="dir in direccionesCliente"
                                        :key="dir.id"
                                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-all hover:border-blue-400"
                                        :class="direccionSeleccionadaId === dir.id
                                            ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-950'
                                            : 'border-gray-200 dark:border-gray-800'"
                                    >
                                        <input
                                            type="radio"
                                            class="mt-1 h-4 w-4 shrink-0 accent-blue-600"
                                            name="direccion-entrega"
                                            :key="'dir-' + dir.id + '-' + versionVista"
                                            :checked="direccionSeleccionadaId === dir.id"
                                            :disabled="ocupado || ! ventaEditable"
                                            @change="seleccionarDireccion(dir)"
                                        />

                                        <span class="min-w-0">
                                            <span class="block font-semibold text-gray-800 dark:text-white">
                                                @{{ dir.nombre }} @{{ dir.apellido }}
                                                <span v-if="dir.predeterminada" class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">Predeterminada</span>
                                            </span>
                                            <span class="block text-gray-600 dark:text-gray-300">@{{ dir.telefono }}</span>
                                            <span class="block text-gray-600 dark:text-gray-300">@{{ dir.direccion }}</span>
                                            <span class="block text-gray-600 dark:text-gray-300">@{{ dir.ciudad }}, @{{ nombreDepartamento(dir.departamento) }}</span>
                                        </span>

                                        <button
                                            type="button"
                                            class="ml-auto shrink-0 rounded-md border border-blue-200 px-2 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50 disabled:opacity-50 dark:border-blue-900 dark:text-blue-300 dark:hover:bg-blue-950"
                                            v-if="! modoCliente"
                                            :disabled="ocupado || ! ventaEditable"
                                            @click.prevent.stop="abrirFormDireccion('envio', dir)"
                                        >
                                            Editar
                                        </button>
                                    </label>
                                </div>

                                <!-- Direccion puntual de ESTA venta (no guardada en el cliente) -->
                                <div
                                    v-if="direccionPuntual && ! direccionSeleccionadaId"
                                    class="mt-2 rounded-lg border border-blue-600 bg-blue-50 p-3 text-sm ring-1 ring-blue-600 dark:bg-blue-950"
                                >
                                    <span class="block font-semibold text-gray-800 dark:text-white">@{{ direccionPuntual.nombre }} @{{ direccionPuntual.apellido }} <span class="text-xs font-normal text-gray-500">(solo esta venta)</span></span>
                                    <span class="block text-gray-600 dark:text-gray-300">@{{ direccionPuntual.telefono }} · @{{ direccionPuntual.linea }} · @{{ direccionPuntual.ciudad }}, @{{ nombreDepartamento(direccionPuntual.departamento) }}</span>
                                </div>

                                <p
                                    v-if="direccionesCliente.length > 1 && ! direccionSeleccionadaId && ! direccionPuntual"
                                    class="mt-2 text-xs text-amber-700 dark:text-amber-300"
                                >
                                    Selecciona la dirección de entrega.
                                </p>

                                <!-- Metodos de envio reales para ESA direccion (Shipping::collectRates de Bagisto) -->
                                <div v-if="tipoAplicado === 'domicilio'" class="mt-4">
                                    <!-- Entrega LOCAL (zona configurada): quien entrega es obligatorio y fija el valor del domicilio. -->
                                    <div
                                        v-if="estado.entrega.domiciliario"
                                        class="mb-4 rounded-lg border p-3 dark:border-gray-800"
                                        data-venta="domiciliario"
                                    >
                                        <div class="flex items-center justify-between gap-2 text-sm">
                                            <span class="text-gray-500 dark:text-gray-400">Zona de entrega</span>
                                            <span class="font-semibold text-gray-800 dark:text-white">@{{ estado.entrega.domiciliario.zona.nombre }}</span>
                                        </div>

                                        <label class="mb-1 mt-2 block text-sm font-semibold text-gray-800 dark:text-white" for="venta-domiciliario">@{{ modoCliente ? '¿Quién lleva tu pedido?' : 'Domiciliario *' }}</label>

                                        <select
                                            id="venta-domiciliario"
                                            class="w-full rounded-lg border bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                            :class="! estado.entrega.domiciliario.elegido_id ? 'border-amber-400' : ''"
                                            :key="'domiciliario-' + versionVista"
                                            :value="estado.entrega.domiciliario.elegido_id ? String(estado.entrega.domiciliario.elegido_id) : ''"
                                            :disabled="ocupado || ! ventaEditable || cargando.domiciliario"
                                            @change="elegirDomiciliario($event.target.value)"
                                        >
                                            <option value="" :disabled="!! estado.entrega.domiciliario.elegido_id">Elige el domiciliario</option>
                                            <option
                                                v-for="o in estado.entrega.domiciliario.opciones"
                                                :key="o.id"
                                                :value="String(o.id)"
                                                :disabled="o.tarifa === null"
                                            >
                                                @{{ o.nombre }}@{{ o.tarifa === null ? ' — sin tarifa en ' + estado.entrega.domiciliario.zona.nombre : (modoCliente ? (o.tarifa === 0 ? ' — sin costo' : ' — ' + precio(o.tarifa)) : '') }}
                                            </option>
                                        </select>

                                        <p
                                            v-if="domiciliarioEsSugerido && ! modoCliente"
                                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Sugerido: último usado en @{{ estado.entrega.domiciliario.zona.nombre }}
                                        </p>

                                        <div v-if="estado.entrega.domiciliario.tarifa !== null" class="mt-2 flex items-center justify-between gap-2 text-sm" data-venta="tarifa-domicilio">
                                            <span class="text-gray-700 dark:text-gray-200">Tarifa domicilio</span>
                                            <span class="font-bold text-gray-900 dark:text-white">@{{ estado.entrega.domiciliario.tarifa === 0 ? 'Sin costo' : precio(estado.entrega.domiciliario.tarifa) }}</span>
                                        </div>

                                        <p v-if="notaDomicilio" class="mt-1 text-xs text-gray-500 dark:text-gray-400">@{{ notaDomicilio }}</p>

                                        <p v-if="! estado.entrega.domiciliario.listo" class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-300" data-venta="domiciliario-requerido">
                                            Elige el domiciliario para ver el valor del domicilio y poder confirmar la venta.
                                        </p>
                                    </div>

                                    <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">Método de envío</p>

                                    <div v-if="estado.entrega.metodos_disponibles.length" class="grid gap-2.5">
                                        <label
                                            v-for="metodo in estado.entrega.metodos_disponibles"
                                            :key="metodo.codigo"
                                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all hover:border-blue-400"
                                            :class="estado.entrega.metodo_seleccionado && estado.entrega.metodo_seleccionado.codigo === metodo.codigo
                                                ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-950'
                                                : 'border-gray-200 dark:border-gray-800'"
                                        >
                                            <input
                                                type="radio"
                                                class="mt-1 h-4 w-4 shrink-0 accent-blue-600"
                                                name="metodo-envio"
                                                :key="'envio-' + metodo.codigo + '-' + versionVista"
                                                :checked="!! (estado.entrega.metodo_seleccionado && estado.entrega.metodo_seleccionado.codigo === metodo.codigo)"
                                                :disabled="ocupado || ! ventaEditable"
                                                @change="seleccionarMetodoEnvio(metodo.codigo)"
                                            />

                                            <template v-if="metodoEsGratis(metodo)">
                                                <svg class="nv-ico" :class="(estado.entrega.metodo_seleccionado && estado.entrega.metodo_seleccionado.codigo === metodo.codigo) ? 'nv-ico--on' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-venta="icono-envio-gratis"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
                                            </template>
                                            <template v-else>
                                                <svg class="nv-ico" :class="(estado.entrega.metodo_seleccionado && estado.entrega.metodo_seleccionado.codigo === metodo.codigo) ? 'nv-ico--on' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-venta="icono-envio"><path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="7.5" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg>
                                            </template>

                                            <span class="min-w-0 flex-1">
                                                <span class="block font-semibold text-gray-800 dark:text-white">@{{ metodo.titulo }}</span>
                                                <span v-if="metodo.descripcion" class="block text-xs text-gray-500 dark:text-gray-400">@{{ metodo.descripcion }}</span>
                                            </span>

                                            <span class="whitespace-nowrap text-right">
                                                <span class="block text-sm font-semibold text-gray-800 dark:text-white">@{{ precio(metodo.precio) }}</span>

                                                <!-- Envio por pagar en zona local: el pedido no cobra flete, pero el cliente paga el del domiciliario al recibir. -->
                                                <span
                                                    v-if="fleteDomiciliarioPorPagar(metodo) !== null"
                                                    class="block text-xs font-semibold text-amber-700 dark:text-amber-300"
                                                    data-venta="flete-domiciliario-por-pagar"
                                                >
                                                    + @{{ precio(fleteDomiciliarioPorPagar(metodo)) }} al recibir
                                                </span>
                                            </span>
                                        </label>
                                    </div>

                                    <p v-else class="text-sm text-gray-500 dark:text-gray-400">No hay métodos de envío disponibles para esta dirección.</p>
                                </div>
                            </div>

                            <!-- ---------- PAGO (aparece cuando la entrega esta completa y coincide con lo elegido) ---------- -->
                            <div
                                v-if="estado && entregaLista && ! modoCliente"
                                id="seccion-pago"
                                class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                            >
                                <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-base font-semibold text-gray-800 dark:text-white">
                                    Pago

                                    <span
                                        v-if="guardandoPago"
                                        class="flex items-center gap-1.5 text-xs font-medium text-blue-700 dark:text-blue-300"
                                        role="status"
                                        data-venta="guardando-pago"
                                    >
                                        <span class="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" aria-hidden="true"></span>
                                        Guardando método de pago…
                                    </span>
                                </p>

                                <p v-if="cargando.pago && ! guardandoPago && ! (pago && pago.metodos_disponibles)" class="text-sm text-gray-500" role="status">Cargando métodos de pago…</p>

                                <div v-else class="grid gap-2.5">
                                    <label
                                        v-for="metodo in (pago ? pago.metodos_disponibles : [])"
                                        :key="metodo.codigo"
                                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all hover:border-blue-400"
                                        :class="estado.pago.metodo_seleccionado && estado.pago.metodo_seleccionado.codigo === metodo.codigo
                                            ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-950'
                                            : 'border-gray-200 dark:border-gray-800'"
                                    >
                                        <input
                                            type="radio"
                                            class="mt-1 h-4 w-4 shrink-0 accent-blue-600"
                                            name="metodo-pago"
                                            :key="'pago-' + metodo.codigo + '-' + versionVista"
                                            :checked="!! (estado.pago.metodo_seleccionado && estado.pago.metodo_seleccionado.codigo === metodo.codigo)"
                                            :disabled="ocupado || ! ventaEditable"
                                            @change="seleccionarPago(metodo.codigo)"
                                        />

                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold text-gray-800 dark:text-white">@{{ iconoPago(metodo.codigo) }} @{{ tituloPago(metodo.titulo).nombre }}</span>
                                            <span v-if="tituloPago(metodo.titulo).medios" class="block text-xs text-gray-500 dark:text-gray-400" data-venta="medios-pago">Medios admitidos: @{{ tituloPago(metodo.titulo).medios }}</span>
                                            <span v-if="metodo.descripcion && metodo.descripcion !== tituloPago(metodo.titulo).nombre" class="block text-xs text-gray-500 dark:text-gray-400">@{{ metodo.descripcion }}</span>
                                        </span>
                                    </label>

                                    <p v-if="pago && ! pago.metodos_disponibles.length" class="text-sm text-gray-500 dark:text-gray-300">
                                        No hay métodos de pago disponibles para esta venta.
                                    </p>

                                    <button type="button" v-if="! pago" class="transparent-button justify-self-start" @click="cargarMetodosPago">
                                        Ver métodos de pago
                                    </button>
                                </div>

                                <p v-if="estado.pago.metodo_seleccionado" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Método seleccionado: @{{ tituloPago(estado.pago.metodo_seleccionado.titulo).nombre }}. El pago sigue pendiente hasta que exista evidencia real.
                                </p>

                                <p v-else-if="! guardandoPago" class="mt-2 text-xs text-gray-500 dark:text-gray-400">Selecciona un método de pago.</p>
                            </div>
                        </template>
                    </div>

                    <!-- ==================== RESUMEN STICKY ==================== -->
                    <div class="lg:sticky lg:top-4 lg:self-start">
                        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">
                                Resumen de venta
                            </p>

                            <template v-if="! estado">
                                <p class="text-sm text-gray-500 dark:text-gray-300">
                                    @{{ clienteActual ? 'Agrega el primer producto para guardar la venta.' : 'Busca o selecciona un cliente para empezar.' }}
                                </p>
                            </template>

                            <template v-else>
                                <dl class="grid gap-1 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Cliente</dt>
                                        <dd class="text-right font-medium text-gray-800 dark:text-white">
                                            @{{ estado.borrador.cliente.nombre }} @{{ estado.borrador.cliente.apellido }}
                                        </dd>
                                    </div>

                                    <template v-if="! modoCliente">
                                    <!-- GRP-01: grupo habitual (el real del cliente) vs grupo aplicado SOLO a esta venta -->
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Grupo habitual</dt>
                                        <dd class="text-right text-gray-700 dark:text-gray-200" data-grupo-habitual>
                                            @{{ (grupoVenta && grupoVenta.habitual_nombre) || estado.borrador.cliente.grupo_nombre || '—' }}
                                        </dd>
                                    </div>

                                    <div class="flex items-start justify-between gap-2">
                                        <dt class="text-gray-500 dark:text-gray-300">Grupo aplicado a esta venta</dt>
                                        <dd class="text-right font-medium text-gray-800 dark:text-white" data-grupo-aplicado>
                                            @{{ grupoAplicadoNombre || '—' }}
                                            <span
                                                v-if="grupoVenta && grupoVenta.override"
                                                class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-900 dark:text-amber-100"
                                            >Temporal</span>
                                        </dd>
                                    </div>

                                    <div
                                        v-if="(puedeCambiarGrupo && ventaEditable) || (grupoVenta && grupoVenta.cambios > 0)"
                                        class="flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs"
                                    >
                                        <button
                                            v-if="puedeCambiarGrupo && ventaEditable"
                                            type="button"
                                            class="rounded border border-blue-200 px-2 py-1 font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-900 dark:text-blue-300 dark:hover:bg-blue-950"
                                            data-grupo-cambiar
                                            @click="abrirModalGrupo('cambiar')"
                                        >
                                            Cambiar para esta venta
                                        </button>

                                        <button
                                            v-if="puedeCambiarGrupo && ventaEditable && grupoVenta && grupoVenta.override"
                                            type="button"
                                            class="px-1 py-1 font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                            data-grupo-restaurar
                                            @click="abrirModalGrupo('restaurar')"
                                        >
                                            Restaurar grupo habitual
                                        </button>

                                        <button
                                            v-if="grupoVenta && grupoVenta.cambios > 0"
                                            type="button"
                                            class="px-1 py-1 text-gray-500 hover:underline dark:text-gray-400"
                                            data-grupo-historial
                                            @click="abrirHistorialGrupo"
                                        >
                                            Ver historial de grupo
                                        </button>
                                    </div>
                                    </template>

                                    <!-- Entrega y pago REALES del carrito (lo aplicado), no lo que se esta eligiendo -->
                                    <div class="flex justify-between gap-2">
                                        <dt class="text-gray-500 dark:text-gray-300">Entrega</dt>
                                        <dd class="min-w-0 text-right text-gray-700 dark:text-gray-200">
                                            <template v-if="tipoAplicado === 'punto_recogida' && estado.entrega.metodo_seleccionado">
                                                @{{ estado.entrega.metodo_seleccionado.titulo }}
                                                <span class="block text-xs text-gray-500 dark:text-gray-400">@{{ estado.entrega.envio.empresa }}</span>
                                            </template>

                                            <template v-else-if="tipoAplicado === 'domicilio' && estado.entrega.metodo_seleccionado">
                                                @{{ estado.entrega.metodo_seleccionado.titulo }}
                                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                                    @{{ (estado.entrega.envio.lineas || []).join(', ') }}, @{{ estado.entrega.envio.ciudad }}
                                                </span>
                                            </template>

                                            <template v-else>—</template>
                                        </dd>
                                    </div>

                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Pago</dt>
                                        <dd class="text-right text-gray-700 dark:text-gray-200">
                                            @{{ estado.pago && estado.pago.metodo_seleccionado ? tituloPago(estado.pago.metodo_seleccionado.titulo).nombre : '—' }}
                                        </dd>
                                    </div>
                                </dl>

                                <hr class="my-3 dark:border-gray-800" />

                                <div
                                    v-if="estado.lineas.length"
                                    class="grid gap-1.5 text-sm"
                                >
                                    <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span>Productos (@{{ estado.lineas.length }}) · @{{ unidadesTotales }} unidades</span>
                                        <button
                                            v-if="ventaEditable"
                                            type="button"
                                            class="text-gray-500 hover:text-blue-600 hover:underline dark:text-gray-400 dark:hover:text-blue-300"
                                            @click="irASeccion('seccion-productos')"
                                        >
                                            Editar selección completa
                                        </button>
                                    </div>

                                    <!-- Un bloque por producto (linea x color). El lapiz abre la modal SOLO con ese producto. -->
                                    <div
                                        v-for="grupo in productosResumen"
                                        :key="grupo.productoId"
                                        class="rounded-lg border px-2.5 py-2 dark:border-gray-800"
                                        :data-resumen-producto="grupo.productoId"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="min-w-0 font-semibold text-gray-800 dark:text-white">
                                                <span class="block truncate">@{{ grupo.titulo }}</span>
                                                <span v-if="grupo.subtitulo" class="block truncate text-xs font-normal text-gray-500 dark:text-gray-400">@{{ grupo.subtitulo }}</span>
                                            </p>

                                            <button
                                                v-if="ventaEditable"
                                                type="button"
                                                class="-m-1.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-blue-600 disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-blue-300"
                                                :disabled="ocupado || cargando.matrices"
                                                :title="'Editar tallas de ' + grupo.titulo"
                                                :aria-label="'Editar tallas de ' + grupo.titulo"
                                                @click="editarProducto(grupo)"
                                            >
                                                <span
                                                    v-if="cargando.matrices && edicionCargando === grupo.productoId"
                                                    class="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"
                                                ></span>
                                                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div
                                            v-for="linea in grupo.lineas"
                                            :key="linea.cartItemId"
                                            class="mt-0.5 flex justify-between gap-2"
                                        >
                                            <span class="text-gray-700 dark:text-gray-200">@{{ tallaDe(linea) }} × @{{ linea.cantidad }}</span>
                                            <span class="font-medium text-gray-800 dark:text-white">@{{ precio(linea.subtotal) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-else
                                    class="text-sm text-gray-500 dark:text-gray-300"
                                >
                                    Sin productos todavía.
                                </p>

                                <hr class="my-3 dark:border-gray-800" />

                                <dl class="grid gap-1 text-sm">
                                    <!-- Solo visual: Productos, Envio y Total son los tres valores destacados; el resto sigue secundario. -->
                                    <div class="nv-res-dest" data-venta="resumen-productos">
                                        <dt>Productos (@{{ unidadesTotales }} @{{ unidadesTotales === 1 ? 'unidad' : 'unidades' }})</dt>
                                        <dd>@{{ precio(estado.borrador.totales?.sub_total || 0) }}</dd>
                                    </div>

                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Subtotal</dt>
                                        <dd class="text-gray-600 dark:text-gray-300">@{{ precio(estado.borrador.totales?.sub_total || 0) }}</dd>
                                    </div>

                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Descuento</dt>
                                        <dd class="text-gray-600 dark:text-gray-300">@{{ precio(estado.borrador.totales?.discount_amount || 0) }}</dd>
                                    </div>

                                    <div class="nv-res-dest" data-venta="resumen-flete">
                                        <dt>Envío</dt>
                                        <dd>@{{ precio(estado.borrador.totales?.shipping_amount || 0) }}</dd>
                                    </div>

                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-300">Impuestos</dt>
                                        <dd class="text-gray-600 dark:text-gray-300">@{{ precio(estado.borrador.totales?.tax_total || 0) }}</dd>
                                    </div>

                                    <div class="nv-res-total" data-venta="resumen-total">
                                        <dt>Total</dt>
                                        <dd>@{{ precio(estado.total_esperado || 0) }}</dd>
                                    </div>
                                </dl>

                                <p v-if="modoCliente" class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-precio-mayorista>Tu precio mayorista</p>

                                <p
                                    v-if="grupoAplicadoNombre"
                                    class="mt-1 text-xs text-gray-400 dark:text-gray-500"
                                >
                                    Precio según grupo @{{ grupoAplicadoNombre }}<template v-if="grupoVenta && grupoVenta.override"> (temporal, solo esta venta)</template>
                                </p>

                                <p
                                    v-if="! modoCliente && estado.confirmable && ! entregaLista"
                                    class="mt-3 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                >
                                    ⚠ Termina de definir la entrega elegida antes de confirmar.
                                </p>

                                <!-- Lo que viaja NO se lista como faltante («guardando» != «falta»); la senal de guardado vive en su seccion. -->
                                <div
                                    v-if="! estado.confirmable && motivosPendientes.length"
                                    class="mt-3 rounded-lg bg-amber-50 p-2.5 text-xs dark:bg-amber-950"
                                >
                                    <p class="mb-1 font-semibold text-amber-700 dark:text-amber-300">⚠ Falta completar</p>


                                    <ul class="grid gap-0.5">
                                        <li
                                            v-for="m in motivosPendientes"
                                            :key="m"
                                        >
                                            <button
                                                type="button"
                                                class="text-left text-amber-700 hover:underline dark:text-amber-300"
                                                @click="irASeccion(seccionDelMotivo(m))"
                                            >
                                                • @{{ motivoLegible(m) }}
                                            </button>
                                        </li>
                                    </ul>
                                </div>

                                {{-- R2 (Doc. 23): advertencia de rentabilidad. Al asesor solo se le nombra la referencia; nunca costos. --}}
                                <div
                                    v-if="rentabilidad && rentabilidad.resultado === 'incumple'"
                                    class="mt-3 rounded-lg p-2.5 text-xs"
                                    :class="rentabilidad.bloqueo_activo ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300'"
                                    data-rentabilidad="incumple"
                                >
                                    <p class="font-semibold">⚠ @{{ rentabilidad.bloqueo_activo ? 'Esta venta no se podrá confirmar' : 'Precio bajo el mínimo (solo aviso: el bloqueo aún no está activo)' }}</p>
                                    <p v-if="rentabilidad.referencias.length" class="mt-0.5">@{{ rentabilidad.referencias.join(', ') }} no alcanza el precio mínimo con este grupo o cantidad. Cambia el grupo o la cantidad, o avisa a Administración.</p>
                                    <p v-if="rentabilidad.costos_pedido" class="mt-0.5">Los costos del pedido (por ejemplo, domicilio gratis) no quedan cubiertos: cobra el domicilio o agrega unidades.</p>
                                    <ul v-if="rentabilidad.detalle" class="mt-1 grid gap-0.5">
                                        <li v-for="d in rentabilidad.detalle.filter((x) => x.resultado === 'incumple')" :key="d.producto">
                                            @{{ d.producto }}: precio @{{ d.precio }} · mínimo @{{ d.minimo ?? '—' }}
                                        </li>
                                    </ul>
                                </div>
                                <p
                                    v-else-if="rentabilidad && rentabilidad.configuracion_pendiente && rentabilidad.bloqueo_activo"
                                    class="mt-3 rounded-lg bg-red-50 p-2.5 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                                    data-rentabilidad="configuracion"
                                >
                                    ⚠ Falta configuración de rentabilidad para esta venta: no se podrá confirmar. Avisa a Administración.
                                </p>

                                {{-- R2: «Vender para» (solo con permiso de asignar asesor). Empresa y asesor quedan diferenciados. --}}
                                <div v-if="puedeVenderPara" class="mt-3" data-vender-para>
                                    <label for="va-vender-para" class="text-xs text-gray-500 dark:text-gray-300">Vender para</label>
                                    <select
                                        id="va-vender-para"
                                        v-model="venderPara"
                                        class="custom-select mt-1 w-full rounded-md border px-2.5 py-1.5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                        :disabled="! ventaEditable || ocupado"
                                    >
                                        <option value="">Automático (yo si soy asesor; si no, sin asignar)</option>
                                        <option value="empresa">Venta de la empresa (sin comisión)</option>
                                        <option v-for="a in asesoresVenta" :key="a.id" :value="String(a.id)">@{{ a.nombre }}</option>
                                    </select>
                                </div>

                                <div v-if="puedeClasificar" class="mt-3" data-tipo-operacion-selector>
                                    <label for="va-tipo-operacion" class="text-xs text-gray-500 dark:text-gray-300">@lang('ventasasesor::app.tipo-operacion.etiqueta')</label>
                                    <select
                                        id="va-tipo-operacion"
                                        v-model="tipoOperacion"
                                        class="custom-select mt-1 w-full rounded-md border px-2.5 py-1.5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                        :disabled="! ventaEditable || ocupado"
                                    >
                                        @foreach (\ByLopez\VentasAsesor\Enums\TipoOperacionPedido::cases() as $tipo)
                                            <option value="{{ $tipo->value }}">@lang('ventasasesor::app.tipo-operacion.tipos.'.$tipo->value)</option>
                                        @endforeach
                                    </select>
                                    <p
                                        v-if="['reposicion', 'garantia'].includes(tipoOperacion) && (estado.total_esperado || 0) > 0"
                                        class="mt-1 rounded-lg bg-amber-50 p-2 text-xs text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                    >
                                        ⚠ @lang('ventasasesor::app.tipo-operacion.advertencia-total')
                                    </p>
                                    <p v-else-if="tipoOperacion === 'muestra'" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                        @lang('ventasasesor::app.tipo-operacion.advertencia-muestra')
                                    </p>
                                    <p v-else class="mt-1 text-xs text-gray-400 dark:text-gray-500">@lang('ventasasesor::app.tipo-operacion.ayuda')</p>
                                </div>

                                <button
                                    type="button"
                                    class="primary-button mt-4 w-full justify-center py-2.5 text-base"
                                    :disabled="! estado.confirmable || ! entregaLista || ! ventaEditable || ocupado"
                                    @click="confirmar"
                                >
                                    <span
                                        v-if="cargando.confirmar"
                                        class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                                    ></span>
                                    @{{ modoCliente ? 'CONFIRMAR PEDIDO' : 'CONFIRMAR VENTA' }}
                                </button>

                                <template v-if="! modoCliente">
                                <!-- Cotizacion: PDF para el cliente. No crea pedido, no reserva stock; el domicilio se cobra por aparte. -->
                                <button
                                    type="button"
                                    class="secondary-button mt-2 w-full justify-center py-2"
                                    data-cotizar
                                    :disabled="! puedeCotizar || cotizando"
                                    @click="cotizar"
                                >
                                    <span
                                        v-if="cotizando"
                                        class="h-4 w-4 animate-spin rounded-full border-2 border-gray-500 border-t-transparent"
                                    ></span>
                                    Cotizar
                                </button>

                                <p v-if="! cotizacion" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    Genera un PDF para enviar al cliente. No crea el pedido ni reserva inventario.
                                </p>

                                <div v-if="cotizacion" class="nv-cot" data-cotizacion-panel>
                                    <div class="nv-cot-fila">
                                        <span class="nv-cot-num">@{{ cotizacion.numero }}</span>
                                        <span :class="'nv-cot-badge nv-cot-badge--' + cotizacion.estado">@{{ etiquetaEstadoCotizacion(cotizacion.estado) }}</span>
                                    </div>

                                    <p class="nv-cot-meta">
                                        Total productos @{{ precio(cotizacion.total) }} · válida hasta @{{ fechaCotizacion(cotizacion.valida_hasta) }}
                                    </p>

                                    <p v-if="cotizacionDesactualizada" class="nv-cot-aviso">
                                        La venta cambió después de esta cotización. Pulsa «Cotizar» para generar una nueva.
                                    </p>

                                    <div class="nv-cot-acciones">
                                        <a :href="cotizacion.url_pdf" target="_blank" rel="noopener" class="secondary-button">Abrir PDF</a>
                                        <button type="button" class="secondary-button" @click="copiarCotizacion">Copiar texto</button>
                                    </div>
                                </div>

                                <p v-if="errorCotizacion" class="nv-cot-error" role="alert">@{{ errorCotizacion }}</p>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- ==================== MODAL: AGREGAR DIRECCIÓN ====================
                     Sirve a envio (domicilio) y a facturacion (recogida). Guardada = RT-04
                     (CustomerAddress); sin guardar = solo el CartAddress de esta venta. El cuerpo
                     scrollea por dentro y el pie queda visible (movil con teclado abierto). -->
                <x-admin::modal ref="modalDireccion">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            @{{ formDireccion.editarId
                                ? (formDireccion.para === 'facturacion' ? 'Editar dirección de facturación' : 'Editar dirección')
                                : (formDireccion.para === 'facturacion' ? 'Agregar dirección de facturación' : 'Agregar dirección') }}
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="grid max-h-[60vh] grid-cols-1 gap-3 overflow-y-auto pb-1 sm:grid-cols-2">
                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Nombre *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" autocomplete="given-name" v-model="formDireccion.datos.nombre" />
                            </div>

                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Apellido *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" autocomplete="family-name" v-model="formDireccion.datos.apellido" />
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-sm text-gray-600 dark:text-gray-300">Teléfono *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" inputmode="tel" autocomplete="tel" v-model="formDireccion.datos.telefono" />
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-sm text-gray-600 dark:text-gray-300">Dirección *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" placeholder="Calle, número, barrio, apto…" autocomplete="street-address" v-model="formDireccion.datos.linea" />
                            </div>

                            <!-- D-58: UN solo autocomplete «Departamento — Municipio» (catalogo DIVIPOLA local).
                                 Escribir no equivale a seleccionar: solo vale una opcion de la lista, de la que sale
                                 el CODIGO del municipio; el backend resuelve ciudad y estado. La lista va EN FLUJO
                                 (no flotante) con scroll propio para que no quede tapada por el teclado del celular. -->
                            <div class="sm:col-span-2">
                                <label class="text-sm text-gray-600 dark:text-gray-300">Ciudad / municipio *</label>

                                <input
                                    type="text"
                                    class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900"
                                    :class="formDireccion.datos.ubicacion ? 'border-green-500' : ''"
                                    placeholder="Escribe: Pes, Tun, Buc, Boy…"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    enterkeyhint="search"
                                    role="combobox"
                                    :aria-expanded="ubicacionBusqueda.abierta ? 'true' : 'false'"
                                    :value="ubicacionBusqueda.texto"
                                    @input="alEscribirUbicacion"
                                    @keydown.down.prevent="moverUbicacion(1)"
                                    @keydown.up.prevent="moverUbicacion(-1)"
                                    @keydown.enter.prevent="elegirUbicacionResaltada"
                                    @keydown.esc="ubicacionBusqueda.abierta = false"
                                />

                                <ul
                                    v-if="ubicacionBusqueda.abierta && ubicacionBusqueda.resultados.length"
                                    class="mt-1 max-h-48 divide-y overflow-y-auto overscroll-contain rounded-lg border bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900"
                                    role="listbox"
                                >
                                    <li
                                        v-for="(u, i) in ubicacionBusqueda.resultados"
                                        :key="u.municipio_codigo"
                                        role="option"
                                        class="cursor-pointer px-3 py-2.5 text-sm text-gray-800 dark:text-gray-100"
                                        :class="i === ubicacionBusqueda.resaltado ? 'bg-blue-50 dark:bg-blue-950' : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
                                        @click="elegirUbicacion(u)"
                                    >
                                        @{{ u.etiqueta }}
                                    </li>
                                </ul>

                                <p v-else-if="ubicacionBusqueda.buscando" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Buscando…</p>

                                <p
                                    v-else-if="ubicacionBusqueda.abierta && ubicacionBusqueda.texto.trim().length >= 2 && ! formDireccion.datos.ubicacion"
                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    Sin resultados. Prueba con otra parte del nombre.
                                </p>

                                <!-- Solo informativo: el departamento ya lo resolvio la seleccion, no es otro selector. -->
                                <p v-if="formDireccion.datos.ubicacion" class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                    Departamento: <span class="font-semibold">@{{ formDireccion.datos.ubicacion.departamento }}</span>
                                </p>

                                <p
                                    v-else-if="ubicacionBusqueda.texto.trim().length >= 2 && ! ubicacionBusqueda.abierta && ! ubicacionBusqueda.buscando"
                                    class="mt-1 text-xs text-amber-700 dark:text-amber-300"
                                >
                                    Selecciona una ciudad o municipio de la lista.
                                </p>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-sm text-gray-600 dark:text-gray-300">
                                    Código postal @{{ formDireccion.guardar && postcodeObligatorio ? '*' : '(opcional)' }}
                                </label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" inputmode="numeric" autocomplete="postal-code" v-model="formDireccion.datos.codigo_postal" />
                            </div>

                            <p v-if="formDireccion.editarId" class="text-xs text-gray-500 sm:col-span-2 dark:text-gray-400">
                                Se corrige la dirección guardada del cliente. Los pedidos ya creados conservan la dirección con la que se hicieron.
                            </p>

                            <label v-else-if="! modoCliente" class="flex items-start gap-2 text-sm text-gray-700 sm:col-span-2 dark:text-gray-200">
                                <input type="checkbox" class="mt-0.5 h-4 w-4 accent-blue-600" v-model="formDireccion.guardar" />
                                <span>
                                    Guardar en las direcciones del cliente
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">Si no la guardas, se usa solo en esta venta. El correo no es obligatorio.</span>
                                </span>
                            </label>
                        </div>

                        <p v-if="formDireccion.error" class="mt-2 text-sm text-red-600 dark:text-red-300">@{{ formDireccion.error }}</p>
                    </x-slot:content>

                    <x-slot:footer>
                        <div class="flex w-full flex-wrap justify-end gap-2">
                            <button type="button" class="transparent-button" @click="cerrarFormDireccion">Cancelar</button>
                            <button type="button" class="primary-button" :disabled="cargando.guardarDireccion" @click="guardarDireccionNueva">
                                @{{ cargando.guardarDireccion ? 'Guardando…' : (formDireccion.editarId ? 'Guardar cambios' : 'Guardar dirección') }}
                            </button>
                        </div>
                    </x-slot:footer>
                </x-admin::modal>

                <!-- ==================== MODAL: CREAR CLIENTE ==================== -->
                <!-- Cliente: pedido recien confirmado, en modal (el mismo detalle que «Mis pedidos»). -->
                <x-admin::modal ref="modalPedidoConfirmado">
                    <x-slot:header>
                        <p class="text-lg font-bold text-green-700 dark:text-green-300" data-modal-pedido-confirmado>
                            ¡Pedido #@{{ pedidoConfirmado ? pedidoConfirmado.numero : (confirmacionCliente ? confirmacionCliente.numero : '') }} confirmado!
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div v-if="pedidoConfirmado" class="grid gap-3 text-sm">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <div class="rounded-lg border p-2.5 dark:border-gray-800">
                                    <p class="text-[11px] font-semibold uppercase text-gray-500">Entrega</p>
                                    <p class="font-semibold text-gray-800 dark:text-white">@{{ pedidoConfirmado.entrega }}</p>
                                </div>
                                <div class="rounded-lg border p-2.5 dark:border-gray-800">
                                    <p class="text-[11px] font-semibold uppercase text-gray-500">Pago</p>
                                    <p class="font-semibold text-gray-800 dark:text-white">@{{ pedidoConfirmado.estado_pago }} · Transferencia</p>
                                </div>
                                <div v-if="pedidoConfirmado.metodo_envio" class="rounded-lg border p-2.5 dark:border-gray-800">
                                    <p class="text-[11px] font-semibold uppercase text-gray-500">Método de envío</p>
                                    <p class="font-semibold text-gray-800 dark:text-white">@{{ pedidoConfirmado.metodo_envio }}</p>
                                </div>
                                <div v-if="pedidoConfirmado.destino" class="rounded-lg border p-2.5 dark:border-gray-800">
                                    <p class="text-[11px] font-semibold uppercase text-gray-500">Dirección de entrega</p>
                                    <p class="font-semibold text-gray-800 dark:text-white">@{{ pedidoConfirmado.destino }}</p>
                                </div>
                            </div>

                            <div class="max-h-56 overflow-y-auto rounded-lg border dark:border-gray-800">
                                <div v-for="(l, i) in pedidoConfirmado.prendas" :key="i" class="flex items-center justify-between gap-2 border-b px-3 py-1.5 last:border-b-0 dark:border-gray-800">
                                    <span class="min-w-0">@{{ l.nombre }} · @{{ l.color }} · @{{ l.talla }} × @{{ l.cantidad }}</span>
                                    <span class="whitespace-nowrap font-semibold">@{{ precio(l.total) }}</span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-base font-extrabold text-gray-900 dark:text-white">
                                <span>Total</span><span>@{{ precio(pedidoConfirmado.total) }}</span>
                            </div>

                            <p class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                                Te avisaremos cuando esté listo. El pago se confirma cuando recibamos tu transferencia.
                            </p>
                        </div>
                        <p v-else class="text-sm text-gray-600 dark:text-gray-300">
                            Total @{{ confirmacionCliente ? precio(confirmacionCliente.total) : '' }}. Te avisaremos cuando esté listo.
                        </p>
                    </x-slot:content>

                    <x-slot:footer>
                        <div class="flex w-full flex-wrap justify-end gap-2">
                            <button type="button" class="secondary-button" @click="otroPedido">Hacer otro pedido</button>
                            <a href="{{ route('admin.autoservicio.pedidos.index') }}" class="primary-button">Ver mis pedidos</a>
                        </div>
                    </x-slot:footer>
                </x-admin::modal>
                <x-admin::modal ref="modalCrearCliente">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">+ Crear cliente</p>
                    </x-slot>

                    <x-slot:content>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Nombre *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900" v-model="crearClienteForm.nombre" />
                            </div>

                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Apellido *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900" v-model="crearClienteForm.apellido" />
                            </div>

                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Teléfono *</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900" v-model="crearClienteForm.telefono" />
                            </div>

                            <div>
                                <label class="text-sm text-gray-600 dark:text-gray-300">Grupo comercial *</label>

                                <select class="mt-1 w-full rounded-lg border px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900" v-model="crearClienteForm.customer_group_id">
                                    <option :value="null">Seleccionar…</option>
                                    <option
                                        v-for="grupo in grupos"
                                        :key="grupo.id"
                                        :value="grupo.id"
                                    >
                                        @{{ grupo.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="col-span-2">
                                <label class="text-sm text-gray-600 dark:text-gray-300">Email @{{ crearClienteForm.habilitar_acceso ? '*' : '(opcional)' }}</label>
                                <input class="mt-1 w-full rounded-lg border px-3 py-1.5 dark:border-gray-800 dark:bg-gray-900" v-model="crearClienteForm.email" />
                            </div>

                            <!-- Doc. 33 P1: solo quien puede administrar accesos. El usuario nace con contrasena propia (enlace por correo). -->
                            <label v-if="puedeAdministrarAcceso" class="col-span-2 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200" data-habilitar-acceso>
                                <input type="checkbox" class="mt-1" v-model="crearClienteForm.habilitar_acceso" />
                                <span>Habilitar acceso para realizar pedidos<span class="block text-xs text-gray-500">Se crea su usuario y se le envía un enlace para definir su contraseña.</span></span>
                            </label>
                        </div>

                        <p
                            v-if="errorCrearCliente"
                            class="mt-2 text-sm text-red-600 dark:text-red-300"
                        >
                            @{{ errorCrearCliente }}
                        </p>
                    </x-slot:content>

                    <x-slot:footer>
                        <button
                            type="button"
                            class="primary-button"
                            :disabled="cargando.crearCliente"
                            @click="crearClienteYContinuar"
                        >
                            Crear y continuar
                        </button>
                    </x-slot:footer>
                </x-admin::modal>

                <!-- ==================== MODAL: TOTALES CAMBIARON ==================== -->
                <x-admin::modal ref="modalTotalesCambiaron">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">El total de la venta cambió</p>
                    </x-slot>

                    <x-slot:content>
                        <div
                            v-if="totalesCambiaron"
                            class="grid gap-1 text-sm"
                        >
                            <div class="flex justify-between">
                                <span>Total anterior</span>
                                <span class="font-medium">@{{ precio(totalesCambiaron.total_esperado) }}</span>
                            </div>

                            <div class="flex justify-between">
                                <span>Total actualizado</span>
                                <span class="font-bold text-gray-800 dark:text-white">@{{ precio(totalesCambiaron.total_nuevo) }}</span>
                            </div>
                        </div>
                    </x-slot:content>

                    <x-slot:footer>
                        <button
                            type="button"
                            class="primary-button"
                            @click="aceptarNuevoTotal"
                        >
                            ACEPTAR NUEVO TOTAL Y CONTINUAR
                        </button>
                    </x-slot:footer>
                </x-admin::modal>

                <!-- ==================== MODAL: CAMBIAR / RESTAURAR GRUPO DE ESTA VENTA (GRP-01) ==================== -->
                <x-admin::modal ref="modalGrupo">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            @{{ modoGrupo === 'restaurar' ? 'Restaurar grupo habitual' : 'Cambiar grupo para esta venta' }}
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div
                            v-if="estado"
                            class="grid gap-3 text-sm"
                        >
                            <dl class="grid grid-cols-3 gap-3 rounded-lg bg-gray-50 p-3 max-sm:grid-cols-1 dark:bg-gray-900">
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase text-gray-500 dark:text-gray-400">Cliente</dt>
                                    <dd class="font-medium text-gray-800 dark:text-white">@{{ estado.borrador.cliente.nombre }} @{{ estado.borrador.cliente.apellido }}</dd>
                                </div>

                                <div>
                                    <dt class="text-[11px] font-semibold uppercase text-gray-500 dark:text-gray-400">Grupo habitual</dt>
                                    <dd class="text-gray-800 dark:text-white">@{{ (grupoVenta && grupoVenta.habitual_nombre) || '—' }}</dd>
                                </div>

                                <div>
                                    <dt class="text-[11px] font-semibold uppercase text-gray-500 dark:text-gray-400">Grupo aplicado actualmente</dt>
                                    <dd class="text-gray-800 dark:text-white">@{{ grupoAplicadoNombre || '—' }}</dd>
                                </div>
                            </dl>

                            <div v-if="modoGrupo === 'cambiar'">
                                <label class="mb-1 block text-xs font-semibold uppercase text-gray-600 dark:text-gray-300">Nuevo grupo para esta venta</label>

                                <select
                                    v-model="grupoForm.grupo_id"
                                    class="w-full rounded-md border px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    data-grupo-select
                                >
                                    <option value="">Selecciona un grupo</option>
                                    <option
                                        v-for="g in gruposDisponibles"
                                        :key="g.id"
                                        :value="g.id"
                                    >@{{ g.name }}</option>
                                </select>
                            </div>

                            <p
                                v-else
                                class="text-gray-700 dark:text-gray-200"
                            >
                                La venta volverá al grupo habitual del cliente: <strong>@{{ (grupoVenta && grupoVenta.habitual_nombre) || '—' }}</strong>.
                            </p>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase text-gray-600 dark:text-gray-300">Motivo del cambio *</label>

                                <textarea
                                    v-model="grupoForm.motivo"
                                    rows="3"
                                    maxlength="500"
                                    class="w-full rounded-md border px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                    placeholder="Ej. Negociación especial por compra de 500 unidades"
                                    data-grupo-motivo
                                ></textarea>
                            </div>

                            <p class="rounded-lg bg-blue-50 p-2.5 text-xs text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                                ⓘ Este cambio aplica únicamente a esta venta. El grupo permanente del cliente no será modificado.
                            </p>

                            <p
                                v-if="hayLineasVenta"
                                class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                            >
                                Al aplicar otro grupo se recalcularán los precios de todos los productos de esta venta.
                            </p>

                            <p
                                v-if="errorGrupo"
                                class="text-xs text-red-600 dark:text-red-400"
                                data-grupo-error
                            >@{{ errorGrupo }}</p>
                        </div>
                    </x-slot:content>

                    <x-slot:footer>
                        <div class="flex items-center gap-2.5">
                            <button
                                type="button"
                                class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                                :disabled="cargando.grupo"
                                @click="cerrarModalGrupo"
                            >
                                Cancelar
                            </button>

                            <button
                                type="button"
                                class="primary-button"
                                :disabled="cargando.grupo || ! grupoFormValido"
                                data-grupo-aplicar
                                @click="aplicarGrupo"
                            >
                                @{{ cargando.grupo ? 'Aplicando…' : (modoGrupo === 'restaurar' ? 'Restaurar y recalcular' : (hayLineasVenta ? 'Aplicar grupo y recalcular' : 'Aplicar grupo')) }}
                            </button>
                        </div>
                    </x-slot:footer>
                </x-admin::modal>

                <!-- ==================== MODAL: HISTORIAL DE GRUPO (GRP-01) ==================== -->
                <x-admin::modal ref="modalHistorialGrupo">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">Historial de grupo de esta venta</p>
                    </x-slot>

                    <x-slot:content>
                        <p
                            v-if="cargando.historialGrupo"
                            class="text-sm text-gray-500 dark:text-gray-400"
                        >Cargando…</p>

                        <p
                            v-else-if="errorHistorialGrupo"
                            class="text-sm text-red-600 dark:text-red-400"
                        >@{{ errorHistorialGrupo }}</p>

                        <p
                            v-else-if="! historialGrupo.length"
                            class="text-sm text-gray-500 dark:text-gray-400"
                        >Esta venta no tiene cambios de grupo.</p>

                        <div
                            v-else
                            class="grid gap-2"
                        >
                            <div
                                v-for="e in historialGrupo"
                                :key="e.id"
                                class="rounded-lg border p-3 text-sm dark:border-gray-800"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-semibold text-gray-800 dark:text-white">
                                        @{{ e.grupo_anterior || '—' }} → @{{ e.grupo_nuevo || (e.evento === 'override_cancelado_cambio_cliente' ? 'Cancelado' : '—') }}
                                    </span>

                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        @{{ etiquetaEventoGrupo(e.evento) }}
                                    </span>
                                </div>

                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    @{{ formatoFechaGrupo(e.fecha) }} · @{{ e.usuario || 'Usuario no registrado' }}
                                </p>

                                <p class="mt-1 break-words text-gray-700 dark:text-gray-200">@{{ e.motivo }}</p>
                            </div>
                        </div>
                    </x-slot:content>
                </x-admin::modal>

                <!-- ==================== MODAL: VENTA CREADA ==================== -->
                <x-admin::modal ref="modalVentaCreada">
                    <x-slot:header>
                        <p class="text-lg font-bold text-green-700 dark:text-green-400">VENTA CREADA</p>
                    </x-slot>

                    <x-slot:content>
                        <div
                            v-if="confirmacion"
                            class="grid gap-1 text-sm"
                        >
                            <p>Pedido: <span class="font-bold">#@{{ confirmacion.increment_id }}</span></p>
                            <p>Total: <span class="font-bold">@{{ precio(confirmacion.total) }}</span></p>
                            <p>Pago: <span class="font-medium">@{{ confirmacion.estado_pago }}</span></p>
                        </div>

                        <!-- D10 V1: saldo a favor. El pedido YA existe; aplicar el credito es un paso aparte y explicito (nunca automatico). -->
                        <div
                            v-if="confirmacion && creditoCliente && creditoCliente.visible && creditoCliente.puede_aplicar && (creditoCliente.total_disponible > 0 || creditoAplicar.hecho)"
                            class="mt-4 rounded-md border border-indigo-200 p-3 text-sm dark:border-indigo-800"
                            data-credito-aplicar-nv
                        >
                            <p class="font-semibold text-indigo-800 dark:text-indigo-200">
                                Saldo a favor disponible: @{{ precio(creditoCliente.total_disponible) }}
                            </p>

                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                El pedido ya fue creado. Aplicar el saldo a favor es un paso aparte: solo descuenta lo que el cliente debe en este pedido; no es dinero recibido.
                            </p>

                            <template v-if="! creditoAplicar.hecho">
                                <label class="mt-2 block text-xs font-semibold text-gray-600 dark:text-gray-300" for="credito-nv-monto">Monto a aplicar (máx. @{{ precio(creditoMaximoAplicable) }})</label>
                                <input
                                    id="credito-nv-monto"
                                    v-model="creditoAplicar.monto"
                                    type="number"
                                    inputmode="decimal"
                                    min="1"
                                    step="0.01"
                                    :max="creditoMaximoAplicable"
                                    class="mt-1 block w-full rounded-md border px-3 py-2 text-base text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                >

                                <p class="mt-1 text-xs text-gray-700 dark:text-gray-200">
                                    Saldo pendiente después de aplicar: <span class="font-bold">@{{ precio(creditoSaldoDespues) }}</span>
                                </p>

                                <p v-if="creditoAplicar.error" class="mt-2 text-sm font-medium text-red-700 dark:text-red-300" role="alert">@{{ creditoAplicar.error }}</p>

                                <button
                                    type="button"
                                    class="secondary-button mt-2"
                                    :disabled="creditoAplicar.enviando"
                                    @click="aplicarCreditoAVentaCreada"
                                >
                                    @{{ creditoAplicar.enviando ? 'Aplicando…' : 'Aplicar saldo a favor' }}
                                </button>
                            </template>

                            <p v-else class="mt-2 font-semibold text-green-700 dark:text-green-400">@{{ creditoAplicar.hecho }}</p>
                        </div>
                    </x-slot:content>

                    <x-slot:footer>
                        <a
                            v-if="confirmacion"
                            :href="'{{ route('admin.ventas_asesor.pedidos.index') }}?pedido=' + confirmacion.order_id"
                            class="secondary-button"
                        >
                            Ver pedido
                        </a>

                        <button
                            type="button"
                            class="primary-button"
                            @click="reiniciar(); $refs.modalVentaCreada.close();"
                        >
                            Nueva venta
                        </button>
                    </x-slot:footer>
                </x-admin::modal>

                <!-- ==================== DRAWER: VENTAS EN CURSO ==================== -->
                <x-admin::drawer ref="drawerEnCurso">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">Ventas en curso</p>
                    </x-slot:header>

                    <x-slot:content>
                        <div
                            v-if="cargando.enCurso"
                            class="text-sm text-gray-500"
                        >
                            Cargando…
                        </div>

                        <div
                            v-else-if="! ventasEnCurso.length"
                            class="text-sm text-gray-500"
                        >
                            No tienes ventas en curso.
                        </div>

                        <div
                            v-else
                            class="grid gap-2"
                        >
                            <div
                                v-for="borrador in ventasEnCurso"
                                :key="borrador.id"
                                class="rounded-lg border p-3 dark:border-gray-800"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white">
                                            @{{ borrador.cliente ? (borrador.cliente.nombre + ' ' + borrador.cliente.apellido) : 'Sin cliente' }}
                                        </p>

                                        <p class="text-xs text-gray-500 dark:text-gray-300">
                                            #@{{ borrador.id }} · @{{ borrador.estado }} · @{{ borrador.totales ? precio(borrador.totales.grand_total) : '—' }}
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="secondary-button"
                                            @click="reanudar(borrador.id)"
                                        >
                                            Reanudar
                                        </button>

                                        <button
                                            type="button"
                                            title="Eliminar venta en curso"
                                            class="text-base text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400"
                                            @click="confirmarEliminarId = borrador.id"
                                        >🗑</button>
                                    </div>
                                </div>

                                <div
                                    v-if="confirmarEliminarId === borrador.id"
                                    class="mt-2 rounded-lg bg-red-50 p-2 text-sm dark:bg-red-950"
                                >
                                    <p class="mb-2 text-red-700 dark:text-red-300">
                                        ¿Eliminar esta venta en curso? Esta acción no se puede deshacer.
                                    </p>

                                    <div class="flex gap-2">
                                        <button type="button" class="transparent-button" @click="confirmarEliminarId = null">Cancelar</button>
                                        <button type="button" class="primary-button bg-red-600 hover:bg-red-700" @click="eliminarVentaEnCurso(borrador.id)">Eliminar venta</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-slot:content>
                </x-admin::drawer>

                <!-- ==================== ERROR GLOBAL ==================== -->
                <div
                    v-if="errorGeneral"
                    class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700 shadow-lg dark:border-red-800 dark:bg-red-950 dark:text-red-300"
                >
                    <div class="flex items-start justify-between gap-2">
                        <span>@{{ errorGeneral }}</span>

                        <span
                            class="cursor-pointer"
                            @click="errorGeneral = null"
                        >
                            ✕
                        </span>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-nueva-venta', {
                template: '#v-nueva-venta-template',

                data() {
                    return {
                        grupos: @json($grupos),
                        // [{codigo, nombre}] de country_states CO: Bagisto guarda el CODIGO en addresses.state.
                        departamentos: @json($departamentos),
                        // customer.address.requirements.postcode (configuracion dinamica de Bagisto).
                        postcodeObligatorio: @json($postcodeObligatorio),

                        // G-11 (COM-D-15): tipo de operacion explicito. El selector solo aparece con permiso; sin el,
                        // siempre venta normal (el servidor lo vuelve a validar).
                        puedeClasificar: @json($puedeClasificar),
                        tipoOperacion: 'venta_normal',

                        // GRP-01: grupo comercial TEMPORAL de la venta. La accion solo se ofrece con permiso (backend y ACL lo exigen).
                        puedeCambiarGrupo: @json($puedeCambiarGrupo),
                        modoGrupo: 'cambiar',

                        // R2 (Doc. 23): «Vender para» solo con `pedidos.assign` ('' = regla automatica, 'empresa' o admin_id),
                        // y la advertencia de rentabilidad del borrador (sin costos; el servidor vuelve a evaluar al confirmar).
                        puedeVenderPara: @json($puedeVenderPara),
                        puedeAdministrarAcceso: @json($puedeAdministrarAcceso ?? false),
                        asesoresVenta: @json($asesoresVenta),
                        venderPara: '',
                        rentabilidad: null,
                        grupoForm: { grupo_id: '', motivo: '' },
                        errorGrupo: null,
                        historialGrupo: [],
                        errorHistorialGrupo: null,

                        // Carrier de recogida de la extension bagisto-eu/pickup (PuntosRecogida::METODO).
                        metodoRecogida: @json(\ByLopez\Logistica\Support\PuntosRecogida::METODO),

                        // Titulo y descripcion REALES del carrier pickup (core_config), no texto propio.
                        recogida: @json($recogida),

                        direccionesCliente: [],
                        direccionesCargadas: false,
                        // CustomerAddress elegida como DESTINO (domicilio) y como facturacion (recogida sin predeterminada).
                        direccionSeleccionadaId: null,
                        direccionFacturacionId: null,
                        // Sube tras cada estado/error recibido: recrea los radios para que lo marcado == backend.
                        versionVista: 0,
                        // Direccion puntual de ESTA venta (no guardada en el cliente): solo CartAddress.
                        direccionPuntual: null,
                        formDireccion: {
                            abierto: false,
                            para: 'envio',
                            guardar: true,
                            error: null,
                            // id de la direccion guardada que se esta corrigiendo (null = agregar una nueva).
                            editarId: null,
                            // ubicacion: opcion elegida del autocomplete {municipio_codigo, municipio, departamento, etiqueta}.
                            // ciudad/departamento son solo para MOSTRAR la direccion puntual; el backend resuelve ambos del codigo.
                            datos: { nombre: '', apellido: '', telefono: '', linea: '', ciudad: '', departamento: '', codigo_postal: '', municipio_codigo: null, ubicacion: null },
                        },
                        // Autocomplete de ubicaciones (D-58): texto escrito, resultados del backend y fila resaltada.
                        ubicacionBusqueda: { texto: '', resultados: [], abierta: false, buscando: false, resaltado: -1 },
                        confirmarEliminarId: null,

                        canales: [],
                        accesosRapidos: [],

                        busquedaCliente: '',
                        resultadoBusqueda: null,
                        clienteActual: null,
                        canalSeleccionado: null,
                        // P7: identificador de ESTA venta (uno por intento). Hace idempotente la creacion del borrador.
                        referenciaVenta: null,
                        // Doc. 33 P3: modo cliente (autoservicio): sin buscador, grupos, rentabilidad, cotizaciones, entrega ni pago.
                        modoCliente: @json($modoCliente ?? false),
                        nombreCliente: null,
                        confirmacionCliente: null,
                        pedidoConfirmado: null,

                        borradorId: null,
                        estado: null,
                        entrega: null,
                        pago: null,

                        lineasSeleccionadas: [],
                        coloresPorLinea: {},
                        coloresSeleccionados: [],
                        coloresColapsados: false,
                        // Lo recien guardado { lineas, colores } (la seleccion se limpia al agregar): solo alimenta el resumen minimizado.
                        // Se descarta al cambiar de linea: describe SOLO la seleccion que se guardo.
                        guardadoReciente: null,
                        // Busqueda por nombre dentro del panel de colores (solo filtra lo que se VE; la seleccion no cambia).
                        busquedaColor: '',
                        // Error al cargar los colores de una linea: { claveLinea: mensaje }.
                        erroresColoresLinea: {},
                        // true SOLO mientras viaja la seleccion de pago (cargando.pago tambien cubre la carga de metodos).
                        guardandoPago: false,
                        // Resultado de agregar productos cuando algo fallo: { guardado: [], pendiente: [], verificar: bool }.
                        resultadoGuardado: null,
                        // Aviso de la ultima accion de la modal (validacion o fallo de quitar talla): se muestra junto al boton.
                        avisoAccion: null,
                        // Aviso (no error) cuando ninguna combinacion elegida tiene tallas disponibles.
                        avisoSinTallas: null,
                        // Animacion del boton «Ver tallas»: 'entrada' | 'pulso' | null (se apaga sola).
                        ctaAnimacion: null,
                        // Edicion de UN producto (linea x color) desde el resumen: { productId, etiqueta, etiquetaLinea }.
                        edicionUnica: null,
                        edicionCargando: null,
                        // Estado TEMPORAL de la modal de matrices: se llena en verTallas() y se
                        // descarta en alCerrarMatrices(). El carrito real vive en `estado`.
                        matricesPorProducto: {},
                        cantidadesPorProducto: {},
                        erroresPorVariante: {},
                        errorMatrices: null,
                        avisoModal: null,
                        detalleSenalAbierto: null,

                        // tipo: lo que el asesor ELIGIO (null = aun no). Lo APLICADO al carrito es tipoAplicado.
                        entregaForm: { tipo: null, metodo: null },

                        crearClienteForm: { nombre: '', apellido: '', telefono: '', customer_group_id: null, email: '', habilitar_acceso: false },
                        errorCrearCliente: null,

                        ventasEnCurso: [],
                        totalesCambiaron: null,
                        confirmacion: null,
                        errorGeneral: null,

                        // Cotizacion de ESTA venta (foto del servidor; el PDF se abre siempre desde ella).
                        cotizacion: null,
                        cotizando: false,
                        errorCotizacion: null,

                        // D10 V1: saldo a favor del cliente (solo informativo hasta que el administrador decide aplicarlo).
                        creditoCliente: null,
                        creditoAplicar: { monto: '', enviando: false, error: null, hecho: null, claves: {} },

                        // Doc. 34: pedido por voz (el navegador transcribe; el servidor interpreta y valida contra el catalogo).
                        dictado: { abierto: false, texto: '', escuchando: false, cargando: false, agregando: false, lineas: [], colores: [], tallas: {}, error: null, resultado: null },

                        cargando: {
                            cliente: false, colores: false, matrices: false, tallas: false, direccion: false,
                            entrega: false, pago: false, confirmar: false, domiciliario: false, crearCliente: false, enCurso: false,
                            guardarDireccion: false, estado: false, grupo: false, historialGrupo: false,
                        },
                    };
                },

                mounted() {
                    this.$refs.buscadorCliente?.focus();

                    this.$axios.get("{{ $rutaNv('estado_inicial') }}")
                        .then((response) => {
                            this.canales = response.data.canales || [];
                            this.canalSeleccionado = this.canales[0] || null;
                            this.accesosRapidos = response.data.accesos_rapidos;

                            if (this.modoCliente) {
                                this.nombreCliente = response.data.cliente?.nombre || '';
                                this.fijarClienteAutoservicio();
                            }
                        })
                        .catch((error) => this.manejarError(error));
                },

                computed: {
                    /** Doc. 34: lineas dictadas que ya tienen una variante exacta (ok o aviso). */
                    dictadoValidas() {
                        return this.dictado.lineas.filter((l) => ! l.omitida && l.variante_id && l.estado !== 'error');
                    },

                    /** Lineas con ❌ que aun no se corrigen ni se omiten: mientras haya, no se agrega nada. */
                    dictadoPendientes() {
                        return this.dictado.lineas.filter((l) => ! l.omitida && (! l.variante_id || l.estado === 'error')).length;
                    },

                    /** D10 V1: tope aplicable = menor entre el saldo a favor y lo que debe el pedido recien creado. */
                    creditoMaximoAplicable() {
                        const disponible = Number(this.creditoCliente?.total_disponible ?? 0);
                        const total = Number(this.confirmacion?.total ?? 0);

                        return Math.max(0, Math.min(disponible, total));
                    },

                    creditoSaldoDespues() {
                        const monto = Math.max(0, Number(this.creditoAplicar.monto) || 0);

                        return Math.max(0, Number(this.confirmacion?.total ?? 0) - monto);
                    },

                    /** GRP-01: grupo habitual vs aplicado, tal cual lo devuelve el backend (nunca se calcula aqui). */
                    grupoVenta() {
                        return this.estado?.borrador?.grupo || null;
                    },

                    grupoAplicadoNombre() {
                        return this.grupoVenta?.aplicado_nombre
                            || this.clienteActual?.grupo_nombre
                            || this.clienteActual?.grupoNombre
                            || null;
                    },

                    /** Todos los grupos comerciales (sin invitado) menos el que ya se aplica a la venta. */
                    gruposDisponibles() {
                        const aplicado = this.grupoVenta?.aplicado_id;

                        return this.grupos.filter((g) => g.id !== aplicado);
                    },

                    hayLineasVenta() {
                        return (this.estado?.lineas || []).length > 0;
                    },

                    grupoFormValido() {
                        return (this.modoGrupo === 'restaurar' || !! this.grupoForm.grupo_id)
                            && this.grupoForm.motivo.trim().length > 0;
                    },

                    /** Cierre F1 (D1): domiciliario sugerido por Logistica (ultimo usado en la zona), si sigue entre las opciones. */
                    /** Quien paga el domicilio, en lenguaje comercial (lo decide el metodo de envio ya elegido). */
                    notaDomicilio() {
                        const d = this.estado && this.estado.entrega ? this.estado.entrega.domiciliario : null;

                        return d && d.tarifa !== null && d.tarifa > 0 ? ({
                            gratis: 'Domicilio gratis para el cliente.',
                            por_pagar: 'El cliente paga este valor al recibir.',
                            cobrado_en_pedido: 'Este valor va incluido en el pedido.',
                        })[d.condicion] || null : null;
                    },

                    /** El elegido coincide con el ultimo usado en la zona (lo que el sistema sugeria). */
                    domiciliarioEsSugerido() {
                        const d = this.estado && this.estado.entrega ? this.estado.entrega.domiciliario : null;

                        return !! d && !! d.elegido_id && d.elegido_id === d.sugerido_id;
                    },

                    domiciliarioSugerido() {
                        const d = this.estado && this.estado.entrega ? this.estado.entrega.domiciliario : null;

                        return d && d.sugerido_id ? (d.opciones.find(o => o.id === d.sugerido_id) || null) : null;
                    },

                    /** Indicador de progreso (NO wizard): refleja el estado real del backend, no un paso forzado. */
                    pasos() {
                        if (! this.estado) {
                            return [];
                        }

                        const e = this.estado;
                        const entregaOk = this.entregaLista;
                        const pagoOk = !! (e.pago && e.pago.metodo_seleccionado);

                        return [
                            { clave: 'seccion-cliente', etiqueta: 'Cliente', estado: 'completo' },
                            { clave: 'seccion-productos', etiqueta: 'Productos', estado: e.lineas.length ? 'completo' : 'actual' },
                            { clave: 'seccion-entrega', etiqueta: 'Entrega', estado: entregaOk ? 'completo' : (e.lineas.length ? 'actual' : 'pendiente') },
                            { clave: 'seccion-pago', etiqueta: 'Pago', estado: pagoOk ? 'completo' : (entregaOk ? 'actual' : 'pendiente') },
                        ];
                    },

                    /** Tipo de entrega YA aplicado al carrito (fuente: el CartAddress de envio que devuelve el backend). */
                    tipoAplicado() {
                        const envio = this.estado?.entrega?.envio;

                        if (! envio) {
                            return null;
                        }

                        return envio.origen === 'punto_recogida' ? 'punto_recogida' : 'domicilio';
                    },

                    /**
                     * Entrega lista = completa en Bagisto (EstadoNuevaVenta) Y coincide con lo que el asesor
                     * eligio en pantalla. Evita confirmar una recogida previa mientras se elige domicilio.
                     */
                    entregaLista() {
                        const dom = this.estado?.entrega?.domiciliario;

                        // Entrega local: sin domiciliario valido (con tarifa en la zona) la entrega no esta lista.
                        return !! (this.estado?.entrega?.entrega_completa
                            && ! (dom && dom.requerido && ! dom.listo)
                            && this.entregaForm.tipo !== null
                            && this.entregaForm.tipo === this.tipoAplicado);
                    },

                    /** Solo un borrador `activo` se edita (BorradorVentaService); durante confirmar o ya confirmado, no. */
                    ventaEditable() {
                        return !! this.estado
                            && this.estado.borrador.estado === 'activo'
                            && ! this.cargando.confirmar
                            && ! this.confirmacion;
                    },

                    /** Cotizar pide cliente y al menos una linea; el servidor lo vuelve a exigir. */
                    puedeCotizar() {
                        return this.ventaEditable && !! this.estado?.borrador?.cliente && (this.estado?.lineas?.length || 0) > 0;
                    },

                    /** El total cotizado es el de los productos: gran total del carrito menos el envio (se cobra por aparte). */
                    totalProductosVenta() {
                        const t = this.estado?.borrador?.totales;

                        return t ? Number(t.grand_total) - Number(t.shipping_amount || 0) : null;
                    },

                    cotizacionDesactualizada() {
                        return !! this.cotizacion
                            && this.totalProductosVenta !== null
                            && Math.abs(Number(this.cotizacion.total) - this.totalProductosVenta) > 0.5;
                    },

                    /** Entrega en vuelo: tipo/metodo de envio, domiciliario o direccion. */
                    guardandoEntrega() {
                        const c = this.cargando;

                        return !! (c.entrega || c.domiciliario || c.guardarDireccion);
                    },

                    /**
                     * Lo que el servidor aun dice que falta, SIN lo que se esta guardando en este instante: mientras viaja la
                     * seleccion de pago o de entrega el dato no esta «faltante», esta «guardandose».
                     */
                    motivosPendientes() {
                        const motivos = this.estado?.motivos_no_confirmable || [];
                        const entrega = ['entrega_incompleta', 'entrega_requiere_revision', 'domiciliario_requerido', 'direccion_facturacion_requerida'];

                        return motivos.filter((m) => ! (this.guardandoPago && m === 'sin_metodo_pago') && ! (this.guardandoEntrega && entrega.includes(m)));
                    },

                    /** Una operacion en vuelo bloquea las demas: nada se intercala con la anterior. */
                    ocupado() {
                        const c = this.cargando;

                        return !! (c.entrega || c.pago || c.guardarDireccion || c.confirmar || c.tallas || c.estado || c.domiciliario);
                    },

                    /** Aviso segun el estado REAL del borrador (nunca se asume editable un borrador que no lo es). */
                    bannerBorrador() {
                        if (! this.estado) {
                            return null;
                        }

                        const b = this.estado.borrador;
                        const orderId = this.confirmacion?.order_id ?? b.orderId ?? null;
                        const pedido = this.confirmacion?.increment_id ? ' #' + this.confirmacion.increment_id : '';

                        if (this.confirmacion || b.estado === 'confirmado') {
                            return {
                                clase: 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200',
                                titulo: 'Esta venta ya fue confirmada',
                                detalle: 'El pedido' + pedido + ' ya se creó y no se puede editar desde aquí. Para vender de nuevo, inicia una nueva venta.',
                                orderId,
                            };
                        }

                        if (b.estado === 'confirmando' && ! this.cargando.confirmar) {
                            return {
                                clase: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
                                titulo: 'La confirmación de esta venta quedó en proceso',
                                detalle: 'Se intentó confirmar y no terminó, así que no se puede editar. Verifica el estado: si el pedido ya se creó aparecerá aquí; si no, puedes reintentar (no duplica pedidos).',
                                verificar: true,
                                reintentar: true,
                            };
                        }

                        if (b.estado === 'descartado') {
                            return {
                                clase: 'border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200',
                                titulo: 'Esta venta fue eliminada',
                                detalle: 'Ya no se puede editar. Inicia una nueva venta.',
                            };
                        }

                        return null;
                    },

                    unidadesTotales() {
                        return (this.estado?.lineas || []).reduce((suma, l) => suma + (l.cantidad || 0), 0);
                    },

                    /**
                     * Union de colores de TODAS las lineas seleccionadas (por color_option_id, o por
                     * etiqueta/nombre si el producto no tiene atributo de color). El backend sigue
                     * siendo quien decide que combinaciones linea+color existen de verdad: esto solo
                     * agrupa lo que ya devolvio /lineas/{clave}/colores para cada linea seleccionada.
                     */
                    coloresUnion() {
                        const vistos = new Map();

                        this.lineasSeleccionadas.forEach((linea) => {
                            (this.coloresPorLinea[linea.clave] || []).forEach((color) => {
                                const clave = String(color.colorOptionId ?? color.colorEtiqueta ?? color.nombre);

                                if (! vistos.has(clave)) {
                                    vistos.set(clave, { clave, etiqueta: color.colorEtiqueta || color.nombre, swatch: color.colorSwatch || null });
                                }
                            });
                        });

                        return Array.from(vistos.values());
                    },

                    /**
                     * Por cada color seleccionado, solo las lineas que SI tienen ese color (nunca se
                     * inventa una combinacion inexistente: si una linea no trae ese color en su propia
                     * respuesta de /colores, simplemente no aparece tarjeta para ella).
                     */
                    tarjetasPorColor() {
                        return this.coloresSeleccionados
                            .map((colorSel) => {
                                const tarjetas = [];

                                this.lineasSeleccionadas.forEach((linea) => {
                                    const encontrado = (this.coloresPorLinea[linea.clave] || [])
                                        .find((c) => String(c.colorOptionId ?? c.colorEtiqueta ?? c.nombre) === colorSel.clave);

                                    if (encontrado) {
                                        tarjetas.push({
                                            claveLinea: linea.clave,
                                            etiquetaLinea: linea.etiqueta,
                                            productId: encontrado.productId,
                                        });
                                    }
                                });

                                return { clave: colorSel.clave, etiqueta: colorSel.etiqueta, swatch: colorSel.swatch || null, tarjetas };
                            })
                            .filter((grupo) => grupo.tarjetas.length);
                    },

                    /** Colores que muestra el resumen minimizado: la seleccion actual o, tras guardar tallas, los recien guardados. */
                    etiquetasColoresResumen() {
                        return this.tarjetasPorColor.length
                            ? this.tarjetasPorColor.map((g) => g.etiqueta)
                            : (this.guardadoReciente ? this.guardadoReciente.colores : []);
                    },

                    /** Lineas activas del panel («Camiseta caballero, Top dama»): el titulo de Colores siempre dice de que producto son. */
                    etiquetasLineasActivas() {
                        return this.lineasSeleccionadas.map((l) => l.etiqueta).join(', ');
                    },

                    /**
                     * Resumen del panel minimizado. Distingue lo ELEGIDO para agregar (aun no esta en la venta) de lo YA GUARDADO.
                     * Nunca mezcla: si no hay colores elegidos solo muestra lo guardado de la seleccion de lineas que se guardo.
                     */
                    resumenColores() {
                        if (this.tarjetasPorColor.length) {
                            const n = this.tarjetasPorColor.length;

                            return { tipo: 'elegidos', texto: n + (n === 1 ? ' color elegido' : ' colores elegidos') + ' para ' + this.etiquetasLineasActivas + ' · ' + this.tarjetasPorColor.map((g) => g.etiqueta).join(', ') };
                        }

                        if (this.guardadoReciente && this.guardadoReciente.colores.length) {
                            const n = this.guardadoReciente.colores.length;

                            return { tipo: 'guardados', texto: n + (n === 1 ? ' color' : ' colores') + ' de ' + this.guardadoReciente.lineas.join(', ') + ' · ' + this.guardadoReciente.colores.join(', ') };
                        }

                        return null;
                    },

                    /** Instruccion junto a «Ver tallas» cuando no hay colores elegidos (coherente con lo ya guardado). */
                    ayudaColores() {
                        if (this.guardadoReciente && this.guardadoReciente.colores.length) {
                            return 'Ya agregaste colores de ' + this.guardadoReciente.lineas.join(', ') + '. Elige más colores para seguir agregando.';
                        }

                        return 'Elige uno o más colores de ' + this.etiquetasLineasActivas + '.';
                    },

                    /** Busqueda solo cuando la lista es larga (con pocos colores recorrerla es mas rapido). */
                    mostrarBusquedaColor() {
                        return this.coloresUnion.length >= 10;
                    },

                    coloresFiltrados() {
                        const q = this.normalizarTexto(this.busquedaColor);

                        return q ? this.coloresUnion.filter((c) => this.normalizarTexto(c.etiqueta).includes(q)) : this.coloresUnion;
                    },

                    /** Colores elegidos que el filtro esconde: siguen elegidos; se avisa para que no sorprenda al pulsar «Ver tallas». */
                    coloresSeleccionadosOcultos() {
                        const visibles = new Set(this.coloresFiltrados.map((c) => c.clave));

                        return this.coloresSeleccionados.filter((c) => ! visibles.has(c.clave)).length;
                    },

                    hayErroresColores() {
                        return Object.keys(this.erroresColoresLinea).length > 0;
                    },

                    textoCtaTallas() {
                        const n = this.tarjetasPorColor.length;

                        return n ? 'Continuar con tallas (' + n + ' ' + (n === 1 ? 'color' : 'colores') + ')' : 'Ver tallas';
                    },

                    /** Lo que muestra la modal: la seleccion de colores o, si se edita desde el resumen, SOLO ese producto. */
                    gruposModal() {
                        if (! this.edicionUnica) {
                            // Una combinacion sin disponibilidad se excluyo en el servidor: no aparece (ni su cabecera).
                            return this.tarjetasPorColor
                                .map((g) => ({ ...g, tarjetas: g.tarjetas.filter((t) => this.matricesPorProducto[t.productId]) }))
                                .filter((g) => g.tarjetas.length);
                        }

                        const e = this.edicionUnica;

                        return [{ clave: 'edicion-' + e.productId, etiqueta: e.etiqueta, swatch: e.swatch || null, tarjetas: [{ claveLinea: null, etiquetaLinea: e.etiquetaLinea, productId: e.productId }] }];
                    },

                    /**
                     * Resumen agrupado por producto (linea x color = productoId del carrito), en el orden de la venta.
                     * Titulo «Color · Linea» cuando la linea se conoce en esta pantalla; si no, el nombre del producto.
                     */
                    productosResumen() {
                        const grupos = new Map();

                        (this.estado?.lineas || []).forEach((linea) => {
                            if (! grupos.has(linea.productoId)) {
                                const color = linea.opciones?.color?.etiqueta || null;
                                const lineaVenta = this.etiquetaLineaDe(linea.productoId);

                                grupos.set(linea.productoId, {
                                    productoId: linea.productoId,
                                    colorEtiqueta: color,
                                    lineaEtiqueta: lineaVenta,
                                    titulo: color ? (lineaVenta ? color + ' · ' + lineaVenta : color) : linea.nombre,
                                    subtitulo: color && ! lineaVenta ? linea.nombre : null,
                                    lineas: [],
                                });
                            }

                            grupos.get(linea.productoId).lineas.push(linea);
                        });

                        return Array.from(grupos.values());
                    },
                },

                watch: {
                    // «Ver tallas»: entrada al elegir el primer color; micro-pulso cuando la seleccion cambia.
                    'tarjetasPorColor.length'(actual, anterior) {
                        if (actual > 0 && ! anterior) {
                            this.animarCta('entrada');
                        } else if (actual > 0 && actual !== anterior) {
                            this.animarCta('pulso');
                        }
                    },

                    // Mismo patron que v-customer-search de Bagisto (sales/orders/index.blade.php):
                    // v-model.lazy + v-debounce + watch que dispara la busqueda solo, sin Enter.
                    busquedaCliente() {
                        this.buscarCliente();
                    },

                    clienteActual(nuevo) {
                        this.cargarCreditoCliente(nuevo ? (nuevo.customer_id ?? nuevo.customerId) : null);
                    },

                    // R2: cada cambio del estado (cliente, grupo, cantidades, entrega, pago) o del propietario reevalua.
                    estado() {
                        this.cargarRentabilidad();
                    },

                    venderPara() {
                        this.cargarRentabilidad();
                    },

                    tipoOperacion() {
                        this.cargarRentabilidad();
                    },

                    // Las matrices YA NO se cargan solas al marcar un color: las pide verTallas()
                    // (boton "Ver tallas") y solo entonces se abre la modal lateral.
                },

                methods: {
                    /**
                     * Doc. 34: dictar con el reconocimiento de voz del navegador (Chrome, Edge, Android; Safari en iPhone con el
                     * dictado activado). Sin soporte se explica que se use el microfono del teclado sobre el mismo cuadro.
                     */
                    dictadoEscuchar() {
                        const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;

                        if (this.dictado.escuchando) {
                            this._reconocimiento && this._reconocimiento.stop();

                            return;
                        }

                        if (! Reconocimiento) {
                            this.dictado.error = 'Este navegador no reconoce la voz. Toca el cuadro de texto y usa el micrófono del teclado del celular.';

                            return;
                        }

                        const r = new Reconocimiento();
                        const base = this.dictado.texto.trim() ? this.dictado.texto.trim() + ', ' : '';

                        r.lang = 'es-CO';
                        r.continuous = true;
                        r.interimResults = true;
                        r.onresult = (evento) => {
                            let finales = '';
                            let parcial = '';

                            for (let i = 0; i < evento.results.length; i++) {
                                const trozo = evento.results[i][0].transcript.trim();

                                if (evento.results[i].isFinal) {
                                    finales += trozo + ', ';
                                } else {
                                    parcial += trozo + ' ';
                                }
                            }

                            this.dictado.texto = (base + finales + parcial).trim();
                        };
                        r.onerror = (evento) => {
                            this.dictado.error = evento.error === 'not-allowed' || evento.error === 'service-not-allowed'
                                ? 'Permite el uso del micrófono para este sitio en el navegador.'
                                : (evento.error === 'no-speech' ? 'No se escuchó nada. Intenta de nuevo.' : 'No se pudo escuchar (' + evento.error + ').');
                        };
                        r.onend = () => { this.dictado.escuchando = false; };

                        this._reconocimiento = r;
                        this.dictado.error = null;
                        this.dictado.escuchando = true;
                        r.start();
                    },

                    dictadoInterpretar() {
                        if (this.dictado.escuchando && this._reconocimiento) {
                            this._reconocimiento.stop();
                        }

                        this.dictado.cargando = true;
                        this.dictado.error = null;
                        this.dictado.resultado = null;

                        this.$axios.post("{{ $rutaNv('dictado.interpretar') }}", { texto: this.dictado.texto })
                            .then((response) => {
                                this.dictado.colores = response.data.colores || [];
                                this.dictado.tallas = response.data.tallas || {};
                                this.dictado.lineas = (response.data.lineas || []).map((l) => this.dictadoPreparar(l));

                                // Lo que no se pudo resolver abre de una vez los selectores para corregirlo (o se omite).
                                this.dictado.lineas.filter((l) => l.estado === 'error').forEach((l) => this.dictadoEditar(l));

                                if (! this.dictado.lineas.length) {
                                    this.dictado.error = 'No se entendió ninguna prenda. Ejemplo: «roja talla M de dama, 10 unidades».';
                                }
                            })
                            .catch((error) => {
                                this.dictado.error = error.response?.data?.message || error.response?.data?.mensaje || 'No se pudo interpretar el pedido.';
                            })
                            .finally(() => { this.dictado.cargando = false; });
                    },

                    /** Campos de pantalla de una linea dictada (omitir / corregir con selectores). */
                    dictadoPreparar(l, anterior = null) {
                        return {
                            ...l,
                            texto: l.texto || anterior?.texto || '',
                            omitida: false,
                            editando: false,
                            validando: false,
                            errorEdicion: null,
                            form: anterior?.form || { color: '', tipo: 'D', talla: '', cantidad: null },
                        };
                    },

                    /** Tallas que ofrece el selector segun el tipo (codigo del SKU; la etiqueta viene del servidor). */
                    dictadoTallasDe(tipo) {
                        return { D: ['S', 'M', 'L', 'XL'], H: ['S', 'M', 'L', 'XL'], N: ['2', '6', '10', '14'], TP: ['U'] }[tipo] || [];
                    },

                    dictadoAjustarTalla(l) {
                        const tallas = this.dictadoTallasDe(l.form.tipo);

                        if (! tallas.includes(l.form.talla)) {
                            l.form.talla = tallas.length === 1 ? tallas[0] : '';
                        }
                    },

                    /** Abre los selectores con lo que se entendio (el color unico, o vacio si fue ambiguo). */
                    dictadoEditar(l) {
                        const tipo = l.linea === 'TP' ? 'TP' : (l.genero || (l.talla && /^\d+$/.test(l.talla) ? 'N' : 'D'));

                        l.form = { color: l.color || '', tipo, talla: l.talla || '', cantidad: l.cantidad ?? null };
                        this.dictadoAjustarTalla(l);
                        l.errorEdicion = null;
                        l.editando = true;
                    },

                    /** Valida la linea corregida contra el catalogo (mismo resolutor del dictado) y la reemplaza. */
                    dictadoValidarCorreccion(i) {
                        const l = this.dictado.lineas[i];
                        const f = l.form;

                        if (! f.color || ! f.talla || ! (Number(f.cantidad) >= 1)) {
                            l.errorEdicion = 'Elige color, talla y una cantidad mayor que cero.';

                            return;
                        }

                        l.validando = true;
                        l.errorEdicion = null;

                        this.$axios.post("{{ $rutaNv('dictado.corregir') }}", {
                            texto: l.texto,
                            color: f.color,
                            genero: f.tipo === 'TP' ? null : f.tipo,
                            linea: f.tipo === 'TP' ? 'TP' : 'DZ',
                            talla: f.talla,
                            cantidad: Number(f.cantidad),
                        })
                            .then((response) => {
                                const nueva = this.dictadoPreparar(response.data.linea, l);

                                // Si la combinacion no existe se deja abierta para seguir corrigiendo, con el motivo.
                                if (nueva.estado === 'error') {
                                    nueva.editando = true;
                                    nueva.errorEdicion = nueva.mensaje;
                                }

                                this.dictado.lineas.splice(i, 1, nueva);
                            })
                            .catch((error) => {
                                l.errorEdicion = error.response?.data?.message || error.response?.data?.mensaje || 'No se pudo validar la línea.';
                            })
                            .finally(() => { l.validando = false; });
                    },

                    dictadoLimpiar() {
                        this.dictado.texto = '';
                        this.dictado.lineas = [];
                        this.dictado.error = null;
                        this.dictado.resultado = null;
                    },

                    /**
                     * Agrega las lineas validas con el MISMO «aplicar tallas» de la matriz: un lote por producto (linea x color), en
                     * secuencia (el primero crea la venta si aun no existe). aplicarTallas recibe la cantidad FINAL por talla: se suma lo
                     * que la venta ya tenia de esa talla + lo dictado (y lo repetido en el dictado).
                     */
                    dictadoAgregar() {
                        const porProducto = {};

                        this.dictadoValidas.forEach((l) => {
                            porProducto[l.producto_id] = porProducto[l.producto_id] || {};
                            porProducto[l.producto_id][l.variante_id] = (porProducto[l.producto_id][l.variante_id] || 0) + Number(l.cantidad);
                        });

                        const enVenta = (varianteId) => {
                            const linea = (this.estado?.lineas || []).find((x) => x.varianteId === varianteId);

                            return linea ? Number(linea.cantidad) : 0;
                        };

                        const lotes = Object.entries(porProducto).map(([productId, cantidades]) => ({
                            productId: Number(productId),
                            nombre: (this.dictadoValidas.find((l) => l.producto_id === Number(productId)) || {}).nombre,
                            cantidades: Object.fromEntries(Object.entries(cantidades).map(([vid, c]) => [vid, c + enVenta(Number(vid))])),
                        }));

                        const resultados = [];

                        this.dictado.agregando = true;
                        this.dictado.resultado = null;

                        lotes
                            .reduce((cadena, lote) => cadena.then(() => this.aplicarCantidadesProducto(lote.productId, lote.cantidades, { sincronizar: false })
                                .then((r) => { resultados.push({ nombre: lote.nombre, ...r }); })), Promise.resolve())
                            .then(() => {
                                const fallidos = resultados.filter((r) => ! r.ok);

                                if (! fallidos.length) {
                                    this.$emitter.emit('add-flash', { type: 'success', message: 'Pedido dictado agregado a la venta. Para cambiar una cantidad, toca el lápiz del producto en el resumen.' });
                                    this.dictadoLimpiar();

                                    return;
                                }

                                this.dictado.resultado = {
                                    guardados: resultados.filter((r) => r.ok).map((r) => r.nombre),
                                    pendientes: fallidos.map((r) => ({ nombre: r.nombre, mensaje: r.mensaje })),
                                };
                            })
                            .finally(() => { this.dictado.agregando = false; });
                    },

                    /** D10 V1: saldo a favor del cliente. Sin permiso responde visible:false (o es refusado) y simplemente no se ofrece. */
                    cargarCreditoCliente(customerId) {
                        this.creditoCliente = null;
                        this.creditoAplicar = { monto: '', enviando: false, error: null, hecho: null, claves: {} };

                        if (! customerId) {
                            return;
                        }

                        this.$axios.get("{{ url(route('admin.ventas_asesor.cartera.credito.cliente', ['cliente' => '__ID__'])) }}".replace('__ID__', customerId))
                            .then((response) => { this.creditoCliente = response.data; })
                            .catch(() => { this.creditoCliente = null; });
                    },

                    /**
                     * Aplica el saldo a favor al pedido YA creado, tomando de los pedidos origen del mas antiguo al mas nuevo. Cada origen usa su
                     * propia clave de idempotencia (estable mientras se reintenta con el mismo monto). Si uno falla se detiene y se informa lo ya aplicado:
                     * no existe una transaccion unica entre varios pedidos origen y no se simula.
                     */
                    async aplicarCreditoAVentaCreada() {
                        const c = this.creditoAplicar;
                        const total = Number(this.confirmacion?.total ?? 0);
                        let restante = Math.round(Number(c.monto) * 100) / 100;

                        c.error = null;

                        if (! (restante > 0)) {
                            c.error = 'Indica un monto mayor a cero.';
                            return;
                        }

                        if (restante > this.creditoMaximoAplicable + 0.0001) {
                            c.error = 'El monto supera el saldo a favor disponible o lo que debe el pedido.';
                            return;
                        }

                        c.enviando = true;
                        let aplicado = 0;

                        try {
                            for (const origen of this.creditoCliente.origenes) {
                                if (restante <= 0) {
                                    break;
                                }

                                const parte = Math.round(Math.min(restante, origen.disponible) * 100) / 100;
                                const idClave = origen.order_id + ':' + this.confirmacion.order_id + ':' + parte.toFixed(2);
                                c.claves[idClave] = c.claves[idClave] ?? (window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'nv-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12));

                                await this.$axios.post("{{ route('admin.ventas_asesor.cartera.credito.aplicar') }}", {
                                    origen: origen.order_id,
                                    destino: this.confirmacion.order_id,
                                    monto: parte.toFixed(2),
                                    motivo: 'Aplicado desde Nueva venta',
                                    idempotency_key: c.claves[idClave],
                                });

                                aplicado += parte;
                                restante = Math.round((restante - parte) * 100) / 100;
                            }

                            c.hecho = 'Saldo a favor aplicado: ' + this.precio(aplicado) + '. Saldo pendiente del pedido: ' + this.precio(Math.max(0, total - aplicado)) + '.';
                            this.cargarCreditoClienteSinReiniciar();
                        } catch (error) {
                            const mensaje = error?.response?.data?.mensaje || error?.response?.data?.message || 'No se pudo aplicar el saldo a favor.';
                            c.error = aplicado > 0
                                ? 'Se aplicaron ' + this.precio(aplicado) + ' y luego falló: ' + mensaje + ' Revisa el pedido antes de reintentar.'
                                : mensaje;
                            this.cargarCreditoClienteSinReiniciar();
                        } finally {
                            c.enviando = false;
                        }
                    },

                    cargarCreditoClienteSinReiniciar() {
                        const id = this.clienteActual ? (this.clienteActual.customer_id ?? this.clienteActual.customerId) : null;

                        if (id) {
                            this.$axios.get("{{ url(route('admin.ventas_asesor.cartera.credito.cliente', ['cliente' => '__ID__'])) }}".replace('__ID__', id))
                                .then((response) => { this.creditoCliente = response.data; })
                                .catch(() => {});
                        }
                    },

                    irASeccion(id) {
                        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    },

                    /** A que seccion pertenece cada motivo_no_confirmable, solo para el scroll del resumen. */
                    seccionDelMotivo(codigo) {
                        return ({
                            sin_lineas: 'seccion-productos',
                            stock_insuficiente: 'seccion-productos',
                            direccion_facturacion_requerida: 'seccion-entrega',
                            entrega_incompleta: 'seccion-entrega',
                            entrega_requiere_revision: 'seccion-entrega',
                            domiciliario_requerido: 'seccion-entrega',
                            sin_metodo_pago: 'seccion-pago',
                        })[codigo] || 'seccion-cliente';
                    },

                    /**
                     * Identidad visual SOLO por el texto del acceso rapido ya configurado (nunca por
                     * id): si no coincide ninguna familia conocida, queda neutro (gris), nunca roto.
                     */
                    identidadAcceso(acceso, seleccionado) {
                        const texto = (acceso.etiqueta || '').toLowerCase();
                        let acento = 'border-gray-200 text-gray-700 hover:border-blue-400 dark:border-gray-800 dark:text-gray-200';

                        if (texto.includes('caballero') || texto.includes('hombre')) {
                            acento = seleccionado
                                ? 'border-blue-700 bg-blue-600 text-white shadow-sm ring-2 ring-blue-200 dark:border-blue-400 dark:bg-blue-700 dark:ring-blue-900'
                                : 'border-blue-200 text-blue-700 hover:border-blue-400 dark:border-blue-900 dark:text-blue-300';
                        } else if (texto.includes('dama') || texto.includes('mujer')) {
                            acento = seleccionado
                                ? 'border-blue-700 bg-blue-600 text-white shadow-sm ring-2 ring-blue-200 dark:border-blue-400 dark:bg-blue-700 dark:ring-blue-900'
                                : 'border-pink-200 text-pink-700 hover:border-pink-400 dark:border-pink-900 dark:text-pink-300';
                        } else if (texto.includes('niñ') || texto.includes('nin')) {
                            acento = seleccionado
                                ? 'border-blue-700 bg-blue-600 text-white shadow-sm ring-2 ring-blue-200 dark:border-blue-400 dark:bg-blue-700 dark:ring-blue-900'
                                : 'border-orange-200 text-orange-700 hover:border-orange-400 dark:border-orange-900 dark:text-orange-300';
                        } else if (seleccionado) {
                            acento = 'border-blue-700 bg-blue-600 text-white shadow-sm ring-2 ring-blue-200 dark:border-blue-400 dark:bg-blue-700 dark:ring-blue-900';
                        }

                        return acento;
                    },

                    /** Icono SOLO decorativo por codigo tecnico de pago (ya estable en config, no es un id comercial). */
                    iconoPago(codigo) {
                        return ({ cashondelivery: '🚚', moneytransfer: '🏦', cashonpickup: '💵' })[codigo] || '💳';
                    },

                    /** Eliminar venta en curso = BorradorVentaService::descartar() (ownership y lock ya existentes). */
                    eliminarVentaEnCurso(borradorId) {
                        this.$axios.post("{{ url($rutaNv('eliminar', ['borrador' => '__ID__'])) }}".replace('__ID__', borradorId))
                            .then(() => {
                                this.confirmarEliminarId = null;
                                this.ventasEnCurso = this.ventasEnCurso.filter((b) => b.id !== borradorId);
                                this.$emitter.emit('add-flash', { type: 'success', message: 'Venta eliminada.' });

                                if (this.borradorId === borradorId) {
                                    this.reiniciar();
                                }
                            })
                            .catch((error) => this.manejarError(error));
                    },

                    /**
                     * Direcciones guardadas (CustomerAddress) del cliente. Preseleccion SOLO visual, sin escribir
                     * nada: la predeterminada de Bagisto (default_address); si no hay y existe UNA sola, esa; con
                     * varias sin predeterminada, el asesor elige. Si el carrito ya tiene un envio guardado
                     * (venta retomada), manda ese.
                     */
                    cargarDireccionesCliente(customerId) {
                        this.direccionesCliente = [];
                        this.direccionesCargadas = false;
                        this.cargando.direccion = true;

                        return this.$axios.get("{{ ($modoCliente ?? false) ? route('admin.autoservicio.direcciones') : url($rutaNv('clientes.direcciones', ['cliente' => '__ID__'])) }}".replace('__ID__', customerId))
                            .then((response) => {
                                this.direccionesCliente = response.data;

                                const preseleccion = this.direccionesCliente.find((d) => d.predeterminada)
                                    || (this.direccionesCliente.length === 1 ? this.direccionesCliente[0] : null);

                                if (! this.direccionSeleccionadaId && preseleccion) {
                                    this.direccionSeleccionadaId = preseleccion.id;
                                }

                                if (! this.direccionFacturacionId && preseleccion) {
                                    this.direccionFacturacionId = preseleccion.id;
                                }

                                // Si ya eligio domicilio mientras cargaban, aplicar la preseleccion.
                                if (this.entregaForm.tipo === 'domicilio' && this.tipoAplicado !== 'domicilio' && this.direccionSeleccionadaId) {
                                    this.aplicarDomicilio();
                                }
                            })
                            .catch((error) => this.manejarError(error))
                            .finally(() => {
                                this.cargando.direccion = false;
                                this.direccionesCargadas = true;
                            });
                    },

                    /** Paso Entrega: Recogida o Domicilio. Cambiar de tipo nunca arrastra el metodo anterior. */
                    elegirTipoEntrega(tipo) {
                        if (this.entregaForm.tipo === tipo && this.tipoAplicado === tipo) {
                            return;
                        }

                        this.entregaForm.tipo = tipo;
                        this.entregaForm.metodo = null;

                        if (tipo === 'punto_recogida') {
                            this.aplicarRecogida();

                            return;
                        }

                        if (this.direccionSeleccionadaId || this.direccionPuntual) {
                            this.aplicarDomicilio();
                        } else if (this.direccionesCargadas && ! this.direccionesCliente.length) {
                            this.abrirFormDireccion('envio');
                        }

                        this.$nextTick(() => this.irASeccion('seccion-direccion'));
                    },

                    /** Domicilio: la CustomerAddress elegida se COPIA al CartAddress (Cart::saveAddresses); no se modifica. */
                    seleccionarDireccion(dir) {
                        this.direccionSeleccionadaId = dir.id;
                        this.direccionPuntual = null;
                        this.entregaForm.metodo = null;
                        this.aplicarDomicilio();
                    },

                    /**
                     * Envio POR PAGAR en zona local con domiciliario ya elegido: el pedido no cobra flete (precio 0), pero el
                     * cliente paga la tarifa del domiciliario al recibir. Solo se MUESTRA junto al metodo para no confundir al
                     * asesor; no suma al total del pedido. null = no aplica.
                     */
                    fleteDomiciliarioPorPagar(metodo) {
                        const d = this.estado && this.estado.entrega ? this.estado.entrega.domiciliario : null;

                        return metodo.condicion === 'por_pagar' && d && d.elegido_id && d.tarifa !== null && d.tarifa !== undefined
                            ? d.tarifa
                            : null;
                    },

                    seleccionarMetodoEnvio(codigo) {
                        this.entregaForm.metodo = codigo;
                        this.aplicarDomicilio();
                    },

                    /** Recogida sin facturacion resoluble: el asesor elige la direccion REAL de facturacion (D-30). */
                    seleccionarFacturacion(dir) {
                        this.direccionFacturacionId = dir.id;
                        this.aplicarRecogida();
                    },

                    /** Sin `dir` agrega una direccion; con `dir` (guardada del cliente) la abre precargada para corregirla. */
                    abrirFormDireccion(para, dir = null) {
                        const c = this.clienteActual || {};

                        this.formDireccion = {
                            abierto: true,
                            para,
                            guardar: true,
                            error: null,
                            editarId: dir ? dir.id : null,
                            datos: dir ? {
                                nombre: dir.nombre || '', apellido: dir.apellido || '', telefono: dir.telefono || '',
                                linea: String(dir.direccion || '').replace(/\s*\n\s*/g, ', '),
                                ciudad: '', departamento: '', codigo_postal: dir.codigo_postal || '', municipio_codigo: null, ubicacion: null,
                            } : {
                                nombre: c.nombre || '', apellido: c.apellido || '',
                                telefono: c.telefono_principal || c.telefonoPrincipal || '',
                                linea: '', ciudad: '', departamento: '', codigo_postal: '', municipio_codigo: null, ubicacion: null,
                            },
                        };

                        this.reiniciarUbicacion();

                        // Municipio guardado que coincide con el catalogo: queda elegido. Si no (texto libre historico),
                        // el campo queda vacio y hay que elegirlo de la lista, como al agregar.
                        if (dir && dir.municipio_codigo) {
                            const departamento = this.nombreDepartamento(dir.departamento);

                            this.elegirUbicacion({
                                municipio_codigo: dir.municipio_codigo, municipio: dir.ciudad, departamento,
                                etiqueta: departamento + ' — ' + dir.ciudad,
                            });
                        }

                        this.$refs.modalDireccion.open();
                    },

                    reiniciarUbicacion() {
                        clearTimeout(this._timerUbicacion);
                        this._tokenUbicacion = (this._tokenUbicacion || 0) + 1;   // invalida respuestas en vuelo
                        this.ubicacionBusqueda = { texto: '', resultados: [], abierta: false, buscando: false, resaltado: -1 };
                    },

                    /**
                     * Escribir NO selecciona: cada tecla invalida la seleccion anterior y relanza la busqueda
                     * (debounce de 300 ms, sin librerias). Solo cuenta lo elegido de la lista.
                     */
                    alEscribirUbicacion(evento) {
                        const texto = evento.target.value;
                        const datos = this.formDireccion.datos;

                        this.ubicacionBusqueda.texto = texto;
                        datos.ubicacion = null;
                        datos.municipio_codigo = null;
                        datos.ciudad = '';
                        datos.departamento = '';

                        clearTimeout(this._timerUbicacion);

                        if (texto.trim().length < 2) {
                            this._tokenUbicacion = (this._tokenUbicacion || 0) + 1;
                            this.ubicacionBusqueda.resultados = [];
                            this.ubicacionBusqueda.abierta = false;
                            this.ubicacionBusqueda.buscando = false;

                            return;
                        }

                        this.ubicacionBusqueda.buscando = true;
                        this._timerUbicacion = setTimeout(() => this.buscarUbicaciones(texto.trim()), 300);
                    },

                    buscarUbicaciones(texto) {
                        const token = this._tokenUbicacion = (this._tokenUbicacion || 0) + 1;

                        this.$axios.get("{{ ($modoCliente ?? false) ? route('admin.autoservicio.ubicaciones') : route('admin.ventas_asesor.ubicaciones.buscar') }}", { params: { q: texto } })
                            .then((response) => {
                                if (token !== this._tokenUbicacion) {
                                    return;   // llego una respuesta vieja: ya se escribio otra cosa
                                }

                                this.ubicacionBusqueda.resultados = response.data;
                                this.ubicacionBusqueda.resaltado = response.data.length ? 0 : -1;
                                this.ubicacionBusqueda.abierta = true;
                            })
                            .catch(() => {
                                if (token === this._tokenUbicacion) {
                                    this.ubicacionBusqueda.resultados = [];
                                    this.ubicacionBusqueda.abierta = true;
                                }
                            })
                            .finally(() => {
                                if (token === this._tokenUbicacion) {
                                    this.ubicacionBusqueda.buscando = false;
                                }
                            });
                    },

                    moverUbicacion(paso) {
                        const n = this.ubicacionBusqueda.resultados.length;

                        if (! this.ubicacionBusqueda.abierta || ! n) {
                            return;
                        }

                        this.ubicacionBusqueda.resaltado = (this.ubicacionBusqueda.resaltado + paso + n) % n;
                    },

                    elegirUbicacionResaltada() {
                        const u = this.ubicacionBusqueda.resultados[this.ubicacionBusqueda.resaltado];

                        if (this.ubicacionBusqueda.abierta && u) {
                            this.elegirUbicacion(u);
                        }
                    },

                    /** Seleccion estructurada: se conserva el CODIGO del municipio; lo demas es informativo. */
                    elegirUbicacion(u) {
                        const datos = this.formDireccion.datos;

                        clearTimeout(this._timerUbicacion);
                        this._tokenUbicacion = (this._tokenUbicacion || 0) + 1;

                        datos.ubicacion = u;
                        datos.municipio_codigo = u.municipio_codigo;
                        datos.ciudad = u.municipio;
                        datos.departamento = u.departamento;

                        this.ubicacionBusqueda = { texto: u.etiqueta, resultados: [], abierta: false, buscando: false, resaltado: -1 };
                        this.formDireccion.error = null;
                    },

                    /** Codigo de country_states → nombre; direcciones historicas con texto libre se muestran tal cual. */
                    nombreDepartamento(valor) {
                        return (this.departamentos.find((d) => d.codigo === valor) || {}).nombre || valor || '';
                    },

                    cerrarFormDireccion() {
                        this.formDireccion.abierto = false;
                        this.formDireccion.error = null;
                        this.reiniciarUbicacion();
                        this.$refs.modalDireccion.close();
                    },

                    /**
                     * Guardar en el cliente = RT-04 (GuardarDireccionClienteComercialService via la API del
                     * borrador). Sin guardar = direccion puntual de ESTA venta: solo viaja al CartAddress.
                     */
                    guardarDireccionNueva() {
                        const d = this.formDireccion.datos;
                        const para = this.formDireccion.para;
                        this.formDireccion.error = null;

                        // Texto parcial («Pes» sin elegir) no es una ubicacion: no se guarda ni se usa en la venta.
                        if (! d.municipio_codigo) {
                            this.formDireccion.error = 'Selecciona una ciudad o municipio de la lista.';

                            return;
                        }

                        if (! this.formDireccion.guardar) {
                            // Puntual: el backend valida igual (03D/RT-04); aqui solo se evita cerrar la modal con campos vacios.
                            const faltan = ['nombre', 'apellido', 'telefono', 'linea'].filter((k) => ! String(d[k] || '').trim());

                            if (faltan.length) {
                                this.formDireccion.error = 'Completa los campos obligatorios (*).';

                                return;
                            }

                            const puntual = { ...d };

                            this.cerrarFormDireccion();

                            if (para === 'facturacion') {
                                this.aplicarRecogida(puntual);
                            } else {
                                this.direccionPuntual = puntual;
                                this.direccionSeleccionadaId = null;
                                this.entregaForm.metodo = null;
                                this.aplicarDomicilio();
                            }

                            return;
                        }

                        this.cargando.guardarDireccion = true;

                        // Editar = corregir la guardada (misma validacion). Al terminar se selecciona como al agregar: elegirla
                        // vuelve a copiarla al carrito, asi la venta queda con los datos corregidos.
                        const urlDireccion = this.formDireccion.editarId
                            // 987654321 = marcador numerico (la ruta exige numero en {direccion}); se reemplaza por el id real.
                            ? "{{ url($rutaNv('direcciones.editar', ['borrador' => '__ID__', 'direccion' => 987654321])) }}".replace('__ID__', this.borradorId).replace('987654321', this.formDireccion.editarId)
                            : "{{ url($rutaNv('direcciones.agregar', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId);

                        this.$axios.post(urlDireccion, {
                            nombre: d.nombre, apellido: d.apellido, telefono: d.telefono, lineas: [d.linea],
                            municipio_codigo: d.municipio_codigo, codigo_postal: d.codigo_postal,
                        })
                            .then((response) => {
                                this.direccionesCliente = response.data.direcciones;
                                this.cerrarFormDireccion();

                                const nueva = this.direccionesCliente.find((x) => x.id === response.data.direccion_id);

                                if (para === 'facturacion') {
                                    this.seleccionarFacturacion(nueva);
                                } else {
                                    this.seleccionarDireccion(nueva);
                                }
                            })
                            .catch((error) => {
                                // El borrador ya no es editable: se cierra la modal y se reconcilia con el estado real.
                                if (error?.response?.data?.codigo === 'borrador_no_editable') {
                                    this.cerrarFormDireccion();
                                    this.errorDeOperacion(error);

                                    return;
                                }

                                this.formDireccion.error = this.mensajeDireccionInvalida(error);
                            })
                            .finally(() => { this.cargando.guardarDireccion = false; });
                    },

                    /** Codigos de RT-04 (datos_cliente_invalidos.errores) en lenguaje del asesor. */
                    mensajeDireccionInvalida(error) {
                        const datos = error?.response?.data || {};
                        const campos = {
                            nombre: 'nombre', apellido: 'apellido', lineas: 'dirección', ciudad: 'ciudad',
                            departamento: 'departamento', pais: 'país', codigo_postal: 'código postal',
                            municipio_codigo: 'ciudad / municipio (selecciónalo de la lista)',
                            telefono: 'teléfono', email: 'correo',
                        };

                        if (datos.errores) {
                            return 'Revisa: ' + Object.keys(datos.errores).map((k) => campos[k] || k).join(', ') + '.';
                        }

                        if (datos.errors) {
                            return Object.values(datos.errors).flat().join(' ');
                        }

                        return datos.codigo ? this.mensajeDeError(error) : (datos.mensaje || 'No se pudo guardar la dirección.');
                    },

                    /** Traduccion SOLO visual de los codigos de EstadoNuevaVenta::motivos_no_confirmable (el codigo interno no cambia). */
                    motivoLegible(codigo) {
                        return ({
                            borrador_no_editable: 'La venta ya no se puede editar.',
                            cliente_no_confirmable: 'El cliente no está habilitado para confirmar venta.',
                            sin_lineas: 'Agrega al menos un producto.',
                            stock_insuficiente: 'Hay una talla sin stock suficiente.',
                            direccion_facturacion_requerida: 'Falta una dirección de facturación.',
                            entrega_incompleta: 'Completa los datos de entrega.',
                            entrega_requiere_revision: 'Revisa el método de entrega.',
                            domiciliario_requerido: 'Elige el domiciliario que hará la entrega.',
                            sin_metodo_pago: 'Selecciona un método de pago.',
                        })[codigo] || codigo;
                    },

                    manejarError(error) {
                        this.errorGeneral = this.mensajeDeError(error);

                        // Cualquier flujo (tallas, eliminar…) que choque con un borrador no editable muestra el
                        // aviso de estado real; la guarda evita un bucle si la relectura tambien fallara.
                        if (this.codigoDeError(error) === 'borrador_no_editable' && this.borradorId && ! this._reconciliando) {
                            this._reconciliando = true;

                            this.refrescarEstado(false).finally(() => { this._reconciliando = false; });
                        }
                    },

                    /** El lote de tallas envuelve la causa real en `lote_rechazado`: se usa la causa. */
                    codigoDeError(error) {
                        const datos = error?.response?.data;

                        return datos?.codigo === 'lote_rechazado' && datos?.causa ? datos.causa : (datos?.codigo || null);
                    },

                    /** Texto seguro para el asesor a partir del contrato RespuestaErrorNuevaVenta. */
                    mensajeDeError(error) {
                        const datos = error?.response?.data;

                        const conocidos = {
                            borrador_no_editable: 'Esta venta ya no se puede editar (está en confirmación, ya fue confirmada o fue eliminada). Revisa el aviso de arriba.',
                            no_es_propietario: 'Esta venta pertenece a otro asesor.',
                            confirmacion_en_proceso: 'La confirmación de esta venta todavía está en proceso. Espera unos minutos y verifica el estado.',
                            confirmacion_inconsistente: 'La venta quedó en un estado inconsistente. Avisa a soporte antes de reintentar.',
                            borrador_no_confirmable: 'Esta venta no se puede confirmar en su estado actual.',
                            metodo_entrega_no_disponible: 'Ese método de envío ya no está disponible para la dirección elegida. Elige otro.',
                            domiciliario_no_valido: 'Ese domiciliario ya no está disponible. Elige otro.',
                            metodo_pago_no_disponible: 'Ese método de pago no está disponible para esta venta. Elige otro.',
                            producto_no_vendible: 'Ese producto no está disponible para la venta. Prueba otro color o producto.',
                            producto_inexistente: 'Ese producto ya no existe en el catálogo. Prueba otro color o producto.',
                            variante_no_pertenece_al_padre: 'Esa talla no corresponde a este producto. Vuelve a elegir los colores.',
                            opciones_incompletas: 'Falta elegir la talla.',
                            variante_no_encontrada: 'No encontramos esa talla. Vuelve a elegir los colores.',
                            variante_ambigua: 'Esa talla coincide con más de un producto. Avisa a soporte.',
                            cantidad_invalida: 'La cantidad no es válida.',
                            sin_permiso: 'No tienes permiso para esta acción.',
                            propiedad_comercial_invalida: 'No puedes asignar esta venta a ese asesor. Revisa «Vender para».',
                        };

                        // R2: el rechazo por rentabilidad nombra la referencia, nunca el costo.
                        if (datos?.codigo === 'rentabilidad_insuficiente') {
                            const refs = datos?.detalles?.referencias || [];

                            return refs.length
                                ? 'No se puede confirmar: ' + refs.join(', ') + ' no alcanza el precio mínimo con este grupo o cantidad. Cambia el grupo o la cantidad, o avisa a Administración para revisar la lista de precios.'
                                : 'No se puede confirmar: falta configuración de rentabilidad para esta venta. Avisa a Administración.';
                        }

                        const codigo = this.codigoDeError(error);

                        if (codigo && conocidos[codigo]) {
                            return conocidos[codigo];
                        }

                        if (datos?.codigo) {
                            return datos.mensaje || ('Error: ' + datos.codigo);
                        }

                        if (error?.response?.status === 422 && datos?.errors) {
                            return Object.values(datos.errors).flat().join(' ');
                        }

                        if (error?.response?.status >= 500) {
                            // Fallo del servidor (p. ej. tardó demasiado): sin detalles tecnicos para la asesora.
                            return 'El servidor no pudo completar la operación. Revisa la venta antes de volver a intentarlo.';
                        }

                        if (error?.response?.status) {
                            // Hubo respuesta HTTP pero sin el contrato JSON esperado (RespuestaErrorNuevaVenta).
                            return 'Ocurrió un error inesperado. Código: ' + error.response.status;
                        }

                        // Sin respuesta HTTP en absoluto (la peticion se cayo/se interrumpio antes de
                        // responder): no es un codigo de dominio conocido, nunca se inventa uno.
                        return 'No se recibió respuesta del servidor. Verifica el estado de la venta antes de volver a intentarlo.';
                    },

                    buscarCliente() {
                        if (! this.busquedaCliente || this.busquedaCliente.trim().length < 2) {
                            this.resultadoBusqueda = null;

                            return;
                        }

                        this.cargando.cliente = true;
                        this.resultadoBusqueda = null;

                        const criterio = /\d/.test(this.busquedaCliente) ? { telefono: this.busquedaCliente } : { nombre: this.busquedaCliente };

                        this.$axios.post("{{ $rutaNv('clientes.buscar') }}", criterio)
                            .then((response) => {
                                this.resultadoBusqueda = response.data;
                            })
                            .catch((error) => this.manejarError(error))
                            .finally(() => { this.cargando.cliente = false; });
                    },

                    /**
                     * P7: elegir cliente NO crea ninguna venta en curso. Se queda en pantalla como cliente pendiente (sin
                     * borrador ni carrito) para consultar productos e inventario; el borrador nace al agregar la primera prenda.
                     */
                    seleccionarClienteEncontrado(cliente) {
                        this.borradorId = null;
                        this.estado = null;
                        this.referenciaVenta = this.nuevaReferenciaVenta();
                        this.clienteActual = {
                            customer_id: cliente.customerId,
                            nombre: cliente.nombre,
                            apellido: cliente.apellido,
                            email: cliente.email ?? null,
                            telefono_principal: cliente.telefonoPrincipal ?? null,
                            grupo_id: cliente.grupoId ?? null,
                            grupo_nombre: cliente.grupoNombre ?? null,
                            // Solo informativo: el servidor vuelve a validar el cliente al crear la venta y al confirmar.
                            clienteConfirmable: !! (cliente.activo !== false && ! cliente.suspendido && cliente.grupoApto !== false),
                        };
                    },

                    /** Un identificador por venta (no secreto): su unico fin es que un reintento no cree dos ventas. */
                    nuevaReferenciaVenta() {
                        if (window.crypto && window.crypto.randomUUID) {
                            return window.crypto.randomUUID();
                        }

                        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
                    },

                    clienteIdPendiente() {
                        return this.clienteActual ? (this.clienteActual.customer_id ?? this.clienteActual.customerId) : null;
                    },

                    abrirCrearCliente() {
                        this.crearClienteForm = { nombre: '', apellido: '', telefono: this.busquedaCliente, customer_group_id: null, email: '' };
                        this.errorCrearCliente = null;
                        this.$refs.modalCrearCliente.open();
                    },

                    crearClienteYContinuar() {
                        this.errorCrearCliente = null;
                        this.cargando.crearCliente = true;

                        // P7: se crea el cliente (dato real) pero NO la venta; esta nace al agregar la primera prenda.
                        this.$axios.post("{{ $rutaNv('clientes.crear') }}", {
                            ...this.crearClienteForm,
                            canal: this.canalSeleccionado,
                        })
                            .then((response) => {
                                const grupo = this.grupos.find((g) => g.id === this.crearClienteForm.customer_group_id);

                                const datosCliente = {
                                    customerId: response.data.customer_id,
                                    nombre: this.crearClienteForm.nombre,
                                    apellido: this.crearClienteForm.apellido,
                                    email: this.crearClienteForm.email || null,
                                    telefonoPrincipal: this.crearClienteForm.telefono,
                                    grupoId: this.crearClienteForm.customer_group_id,
                                    grupoNombre: grupo ? grupo.name : null,
                                };
                                const habilitar = this.puedeAdministrarAcceso && this.crearClienteForm.habilitar_acceso;

                                this.$refs.modalCrearCliente.close();
                                this.seleccionarClienteEncontrado(datosCliente);

                                // El cliente ya existe; el acceso es un paso aparte y su fallo NO deshace el cliente ni la venta.
                                if (habilitar) {
                                    this.$axios.post("{{ url(route('admin.ventas_asesor.clientes_acceso.crear_usuario', ['cliente' => '__ID__'])) }}".replace('__ID__', datosCliente.customerId), {
                                        email: this.crearClienteForm.email || null,
                                    })
                                        .then((r) => this.$emitter.emit('add-flash', {
                                            type: 'success',
                                            message: r.data.enlace_enviado
                                                ? 'Cliente creado y acceso habilitado. Se envió el enlace para crear su contraseña.'
                                                : 'Cliente creado y acceso habilitado, pero no se pudo enviar el correo.',
                                        }))
                                        .catch((e) => this.$emitter.emit('add-flash', {
                                            type: 'warning',
                                            message: 'Cliente creado, pero el acceso no se habilitó: ' + (e?.response?.data?.mensaje || 'intenta desde la ficha del cliente.'),
                                        }));
                                }

                                this.crearClienteForm = { nombre: '', apellido: '', telefono: '', customer_group_id: null, email: '', habilitar_acceso: false };
                            })
                            .catch((error) => {
                                this.errorCrearCliente = error?.response?.data?.mensaje
                                    || Object.values(error?.response?.data?.errors || {}).flat().join(' ')
                                    || 'No se pudo crear el cliente.';
                            })
                            .finally(() => { this.cargando.crearCliente = false; });
                    },

                    /**
                     * Operaciones que escriben `estado` (entrega, pago, refresco): solo la ULTIMA enviada manda.
                     * El servidor las serializa con bloqueo, asi que la respuesta de la mas reciente es la mas
                     * nueva; una respuesta vieja que llega despues (tap A → tap B → respuesta B → respuesta A)
                     * se descarta en vez de pisar el estado.
                     */
                    nuevaOperacion() {
                        this._secuencia = (this._secuencia || 0) + 1;

                        return this._secuencia;
                    },

                    operacionVigente(token) {
                        return token === this._secuencia;
                    },

                    fijarEstado(estado) {
                        this.estado = estado;
                        this.borradorId = estado.borrador.id;
                        this.clienteActual = estado.borrador.cliente;

                        // Los radios nativos pueden quedar marcados distinto del estado real (toque + error o
                        // respuesta sin cambios: Vue no re-pinta lo que no cambio). Recrearlos garantiza que
                        // lo marcado sea siempre lo que el backend tiene.
                        this.versionVista++;

                        if (estado.entrega && estado.entrega.metodo_seleccionado) {
                            this.entregaForm.metodo = estado.entrega.metodo_seleccionado.codigo;
                        }

                        // Venta retomada: lo elegido arranca igual a lo que el carrito ya tiene aplicado.
                        if (this.entregaForm.tipo === null && this.tipoAplicado) {
                            this.entregaForm.tipo = this.tipoAplicado;

                            if (this.tipoAplicado === 'domicilio') {
                                this.direccionSeleccionadaId = estado.entrega.envio.customerAddressId || null;
                            }
                        }

                        // Entrega local: el domiciliario es obligatorio. Si no hay uno elegido y existe un sugerido (el ultimo usado
                        // en la zona), se aplica UNA vez por venta y zona; el asesor lo cambia con un clic.
                        const dom = estado.entrega ? estado.entrega.domiciliario : null;
                        const claveAuto = dom ? estado.borrador.id + ':' + dom.zona.id : null;

                        if (! this.modoCliente && dom && ! dom.elegido_id && dom.sugerido_id && this.ventaEditable && this._domiciliarioAuto !== claveAuto) {
                            this._domiciliarioAuto = claveAuto;
                            this.$nextTick(() => this.elegirDomiciliario(String(dom.sugerido_id)));
                        }

                        const customerId = estado.borrador.cliente?.customer_id;

                        if (this.modoCliente) {
                            this.prepararCliente(estado);
                        }

                        if (customerId && this._direccionesCargadasPara !== customerId) {
                            this._direccionesCargadasPara = customerId;
                            this.cargarDireccionesCliente(customerId);
                        }
                    },

                    /**
                     * conMetodosDePago=true pide al backend que resuelva Payment::getPaymentMethods()
                     * (costoso, por eso el backend lo omite por defecto: NuevaVentaService::estado()
                     * devuelve metodos_disponibles=[] si no se pide explicitamente con
                     * ?con_metodos_pago=true). Sin esto, el bloque de Pago nunca ve metodos reales.
                     */
                    refrescarEstado(conMetodosDePago = false) {
                        const token = this.nuevaOperacion();
                        const url = "{{ url($rutaNv('estado', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId)
                            + (conMetodosDePago ? '?con_metodos_pago=true' : '');

                        // Resuelve true si el estado recibido se aplico; false si era viejo o fallo.
                        return this.$axios.get(url)
                            .then((response) => {
                                if (! this.operacionVigente(token)) {
                                    return false;
                                }

                                this.fijarEstado(response.data);

                                return true;
                            })
                            .catch((error) => {
                                if (this.operacionVigente(token)) {
                                    this.manejarError(error);
                                }

                                return false;
                            });
                    },

                    /** Boton «Verificar estado» del aviso: vuelve a leer el borrador real (no modifica nada). */
                    verificarBorrador() {
                        this.cargando.estado = true;

                        this.refrescarEstado(true)
                            .then((aplicado) => {
                                if (aplicado) {
                                    this.pago = this.estado.pago;
                                }
                            })
                            .finally(() => { this.cargando.estado = false; });
                    },

                    /**
                     * Fallo de una operacion que cambia la venta (409 / 422 / sin respuesta): se informa con
                     * un texto entendible, los radios vuelven a lo que el backend tiene y se relee el estado real
                     * (el borrador pudo pasar a confirmando/confirmado). Lo elegido y no aplicado se descarta.
                     */
                    errorDeOperacion(error) {
                        const mensaje = this.mensajeDeError(error);

                        this.versionVista++;

                        return this.refrescarEstado(false).then((aplicado) => {
                            if (aplicado && this.tipoAplicado) {
                                this.entregaForm.tipo = this.tipoAplicado;
                            }

                            // El aviso se fija AL FINAL, ya reconciliado, para que el asesor lo vea junto al estado real.
                            this.errorGeneral = mensaje;
                        });
                    },

                    urlMisVentas(orderId) {
                        return "{{ route('admin.ventas_asesor.pedidos.index') }}?pedido=" + encodeURIComponent(orderId);
                    },

                    lineaSeleccionada(clave) {
                        return this.lineasSeleccionadas.some((l) => l.clave === clave);
                    },

                    toggleLinea(acceso) {
                        if (! acceso.categoriaId) {
                            return;
                        }

                        const i = this.lineasSeleccionadas.findIndex((l) => l.clave === acceso.clave);

                        // Cambiar de linea cambia de que producto es el panel: lo guardado de la seleccion anterior ya no se
                        // describe aqui (sigue en el carrito) y el panel se abre para elegir los colores de la linea activa.
                        this.guardadoReciente = null;
                        this.coloresColapsados = false;
                        this.busquedaColor = '';

                        if (i >= 0) {
                            this.lineasSeleccionadas.splice(i, 1);
                            delete this.erroresColoresLinea[acceso.clave];

                            return;
                        }

                        this.lineasSeleccionadas.push(acceso);

                        if (! this.coloresPorLinea[acceso.clave]) {
                            this.cargarColoresDeLinea(acceso.clave);
                        }
                    },

                    cargarColoresDeLinea(clave) {
                        // Varias lineas pueden cargar a la vez: «Cargando colores…» dura hasta que termina la ultima.
                        this._coloresPendientes = (this._coloresPendientes || 0) + 1;
                        this.cargando.colores = true;
                        delete this.erroresColoresLinea[clave];

                        this.$axios.get("{{ url($rutaNv('lineas.colores', ['clave' => '__CLAVE__'])) }}".replace('__CLAVE__', clave))
                            .then((response) => { this.coloresPorLinea[clave] = response.data; })
                            .catch((error) => {
                                const linea = this.accesosRapidos.find((a) => a.clave === clave);

                                this.erroresColoresLinea[clave] = 'No se pudieron cargar los colores de ' + (linea ? linea.etiqueta : 'esta línea') + '.';
                                this.manejarError(error);
                            })
                            .finally(() => {
                                this._coloresPendientes = Math.max(0, (this._coloresPendientes || 1) - 1);
                                this.cargando.colores = this._coloresPendientes > 0;
                            });
                    },

                    /** Minusculas y sin tildes: «Azul Bebé» se encuentra escribiendo «bebe». Solo para filtrar lo que se ve. */
                    normalizarTexto(texto) {
                        return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
                    },

                    /**
                     * Titulo del metodo de pago tal como esta configurado, partido SOLO para leerlo: «Nombre(MEDIO1,MEDIO2)» → nombre + medios.
                     * No se renombra ni se interpreta (si incluye efectivo, se dice): el codigo y las reglas de pago no cambian.
                     */
                    tituloPago(titulo) {
                        const texto = String(titulo || '').trim();
                        const m = /^(.*?)\s*\((.+)\)\s*$/.exec(texto);

                        if (! m) {
                            return { nombre: texto, medios: '' };
                        }

                        const medios = m[2].split(',').map((x) => x.trim()).filter(Boolean)
                            .map((x) => x.charAt(0).toUpperCase() + x.slice(1).toLowerCase()).join(', ');

                        return { nombre: m[1].trim() || texto, medios };
                    },

                    /** Moneda de ByLopez («$ 8.500»): siempre con separador de miles. Solo presentacion del importe que entrega el servidor. */
                    precio(valor) {
                        return window.ByLopezMoneda.precio(valor);
                    },

                    /**
                     * Explica que son las senales de la celda. «En otras ventas sin confirmar» = borradores de otras ventas: NO reservan ni
                     * restan disponible (Doc. 05 §40). «Sin despachar» = pedidos ya confirmados cuyas unidades aun no salen de bodega.
                     */
                    textoAyudaSenal(senal) {
                        const partes = [];

                        if (senal && senal.en_otras_ventas_sin_confirmar) {
                            partes.push('«En otras ventas sin confirmar»: unidades en borradores de otras ventas. Es solo informativo: no están reservadas ni se restan del disponible.');
                        }

                        if (senal && senal.confirmados_sin_despachar) {
                            partes.push('«Pedidos sin despachar»: unidades de pedidos ya confirmados que aún no salen de bodega. Es solo informativo: el disponible puede no reflejarlas todavía.');
                        }

                        return partes.join(' ');
                    },

                    /** Este color de la linea activa YA esta en la venta (guardado en el carrito): no depende de lo que se elija ahora. */
                    colorEnVenta(color) {
                        const lineas = new Set(this.lineasSeleccionadas.map((l) => l.etiqueta));
                        const etiqueta = this.normalizarTexto(color.etiqueta);

                        return this.productosResumen.some((g) => g.lineaEtiqueta && lineas.has(g.lineaEtiqueta) && this.normalizarTexto(g.colorEtiqueta) === etiqueta);
                    },

                    colorSeleccionadoClave(clave) {
                        return this.coloresSeleccionados.some((c) => c.clave === clave);
                    },

                    /**
                     * Disponibilidad de un color entre las lineas SELECCIONADAS que lo tienen. El dato (`disponible`, tallas con
                     * stock) lo calcula el servidor con las mismas reglas de la matriz; aqui solo se cuenta:
                     *  - ninguna linea con stock → agotado («SIN STOCK», boton deshabilitado);
                     *  - algunas → parcial («1 de 2 disponible», habilitado);
                     *  - todas → ok (sin texto). Se recalcula solo al cambiar las lineas elegidas.
                     */
                    disponibilidadColor(color) {
                        const entradas = this.lineasSeleccionadas
                            .map((linea) => (this.coloresPorLinea[linea.clave] || [])
                                .find((c) => String(c.colorOptionId ?? c.colorEtiqueta ?? c.nombre) === color.clave))
                            .filter(Boolean);
                        const total = entradas.length;
                        const disponibles = entradas.filter((c) => c.disponible !== false).length;

                        if (total === 0 || disponibles === total) {
                            return { estado: 'ok', texto: null };
                        }

                        return disponibles === 0
                            ? { estado: 'agotado', texto: 'Sin stock' }
                            : { estado: 'parcial', texto: disponibles + ' de ' + total + ' disponible' };
                    },

                    /** Agotado en todas las lineas elegidas: no se puede elegir (sí desmarcar si ya estaba marcado). */
                    colorBloqueado(color) {
                        return this.disponibilidadColor(color).estado === 'agotado' && ! this.colorSeleccionadoClave(color.clave);
                    },

                    /** Texto para el asesor de lo que quedo fuera de la modal: «Camiseta caballero · Rojo — Sin stock». */
                    textoExcluidas(excluidas) {
                        return excluidas.map((e) => {
                            const c = this.combinacionDe(e.producto_id);

                            return '• ' + c.linea + ' · ' + c.color + ' — ' + (e.motivo === 'sin_stock' ? 'Sin stock' : 'No disponible');
                        });
                    },

                    /** Linea y color (como los ve el asesor) del producto de una combinacion de la seleccion. */
                    combinacionDe(productId) {
                        for (const g of this.tarjetasPorColor) {
                            const t = g.tarjetas.find((x) => x.productId === productId);

                            if (t) {
                                return { linea: t.etiquetaLinea, color: g.etiqueta };
                            }
                        }

                        return { linea: 'Producto #' + productId, color: '' };
                    },

                    toggleColor(color) {
                        // Un color agotado en todas las lineas elegidas no se puede elegir.
                        if (this.colorBloqueado(color)) {
                            return;
                        }

                        // Cambiar la seleccion invalida el error de la consulta anterior.
                        this.errorMatrices = null;
                        this.avisoSinTallas = null;

                        const i = this.coloresSeleccionados.findIndex((c) => c.clave === color.clave);

                        if (i >= 0) {
                            this.coloresSeleccionados.splice(i, 1);

                            return;
                        }

                        this.coloresSeleccionados.push(color);
                    },

                    /** Solo reconocimiento visual del icono: gratis si el codigo o el titulo lo dicen; el resto es envio con transportadora. No decide nada de logistica. */
                    metodoEsGratis(metodo) {
                        return /free|gratu|gratis/i.test(String(metodo.codigo || '') + ' ' + String(metodo.titulo || ''));
                    },

                    /** Minimizar / «Editar colores»: al reabrir se descarta el resumen de lo ya guardado. */
                    alternarColores() {
                        this.coloresColapsados = ! this.coloresColapsados;

                        if (! this.coloresColapsados) {
                            this.guardadoReciente = null;
                        }
                    },

                    /**
                     * Boton "Ver tallas": consulta (siempre fresca: stock y precio pueden haber cambiado)
                     * la matriz de cada producto linea×color de la seleccion con el MISMO endpoint de
                     * siempre. Solo si TODAS cargan se abre la modal; si alguna falla no se abre nada,
                     * se muestra el error junto al boton y la seleccion queda intacta para reintentar.
                     */
                    verTallas() {
                        const productos = [...new Set(this.tarjetasPorColor.flatMap((g) => g.tarjetas.map((t) => t.productId)))];

                        if (! productos.length || this.cargando.matrices) {
                            return;
                        }

                        this.errorMatrices = null;
                        this.abrirMatrices(productos, null).catch((error) => { this.errorMatrices = this.mensajeDeError(error); });
                    },

                    /**
                     * Lapiz del resumen: la MISMA modal, cargada SOLO con ese producto (linea x color) y sus cantidades
                     * actuales. Guardar solo envia ese producto: el resto de la venta no se toca.
                     */
                    editarProducto(grupo) {
                        if (this.cargando.matrices || ! grupo) {
                            return;
                        }

                        this.edicionCargando = grupo.productoId;

                        this.abrirMatrices([grupo.productoId], {
                            productId: grupo.productoId,
                            etiqueta: grupo.colorEtiqueta || grupo.titulo,
                            etiquetaLinea: grupo.lineaEtiqueta || grupo.subtitulo || grupo.titulo,
                            swatch: this.swatchDe(grupo.productoId, grupo.lineas[0]?.opciones?.color?.option_id),
                        })
                            .catch((error) => this.$emitter.emit('add-flash', { type: 'error', message: this.mensajeDeError(error) }))
                            .finally(() => { this.edicionCargando = null; });
                    },

                    /** Linea de venta (acceso rapido) de un producto, si sus colores ya se cargaron en esta pantalla. */
                    etiquetaLineaDe(productoId) {
                        const clave = Object.keys(this.coloresPorLinea).find((c) => (this.coloresPorLinea[c] || []).some((color) => color.productId === productoId));
                        const acceso = clave ? this.accesosRapidos.find((a) => a.clave === clave) : null;

                        return acceso ? acceso.etiqueta : null;
                    },

                    /**
                     * Bolita del color (swatch real de Bagisto) para un producto del carrito: la de ese producto si sus colores
                     * ya se cargaron; si no, la del MISMO color (option_id) en cualquier linea cargada. null si no se conoce.
                     */
                    swatchDe(productoId, colorOptionId) {
                        const colores = Object.values(this.coloresPorLinea).flat();
                        const color = colores.find((c) => c.productId === productoId && c.colorSwatch)
                            || (colorOptionId ? colores.find((c) => c.colorOptionId === colorOptionId && c.colorSwatch) : null);

                        return color ? color.colorSwatch : null;
                    },

                    /** True si el precio de esta talla difiere del de la fila anterior (p. ej. XXL): se separa visualmente para no confundir. */
                    precioCambia(celdas, indice) {
                        if (indice < 1) {
                            return false;
                        }

                        const anterior = celdas[indice - 1].variante.precioUnitario;
                        const actual = celdas[indice].variante.precioUnitario;

                        return anterior !== null && actual !== null && Number(anterior) !== Number(actual);
                    },

                    /** Talla (u otra opcion que no sea el color) de una linea del carrito; si no hay, el SKU. */
                    tallaDe(linea) {
                        const opcion = Object.entries(linea.opciones || {}).find(([codigo, o]) => codigo !== 'color' && o && o.etiqueta);

                        return opcion ? opcion[1].etiqueta : linea.sku;
                    },

                    animarCta(tipo) {
                        clearTimeout(this._ctaTemporizador);
                        this.ctaAnimacion = null;

                        // Quitar y volver a poner la clase en otro cuadro reinicia la animacion (una sola pasada).
                        this.$nextTick(() => requestAnimationFrame(() => {
                            this.ctaAnimacion = tipo;
                            this._ctaTemporizador = setTimeout(() => { this.ctaAnimacion = null; }, 600);
                        }));
                    },

                    /**
                     * Consulta las matrices (siempre frescas) y SOLO si todas cargan abre la modal. `edicion` = un solo
                     * producto desde el resumen (null = la seleccion de colores). Rechaza con el error para que el llamador
                     * lo muestre donde corresponde.
                     */
                    abrirMatrices(productos, edicion) {
                        this.cargando.matrices = true;
                        this.avisoSinTallas = null;

                        return this.$axios.get("{{ url($rutaNv('matrices')) }}", {
                            params: {
                                productos,
                                customer_id: this.estado ? this.estado.borrador.cliente.customer_id : this.clienteIdPendiente(),
                                borrador_id: this.borradorId,
                                // Editar lo que ya esta en la venta no se limita por stock (hay que poder bajarlo o quitarlo).
                                solo_vendibles: edicion ? 0 : 1,
                            },
                        })
                            .then((response) => {
                                const { matrices, excluidas } = response.data;
                                const incluidos = Object.keys(matrices).map(Number);

                                // Ninguna combinacion tiene tallas: la modal NO abre y se explica en lenguaje comercial.
                                if (! incluidos.length) {
                                    const unica = excluidas.length === 1 && ! edicion ? this.combinacionDe(excluidas[0].producto_id) : null;
                                    const mensaje = edicion
                                        ? 'Este producto ya no está disponible.'
                                        : (unica
                                            ? unica.color + ' no tiene tallas disponibles para ' + unica.linea + '.'
                                            : 'No hay tallas disponibles para la selección realizada. Prueba otro color o producto.');

                                    if (edicion) {
                                        this.$emitter.emit('add-flash', { type: 'warning', message: mensaje });
                                    } else {
                                        this.avisoSinTallas = mensaje;
                                    }

                                    return;
                                }

                                this.matricesPorProducto = matrices;
                                this.cantidadesPorProducto = {};
                                this.erroresPorVariante = {};
                                this.edicionUnica = edicion;
                                incluidos.forEach((productId) => this.sincronizarCantidadesProducto(productId));

                                // Combinaciones sin disponibilidad: aviso discreto; las demas se muestran normalmente.
                                this.avisoModal = excluidas.length
                                    ? '⚠ Algunas combinaciones no tienen disponibilidad:\n' + this.textoExcluidas(excluidas).join('\n')
                                    : null;

                                this.$refs.drawerMatrices.open();
                            })
                            .finally(() => { this.cargando.matrices = false; });
                    },

                    /**
                     * Cierre de la modal (X, Cancelar o tras agregar con exito): descarta SOLO el estado
                     * temporal (matrices y cantidades escritas, que nunca se aplicaron). No toca el
                     * carrito. Los colores se conservan en un cierre manual para poder ajustar la
                     * seleccion y volver a "Ver tallas"; tras agregar, agregarAlCarrito() ya los limpio.
                     */
                    alCerrarMatrices() {
                        this.matricesPorProducto = {};
                        this.cantidadesPorProducto = {};
                        this.erroresPorVariante = {};
                        this.avisoModal = null;
                        this.avisoAccion = null;
                        this.resultadoGuardado = null;
                        this.detalleSenalAbierto = null;
                        this.edicionUnica = null;
                    },

                    sincronizarCantidadesProducto(productId) {
                        const cantidades = {};

                        (this.matricesPorProducto[productId]?.celdas || []).forEach((celda) => {
                            const linea = (this.estado?.lineas || []).find((l) => l.varianteId === celda.variante.productId);

                            cantidades[celda.variante.productId] = linea ? linea.cantidad : 0;
                        });

                        this.cantidadesPorProducto[productId] = cantidades;
                    },

                    cantidadDe(productId, varianteId) {
                        return this.cantidadesPorProducto[productId]?.[varianteId] || 0;
                    },

                    ajustarCantidad(productId, varianteId, delta) {
                        const actual = this.cantidadDe(productId, varianteId);

                        this.cantidadesPorProducto[productId][varianteId] = Math.max(0, actual + delta);
                    },

                    /**
                     * "Agregar productos al carrito": UNA accion para todas las matrices de la modal.
                     *
                     * La matriz trabaja con cantidad FINAL por variante (contrato de aplicarTallas, 03G-B:
                     * los inputs arrancan con lo que ya esta en la venta). Por eso solo se envian las
                     * variantes cuya cantidad CAMBIO respecto a la venta: las nuevas > 0 y, si el asesor
                     * bajo a 0 una talla que ya estaba, ese 0 (quitarla). Lo no tocado no se reenvia.
                     *
                     * Un request por producto linea×color, en secuencia: cada uno sigue siendo todo-o-nada
                     * en el backend (no se inventa una transaccion entre productos). Si todos salen bien
                     * se cierra la modal y se limpian SOLO los colores; si alguno falla, la modal sigue
                     * abierta con el error por talla y lo ya aplicado queda aplicado.
                     */
                    agregarAlCarrito() {
                        if (this.cargando.tallas) {
                            return;
                        }

                        const enVenta = {};

                        (this.estado?.lineas || []).forEach((l) => { enVenta[l.varianteId] = l.cantidad; });

                        const lotes = [];
                        let invalida = false;

                        Object.entries(this.cantidadesPorProducto).forEach(([productId, cantidades]) => {
                            const cambios = {};

                            Object.entries(cantidades).forEach(([varianteId, valor]) => {
                                const cantidad = valor === '' || valor === null ? 0 : Number(valor);

                                if (! Number.isInteger(cantidad) || cantidad < 0) {
                                    invalida = true;

                                    return;
                                }

                                if (cantidad !== (enVenta[varianteId] || 0)) {
                                    cambios[varianteId] = cantidad;
                                }
                            });

                            if (Object.keys(cambios).length) {
                                lotes.push({ productId: Number(productId), cantidades: cambios });
                            }
                        });

                        this.avisoAccion = null;
                        this.resultadoGuardado = null;

                        if (invalida) {
                            this.avisoAccion = 'Revisa las cantidades: solo se permiten números enteros desde 0.';

                            return;
                        }

                        if (! lotes.length) {
                            this.avisoAccion = this.edicionUnica ? 'No hay cambios para guardar.' : 'Ingresa al menos una cantidad para agregar.';

                            return;
                        }

                        const editando = !! this.edicionUnica;

                        this.cargando.tallas = true;
                        this.erroresPorVariante = {};

                        const resultados = [];

                        lotes
                            .reduce((cadena, lote) => cadena.then(() => this.aplicarCantidadesProducto(lote.productId, lote.cantidades)
                                .then((resultado) => { resultados.push({ productId: lote.productId, ...resultado }); })), Promise.resolve())
                            .then(() => {
                                const fallidos = resultados.filter((r) => ! r.ok);

                                if (fallidos.length) {
                                    // Lo guardado y lo pendiente se dicen por separado; la modal sigue abierta con las cantidades escritas.
                                    this.resultadoGuardado = {
                                        guardados: resultados.filter((r) => r.ok).map((r) => this.nombreDeProducto(r.productId)),
                                        pendientes: fallidos.map((r) => ({ nombre: this.nombreDeProducto(r.productId), mensaje: r.mensaje, verificar: !! r.verificar })),
                                    };

                                    return;
                                }

                                // Edicion desde el resumen: solo cambio ESE producto; la seleccion de colores en curso se respeta.
                                if (editando) {
                                    this.$emitter.emit('add-flash', { type: 'success', message: 'Cambios guardados.' });
                                    this.$refs.drawerMatrices.close();

                                    return;
                                }

                                this.$emitter.emit('add-flash', { type: 'success', message: 'Productos agregados a la venta.' });

                                // Guardado correcto: la seccion de colores se minimiza sola (ahorra scroll). Si hubo error se
                                // salio arriba y sigue abierta.
                                const coloresAgregados = this.tarjetasPorColor.map((g) => g.etiqueta);

                                this.guardadoReciente = coloresAgregados.length
                                    ? { lineas: this.lineasSeleccionadas.map((l) => l.etiqueta), colores: coloresAgregados }
                                    : null;
                                this.coloresColapsados = coloresAgregados.length > 0;

                                // Solo la seleccion temporal: la linea (producto) se conserva para seguir
                                // vendiendo la misma prenda; el carrito no se toca.
                                this.coloresSeleccionados = [];
                                this.errorMatrices = null;
                                this.$refs.drawerMatrices.close();
                            })
                            .finally(() => { this.cargando.tallas = false; });
                    },

                    /**
                     * Un producto (linea x color) = un lote todo o nada (aplicarTallas, sin logica nueva).
                     * Resuelve { ok } sin lanzar, para que el llamador decida si cierra la modal.
                     */
                    aplicarCantidadesProducto(productId, cantidades, { sincronizar = true } = {}) {
                        // P7: sin venta todavia, la primera prenda la crea (idempotente por `referencia`); las siguientes usan el borrador.
                        const creaVenta = ! this.borradorId;
                        const peticion = creaVenta
                            ? this.$axios.post("{{ $rutaNv('iniciar_con_tallas') }}", {
                                customer_id: this.clienteIdPendiente(),
                                canal: this.canalSeleccionado,
                                referencia: this.referenciaVenta,
                                producto_id: productId,
                                cantidades,
                            })
                            : this.$axios.post("{{ url($rutaNv('tallas', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), {
                                producto_id: productId,
                                cantidades,
                            });

                        return peticion
                            .then((response) => {
                                this.fijarEstado(response.data);

                                if (sincronizar) {
                                    this.sincronizarCantidadesProducto(productId);
                                }

                                return { ok: true };
                            })
                            .catch((error) => {
                                const datos = error?.response?.data;
                                const mensaje = this.mensajeDeError(error);

                                if (datos?.codigo === 'lote_rechazado' && datos?.variante_id) {
                                    this.erroresPorVariante[datos.variante_id] = mensaje;
                                }

                                // Aun no hay venta (la primera prenda no se pudo confirmar): reintentar es seguro, la referencia evita duplicarla.
                                if (! this.borradorId) {
                                    return Promise.resolve({
                                        ok: false,
                                        mensaje: datos ? mensaje : 'No pudimos confirmar si se guardó. Vuelve a pulsar «Agregar»: no se duplicará la venta.',
                                    });
                                }

                                // Se relee el estado REAL de la venta (mecanismo existente) para saber que quedo guardado: un corte
                                // o un 500 pudo ocurrir DESPUES de guardar. Nada se reenvia solo.
                                return this.refrescarEstado().then((leido) => {
                                    if (! leido) {
                                        // No hay forma de comprobarlo: se dice tal cual, sin prometer que se guardo ni que no.
                                        return { ok: false, mensaje, verificar: true };
                                    }

                                    const enVenta = {};

                                    (this.estado?.lineas || []).forEach((l) => { enVenta[l.varianteId] = l.cantidad; });

                                    if (Object.entries(cantidades).every(([varianteId, cantidad]) => (enVenta[varianteId] || 0) === Number(cantidad))) {
                                        if (sincronizar) {
                                            this.sincronizarCantidadesProducto(productId);
                                        }

                                        return { ok: true, recuperado: true };
                                    }

                                    return { ok: false, mensaje };
                                });
                            });
                    },

                    /** «Camiseta caballero · Azul Bebe» de un producto linea x color de la modal (para decir que se guardo y que no). */
                    nombreDeProducto(productId) {
                        if (this.edicionUnica && this.edicionUnica.productId === productId) {
                            return this.edicionUnica.etiquetaLinea + ' · ' + this.edicionUnica.etiqueta;
                        }

                        const c = this.combinacionDe(productId);

                        return c.color ? c.linea + ' · ' + c.color : c.linea;
                    },

                    /** true si esta variante ya tiene una cantidad confirmada en el borrador (para mostrar "Quitar"). */
                    cantidadAplicada(varianteId) {
                        return !! (this.estado?.lineas || []).find((l) => l.varianteId === varianteId && l.cantidad > 0);
                    },

                    /**
                     * Quitar UNA talla ya aplicada (0 = quitarla, contrato de aplicarTallas). Envia solo
                     * esa variante: las demas cantidades escritas y aun no agregadas no se aplican aqui.
                     */
                    quitarTalla(productId, varianteId) {
                        this.cargando.tallas = true;
                        this.avisoAccion = null;
                        this.resultadoGuardado = null;

                        this.aplicarCantidadesProducto(productId, { [varianteId]: 0 }, { sincronizar: false })
                            .then((resultado) => {
                                if (resultado.ok) {
                                    this.cantidadesPorProducto[productId][varianteId] = 0;
                                } else {
                                    this.avisoAccion = resultado.verificar
                                        ? 'No pudimos confirmar si se quitó esta talla. Mira el resumen de la venta antes de volver a intentarlo.'
                                        : resultado.mensaje;
                                }
                            })
                            .finally(() => { this.cargando.tallas = false; });
                    },

                    toggleDetalleSenal(varianteId) {
                        this.detalleSenalAbierto = this.detalleSenalAbierto === varianteId ? null : varianteId;
                    },

                    /**
                     * Recogida: envio = bodega real (PuntosRecogida, D-41) + carrier `pickup` aplicado SOLO, sin
                     * volver a preguntarlo. La facturacion solo se manda si el backend no puede resolverla
                     * (sin facturacion previa ni predeterminada): una direccion REAL del cliente (D-30).
                     */
                    aplicarRecogida(facturacionPuntual = null) {
                        let facturacion = null;

                        if (this.estado.requiere_direccion_facturacion) {
                            if (facturacionPuntual) {
                                facturacion = { tipo: 'puntual', direccion: this.direccionParaApi(facturacionPuntual) };
                            } else if (this.direccionFacturacionId) {
                                facturacion = { tipo: 'guardada', address_id: this.direccionFacturacionId };
                            } else {
                                return; // se muestra el selector de facturacion; no se inventa nada
                            }
                        }

                        this.enviarEntrega({ envio: { tipo: 'punto_recogida' }, facturacion, metodo: this.metodoRecogida });
                    },

                    /**
                     * Domicilio: envio = la CustomerAddress elegida (guardada → parent_address_id) o la puntual de
                     * esta venta. El metodo solo viaja si el asesor ya lo eligio para ESTE destino.
                     */
                    aplicarDomicilio() {
                        const origen = this.direccionSeleccionadaId
                            ? { tipo: 'guardada', address_id: this.direccionSeleccionadaId }
                            : (this.direccionPuntual ? { tipo: 'puntual', direccion: this.direccionParaApi(this.direccionPuntual) } : null);

                        if (! origen) {
                            return;
                        }

                        this.enviarEntrega({
                            envio: origen,
                            facturacion: this.estado.requiere_direccion_facturacion ? origen : null,
                            metodo: this.entregaForm.metodo,
                        });
                    },

                    enviarEntrega(cuerpo) {
                        const token = this.nuevaOperacion();

                        this.errorGeneral = null;
                        this.cargando.entrega = true;

                        this.$axios.post("{{ url($rutaNv('entrega', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), cuerpo)
                            .then((response) => {
                                if (! this.operacionVigente(token)) {
                                    return;   // llego una respuesta vieja: ya hay otra operacion mas reciente
                                }

                                this.fijarEstado(response.data);

                                // Entrega lista: trae los metodos de pago reales de una vez.
                                if (this.entregaLista) {
                                    this.cargarMetodosPago();
                                    this.$nextTick(() => this.irASeccion('seccion-pago'));
                                }
                            })
                            .catch((error) => {
                                if (this.operacionVigente(token)) {
                                    this.errorDeOperacion(error);
                                }
                            })
                            .finally(() => { this.cargando.entrega = false; });
                    },

                    direccionParaApi(d) {
                        // D-58: solo viaja el CODIGO del municipio; ciudad y estado los resuelve el backend.
                        return {
                            nombre: d.nombre, apellido: d.apellido, lineas: [d.linea], pais: 'CO',
                            telefono: d.telefono, codigo_postal: d.codigo_postal || null,
                            municipio_codigo: d.municipio_codigo,
                        };
                    },

                    cargarMetodosPago() {
                        this.cargando.pago = true;

                        this.refrescarEstado(true)
                            .then((aplicado) => {
                                if (aplicado) {
                                    this.pago = this.estado.pago;
                                }
                            })
                            .finally(() => { this.cargando.pago = false; });
                    },

                    /** Cierre F1 (D1): guarda quien entrega (vacio = sin elegir: BODEGA recibe la sugerencia por historial). */
                    elegirDomiciliario(valor) {
                        const token = this.nuevaOperacion();

                        this.errorGeneral = null;
                        this.cargando.domiciliario = true;

                        this.$axios.post("{{ url($rutaNv('domiciliario', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), {
                            transportadora_id: valor ? parseInt(valor, 10) : null,
                        })
                            .then((response) => {
                                if (this.operacionVigente(token)) {
                                    this.fijarEstado(response.data);
                                }
                            })
                            .catch((error) => {
                                if (this.operacionVigente(token)) {
                                    this.versionVista++;
                                    this.errorDeOperacion(error);
                                }
                            })
                            .finally(() => { this.cargando.domiciliario = false; });
                    },

                    seleccionarPago(metodo) {
                        const token = this.nuevaOperacion();

                        this.errorGeneral = null;
                        this.cargando.pago = true;
                        this.guardandoPago = true;
                        this._pagoToken = token;

                        this.$axios.post("{{ url($rutaNv('pago', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), { metodo })
                            .then((response) => {
                                if (! this.operacionVigente(token)) {
                                    return;
                                }

                                this.fijarEstado(response.data);
                                this.pago = response.data.pago;
                            })
                            .catch((error) => {
                                if (this.operacionVigente(token)) {
                                    this.errorDeOperacion(error);
                                }
                            })
                            .finally(() => {
                                // Solo la ultima seleccion enviada apaga el aviso: una respuesta vieja no lo borra mientras otra sigue en vuelo.
                                if (this._pagoToken === token) {
                                    this.guardandoPago = false;
                                }

                                this.cargando.pago = false;
                            });
                    },

                    /** R2: advertencia de rentabilidad (solo nombres de referencia; nunca costos). Un fallo no bloquea la pantalla. */
                    cargarRentabilidad() {
                        if (this.modoCliente || ! this.borradorId || ! this.estado || ! (this.estado.lineas || []).length) {
                            this.rentabilidad = null;

                            return;
                        }

                        const id = this.borradorId;

                        this.$axios.get("{{ url($rutaNv('rentabilidad', ['borrador' => '__ID__'])) }}".replace('__ID__', id), {
                            params: { vender_para: this.venderPara, tipo_operacion: this.puedeClasificar ? this.tipoOperacion : 'venta_normal' },
                        })
                            .then((response) => {
                                if (this.borradorId === id) {
                                    this.rentabilidad = response.data;
                                }
                            })
                            .catch(() => { this.rentabilidad = null; });
                    },

                    confirmar() {
                        if (this.modoCliente) {
                            return this.confirmarCliente();
                        }

                        this.errorGeneral = null;
                        this.cargando.confirmar = true;

                        this.$axios.post("{{ url($rutaNv('confirmar', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), {
                            total_esperado: this.estado.total_esperado,
                            tipo_operacion: this.puedeClasificar ? this.tipoOperacion : 'venta_normal',
                            vender_para: this.puedeVenderPara ? this.venderPara : '',
                        })
                            .then((response) => {
                                this.confirmacion = response.data;
                                this.creditoAplicar.monto = this.creditoMaximoAplicable > 0 ? String(this.creditoMaximoAplicable) : '';
                                this.$refs.modalVentaCreada.open();
                            })
                            .catch((error) => {
                                const datos = error?.response?.data;

                                if (datos?.codigo === 'totales_cambiaron') {
                                    this.totalesCambiaron = datos.detalles;
                                    this.$refs.modalTotalesCambiaron.open();

                                    return;
                                }

                                // 409 / 422 / sin respuesta: el borrador pudo quedar `confirmando` o ya `confirmado`.
                                // Se relee el estado real; el aviso de arriba dice cual es (D-55: reintentar es idempotente).
                                this.errorDeOperacion(error);
                            })
                            .finally(() => { this.cargando.confirmar = false; });
                    },

                    /**
                     * Cotizar: el servidor guarda una foto (cliente, lineas, totales y datos de pago) y devuelve la misma si nada cambio.
                     * No crea pedido ni reserva stock. El PDF se abre con un enlace (no con window.open tras la peticion: el navegador
                     * lo bloquearia como ventana emergente).
                     */
                    cotizar() {
                        if (this.cotizando || ! this.puedeCotizar) {
                            return;
                        }

                        this.cotizando = true;
                        this.errorCotizacion = null;

                        this.$axios.post("{{ url($rutaNv('cotizaciones.store', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), {})
                            .then((response) => { this.cotizacion = response.data; })
                            .catch((error) => {
                                this.errorCotizacion = error?.response?.data?.mensaje || 'No se pudo generar la cotización. Intenta de nuevo.';
                            })
                            .finally(() => { this.cotizando = false; });
                    },

                    /** Al reanudar una venta se muestra su ultima cotizacion (la mas nueva), si existe. */
                    cargarCotizacion() {
                        if (this.modoCliente) {
                            return;
                        }

                        const id = this.borradorId;

                        this.$axios.get("{{ url($rutaNv('cotizaciones.index', ['borrador' => '__ID__'])) }}".replace('__ID__', id))
                            .then((response) => {
                                if (this.borradorId === id) {
                                    this.cotizacion = response.data[0] || null;
                                }
                            })
                            .catch(() => { /* informativo: sin cotizacion previa visible no se bloquea la venta */ });
                    },

                    copiarCotizacion() {
                        const texto = this.cotizacion?.texto || '';
                        const listo = () => this.$emitter.emit('add-flash', { type: 'success', message: 'Texto copiado. Pégalo en WhatsApp junto al PDF.' });
                        const respaldo = () => {
                            const area = document.createElement('textarea');
                            area.value = texto;
                            area.style.position = 'fixed';
                            area.style.opacity = '0';
                            document.body.appendChild(area);
                            area.select();

                            try {
                                document.execCommand('copy') ? listo() : this.$emitter.emit('add-flash', { type: 'warning', message: 'No se pudo copiar el texto.' });
                            } finally {
                                document.body.removeChild(area);
                            }
                        };

                        if (navigator.clipboard?.writeText) {
                            navigator.clipboard.writeText(texto).then(listo).catch(respaldo);
                        } else {
                            respaldo();
                        }
                    },

                    etiquetaEstadoCotizacion(estado) {
                        return { vigente: 'Vigente', vencida: 'Vencida', convertida: 'Convertida en venta', reemplazada: 'Reemplazada' }[estado] || estado;
                    },

                    fechaCotizacion(fecha) {
                        const [a, m, d] = String(fecha || '').split('-');

                        return d ? `${d}/${m}/${a}` : '';
                    },

                    /** GRP-01: abre la modal para cambiar (o restaurar) el grupo de ESTA venta. */
                    abrirModalGrupo(modo) {
                        if (! this.puedeCambiarGrupo || ! this.ventaEditable) {
                            return;
                        }

                        this.modoGrupo = modo;
                        this.grupoForm = { grupo_id: '', motivo: '' };
                        this.errorGrupo = null;
                        this.$refs.modalGrupo.open();
                    },

                    cerrarModalGrupo() {
                        if (this.cargando.grupo) {
                            return;
                        }

                        this.$refs.modalGrupo.close();
                    },

                    /**
                     * GRP-01: el backend aplica el grupo y recalcula TODO el carrito en una transaccion; aqui solo se muestra el
                     * estado que devuelve. La modal no se cierra hasta tener respuesta correcta; si falla, el grupo anterior
                     * sigue vigente y el error se muestra dentro de la modal.
                     */
                    aplicarGrupo() {
                        if (this.cargando.grupo || ! this.grupoFormValido) {
                            return;
                        }

                        const restaurar = this.modoGrupo === 'restaurar';
                        const totalAntes = this.estado?.total_esperado ?? null;
                        const hayLineas = this.hayLineasVenta;
                        const ruta = restaurar
                            ? "{{ url($rutaNv('grupo.restaurar', ['borrador' => '__ID__'])) }}"
                            : "{{ url($rutaNv('grupo.cambiar', ['borrador' => '__ID__'])) }}";
                        const cuerpo = restaurar
                            ? { motivo: this.grupoForm.motivo }
                            : { grupo_id: this.grupoForm.grupo_id, motivo: this.grupoForm.motivo };

                        this.cargando.grupo = true;
                        this.errorGrupo = null;

                        this.$axios.post(ruta.replace('__ID__', this.borradorId), cuerpo)
                            .then((response) => {
                                this.fijarEstado(response.data);
                                this.$refs.modalGrupo.close();

                                const nombre = this.grupoAplicadoNombre;
                                let mensaje = restaurar
                                    ? 'Grupo habitual ' + nombre + ' restaurado.'
                                    : 'Grupo ' + nombre + ' aplicado a esta venta.';

                                if (hayLineas) {
                                    mensaje += ' Los precios fueron recalculados.';

                                    const totalNuevo = this.estado?.total_esperado ?? null;

                                    if (totalAntes !== null && totalNuevo !== null && totalAntes !== totalNuevo) {
                                        mensaje += ' Total actualizado: ' + this.precio(totalNuevo);
                                    }
                                }

                                this.$emitter.emit('add-flash', { type: 'success', message: mensaje });
                            })
                            .catch((error) => {
                                this.errorGrupo = this.mensajeDeError(error);

                                if (this.codigoDeError(error) === 'borrador_no_editable') {
                                    this.refrescarEstado(false);
                                }
                            })
                            .finally(() => { this.cargando.grupo = false; });
                    },

                    abrirHistorialGrupo() {
                        this.historialGrupo = [];
                        this.errorHistorialGrupo = null;
                        this.cargando.historialGrupo = true;
                        this.$refs.modalHistorialGrupo.open();

                        this.$axios.get("{{ url($rutaNv('grupo.historial', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId))
                            .then((response) => { this.historialGrupo = response.data.historial || []; })
                            .catch((error) => { this.errorHistorialGrupo = this.mensajeDeError(error); })
                            .finally(() => { this.cargando.historialGrupo = false; });
                    },

                    etiquetaEventoGrupo(evento) {
                        return ({
                            override_aplicado: 'Grupo aplicado',
                            override_cambiado: 'Grupo cambiado',
                            restauracion: 'Grupo habitual restaurado',
                            override_cancelado_cambio_cliente: 'Cancelado por cambio de cliente',
                        })[evento] || evento;
                    },

                    formatoFechaGrupo(iso) {
                        const fecha = iso ? new Date(iso) : null;

                        return fecha && ! isNaN(fecha) ? fecha.toLocaleString('es-CO') : '—';
                    },

                    aceptarNuevoTotal() {
                        this.$refs.modalTotalesCambiaron.close();

                        this.refrescarEstado().then(() => {
                            this.$emitter.emit('add-flash', { type: 'info', message: 'Total actualizado. Vuelve a confirmar cuando quieras.' });
                        });
                    },

                    abrirVentasEnCurso() {
                        this.$refs.drawerEnCurso.open();
                        this.cargando.enCurso = true;

                        this.$axios.get("{{ $rutaNv('en_curso') }}")
                            .then((response) => { this.ventasEnCurso = response.data; })
                            .catch((error) => this.manejarError(error))
                            .finally(() => { this.cargando.enCurso = false; });
                    },

                    reanudar(borradorId) {
                        // Otra venta: la entrega elegida se vuelve a tomar del carrito de ESA venta.
                        this.entregaForm = { tipo: null, metodo: null };
                        this.guardadoReciente = null;
                        this.coloresSeleccionados = [];
                        this.busquedaColor = '';
                        this.direccionSeleccionadaId = null;
                        this.direccionFacturacionId = null;
                        this.direccionPuntual = null;
                        this.formDireccion.abierto = false;
                        this._direccionesCargadasPara = null;
                        this.pago = null;
                        this.cotizacion = null;
                        this.errorCotizacion = null;

                        this.borradorId = borradorId;
                        this.$refs.drawerEnCurso.close();
                        this.refrescarEstado(true).then(() => { this.pago = this.estado?.pago || null; });
                        this.cargarCotizacion();
                    },

                    reiniciar() {
                        this.cotizacion = null;
                        this.errorCotizacion = null;
                        this.tipoOperacion = 'venta_normal';
                        this.venderPara = '';
                        this.rentabilidad = null;
                        this.busquedaCliente = '';
                        this.resultadoBusqueda = null;
                        this.clienteActual = null;
                        this.referenciaVenta = null;
                        this.borradorId = null;
                        this.estado = null;
                        this.entrega = null;
                        this.pago = null;
                        this.lineasSeleccionadas = [];
                        this.coloresPorLinea = {};
                        this.coloresSeleccionados = [];
                        this.guardadoReciente = null;
                        this.busquedaColor = '';
                        this.erroresColoresLinea = {};
                        this.coloresColapsados = false;
                        this.matricesPorProducto = {};
                        this.cantidadesPorProducto = {};
                        this.erroresPorVariante = {};
                        this.errorMatrices = null;
                        this.avisoModal = null;
                        this.confirmacion = null;
                        this.totalesCambiaron = null;
                        this.direccionesCliente = [];
                        this.direccionesCargadas = false;
                        this.direccionSeleccionadaId = null;
                        this.direccionFacturacionId = null;
                        this.direccionPuntual = null;
                        this.formDireccion.abierto = false;
                        this._direccionesCargadasPara = null;
                        this.entregaForm = { tipo: null, metodo: null };
                        this.pago = null;

                        if (this.modoCliente) {
                            this.confirmacionCliente = null;
                            this.confirmacion = null;
                            this.fijarClienteAutoservicio();
                        }

                        this.$nextTick(() => this.$refs.buscadorCliente?.focus());
                    },

                    /** Modo cliente: el cliente lo fija el servidor; aqui solo se muestra su nombre y se abre el catalogo. */
                    fijarClienteAutoservicio() {
                        this.clienteActual = { customer_id: null, nombre: this.nombreCliente, apellido: '', telefono_principal: null, clienteConfirmable: true };
                        this.referenciaVenta = this.nuevaReferenciaVenta();
                    },

                    otroPedido() {
                        if (this.$refs.modalPedidoConfirmado) {
                            this.$refs.modalPedidoConfirmado.close();
                        }

                        this.pedidoConfirmado = null;
                        this.reiniciar();
                    },

                    /** P4: aplica recogida + transferencia por defecto en el servidor para que el total y el boton queden listos. */
                    prepararCliente(estado) {
                        if (this._preparando || ! (estado.lineas || []).length || estado.confirmable || estado.borrador.estado !== 'activo' || ! (estado.entrega && estado.entrega.entrega_completa)) {
                            return;
                        }

                        this._preparando = true;

                        this.$axios.post("{{ url($rutaNv('preparar', ['borrador' => '__ID__'])) }}".replace('__ID__', estado.borrador.id), { solo_pago: true })
                            .then((response) => { this.estado = response.data; })
                            .catch((error) => { this.errorGeneral = error?.response?.data?.mensaje || 'No pudimos preparar tu pedido. Inténtalo de nuevo.'; })
                            .finally(() => { this._preparando = false; });
                    },

                    confirmarCliente() {
                        this.errorGeneral = null;
                        this.cargando.confirmar = true;

                        this.$axios.post("{{ url($rutaNv('confirmar', ['borrador' => '__ID__'])) }}".replace('__ID__', this.borradorId), { total_esperado: this.estado.total_esperado })
                            .then((response) => {
                                this.confirmacionCliente = response.data;
                                // Bloquea la pantalla (ventaEditable) y abre el pedido recien creado en un modal (mismo detalle de «Mis pedidos»).
                                this.confirmacion = { cliente: true };
                                this.pedidoConfirmado = null;
                                this.$refs.modalPedidoConfirmado.open();

                                this.$axios.get("{{ route('admin.autoservicio.pedidos.show', ['pedido' => '__ID__']) }}".replace('__ID__', response.data.order_id))
                                    .then((detalle) => { this.pedidoConfirmado = detalle.data; })
                                    .catch(() => {});
                            })
                            .catch((error) => {
                                this.errorGeneral = error?.response?.data?.mensaje || 'No pudimos confirmar tu pedido. Inténtalo de nuevo.';
                                this.refrescarEstado();
                            })
                            .finally(() => { this.cargando.confirmar = false; });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
