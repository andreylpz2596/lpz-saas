@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Ordenes de corte</x-slot>

    <div class="bylopez-scope">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Ordenes de corte</p>

            <a href="{{ route('admin.corte_confeccion.ordenes_corte.create') }}" class="primary-button">Crear orden</a>
        </div>

        <x-admin::datagrid
            :src="route('admin.corte_confeccion.ordenes_corte.index')"
            :isMultiRow="true"
        >
            <template #header="{ isLoading, available, applied, sort }">
                <template v-if="isLoading">
                    <x-admin::shimmer.datagrid.table.head
                        :isMultiRow="true"
                        template="1.4fr 1.6fr 1.8fr 0.5fr"
                        :groups="[2, 2, 3, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.4fr_1.6fr_1.8fr_0.5fr]">
                        <div
                            class="flex select-none items-center gap-2.5"
                            v-for="(columnGroup, index) in [['codigo', 'estado'], ['cortador_nombre_snapshot', 'fecha_entrega'], ['cantidad_rollos', 'peso_total', 'total_camisetas_cortadas'], []]"
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
                        template="1.4fr 1.6fr 1.8fr 0.5fr"
                        :groups="[2, 2, 3, 0]"
                        :massAction="false"
                    />
                </template>

                <template v-else>
                    <div
                        class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.4fr_1.6fr_1.8fr_0.5fr] dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="record in available.records"
                    >
                        <!-- Codigo / estado -->
                        <div class="flex flex-col gap-1.5">
                            <p
                                class="text-sm font-semibold sm:text-base"
                                v-html="record.codigo"
                            ></p>

                            <p v-html="record.estado"></p>
                        </div>

                        <!-- Cortador / fecha de entrega -->
                        <div class="flex flex-col gap-1.5">
                            <p class="text-sm text-gray-800 sm:text-base dark:text-white">
                                @{{ record.cortador_nombre_snapshot ?? '—' }}
                            </p>

                            <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                Entrega: @{{ record.fecha_entrega ?? '—' }}
                            </p>
                        </div>

                        <!-- Produccion: rollos / peso / camisetas cortadas -->
                        <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                            <p class="text-gray-600 dark:text-gray-300">
                                Rollos: <span class="font-semibold text-gray-800 dark:text-white">@{{ record.cantidad_rollos ?? 0 }}</span>
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                Peso: <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.peso_total ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) }} kg</span>
                            </p>

                            <p class="text-gray-600 dark:text-gray-300">
                                Camisetas: <span class="font-semibold text-gray-800 dark:text-white">@{{ Number(record.total_camisetas_cortadas ?? 0).toLocaleString('es-CO') }}</span>
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
