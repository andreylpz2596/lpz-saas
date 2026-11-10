{{--
    Vista de auditoria de inventario. La logica de registro vive en
    InventarioMovimientoService; aqui solo se mejora lectura y jerarquia visual.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Movimientos de inventario</x-slot>

    <div class="bylopez-scope -m-4 min-h-[calc(100vh-64px)] bg-slate-50 p-4 max-sm:-m-3 max-sm:p-3 dark:bg-gray-950">
        <div class="flex items-start gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                <span class="icon-product"></span>
            </span>

            <div>
                <h1 class="text-[28px] font-bold leading-8 text-gray-900 dark:text-white">
                    Movimientos de inventario
                </h1>

                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">
                    Entradas y ajustes generados por los procesos de Corte y Confeccion.
                </p>
            </div>
        </div>

        <div class="mt-7 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                        <span class="icon-attribute"></span>
                    </span>

                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-gray-400">Movimientos hoy</p>
                        <p class="mt-1 text-[26px] font-bold leading-7 text-gray-900 dark:text-white">{{ $movimientosHoy }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $movimientosHoy ? 'Registrados hoy' : 'Sin actividad hoy' }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-50 text-xl text-green-600 dark:bg-green-950 dark:text-green-300">
                        <span class="icon-arrow-down"></span>
                    </span>

                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-gray-400">Entradas hoy</p>
                        <p class="mt-1 text-[26px] font-bold leading-7 text-gray-900 dark:text-white">{{ $entradasHoy }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $entradasHoy ? 'Entradas registradas' : 'Sin entradas hoy' }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-xl text-violet-600 dark:bg-violet-950 dark:text-violet-300">
                        <span class="icon-product"></span>
                    </span>

                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-gray-400">Unidades ingresadas</p>
                        <p class="mt-1 text-[26px] font-bold leading-7 text-gray-900 dark:text-white">{{ $unidadesHoy }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $unidadesHoy ? 'Unidades positivas' : 'Sin cambios hoy' }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-xl text-orange-600 dark:bg-orange-950 dark:text-orange-300">
                        <span class="icon-store"></span>
                    </span>

                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-gray-400">Talleres con actividad</p>
                        <p class="mt-1 text-[26px] font-bold leading-7 text-gray-900 dark:text-white">{{ $talleresHoy }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $talleresHoy ? 'Con movimientos hoy' : 'Sin actividad hoy' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900">
            <div class="flex min-h-14 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-gray-800 max-sm:flex-wrap">
                <div class="flex items-center gap-2.5">
                    <span class="icon-list text-xl text-blue-600"></span>

                    <p class="text-base font-semibold text-gray-900 dark:text-white">
                        Listado de movimientos
                    </p>
                </div>

                <p class="text-xs text-slate-500 dark:text-gray-400">
                    Trazabilidad de inventario
                </p>
            </div>

            <div class="px-5 pb-5 pt-4">
                <v-movimiento-detalle v-slot="{ abrir }">
                <x-admin::datagrid
                    :src="route('admin.corte_confeccion.inventario_movimientos.index')"
                    :isMultiRow="true"
                >
                    <template #header="{ isLoading, available, applied, sort }">
                        <template v-if="isLoading">
                            <x-admin::shimmer.datagrid.table.head
                                :isMultiRow="true"
                                template="1.05fr 1.15fr 2fr 2.5fr 1.45fr 1fr 0.9fr 0.45fr"
                                :groups="[1, 1, 1, 1, 2, 1, 1, 0]"
                                :massAction="false"
                            />
                        </template>

                        <template v-else>
                            <div class="row datagrid-head datagrid-head-cards grid min-w-[1180px] grid-cols-[1.05fr_1.15fr_2fr_2.5fr_1.45fr_1fr_0.9fr_0.45fr] bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-gray-950 dark:text-gray-300">
                                <div
                                    class="flex select-none items-center gap-2.5"
                                    v-for="columnGroup in [['created_at'], ['tipo_movimiento'], ['producto_sku'], ['stock_cambio'], ['orden_confeccion_codigo', 'retorno_codigo'], ['taller_nombre'], ['nombre_usuario_snapshot'], []]"
                                >
                                    <p>
                                        <span>
                                            <template v-for="column in columnGroup">
                                                <span
                                                    :class="{
                                                        'text-gray-900 dark:text-white': applied.sort.column == column,
                                                        'cursor-pointer hover:text-gray-900 dark:hover:text-white': available.columns.find(columnTemp => columnTemp.index === column)?.sortable,
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
                                template="1.05fr 1.15fr 2fr 2.5fr 1.45fr 1fr 0.9fr 0.45fr"
                                :groups="[1, 1, 1, 1, 2, 1, 1, 0]"
                                :massAction="false"
                            />
                        </template>

                        <template v-else>
                            <div
                                class="row grid min-w-[1180px] grid-cols-[1.05fr_1.15fr_2fr_2.5fr_1.45fr_1fr_0.9fr_0.45fr] items-center border-b border-slate-100 px-4 py-4 text-sm transition-all hover:bg-blue-50/40 dark:border-gray-800 dark:hover:bg-gray-950"
                                v-for="record in available.records"
                            >
                                <div v-html="record.created_at"></div>

                                <div v-html="record.tipo_movimiento"></div>

                                <div v-html="record.producto_sku"></div>

                                <div v-html="record.stock_cambio"></div>

                                <div class="flex flex-col gap-1">
                                    <div v-html="record.orden_confeccion_codigo"></div>
                                    <div v-html="record.retorno_codigo"></div>
                                </div>

                                <div v-html="record.taller_nombre"></div>

                                <div v-html="record.nombre_usuario_snapshot"></div>

                                <div
                                    class="flex justify-end"
                                    v-if="record.actions?.length"
                                >
                                    <button
                                        type="button"
                                        aria-label="Ver detalle del movimiento"
                                        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
                                        :class="action.icon"
                                        :title="action.title"
                                        v-text="! action.icon ? action.title : ''"
                                        v-for="action in record.actions"
                                        @click="abrir(action.url)"
                                    ></button>
                                </div>
                            </div>
                        </template>
                    </template>
                </x-admin::datagrid>
                </v-movimiento-detalle>
            </div>
        </div>
    </div>
    @include('corteconfeccion::admin.inventario-movimientos.modal')
</x-admin::layouts>
