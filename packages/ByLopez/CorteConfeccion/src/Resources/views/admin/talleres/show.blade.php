@include('corteconfeccion::admin.partials.estilos')
@include('corteconfeccion::admin.partials.tarifas-prestador')

<x-admin::layouts>
    <x-slot:title>{{ $taller->nombre }}</x-slot>

    <v-taller-view>
        <div class="grid">
            <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div class="flex items-center gap-2.5">
                    <p class="shimmer w-40 p-2.5"></p>
                    <p class="shimmer w-16 p-2.5"></p>
                </div>
            </div>
        </div>
    </v-taller-view>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-taller-view-template"
        >
            <div class="bylopez-scope grid">
                <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <h1
                            class="text-xl font-bold leading-6 text-gray-800 dark:text-white"
                            v-text="taller.nombre"
                        ></h1>

                        <span
                            v-if="taller.estado === 'activo'"
                            class="label-active mx-1.5 text-sm"
                        >
                            Activo
                        </span>

                        <span
                            v-else
                            class="label-canceled mx-1.5 text-sm"
                        >
                            Inactivo
                        </span>
                    </div>

                    <a
                        href="{{ route('admin.corte_confeccion.talleres.index') }}"
                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                    >
                        Volver
                    </a>
                </div>

                {{--
                    Misma franja de la vista de Cliente (admin::customers.customers.view),
                    pero con indicadores de solo lectura en vez de botones CRUD: aqui no
                    hay una accion de "crear confeccion" que tenga sentido dentro del
                    detalle de un taller ya existente.
                --}}
                <div class="mt-7 flex flex-wrap items-center gap-x-1 gap-y-2">
                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-processing text-2xl"></span>

                        @{{ resumen.ordenes_activas }} orden@{{ Number(resumen.ordenes_activas) === 1 ? '' : 'es' }} activa@{{ Number(resumen.ordenes_activas) === 1 ? '' : 's' }}
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-product text-2xl"></span>

                        @{{ Number(resumen.prendas_asignadas).toLocaleString('es-CO') }} prendas asignadas
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-pause text-2xl"></span>

                        @{{ Number(resumen.pendiente_taller).toLocaleString('es-CO') }} pendiente taller
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-ship text-2xl"></span>

                        @{{ Number(resumen.pendiente_recepcion).toLocaleString('es-CO') }} pendiente recepcion
                    </div>
                </div>

                <div class="mt-3.5 flex items-start gap-2.5 max-xl:flex-wrap">
                    <div class="flex min-w-0 flex-1 flex-col gap-2 max-xl:flex-auto">
                        {{--
                            Card "Ordenes de confeccion": misma envoltura de la card "Pedidos"
                            de Cliente (box-shadow rounded bg-white p-4). El DataGrid usa la
                            estructura compacta ya aprobada en Rollos, sin columna Taller
                            (redundante: ya estamos dentro del detalle de uno solo).
                        --}}
                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                Ordenes de confeccion
                            </p>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Ordenes asignadas a este taller.
                            </p>

                            <div class="mt-4">
                                <x-admin::datagrid
                                    :src="route('admin.corte_confeccion.ordenes_confeccion.index', ['taller_id' => $taller->id])"
                                    :isMultiRow="true"
                                >
                                    <template #header="{ isLoading, available, applied, sort }">
                                        <template v-if="isLoading">
                                            <x-admin::shimmer.datagrid.table.head
                                                :isMultiRow="true"
                                                template="1.6fr 1.8fr 1.4fr 0.8fr 0.4fr"
                                                :groups="[2, 3, 2, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.6fr_1.8fr_1.4fr_0.8fr_0.4fr]">
                                                <div
                                                    class="flex select-none items-center gap-2.5"
                                                    v-for="(columnGroup, index) in [['codigo', 'orden_corte_codigo'], ['total_entregado', 'total_reportado_taller', 'total_recibido_bodega'], ['estado', 'progreso_confeccion'], ['fecha_entrega'], []]"
                                                >
                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        <span class="[&>*]:after:content-['_/_']">
                                                            <template v-for="column in columnGroup">
                                                                <span
                                                                    class="after:content-['/'] last:after:content-['']"
                                                                    :class="{
                                                                        'font-medium text-gray-800 dark:text-white': applied.sort.column == column,
                                                                        'cursor-pointer hover:text-gray-800 dark:hover:text-white': available.columns.find(columnTemp => columnTemp.index === column)?.sortable,
                                                                    }"
                                                                    @click="
                                                                        available.columns.find(columnTemp => columnTemp.index === column)?.sortable ? sort(available.columns.find(columnTemp => columnTemp.index === column)) : {}
                                                                    "
                                                                >
                                                                    @{{ available.columns.find(columnTemp => columnTemp.index === column)?.label }}
                                                                </span>
                                                            </template>
                                                        </span>

                                                        <i
                                                            class="align-text-bottom text-base text-gray-800 dark:text-white ltr:ml-1.5 rtl:mr-1.5"
                                                            :class="[applied.sort.order === 'asc' ? 'icon-down-stat': 'icon-up-stat']"
                                                            v-if="columnGroup.includes(applied.sort.column)"
                                                        ></i>
                                                    </p>
                                                </div>
                                            </div>
                                        </template>
                                    </template>

                                    <template #body="{ isLoading, available, performAction }">
                                        <template v-if="isLoading">
                                            <x-admin::shimmer.datagrid.table.body
                                                :isMultiRow="true"
                                                template="1.6fr 1.8fr 1.4fr 0.8fr 0.4fr"
                                                :groups="[2, 3, 2, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div
                                                class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.6fr_1.8fr_1.4fr_0.8fr_0.4fr] dark:border-gray-800 dark:hover:bg-gray-950"
                                                v-for="record in available.records"
                                            >
                                                <!-- Orden / corte de origen -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p
                                                        class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white"
                                                        v-html="record.codigo"
                                                    >
                                                    </p>

                                                    <p
                                                        class="text-xs sm:text-sm"
                                                        v-html="record.orden_corte_codigo"
                                                    ></p>
                                                </div>

                                                <!-- Produccion: entregado / reportado / recibido -->
                                                <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        Entregado: <span class="font-semibold text-gray-800 dark:text-white">@{{ record.total_entregado }}</span>
                                                    </p>

                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        Reportado: <span class="font-semibold text-gray-800 dark:text-white">@{{ record.total_reportado_taller }}</span>
                                                    </p>

                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        Recibido: <span class="font-semibold text-gray-800 dark:text-white">@{{ record.total_recibido_bodega }}</span>
                                                    </p>
                                                </div>

                                                <!-- Estado / progreso -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p v-html="record.estado"></p>

                                                    <div v-html="record.progreso_confeccion"></div>
                                                </div>

                                                <!-- Entrega -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                                        @{{ record.fecha_entrega ?? '—' }}
                                                    </p>
                                                </div>

                                                <!-- Accion -->
                                                <div
                                                    class="flex items-center gap-x-0.5 max-sm:justify-end"
                                                    v-if="record.actions?.length"
                                                >
                                                    <span
                                                        class="cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-200 dark:hover:bg-gray-800"
                                                        :class="action.icon"
                                                        :title="action.title"
                                                        v-text="! action.icon ? action.title : ''"
                                                        v-for="action in record.actions"
                                                        @click="performAction(action)"
                                                    ></span>
                                                </div>
                                            </div>
                                        </template>
                                    </template>
                                </x-admin::datagrid>
                            </div>
                        </div>

                        @include('corteconfeccion::admin.partials.reasignaciones-taller', ['reasignacionesTaller' => $reasignacionesTaller, 'tallerId' => $taller->id])

                        <v-referencias-tarifa
                            titulo="Referencias y precios de confeccion"
                            subtitulo="Valor de confeccion por unidad para cada tipo de tela y referencia de producto. Cada referencia se configura por separado."
                            :puede-gestionar="{{ $puedeGestionarTarifas ? 'true' : 'false' }}"
                            grid-url="{{ route('admin.corte_confeccion.talleres.referencias.index', $taller->id) }}"
                            historial-url="{{ route('admin.corte_confeccion.talleres.referencias.historial', $taller->id) }}"
                            store-url="{{ route('admin.corte_confeccion.talleres.tarifas.store', $taller->id) }}"
                            anular-url="{{ route('admin.corte_confeccion.talleres.tarifas.anular', ['id' => $taller->id, 'tarifaId' => '__ID__']) }}"
                            :opciones="opcionesTarifa"
                            :aviso="tarifaGeneralAnterior"
                        ></v-referencias-tarifa>

                        @include('corteconfeccion::admin.partials.cartera-resumen', ['carteraResumen' => \ByLopez\CorteConfeccion\Support\CarteraVista::resumenFicha($taller)])

                        {{--
                            Segundo bloque, reservado. Misma envoltura que la card "Facturas"
                            de Cliente. Todavia no hay un dato real de pagos, movimientos o
                            recepciones a nivel de taller para mostrar aqui sin inventarlo;
                            queda estructuralmente lista para cuando exista.
                        --}}
                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                Historial del taller
                            </p>

                            <div class="mt-6 grid justify-center justify-items-center gap-3 px-2.5 py-6 text-center">
                                <span class="icon-report text-4xl text-gray-300 dark:text-gray-700"></span>

                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Aqui se mostraran recepciones, pagos o incidencias del taller cuando el modulo los registre.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex w-[360px] max-w-full shrink-0 flex-col gap-2 max-xl:w-full max-sm:w-full">
                        <x-admin::accordion>
                            <x-slot:header>
                                <div class="flex w-full">
                                    <p class="w-full p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                        Taller
                                    </p>

                                    @if (bouncer()->hasPermission('corte_confeccion.talleres.edit'))
                                        <v-taller-edit
                                            :taller="taller"
                                            :usuarios="usuariosTaller"
                                            variant="link"
                                            @update-taller="updateTaller"
                                        ></v-taller-edit>
                                    @endif
                                </div>
                            </x-slot>

                            <x-slot:content>
                                <div class="grid gap-y-2.5">
                                    <p
                                        class="break-all font-semibold text-gray-800 dark:text-white"
                                        v-text="taller.nombre"
                                    ></p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Responsable: @{{ taller.contacto || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Telefono: @{{ taller.telefono || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Direccion: @{{ taller.direccion || 'Sin direccion registrada' }}
                                    </p>

                                    <p
                                        class="break-all text-gray-600 dark:text-gray-300"
                                        v-if="taller.usuario"
                                    >
                                        Usuario asociado: @{{ taller.usuario.name }} (@{{ taller.usuario.email }})
                                    </p>

                                    <p
                                        v-if="taller.estado === 'activo'"
                                        class="label-active w-max text-sm"
                                    >
                                        Activo
                                    </p>

                                    <p
                                        v-else
                                        class="label-canceled w-max text-sm"
                                    >
                                        Inactivo
                                    </p>
                                </div>
                            </x-slot>
                        </x-admin::accordion>
                    </div>
                </div>
            </div>
        </script>

        @if (bouncer()->hasPermission('corte_confeccion.talleres.edit'))
            @include('corteconfeccion::admin.talleres.edit-modal')
        @endif

        <script type="module">
            app.component('v-taller-view', {
                template: '#v-taller-view-template',

                data() {
                    return {
                        taller: @json($tallerPayload),

                        usuariosTaller: @json($usuariosTaller),

                        resumen: @json($resumen),

                        opcionesTarifa: @json($opcionesTarifa),

                        tarifaGeneralAnterior: @json($tarifaGeneralAnterior),
                    };
                },

                methods: {
                    updateTaller(taller) {
                        this.taller = {
                            ...this.taller,
                            ...taller,
                        };
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
