{{--
    Ficha de detalle del proveedor, siguiendo el mismo patron ya aprobado en el
    detalle de Taller (que a su vez sigue admin::customers.customers.view):
    cabecera con estado, franja de indicadores en vez de botones CRUD, bloque
    principal (Compras de tela, equivalente a Pedidos), bloque secundario
    (Referencias que ofrece, equivalente a Facturas) y panel lateral unico
    "Proveedor".

    Distincion que gobierna toda esta vista: el proveedor OFRECE referencias
    comerciales (catalogo); una Compra de tela RECIBE rollos fisicos. Las
    referencias nunca se tratan aqui como inventario.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>{{ $proveedor->nombre }}</x-slot>

    <v-proveedor-view>
        <div class="grid">
            <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div class="flex items-center gap-2.5">
                    <p class="shimmer w-40 p-2.5"></p>
                    <p class="shimmer w-16 p-2.5"></p>
                </div>
            </div>
        </div>
    </v-proveedor-view>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-proveedor-view-template"
        >
            <div class="bylopez-scope grid">
                <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <h1
                            class="text-xl font-bold leading-6 text-gray-800 dark:text-white"
                            v-text="proveedor.nombre"
                        ></h1>

                        <span
                            v-if="proveedor.estado === 'activo'"
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
                        href="{{ route('admin.corte_confeccion.proveedores_tela.index') }}"
                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                    >
                        Volver
                    </a>
                </div>

                {{--
                    Misma franja de la vista de Cliente/Taller, con indicadores de solo
                    lectura: aqui no hay una accion CRUD que tenga sentido (crear una
                    compra o una referencia se hace desde sus propias pantallas, con
                    contexto completo).
                --}}
                <div class="mt-7 flex flex-wrap items-center gap-x-1 gap-y-2">
                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-cart text-2xl"></span>

                        @{{ resumen.total_compras }} compra@{{ Number(resumen.total_compras) === 1 ? '' : 's' }} realizada@{{ Number(resumen.total_compras) === 1 ? '' : 's' }}
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-sales text-2xl"></span>

                        $@{{ Number(resumen.total_comprado).toLocaleString('es-CO', { maximumFractionDigits: 0 }) }} comprado
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-product text-2xl"></span>

                        @{{ resumen.total_rollos_comprados }} rollo@{{ Number(resumen.total_rollos_comprados) === 1 ? '' : 's' }} comprado@{{ Number(resumen.total_rollos_comprados) === 1 ? '' : 's' }}
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-attribute text-2xl"></span>

                        @{{ resumen.referencias_activas }} referencia@{{ Number(resumen.referencias_activas) === 1 ? '' : 's' }} activa@{{ Number(resumen.referencias_activas) === 1 ? '' : 's' }}
                    </div>
                </div>

                <div class="mt-3.5 flex items-start gap-2.5 max-xl:flex-wrap">
                    <div class="flex min-w-0 flex-1 flex-col gap-2 max-xl:flex-auto">
                        {{--
                            Bloque principal: Compras de tela. Equivalente a la card
                            "Pedidos" de Cliente. No repite el nombre del proveedor en
                            cada fila: ya estamos dentro de su ficha.
                        --}}
                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                Compras de tela
                            </p>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Compras registradas a este proveedor.
                            </p>

                            <div class="mt-4">
                                <x-admin::datagrid
                                    :src="route('admin.corte_confeccion.compras_tela.index', ['proveedor_id' => $proveedor->id])"
                                    :isMultiRow="true"
                                >
                                    <template #header="{ isLoading, available, applied, sort }">
                                        <template v-if="isLoading">
                                            <x-admin::shimmer.datagrid.table.head
                                                :isMultiRow="true"
                                                template="1.4fr 1.8fr 0.8fr 0.4fr"
                                                :groups="[2, 3, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.4fr_1.8fr_0.8fr_0.4fr]">
                                                <div
                                                    class="flex select-none items-center gap-2.5"
                                                    v-for="(columnGroup, index) in [['codigo', 'fecha_compra'], ['total_factura', 'cantidad_rollos', 'peso_total'], ['estado'], []]"
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
                                                template="1.4fr 1.8fr 0.8fr 0.4fr"
                                                :groups="[2, 3, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div
                                                class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.4fr_1.8fr_0.8fr_0.4fr] dark:border-gray-800 dark:hover:bg-gray-950"
                                                v-for="record in available.records"
                                            >
                                                <!-- Compra / fecha -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">
                                                        @{{ record.codigo }}
                                                    </p>

                                                    <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                                        @{{ record.fecha_compra ?? '—' }}
                                                    </p>
                                                </div>

                                                <!-- Resumen: valor / rollos / peso -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">
                                                        @{{ '$' + Number(record.total_factura ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 }) }}
                                                    </p>

                                                    <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                                        @{{ record.cantidad_rollos ?? 0 }} rollo@{{ Number(record.cantidad_rollos) === 1 ? '' : 's' }} · @{{ Number(record.peso_total ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) }} kg
                                                    </p>
                                                </div>

                                                <!-- Estado -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p v-html="record.estado"></p>
                                                </div>

                                                <!-- Accion: ver compra -->
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

                        {{--
                            Bloque secundario: Referencias que ofrece. Equivalente a la
                            card "Facturas" de Cliente. Son entradas de catalogo comercial,
                            no inventario: nunca se toca bylopez_cc_rollos desde aqui.
                        --}}
                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                                <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                    Referencias que ofrece
                                </p>

                                @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit'))
                                    <button
                                        type="button"
                                        class="primary-button whitespace-nowrap"
                                        @click="openCrearReferencia"
                                    >
                                        Agregar referencia
                                    </button>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Catalogo de referencias comerciales de este proveedor.
                            </p>

                            <div class="mt-4">
                                <x-admin::datagrid
                                    :src="route('admin.corte_confeccion.proveedores_tela.referencias', ['proveedor_id' => $proveedor->id])"
                                    :isMultiRow="true"
                                    ref="referenciasDatagrid"
                                >
                                    <template #header="{ isLoading, available, applied, sort }">
                                        <template v-if="isLoading">
                                            <x-admin::shimmer.datagrid.table.head
                                                :isMultiRow="true"
                                                template="1.6fr 1.4fr 1fr 0.8fr 0.4fr"
                                                :groups="[2, 2, 1, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div class="row datagrid-head datagrid-head-cards grid grid-cols-[1.6fr_1.4fr_1fr_0.8fr_0.4fr]">
                                                <div
                                                    class="flex select-none items-center gap-2.5"
                                                    v-for="(columnGroup, index) in [['tipo_tela', 'referencia'], ['color', 'gramaje'], ['valor_kilo_referencia'], ['estado'], []]"
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
                                                template="1.6fr 1.4fr 1fr 0.8fr 0.4fr"
                                                :groups="[2, 2, 1, 1, 0]"
                                                :massAction="false"
                                            />
                                        </template>

                                        <template v-else>
                                            <div
                                                class="row grid grid-cols-1 gap-y-4 border-b px-2 py-2.5 transition-all hover:bg-gray-50 sm:grid-cols-2 sm:px-4 md:grid-cols-[1.6fr_1.4fr_1fr_0.8fr_0.4fr] dark:border-gray-800 dark:hover:bg-gray-950"
                                                v-for="record in available.records"
                                            >
                                                <!-- Tela / referencia -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p class="text-sm font-semibold text-gray-800 sm:text-base dark:text-white">
                                                        @{{ record.tipo_tela }}
                                                    </p>

                                                    <p class="text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                                                        Ref. @{{ record.referencia }}
                                                    </p>
                                                </div>

                                                <!-- Especificacion: color / gramaje -->
                                                <div class="flex flex-col gap-1.5 text-xs sm:text-sm">
                                                    <p class="flex items-center gap-2 text-gray-800 dark:text-white">
                                                        <span
                                                            class="h-4 w-4 shrink-0 rounded-full border dark:border-gray-700"
                                                            :style='{ backgroundColor: @json($coloresTela->pluck("swatch_value", "id"))[record.color_option_id] || "#e5e7eb" }'
                                                            aria-hidden="true"
                                                        ></span>

                                                        <span>@{{ record.color }}</span>
                                                    </p>

                                                    <p class="text-gray-600 dark:text-gray-300">
                                                        @{{ record.gramaje }} g
                                                    </p>
                                                </div>

                                                <!-- Precio referencia -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p class="text-sm text-gray-800 sm:text-base dark:text-white">
                                                        <template v-if="record.valor_kilo_referencia">
                                                            $@{{ Number(record.valor_kilo_referencia).toLocaleString('es-CO', { maximumFractionDigits: 0 }) }} / kg
                                                        </template>

                                                        <template v-else>—</template>
                                                    </p>
                                                </div>

                                                <!-- Estado -->
                                                <div class="flex flex-col gap-1.5">
                                                    <p v-html="record.estado"></p>
                                                </div>

                                                <!-- Accion: editar -->
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
                                                        @click="action.title === 'Editar' ? openEditarReferencia(record) : performAction(action)"
                                                    ></span>
                                                </div>
                                            </div>
                                        </template>
                                    </template>
                                </x-admin::datagrid>
                            </div>
                        </div>
                    </div>

                    {{--
                        Panel lateral unico "Proveedor", mismo patron que el detalle de
                        Taller: identidad, contacto, tipos de tela (texto compacto, no la
                        matriz de checkboxes) y estado, todo en una sola tarjeta.
                    --}}
                    <div class="flex w-[360px] max-w-full shrink-0 flex-col gap-2 max-xl:w-full max-sm:w-full">
                        <x-admin::accordion>
                            <x-slot:header>
                                <div class="flex w-full">
                                    <p class="w-full p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                        Proveedor
                                    </p>

                                    @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit'))
                                        <v-proveedor-edit
                                            :proveedor="proveedor"
                                            variant="link"
                                            @update-proveedor="updateProveedor"
                                        ></v-proveedor-edit>
                                    @endif
                                </div>
                            </x-slot>

                            <x-slot:content>
                                <div class="grid gap-y-2.5">
                                    <p
                                        class="break-all font-semibold text-gray-800 dark:text-white"
                                        v-text="proveedor.nombre"
                                    ></p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        NIT: @{{ proveedor.nit || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Contacto: @{{ proveedor.contacto || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Telefono: @{{ proveedor.telefono || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Direccion: @{{ proveedor.direccion || 'Sin direccion registrada' }}
                                    </p>

                                    <p
                                        class="text-gray-600 dark:text-gray-300"
                                        v-if="proveedor.observacion"
                                    >
                                        Observacion: @{{ proveedor.observacion }}
                                    </p>

                                    <p
                                        v-if="proveedor.estado === 'activo'"
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

                        <x-admin::accordion>
                            <x-slot:header>
                                <div class="flex w-full items-center justify-between gap-2.5">
                                    <p class="w-full p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                        Tipos de tela
                                    </p>

                                    @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit'))
                                        <button
                                            type="button"
                                            class="secondary-button whitespace-nowrap"
                                            @click.stop="openEditarTipos"
                                        >
                                            Editar tipos
                                        </button>
                                    @endif
                                </div>
                            </x-slot>

                            <x-slot:content>
                                <div class="grid gap-2.5">
                                    <div
                                        class="flex flex-wrap gap-1.5"
                                        v-if="proveedor.tipos_tela.length"
                                    >
                                        <span
                                            class="rounded border px-2 py-1 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300"
                                            v-for="tipoTela in proveedor.tipos_tela"
                                            :key="tipoTela"
                                        >
                                            @{{ tipoTela }}
                                        </span>
                                    </div>

                                    <p
                                        class="text-gray-600 dark:text-gray-300"
                                        v-else
                                    >
                                        Sin tipos asociados
                                    </p>
                                </div>
                            </x-slot>
                        </x-admin::accordion>
                    </div>
                </div>

                @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit'))
                    <v-proveedor-tipos-modal
                        ref="tiposModal"
                        :proveedor="proveedor"
                        :tipos-tela='@json($tiposTelaCatalogo->map(fn ($tipoTela) => ['id' => (int) $tipoTela->id, 'nombre' => $tipoTela->admin_name])->values())'
                        @saved="tiposGuardados"
                    ></v-proveedor-tipos-modal>

                    <v-proveedor-referencia-modal
                        ref="referenciaModal"
                        :proveedor="proveedor"
                        :tipos-tela='@json($tiposTelaProveedor->map(fn ($tipoTela) => ['id' => $tipoTela->id, 'admin_name' => $tipoTela->admin_name])->values())'
                        :colores='@json($coloresTela->map(fn ($color) => ['id' => $color->id, 'admin_name' => $color->admin_name, 'swatch_value' => $color->swatch_value])->values())'
                        @saved="referenciaGuardada"
                    ></v-proveedor-referencia-modal>
                @endif
            </div>
        </script>

        @if (bouncer()->hasPermission('corte_confeccion.proveedores_tela.edit'))
            @include('corteconfeccion::admin.proveedores-tela.edit-modal')

            <script
                type="text/x-template"
                id="v-proveedor-tipos-modal-template"
            >
                <x-admin::form
                    v-slot="{ errors, handleSubmit }"
                    as="div"
                    ::key="formKey"
                >
                    <form
                        @submit="handleSubmit($event, save)"
                        ref="tiposForm"
                    >
                        <input type="hidden" name="nombre" :value="proveedor.nombre">
                        <input type="hidden" name="nit" :value="proveedor.nit">
                        <input type="hidden" name="telefono" :value="proveedor.telefono">
                        <input type="hidden" name="contacto" :value="proveedor.contacto">
                        <input type="hidden" name="direccion" :value="proveedor.direccion">
                        <input type="hidden" name="estado" :value="proveedor.estado">
                        <input type="hidden" name="observacion" :value="proveedor.observacion">

                        <template v-for="tipoTelaId in selectedTipoTelaIds">
                            <input
                                type="hidden"
                                name="tipo_tela_ids[]"
                                :value="tipoTelaId"
                            >
                        </template>

                        <x-admin::modal
                            ref="tiposModal"
                            panel-class="max-w-[768px]"
                        >
                            <x-slot:header>
                                <p class="text-lg font-bold text-gray-800 dark:text-white">
                                    Editar tipos de tela
                                </p>
                            </x-slot>

                            <x-slot:content>
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        Tipos de tela
                                    </x-admin::form.control-group.label>

                                    <div
                                        class="grid grid-cols-2 gap-3 rounded border p-4 dark:border-gray-800 max-sm:grid-cols-1"
                                        :class="[errors['tipo_tela_ids'] || errors['tipo_tela_ids.0'] ? 'border border-red-600 hover:border-red-600' : '']"
                                    >
                                        <label
                                            class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                                            v-for="tipoTela in tiposTela"
                                            :key="tipoTela.id"
                                        >
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                                :value="tipoTela.id"
                                                v-model="selectedTipoTelaIds"
                                            >

                                            <span>@{{ tipoTela.nombre }}</span>
                                        </label>
                                    </div>

                                    <x-admin::form.control-group.error control-name="tipo_tela_ids" />
                                    <x-admin::form.control-group.error control-name="tipo_tela_ids.0" />
                                </x-admin::form.control-group>

                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Si un tipo ya tiene referencias o compras asociadas, el sistema no permitira retirarlo.
                                </p>
                            </x-slot>

                            <x-slot:footer>
                                <div class="flex items-center gap-2.5">
                                    <button
                                        type="button"
                                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                                        :disabled="isLoading"
                                        @click="closeModal"
                                    >
                                        Cancelar
                                    </button>

                                    <x-admin::button
                                        button-type="submit"
                                        class="primary-button justify-center"
                                        title="Guardar cambios"
                                        ::loading="isLoading"
                                        ::disabled="isLoading"
                                    />
                                </div>
                            </x-slot>
                        </x-admin::modal>
                    </form>
                </x-admin::form>
            </script>

            <script type="module">
                app.component('v-proveedor-tipos-modal', {
                    template: '#v-proveedor-tipos-modal-template',

                    props: {
                        proveedor: {
                            type: Object,
                            required: true,
                        },

                        tiposTela: {
                            type: Array,
                            required: true,
                        },
                    },

                    emits: ['saved'],

                    data() {
                        return {
                            formKey: 0,
                            isLoading: false,
                            selectedTipoTelaIds: [],
                        };
                    },

                    methods: {
                        openModal() {
                            this.selectedTipoTelaIds = [...this.proveedor.tipo_tela_ids];
                            this.formKey++;

                            this.$nextTick(() => {
                                this.$refs.tiposModal.open();
                                this.centerModal();
                            });
                        },

                        centerModal() {
                            this.$nextTick(() => {
                                let root = this.$refs.tiposModal?.$el;
                                let content = root?.querySelector('.fixed.inset-0.transform');
                                let wrapper = content?.firstElementChild;
                                let panel = wrapper?.querySelector('.box-shadow');

                                if (content) {
                                    content.style.height = '100dvh';
                                    content.style.maxHeight = '100dvh';
                                    content.style.overflowY = 'auto';
                                }

                                if (wrapper) {
                                    wrapper.style.minHeight = '100dvh';
                                    wrapper.style.alignItems = 'center';
                                    wrapper.style.justifyContent = 'center';
                                    wrapper.style.padding = '16px';
                                }

                                if (panel) {
                                    panel.style.width = 'min(768px, calc(100vw - 32px))';
                                    panel.style.maxWidth = '768px';
                                    panel.style.marginTop = 'auto';
                                    panel.style.marginBottom = 'auto';
                                }
                            });
                        },

                        closeModal() {
                            this.$refs.tiposModal.close();
                        },

                        save(params, { setErrors }) {
                            this.isLoading = true;

                            let formData = new FormData(this.$refs.tiposForm);

                            formData.append('_method', 'put');

                            this.$axios.post('{{ route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id) }}', formData)
                                .then((response) => {
                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                                    this.$emit('saved', response.data.data);

                                    this.isLoading = false;

                                    this.$refs.tiposModal.close();
                                })
                                .catch((error) => {
                                    this.isLoading = false;

                                    if (error.response.status == 422) {
                                        setErrors(error.response.data.errors);
                                    }
                                });
                        },
                    },
                });
            </script>

            <script
                type="text/x-template"
                id="v-proveedor-referencia-modal-template"
            >
                <x-admin::form
                    v-slot="{ errors, handleSubmit }"
                    as="div"
                    ::key="formKey"
                >
                    <form
                        @submit="handleSubmit($event, save)"
                        ref="referenciaForm"
                    >
                        <input type="hidden" name="nombre" :value="proveedor.nombre">
                        <input type="hidden" name="nit" :value="proveedor.nit">
                        <input type="hidden" name="telefono" :value="proveedor.telefono">
                        <input type="hidden" name="contacto" :value="proveedor.contacto">
                        <input type="hidden" name="direccion" :value="proveedor.direccion">
                        <input type="hidden" name="estado" :value="proveedor.estado">
                        <input type="hidden" name="observacion" :value="proveedor.observacion">

                        <template v-for="tipoTelaId in proveedor.tipo_tela_ids">
                            <input
                                type="hidden"
                                name="tipo_tela_ids[]"
                                :value="tipoTelaId"
                            >
                        </template>

                        <input
                            v-if="isEditing"
                            type="hidden"
                            name="referencias[0][id]"
                            :value="form.id"
                        >

                        <x-admin::modal
                            ref="referenciaModal"
                            panel-class="max-w-[768px]"
                        >
                            <x-slot:header>
                                <p class="text-lg font-bold text-gray-800 dark:text-white">
                                    @{{ isEditing ? 'Editar referencia' : 'Agregar referencia' }}
                                </p>
                            </x-slot>

                            <x-slot:content>
                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Tipo de tela
                                        </x-admin::form.control-group.label>

                                        <v-field
                                            name="referencias[0][tipo_tela_id]"
                                            rules="required"
                                            label="Tipo de tela"
                                            v-model="form.tipo_tela_id"
                                        >
                                            <select
                                                name="referencias[0][tipo_tela_id]"
                                                class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                :class="[errors['referencias[0][tipo_tela_id]'] || errors['referencias.0.tipo_tela_id'] ? 'border border-red-600 hover:border-red-600' : '']"
                                                v-model="form.tipo_tela_id"
                                            >
                                                <option value="">
                                                    Seleccionar tipo de tela
                                                </option>

                                                <option
                                                    v-for="tipoTela in tiposTela"
                                                    :key="tipoTela.id"
                                                    :value="tipoTela.id"
                                                >
                                                    @{{ tipoTela.admin_name }}
                                                </option>
                                            </select>
                                        </v-field>

                                        <x-admin::form.control-group.error control-name="referencias.0.tipo_tela_id" />

                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Solo se muestran los tipos de tela ya asociados a este proveedor.
                                        </p>
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Color
                                        </x-admin::form.control-group.label>

                                        <div class="flex items-center gap-2">
                                            <span
                                                class="h-6 w-6 shrink-0 rounded-full border dark:border-gray-700"
                                                :style="{ backgroundColor: swatchActual }"
                                            ></span>

                                            <v-field
                                                name="referencias[0][color_option_id]"
                                                rules="required"
                                                label="Color"
                                                v-model="form.color_option_id"
                                            >
                                                <select
                                                    name="referencias[0][color_option_id]"
                                                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                    :class="[errors['referencias[0][color_option_id]'] || errors['referencias.0.color_option_id'] ? 'border border-red-600 hover:border-red-600' : '']"
                                                    v-model="form.color_option_id"
                                                >
                                                    <option value="">Seleccionar color del catálogo</option>

                                                    <option
                                                        v-for="color in colores"
                                                        :key="color.id"
                                                        :value="color.id"
                                                    >
                                                        @{{ color.admin_name }}
                                                    </option>
                                                </select>
                                            </v-field>
                                        </div>

                                        <x-admin::form.control-group.error control-name="referencias.0.color_option_id" />

                                        <div
                                            class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                                            v-if="form.es_historica_sin_vincular"
                                        >
                                            <p class="font-semibold">Color sin vincular al catálogo</p>

                                            <p
                                                class="mt-1"
                                                v-if="form.color"
                                            >
                                                Color histórico: @{{ form.color }}
                                            </p>
                                        </div>
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Referencia
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="referencias[0][referencia]"
                                            v-model="form.referencia"
                                            rules="required"
                                            label="Referencia"
                                            placeholder="Codigo de referencia"
                                        />

                                        <x-admin::form.control-group.error control-name="referencias.0.referencia" />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Gramaje
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="referencias[0][gramaje]"
                                            v-model="form.gramaje"
                                            rules="required"
                                            label="Gramaje"
                                            placeholder="Ej. 175"
                                        />

                                        <x-admin::form.control-group.error control-name="referencias.0.gramaje" />
                                    </x-admin::form.control-group>
                                </div>

                                <div class="flex gap-4 max-sm:flex-wrap">
                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label>
                                            Precio referencia (por kilo)
                                        </x-admin::form.control-group.label>

                                        <x-admin::form.control-group.control
                                            type="text"
                                            name="referencias[0][valor_kilo_referencia]"
                                            v-model="form.valor_kilo_referencia"
                                            label="Precio referencia"
                                            placeholder="Opcional"
                                        />

                                        <x-admin::form.control-group.error control-name="referencias.0.valor_kilo_referencia" />

                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Valor sugerido del catalogo. Cada compra conserva su propio valor real pagado.
                                        </p>
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group class="mb-2.5 w-full">
                                        <x-admin::form.control-group.label class="required">
                                            Estado
                                        </x-admin::form.control-group.label>

                                        <v-field
                                            name="referencias[0][estado]"
                                            rules="required"
                                            label="Estado"
                                            v-model="form.estado"
                                        >
                                            <select
                                                name="referencias[0][estado]"
                                                class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                :class="[errors['referencias[0][estado]'] || errors['referencias.0.estado'] ? 'border border-red-600 hover:border-red-600' : '']"
                                                v-model="form.estado"
                                            >
                                                <option value="activo">Activo</option>
                                                <option value="inactivo">Inactivo</option>
                                            </select>
                                        </v-field>

                                        <x-admin::form.control-group.error control-name="referencias.0.estado" />
                                    </x-admin::form.control-group>
                                </div>
                            </x-slot>

                            <x-slot:footer>
                                <div class="flex items-center gap-2.5">
                                    <button
                                        type="button"
                                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                                        :disabled="isLoading"
                                        @click="closeModal"
                                    >
                                        Cancelar
                                    </button>

                                    <x-admin::button
                                        button-type="submit"
                                        class="primary-button justify-center"
                                        ::title="isEditing ? 'Guardar referencia' : 'Agregar referencia'"
                                        ::loading="isLoading"
                                        ::disabled="isLoading"
                                    />
                                </div>
                            </x-slot>
                        </x-admin::modal>
                    </form>
                </x-admin::form>
            </script>

            <script type="module">
                app.component('v-proveedor-referencia-modal', {
                    template: '#v-proveedor-referencia-modal-template',

                    props: {
                        proveedor: {
                            type: Object,
                            required: true,
                        },

                        tiposTela: {
                            type: Array,
                            required: true,
                        },

                        colores: {
                            type: Array,
                            required: true,
                        },
                    },

                    emits: ['saved'],

                    data() {
                        return {
                            formKey: 0,
                            isEditing: false,
                            isLoading: false,
                            form: this.blankForm(),
                        };
                    },

                    computed: {
                        swatchActual() {
                            let color = this.colores.find((color) => String(color.id) === String(this.form.color_option_id));

                            return color?.swatch_value || '#e5e7eb';
                        },
                    },

                    methods: {
                        blankForm() {
                            return {
                                id: null,
                                tipo_tela_id: this.tiposTela[0]?.id ?? '',
                                color_option_id: '',
                                color: '',
                                es_historica_sin_vincular: false,
                                referencia: '',
                                gramaje: '',
                                valor_kilo_referencia: '',
                                estado: 'activo',
                            };
                        },

                        openCreate() {
                            this.isEditing = false;
                            this.form = this.blankForm();
                            this.formKey++;

                            this.$nextTick(() => {
                                this.$refs.referenciaModal.open();
                                this.centerModal();
                            });
                        },

                        openEdit(referencia) {
                            this.isEditing = true;
                            this.form = {
                                id: referencia.id,
                                tipo_tela_id: referencia.tipo_tela_id,
                                // F1-B: si es historica sin color vinculado,
                                // el select queda en el placeholder y se
                                // muestra el snapshot solo como aviso. Guardar
                                // exige elegir una opcion real.
                                color_option_id: referencia.color_option_id ?? '',
                                color: referencia.color ?? '',
                                es_historica_sin_vincular: ! referencia.color_option_id,
                                referencia: referencia.referencia ?? '',
                                gramaje: referencia.gramaje ?? '',
                                valor_kilo_referencia: referencia.valor_kilo_referencia ?? '',
                                estado: referencia.estado_valor ?? 'activo',
                            };
                            this.formKey++;

                            this.$nextTick(() => {
                                this.$refs.referenciaModal.open();
                                this.centerModal();
                            });
                        },

                        centerModal() {
                            this.$nextTick(() => {
                                let root = this.$refs.referenciaModal?.$el;
                                let content = root?.querySelector('.fixed.inset-0.transform');
                                let wrapper = content?.firstElementChild;
                                let panel = wrapper?.querySelector('.box-shadow');

                                if (content) {
                                    content.style.height = '100dvh';
                                    content.style.maxHeight = '100dvh';
                                    content.style.overflowY = 'auto';
                                }

                                if (wrapper) {
                                    wrapper.style.minHeight = '100dvh';
                                    wrapper.style.alignItems = 'center';
                                    wrapper.style.justifyContent = 'center';
                                    wrapper.style.padding = '16px';
                                }

                                if (panel) {
                                    panel.style.width = 'min(768px, calc(100vw - 32px))';
                                    panel.style.maxWidth = '768px';
                                    panel.style.marginTop = 'auto';
                                    panel.style.marginBottom = 'auto';
                                }
                            });
                        },

                        closeModal() {
                            this.$refs.referenciaModal.close();
                        },

                        save(params, { resetForm, setErrors }) {
                            this.isLoading = true;

                            let formData = new FormData(this.$refs.referenciaForm);

                            formData.append('_method', 'put');

                            this.$axios.post('{{ route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id) }}', formData)
                                .then((response) => {
                                    this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });

                                    this.$emit('saved', response.data.data);

                                    resetForm();

                                    this.isLoading = false;

                                    this.$refs.referenciaModal.close();
                                })
                                .catch((error) => {
                                    this.isLoading = false;

                                    if (error.response.status == 422) {
                                        setErrors(error.response.data.errors);
                                    }
                                });
                        },
                    },
                });
            </script>
        @endif

        <script type="module">
            app.component('v-proveedor-view', {
                template: '#v-proveedor-view-template',

                data() {
                    return {
                        proveedor: @json($proveedorPayload),

                        resumen: @json($resumen),
                    };
                },

                methods: {
                    openCrearReferencia() {
                        this.$refs.referenciaModal.openCreate();
                    },

                    openEditarReferencia(referencia) {
                        this.$refs.referenciaModal.openEdit(referencia);
                    },

                    openEditarTipos() {
                        this.$refs.tiposModal.openModal();
                    },

                    tiposGuardados(data) {
                        if (data.proveedor) {
                            this.updateProveedor(data.proveedor);
                        }
                    },

                    referenciaGuardada(data) {
                        if (data.proveedor) {
                            this.updateProveedor(data.proveedor);
                        }

                        if (data.resumen) {
                            this.resumen = data.resumen;
                        }

                        this.$refs.referenciasDatagrid.get();
                    },

                    updateProveedor(proveedor) {
                        this.proveedor = {
                            ...this.proveedor,
                            ...proveedor,
                        };
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
