{{--
    Cabecera y cuerpo replican literalmente el patron ya aprobado en Rollos, que a su
    vez sigue admin::sales.orders.index (Pedidos): mismo v-for de columnGroups en el
    header, mismo grid de celdas en el body, mismos breakpoints. Sin partials propios:
    cada celda es una celda REAL del DataGrid, con varias lineas dentro cuando agrupa
    mas de un dato.

    NIT y "usuario asociado" (la cuenta de acceso del taller, distinta del
    responsable) salen del listado porque no estan en las columnas pedidas; ambos
    siguen visibles en el detalle del taller (accion "Ver").
--}}

@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Talleres</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Talleres</p>

            <a href="{{ route('admin.corte_confeccion.talleres.create') }}" class="primary-button">Crear taller</a>
        </div>

        <x-admin::datagrid
            :src="route('admin.corte_confeccion.talleres.index')"
            :isMultiRow="true"
        >
            <template #header="{ isLoading, available, applied, sort }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head
                        :isMultiRow="true"
                        template="2fr 1.6fr 1.6fr 0.8fr 0.5fr"
                        :groups="[2, 3, 2, 1, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div class="row datagrid-head datagrid-head-cards grid grid-cols-[2fr_1.6fr_1.6fr_0.8fr_0.5fr]">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [['nombre', 'contacto', 'telefono'], ['ordenes_activas', 'prendas_asignadas', 'prendas_pendientes'], ['ordenes_terminadas', 'prendas_recibidas'], ['estado'], []]"
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
                        template="2fr 1.6fr 1.6fr 0.8fr 0.5fr"
                        :groups="[2, 3, 2, 1, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div
                        class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[2fr_1.6fr_1.6fr_0.8fr_0.5fr] dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="record in available.records"
                    >
                        <!-- Taller / contacto -->
                        <div class="flex flex-col gap-1.5">
                            <p
                                class="text-sm font-semibold sm:text-base"
                                v-html="record.nombre"
                            ></p>

                            <p
                                class="text-xs text-gray-600 sm:text-sm dark:text-gray-300"
                                v-if="record.contacto"
                            >
                                @{{ record.contacto }}
                            </p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                @{{ record.telefono ?? '—' }}
                            </p>
                        </div>

                        <!-- Produccion activa: ordenes / asignadas / pendientes -->
                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ record.ordenes_activas }} orden@{{ Number(record.ordenes_activas) === 1 ? '' : 'es' }} activa@{{ Number(record.ordenes_activas) === 1 ? '' : 's' }}
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.prendas_asignadas ?? 0).toLocaleString('es-CO') }}</span> prendas asignadas
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ Number(record.prendas_pendientes ?? 0).toLocaleString('es-CO') }} pendientes
                            </p>
                        </div>

                        <!-- Historico: ordenes terminadas / prendas recibidas -->
                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-600 dark:text-gray-300">
                                @{{ record.ordenes_terminadas }} orden@{{ Number(record.ordenes_terminadas) === 1 ? '' : 'es' }} terminada@{{ Number(record.ordenes_terminadas) === 1 ? '' : 's' }}
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.prendas_recibidas ?? 0).toLocaleString('es-CO') }}</span> prendas recibidas
                            </p>
                        </div>

                        <!-- Estado -->
                        <div class="flex flex-col gap-1.5">
                            <p>
                                <span :class="record.estado === 'activo' ? 'label-active' : 'label-canceled'">
                                    @{{ record.estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </span>
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
