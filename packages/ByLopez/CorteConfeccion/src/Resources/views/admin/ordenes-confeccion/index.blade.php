{{--
    Cabecera y cuerpo replican literalmente el patron de admin::sales.orders.index
    (Pedidos): mismo v-for de columnGroups en el header, mismo grid de celdas en el
    body, mismos breakpoints. No hay partials propios: cada celda del grid es una
    celda REAL del DataGrid, con varias lineas dentro cuando agrupa mas de un dato.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>{{ $titulo ?? 'Ordenes de confeccion' }}</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $titulo ?? 'Ordenes de confeccion' }}</p>
        </div>

        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ $descripcion ?? 'Las ordenes de confeccion se crean desde una orden de corte ya recibida en bodega, usando el boton "Distribuir a talleres".' }}
        </p>

        <x-admin::datagrid
            :src="$dataGridRoute ?? route('admin.corte_confeccion.ordenes_confeccion.index')"
            :isMultiRow="true"
        >
            <template #header="{ isLoading, available, applied, sort }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head
                        :isMultiRow="true"
                        template="2fr 1.4fr 2fr 1.4fr 0.6fr"
                        :groups="[3, 3, 3, 2, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div class="row datagrid-head datagrid-head-cards grid grid-cols-[2fr_1.4fr_2fr_1.4fr_0.6fr]">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [['codigo', 'orden_corte_codigo', 'corte_fecha_recepcion'], ['taller_nombre', 'estado', 'retornos_pendientes'], ['total_entregado', 'total_reportado_taller', 'total_recibido_bodega'], ['progreso_confeccion', 'fecha_entrega'], []]"
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
                        template="2fr 1.4fr 2fr 1.4fr 0.6fr"
                        :groups="[3, 3, 3, 2, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div
                        class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[2fr_1.4fr_2fr_1.4fr_0.6fr] dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="record in available.records"
                    >
                        <!-- Orden / corte de origen / fecha de recepcion del corte -->
                        <div class="flex flex-col gap-1.5">
                            <p
                                class="text-sm font-semibold sm:text-base"
                                v-html="record.codigo"
                            ></p>

                            <p
                                class="text-xs sm:text-sm"
                                v-html="record.orden_corte_codigo"
                            ></p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                Corte recibido: @{{ record.corte_fecha_recepcion ?? '—' }}
                            </p>
                        </div>

                        <!-- Taller / estado -->
                        <div class="flex flex-col gap-1.5">
                            <p class="text-sm text-gray-800 sm:text-base dark:text-white">
                                @{{ record.taller_nombre }}
                            </p>

                            <p v-html="record.estado"></p>

                            <p v-html="record.retornos_pendientes"></p>
                        </div>

                        <!-- Cantidades: entregado / reportado / recibido -->
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

                        <!-- Progreso / fecha de entrega -->
                        <div class="flex flex-col gap-1.5">
                            <div v-html="record.progreso_confeccion"></div>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                Entrega: @{{ record.fecha_entrega ?? '—' }}
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
</x-admin::layouts>
