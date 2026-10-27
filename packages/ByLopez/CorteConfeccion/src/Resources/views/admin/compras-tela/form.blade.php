@include('corteconfeccion::admin.partials.estilos')

@php
    $proveedoresCatalogo = $proveedores
        ->mapWithKeys(fn ($proveedor) => [
            (string) $proveedor->id => [
                'tipos' => $proveedor->tiposTela
                    ->map(fn ($tipoTela) => [
                        'id' => (int) $tipoTela->id,
                        'nombre' => $tipoTela->admin_name,
                    ])
                    ->values()
                    ->all(),
                'referencias' => $proveedor->referenciasTela
                    ->map(fn ($referencia) => [
                        'id' => (int) $referencia->id,
                        'tipo_tela_id' => (int) $referencia->tipo_tela_id,
                        'tipo_tela' => $referencia->tipoTela?->admin_name,
                        'color' => $referencia->color,
                        'referencia' => $referencia->referencia,
                        'gramaje' => $referencia->gramaje,
                        'valor_kilo_referencia' => $referencia->valor_kilo_referencia,
                        'estado' => $referencia->estado,
                    ])
                    ->values()
                    ->all(),
            ],
        ])
        ->all();

    $proveedorSeleccionadoId = (string) old('proveedor_id', $compra?->proveedor_id ?? '');
    $rollosIniciales = old('rollos');
    $referenciaLabel = function ($referencia, $rollo = null) {
        if (! $referencia && $rollo) {
            $label = trim(implode(' - ', array_filter([
                $rollo->color,
                $rollo->referencia,
                $rollo->gramaje ? $rollo->gramaje.'gr' : null,
            ])));

            return $label ? $label.' (historico)' : '';
        }

        if (! $referencia) {
            return '';
        }

        $valor = $referencia->valor_kilo_referencia === null
            ? 'sin valor ref.'
            : '$'.number_format((float) $referencia->valor_kilo_referencia, 0, ',', '.').'/kg';

        return "{$referencia->color} - {$referencia->referencia} - {$referencia->gramaje}gr - {$valor}";
    };

    if ($rollosIniciales === null && $compra) {
        // PRE-F4: bloqueado = vinculado a alguna Orden de Corte por el pivote real
        // O por la FK historica (antes solo miraba la FK y dejaba editables/
        // eliminables los rollos 2..N de una orden multi-rollo).
        $vinculosRollos = \ByLopez\CorteConfeccion\Models\Rollo::vinculosConOrdenCorte($compra->rollos);

        $rollosIniciales = $compra->rollos->map(fn ($rollo) => [
            'id' => $rollo->id,
            'codigo' => $rollo->codigo,
            'tipo_tela_id' => $rollo->referenciaTela?->tipo_tela_id ?? $rollo->tipo_tela_id,
            'referencia_tela_id' => $rollo->proveedor_tela_referencia_id,
            'referencia_label' => $referenciaLabel($rollo->referenciaTela, $rollo),
            'historico' => blank($rollo->proveedor_tela_referencia_id),
            'bloqueado' => isset($vinculosRollos[$rollo->id]),
            'tipo_tela' => $rollo->referenciaTela?->tipoTela?->admin_name ?? $rollo->tipoTela?->admin_name ?? $rollo->tipo_tela,
            'color' => $rollo->color,
            'referencia' => $rollo->referencia,
            'gramaje' => $rollo->gramaje,
            'peso_inicial' => $rollo->peso_inicial,
            'valor_kilo' => $rollo->valor_kilo,
            'observacion' => $rollo->observacion,
        ])->values()->all();
    }

    $rollosIniciales = $rollosIniciales ?: [];
@endphp

<div class="bylopez-scope">

@if ($errors->any())
    <div class="mb-4 rounded border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
        <p class="font-semibold">No se guardó la compra. Corrige lo siguiente:</p>
        <ul class="mt-2 list-disc pl-5">
            @foreach (array_unique($errors->all()) as $mensaje)
                <li>{{ $mensaje }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <div>
        <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $compra ? 'Editar compra de tela' : 'Crear compra de tela' }}</p>
        @if ($compra)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $compra->codigo }} - {{ $compra->estado }}</p>
        @endif
    </div>

    <button class="primary-button">Guardar compra</button>
</div>

<div class="mt-4 rounded border p-4 dark:border-gray-800">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Encabezado</p>

    <div class="grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Numero compra</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="codigo" value="{{ old('codigo', $compra?->codigo ?? ($codigoSugerido ?? '')) }}" />
            <x-admin::form.control-group.error control-name="codigo" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Proveedor</x-admin::form.control-group.label>
            <select
                name="proveedor_id"
                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                data-proveedor
            >
                <option value="">Seleccionar</option>
                @foreach ($proveedores as $proveedor)
                    <option value="{{ $proveedor->id }}" @selected($proveedorSeleccionadoId === (string) $proveedor->id)>{{ $proveedor->nombre }}{{ $proveedor->nit ? ' - '.$proveedor->nit : '' }}</option>
                @endforeach
            </select>
            <x-admin::form.control-group.error control-name="proveedor_id" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>Numero factura</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="numero_factura" value="{{ old('numero_factura', $compra?->numero_factura) }}" />
            <x-admin::form.control-group.error control-name="numero_factura" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Fecha compra</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="date" name="fecha_compra" value="{{ old('fecha_compra', $compra?->fecha_compra?->format('Y-m-d')) }}" />
            <x-admin::form.control-group.error control-name="fecha_compra" />
        </x-admin::form.control-group>

        <x-admin::form.control-group class="col-span-4 max-lg:col-span-2 max-md:col-span-1">
            <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $compra?->observacion) }}" />
            <x-admin::form.control-group.error control-name="observacion" />
        </x-admin::form.control-group>
    </div>
</div>

<x-admin::form.control-group.error control-name="rollos" />

<v-compra-tela-rollos
    :catalogo='@json($proveedoresCatalogo)'
    referencias-url="{{ route('admin.corte_confeccion.compras_tela.referencias') }}"
    :rollos-iniciales='@json($rollosIniciales)'
    proveedor-inicial-id="{{ $proveedorSeleccionadoId }}"
    descuento-inicial="{{ old('descuento', $compra?->descuento ?? 0) }}"
    impuesto-inicial="{{ old('impuesto', $compra?->impuesto ?? 0) }}"
></v-compra-tela-rollos>

</div>

@pushOnce('scripts')
    <script type="text/x-template" id="v-compra-tela-rollos-template">
        <div ref="root" class="mt-4">
            <div class="rounded border dark:border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4 dark:border-gray-800">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Rollos comprados</p>

                    <x-admin::dropdown position="bottom-right">
                        <x-slot:toggle>
                            <button type="button" class="secondary-button">+ Agregar rollos ▾</button>
                        </x-slot>

                        <x-slot:menu class="!p-0">
                            <x-admin::dropdown.menu.item>
                                <button type="button" class="w-full text-left" @click="agregarRolloIndividual">
                                    Agregar rollo
                                </button>
                            </x-admin::dropdown.menu.item>
                            <x-admin::dropdown.menu.item>
                                <button type="button" class="w-full text-left" @click="abrirLote" :disabled="!proveedorId">
                                    Agregar rollos en lote
                                </button>
                            </x-admin::dropdown.menu.item>
                        </x-slot>
                    </x-admin::dropdown>
                </div>

                <div class="p-4">
                    <!-- Estado vacio -->
                    <div class="rounded border border-dashed p-8 text-center dark:border-gray-800" v-if="!filasState.length">
                        <p class="text-sm text-gray-500 dark:text-gray-400">No has agregado rollos a esta compra.</p>
                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            <button type="button" class="secondary-button" @click="agregarRolloIndividual">Agregar rollo</button>
                            <button type="button" class="transparent-button" @click="abrirLote" :disabled="!proveedorId">Agregar rollos en lote</button>
                        </div>
                    </div>

                    <!-- Grupos -->
                    <div class="space-y-2" v-else>
                        <div class="rounded border dark:border-gray-800" v-for="grupo in grupos" :key="grupo.key">
                            <button
                                type="button"
                                class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 rounded p-3 text-left hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-gray-950"
                                :aria-expanded="estaAbierto(grupo.key) ? 'true' : 'false'"
                                @click="toggleGrupo(grupo.key)"
                            >
                                <span
                                    class="shrink-0 text-lg text-gray-400 transition-transform dark:text-gray-500"
                                    :class="estaAbierto(grupo.key) ? 'icon-arrow-up' : 'icon-arrow-down'"
                                ></span>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-gray-900 dark:text-white">@{{ grupo.tipoTela }} &middot; @{{ grupo.color }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">@{{ grupo.referencia ? 'Ref ' + grupo.referencia : 'Sin referencia' }} &middot; @{{ grupo.gramaje || '—' }}g</span>
                                </span>

                                <span class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-sm">
                                    <span class="text-gray-600 dark:text-gray-300">@{{ grupo.filas.length }} rollo@{{ grupo.filas.length === 1 ? '' : 's' }}</span>
                                    <span class="text-gray-600 dark:text-gray-300">@{{ formatWeight(grupo.pesoTotal) }}</span>
                                    <span class="font-semibold text-gray-900 dark:text-white">@{{ formatMoney(grupo.subtotal) }}</span>
                                    <span
                                        class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-200"
                                        v-if="grupo.pendientes > 0"
                                    >@{{ grupo.pendientes }} peso@{{ grupo.pendientes === 1 ? '' : 's' }} pendiente@{{ grupo.pendientes === 1 ? '' : 's' }}</span>
                                </span>
                            </button>

                            <div class="border-t p-3 dark:border-gray-800" v-if="estaAbierto(grupo.key)">
                                <div class="overflow-x-auto">
                                    <table data-testid="rollos-tabla" class="w-full min-w-[720px] border-collapse text-left text-sm">
                                        <thead>
                                            <tr class="border-b text-xs font-semibold uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                                <th class="px-2 py-2 w-10">#</th>
                                                <th class="px-2 py-2">Peso inicial</th>
                                                <th class="px-2 py-2">Valor kilo</th>
                                                <th class="px-2 py-2 text-right">Subtotal</th>
                                                <th class="px-2 py-2">Observacion</th>
                                                <th class="px-2 py-2 w-10"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                class="border-b last:border-b-0 dark:border-gray-800"
                                                v-for="(fila, indice) in grupo.filas"
                                                :key="fila._key"
                                            >
                                                <td class="px-2 py-1.5 text-gray-500 dark:text-gray-400">@{{ indice + 1 }}</td>
                                                <td class="px-2 py-1.5">
                                                    <input
                                                        type="number"
                                                        step="0.001"
                                                        min="0"
                                                        class="w-28 rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                        v-model="fila.peso_inicial"
                                                        :ref="(el) => registrarPesoRef(fila._key, el)"
                                                        :readonly="fila.bloqueado"
                                                        @keydown.enter.prevent="enfocarSiguientePeso(grupo, indice)"
                                                    >
                                                </td>
                                                <td class="px-2 py-1.5">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="w-28 rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                        v-model="fila.valor_kilo"
                                                        :readonly="fila.bloqueado"
                                                    >
                                                </td>
                                                <td class="px-2 py-1.5 text-right font-semibold text-gray-800 dark:text-white">@{{ formatMoney(subtotalFila(fila)) }}</td>
                                                <td class="px-2 py-1.5">
                                                    <input
                                                        type="text"
                                                        class="w-full min-w-[140px] rounded-md border px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                        v-model="fila.observacion"
                                                    >
                                                </td>
                                                <td class="px-2 py-1.5 text-right">
                                                    <x-admin::dropdown position="bottom-right">
                                                        <x-slot:toggle>
                                                            <button type="button" class="rounded px-2 py-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200" aria-label="Mas acciones">&#8942;</button>
                                                        </x-slot>
                                                        <x-slot:menu class="!p-0">
                                                            <x-admin::dropdown.menu.item>
                                                                <button type="button" class="w-full text-left" @click="duplicarUnaVez(fila)">Duplicar</button>
                                                            </x-admin::dropdown.menu.item>
                                                            <x-admin::dropdown.menu.item>
                                                                <button type="button" class="w-full text-left" @click="abrirDuplicarVarias(fila)">Duplicar varias veces</button>
                                                            </x-admin::dropdown.menu.item>
                                                            <x-admin::dropdown.menu.item>
                                                                <button
                                                                    type="button"
                                                                    class="w-full text-left"
                                                                    :class="fila.bloqueado ? 'cursor-not-allowed text-gray-400' : 'text-red-600'"
                                                                    :disabled="fila.bloqueado"
                                                                    :title="fila.bloqueado ? 'Rollo vinculado a una orden de corte. Si la orden aun no inicia corte, retirelo primero de la orden.' : ''"
                                                                    @click="eliminarFila(fila)"
                                                                >Eliminar</button>
                                                            </x-admin::dropdown.menu.item>
                                                        </x-slot>
                                                    </x-admin::dropdown>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" v-if="grupo.filas.some((f) => f.historico)">
                                    Incluye rollos historicos sin referencia vinculada al catalogo actual.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Campos ocultos reales enviados al backend: la agrupacion es solo presentacion -->
                    <template v-for="(fila, i) in filasState" :key="'campos-' + fila._key">
                        <input v-if="fila.id" type="hidden" :name="'rollos[' + i + '][id]'" :value="fila.id">
                        <input type="hidden" :name="'rollos[' + i + '][codigo]'" :value="fila.codigo">
                        <input type="hidden" :name="'rollos[' + i + '][tipo_tela_id]'" :value="fila.tipo_tela_id">
                        <input type="hidden" :name="'rollos[' + i + '][tipo_tela]'" :value="fila.tipo_tela">
                        <input type="hidden" :name="'rollos[' + i + '][referencia_tela_id]'" :value="fila.referencia_tela_id">
                        <input type="hidden" :name="'rollos[' + i + '][color]'" :value="fila.color">
                        <input type="hidden" :name="'rollos[' + i + '][referencia]'" :value="fila.referencia">
                        <input type="hidden" :name="'rollos[' + i + '][gramaje]'" :value="fila.gramaje">
                        <input type="hidden" :name="'rollos[' + i + '][peso_inicial]'" :value="fila.peso_inicial">
                        <input type="hidden" :name="'rollos[' + i + '][valor_kilo]'" :value="fila.valor_kilo">
                        <input type="hidden" :name="'rollos[' + i + '][observacion]'" :value="fila.observacion">
                    </template>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>Descuento</x-admin::form.control-group.label>
                    <input type="number" step="0.01" name="descuento" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" v-model="descuento">
                    <x-admin::form.control-group.error control-name="descuento" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>Impuesto</x-admin::form.control-group.label>
                    <input type="number" step="0.01" name="impuesto" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" v-model="impuesto">
                    <x-admin::form.control-group.error control-name="impuesto" />
                </x-admin::form.control-group>
            </div>

            <!-- Espaciador (altura real medida de la barra) para que la barra inferior fija nunca tape el ultimo contenido -->
            <div :style="{ height: barraAltura + 'px' }"></div>

            <div
                ref="barra"
                class="fixed inset-x-0 bottom-0 z-30 border-t bg-white px-4 py-2.5 shadow-[0_-4px_12px_rgba(0,0,0,0.08)] dark:border-gray-800 dark:bg-gray-900"
                :style="barraEstilo"
            >
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 text-sm">
                    <span class="flex flex-wrap items-center gap-x-4 gap-y-0.5">
                        <span class="text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-white">@{{ totalRollos }}</strong> rollos</span>
                        <span class="text-gray-600 dark:text-gray-300 max-sm:hidden">@{{ formatWeight(pesoTotalCompra) }}</span>
                        <span class="text-gray-600 dark:text-gray-300 max-sm:hidden">Subtotal @{{ formatMoney(subtotalCompra) }}</span>
                    </span>
                    <span class="font-semibold text-gray-900 dark:text-white">Total @{{ formatMoney(totalFactura) }}</span>
                </div>
            </div>

            <!-- Drawer: Agregar rollos en lote -->
            <x-admin::drawer ref="drawerLote" width="420px">
                <x-slot:header>
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Agregar rollos en lote</p>
                </x-slot:header>

                <x-slot:content>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Tipo de tela *</label>
                            <select data-testid="lote-tipo-tela" v-model="loteDraft.tipo_tela_id" @change="loteCambioTipo" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="">Seleccionar</option>
                                <option v-for="tipo in tiposProveedor" :key="tipo.id" :value="tipo.id">@{{ tipo.nombre }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Referencia / Color *</label>
                            <div class="relative">
                                <input
                                    type="text"
                                    data-testid="lote-referencia"
                                    class="block w-full rounded-lg border bg-white py-2.5 text-base leading-6 text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 ltr:pl-4 ltr:pr-10 rtl:pl-10 rtl:pr-4"
                                    v-model="loteDraft.referencia_label"
                                    @input="loteEditarBusqueda"
                                    @keydown.enter.prevent="loteBuscarReferencia"
                                    :disabled="!loteDraft.tipo_tela_id"
                                    :placeholder="loteDraft.tipo_tela_id ? 'Buscar color, referencia o gramaje' : 'Seleccione tipo de tela'"
                                    autocomplete="off"
                                >
                                <span v-if="loteBuscando" class="absolute top-3 flex h-5 w-5 animate-spin items-center rounded-full border-2 border-blue-600 border-t-transparent ltr:right-3 rtl:left-3"></span>
                                <button v-else type="button" class="icon-search absolute top-2.5 text-2xl text-gray-400 ltr:right-3 rtl:left-3" :disabled="!loteDraft.tipo_tela_id" @click="loteBuscarReferencia" aria-label="Buscar referencia"></button>
                            </div>

                            <div v-if="loteSugerencias.length" class="mt-3 grid max-h-60 gap-2 overflow-y-auto">
                                <button
                                    type="button"
                                    v-for="ref in loteSugerencias"
                                    :key="ref.id"
                                    class="flex w-full items-center justify-between gap-2 rounded-lg border p-3 text-left hover:border-blue-500 dark:border-gray-800"
                                    @click="loteSeleccionarReferencia(ref)"
                                >
                                    <span>
                                        <span class="block font-semibold text-gray-800 dark:text-white">@{{ ref.color }} · @{{ ref.referencia }}</span>
                                        <span class="block text-sm text-gray-500 dark:text-gray-300">@{{ ref.gramaje }}gr · @{{ ref.valor_kilo_referencia == null ? 'Sin valor ref.' : '$' + Number(ref.valor_kilo_referencia).toLocaleString('es-CO') + '/kg' }}</span>
                                    </span>
                                    <span class="icon-arrow-right text-xl text-gray-400" aria-hidden="true"></span>
                                </button>
                            </div>
                            <p v-else-if="loteBusquedaRealizada && !loteBuscando && !loteDraft.referencia_tela_id" class="mt-3 text-sm text-gray-500 dark:text-gray-300">No se encontraron referencias.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Gramaje</label>
                                <input type="text" readonly class="w-full rounded-md border bg-gray-50 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" :value="loteDraft.gramaje">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Valor kilo *</label>
                                <input type="number" step="0.01" min="0" data-testid="lote-valor-kilo" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" v-model="loteDraft.valor_kilo">
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidad de rollos *</label>
                            <input type="number" step="1" min="1" max="200" data-testid="lote-cantidad" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" v-model="loteDraft.cantidad">
                        </div>

                        <ul class="list-disc pl-5 text-xs text-red-600 dark:text-red-400" v-if="loteErrores.length">
                            <li v-for="error in loteErrores" :key="error">@{{ error }}</li>
                        </ul>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <div class="flex w-full justify-end gap-2">
                        <button type="button" class="transparent-button" @click="cerrarLote">Cancelar</button>
                        <button type="button" class="primary-button" :disabled="loteErrores.length > 0 || loteProcesando" @click="confirmarLote">
                            @{{ loteProcesando ? 'Agregando...' : 'Agregar ' + (Number(loteDraft.cantidad) || 0) + ' rollo' + (Number(loteDraft.cantidad) === 1 ? '' : 's') }}
                        </button>
                    </div>
                </x-slot:footer>
            </x-admin::drawer>

            <!-- Modal: Duplicar varias veces -->
            <x-admin::modal ref="modalDuplicar" panel-class="max-w-[360px]">
                <x-slot:header>
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Duplicar varias veces</p>
                </x-slot:header>
                <x-slot:content>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidad de copias</label>
                    <input type="number" step="1" min="1" max="100" data-testid="duplicar-cantidad" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" v-model="duplicarCantidad">
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Se crearan @{{ Number(duplicarCantidad) || 0 }} rollo@{{ Number(duplicarCantidad) === 1 ? '' : 's' }} nuevo@{{ Number(duplicarCantidad) === 1 ? '' : 's' }} con las mismas caracteristicas (sin peso ni observacion).
                    </p>
                </x-slot:content>
                <x-slot:footer>
                    <div class="flex w-full justify-end gap-2">
                        <button type="button" class="transparent-button" @click="$refs.modalDuplicar.close()">Cancelar</button>
                        <button type="button" class="primary-button" :disabled="!duplicarCantidad || duplicarCantidad < 1 || duplicarProcesando" @click="confirmarDuplicarVarias">
                            @{{ duplicarProcesando ? 'Creando...' : 'Crear copias' }}
                        </button>
                    </div>
                </x-slot:footer>
            </x-admin::modal>
        </div>
    </script>

    <script type="module">
        app.component('v-compra-tela-rollos', {
            template: '#v-compra-tela-rollos-template',

            props: {
                catalogo: { type: Object, default: () => ({}) },
                referenciasUrl: { type: String, required: true },
                rollosIniciales: { type: Array, default: () => [] },
                proveedorInicialId: { type: String, default: '' },
                descuentoInicial: { default: 0 },
                impuestoInicial: { default: 0 },
            },

            data() {
                let contador = 0;

                const filas = (this.rollosIniciales.length ? this.rollosIniciales : []).map((rollo) => ({
                    _key: 'r' + (contador++),
                    id: rollo.id || '',
                    codigo: rollo.codigo || '',
                    tipo_tela_id: rollo.tipo_tela_id || '',
                    referencia_tela_id: rollo.referencia_tela_id || '',
                    historico: !!rollo.historico,
                    bloqueado: !!rollo.bloqueado,
                    tipo_tela: rollo.tipo_tela || '',
                    color: rollo.color || '',
                    referencia: rollo.referencia || '',
                    gramaje: rollo.gramaje || '',
                    peso_inicial: rollo.peso_inicial ?? '',
                    valor_kilo: rollo.valor_kilo ?? '',
                    observacion: rollo.observacion || '',
                }));

                return {
                    proveedorId: this.proveedorInicialId || '',
                    filasState: filas,
                    _contador: contador,
                    gruposAbiertosSet: {},
                    descuento: this.descuentoInicial,
                    impuesto: this.impuestoInicial,
                    pesoRefs: {},
                    barraAltura: 64,
                    barraLeft: 0,
                    barraWidth: 0,

                    loteDraft: this.loteDraftVacio(),
                    loteSugerencias: [],
                    loteBuscando: false,
                    loteBusquedaRealizada: false,
                    loteBusquedaVersion: 0,
                    loteBusquedaTimer: null,
                    loteProcesando: false,

                    duplicarObjetivoKey: null,
                    duplicarCantidad: 5,
                    duplicarProcesando: false,
                };
            },

            computed: {
                tiposProveedor() {
                    return (this.catalogo[String(this.proveedorId)] || { tipos: [] }).tipos;
                },

                referenciasProveedor() {
                    return (this.catalogo[String(this.proveedorId)] || { referencias: [] }).referencias;
                },

                grupos() {
                    const mapa = new Map();

                    this.filasState.forEach((fila) => {
                        const valorKilo = Number(fila.valor_kilo || 0).toFixed(2);
                        const key = fila.referencia_tela_id
                            ? 'ref:' + fila.referencia_tela_id + '|vk:' + valorKilo
                            : 'hist:' + fila.tipo_tela_id + '|' + fila.color + '|' + fila.referencia + '|' + fila.gramaje + '|vk:' + valorKilo;

                        if (!mapa.has(key)) {
                            mapa.set(key, {
                                key,
                                tipoTela: fila.tipo_tela || 'Sin tipo',
                                color: fila.color || 'Sin color',
                                referencia: fila.referencia || '',
                                gramaje: fila.gramaje || '',
                                filas: [],
                            });
                        }

                        mapa.get(key).filas.push(fila);
                    });

                    return [...mapa.values()].map((grupo) => {
                        const pesoTotal = grupo.filas.reduce((total, f) => total + (Number(f.peso_inicial) || 0), 0);
                        const subtotal = grupo.filas.reduce((total, f) => total + this.subtotalFila(f), 0);
                        const pendientes = grupo.filas.filter((f) => !(Number(f.peso_inicial) > 0)).length;

                        return { ...grupo, pesoTotal, subtotal, pendientes };
                    });
                },

                totalRollos() {
                    return this.filasState.length;
                },

                pesoTotalCompra() {
                    return this.filasState.reduce((total, f) => total + (Number(f.peso_inicial) || 0), 0);
                },

                subtotalCompra() {
                    return this.filasState.reduce((total, f) => total + this.subtotalFila(f), 0);
                },

                totalFactura() {
                    const total = this.subtotalCompra - (Number(this.descuento) || 0) + (Number(this.impuesto) || 0);

                    return Math.max(total, 0);
                },

                loteErrores() {
                    const errores = [];

                    if (!this.loteDraft.tipo_tela_id) errores.push('Selecciona el tipo de tela.');
                    if (!this.loteDraft.referencia_tela_id) errores.push('Selecciona una referencia/color valida del catalogo.');
                    if (!(Number(this.loteDraft.valor_kilo) > 0)) errores.push('El valor kilo debe ser mayor a 0.');

                    const cantidad = Number(this.loteDraft.cantidad);

                    if (!Number.isInteger(cantidad) || cantidad < 1) errores.push('La cantidad de rollos debe ser un entero mayor o igual a 1.');
                    if (cantidad > 200) errores.push('Maximo 200 rollos por lote.');

                    return errores;
                },

                barraEstilo() {
                    return {
                        left: (this.barraLeft || 0) + 'px',
                        width: (this.barraWidth || 0) + 'px',
                    };
                },
            },

            mounted() {
                const proveedorSelect = document.querySelector('[data-proveedor]');

                if (proveedorSelect) {
                    if (this.proveedorId && !proveedorSelect.value) {
                        proveedorSelect.value = this.proveedorId;
                    }

                    proveedorSelect.addEventListener('change', () => {
                        this.proveedorId = proveedorSelect.value;
                    });
                }

                this.medirBarra();
                window.addEventListener('resize', this.medirBarra);
            },

            beforeUnmount() {
                clearTimeout(this.loteBusquedaTimer);
                window.removeEventListener('resize', this.medirBarra);
            },

            methods: {
                loteDraftVacio() {
                    return {
                        tipo_tela_id: '',
                        referencia_tela_id: '',
                        referencia_label: '',
                        tipo_tela: '',
                        color: '',
                        referencia: '',
                        gramaje: '',
                        valor_kilo: '',
                        cantidad: 10,
                    };
                },

                subtotalFila(fila) {
                    return (Number(fila.peso_inicial) || 0) * (Number(fila.valor_kilo) || 0);
                },

                formatMoney(value) {
                    const numero = Math.round(Number(value) || 0);

                    return '$' + numero.toLocaleString('es-CO');
                },

                formatWeight(value) {
                    return (Number(value) || 0).toFixed(2) + ' kg';
                },

                estaAbierto(key) {
                    return !!this.gruposAbiertosSet[key];
                },

                toggleGrupo(key) {
                    this.gruposAbiertosSet = { ...this.gruposAbiertosSet, [key]: !this.gruposAbiertosSet[key] };
                },

                abrirGrupo(key) {
                    this.gruposAbiertosSet = { ...this.gruposAbiertosSet, [key]: true };
                },

                agregarRolloIndividual() {
                    // "Agregar rollo" reutiliza el mismo drawer de lote con cantidad=1:
                    // asi todo rollo, sea 1 o muchos, siempre queda con tipo/referencia
                    // reales asignados a traves del mismo flujo validado.
                    this.abrirLote();
                    this.loteDraft.cantidad = 1;
                },

                eliminarFila(fila) {
                    if (fila.bloqueado) {
                        return;
                    }

                    const indice = this.filasState.findIndex((f) => f._key === fila._key);

                    if (indice !== -1) {
                        this.filasState.splice(indice, 1);
                    }
                },

                duplicarUnaVez(fila) {
                    this.insertarCopias(fila, 1);
                },

                abrirDuplicarVarias(fila) {
                    this.duplicarObjetivoKey = fila._key;
                    this.duplicarCantidad = 5;
                    this.$refs.modalDuplicar.open();
                },

                confirmarDuplicarVarias() {
                    const cantidad = Number(this.duplicarCantidad);

                    if (!Number.isInteger(cantidad) || cantidad < 1 || this.duplicarProcesando) {
                        return;
                    }

                    const fila = this.filasState.find((f) => f._key === this.duplicarObjetivoKey);

                    if (!fila) {
                        this.$refs.modalDuplicar.close();
                        return;
                    }

                    this.duplicarProcesando = true;
                    this.insertarCopias(fila, cantidad);
                    this.duplicarProcesando = false;
                    this.$refs.modalDuplicar.close();
                },

                insertarCopias(filaOrigen, cantidad) {
                    const indiceOrigen = this.filasState.findIndex((f) => f._key === filaOrigen._key);
                    const copias = [];

                    for (let i = 0; i < cantidad; i++) {
                        copias.push({
                            _key: 'r' + (this._contador++),
                            id: '',
                            codigo: '',
                            tipo_tela_id: filaOrigen.tipo_tela_id,
                            referencia_tela_id: filaOrigen.referencia_tela_id,
                            historico: filaOrigen.historico,
                            bloqueado: false,
                            tipo_tela: filaOrigen.tipo_tela,
                            color: filaOrigen.color,
                            referencia: filaOrigen.referencia,
                            gramaje: filaOrigen.gramaje,
                            valor_kilo: filaOrigen.valor_kilo,
                            peso_inicial: '',
                            observacion: '',
                        });
                    }

                    this.filasState.splice(indiceOrigen + 1, 0, ...copias);
                },

                registrarPesoRef(key, el) {
                    if (el) {
                        this.pesoRefs[key] = el;
                    } else {
                        delete this.pesoRefs[key];
                    }
                },

                enfocarSiguientePeso(grupo, indiceEnGrupo) {
                    const siguiente = grupo.filas[indiceEnGrupo + 1];

                    if (siguiente && this.pesoRefs[siguiente._key]) {
                        this.pesoRefs[siguiente._key].focus();
                        this.pesoRefs[siguiente._key].select();
                    }
                },

                referenciaLabelTexto(referencia) {
                    const valor = referencia.valor_kilo_referencia === null || referencia.valor_kilo_referencia === undefined || referencia.valor_kilo_referencia === ''
                        ? 'sin valor ref.'
                        : '$' + Number(referencia.valor_kilo_referencia).toLocaleString('es-CO') + '/kg';

                    return referencia.color + ' - ' + referencia.referencia + ' - ' + referencia.gramaje + 'gr - ' + valor;
                },

                loteCambioTipo() {
                    clearTimeout(this.loteBusquedaTimer);
                    this.loteBusquedaVersion++;
                    this.loteBuscando = false;
                    this.loteBusquedaRealizada = false;
                    this.loteDraft.referencia_tela_id = '';
                    this.loteDraft.referencia_label = '';
                    this.loteDraft.color = '';
                    this.loteDraft.referencia = '';
                    this.loteDraft.gramaje = '';
                    this.loteDraft.valor_kilo = '';
                    this.loteSugerencias = [];
                },

                loteSugerenciasLocales(search) {
                    const term = String(search || '').toLowerCase();

                    return this.referenciasProveedor
                        .filter((r) => String(r.tipo_tela_id) === String(this.loteDraft.tipo_tela_id))
                        .map((r) => ({ ...r, label: this.referenciaLabelTexto(r) }))
                        .filter((r) => !term || r.label.toLowerCase().indexOf(term) !== -1)
                        .slice(0, 20);
                },

                loteBuscarReferencia() {
                    clearTimeout(this.loteBusquedaTimer);
                    if (!this.proveedorId || !this.loteDraft.tipo_tela_id) {
                        this.loteSugerencias = [];
                        return;
                    }

                    if (this.loteDraft.referencia_tela_id) {
                        return;
                    }

                    const version = ++this.loteBusquedaVersion;
                    this.loteBuscando = true;
                    this.loteBusquedaRealizada = true;
                    const search = this.loteDraft.referencia_label;
                    const params = new URLSearchParams({
                        proveedor_tela_id: this.proveedorId,
                        tipo_tela_id: this.loteDraft.tipo_tela_id,
                        search: search || '',
                    });

                    fetch(this.referenciasUrl + '?' + params.toString(), { headers: { Accept: 'application/json' } })
                        .then((response) => {
                            if (!response.ok) throw new Error('Busqueda no disponible');
                            return response.json();
                        })
                        .then((items) => {
                            if (version !== this.loteBusquedaVersion) return;
                            this.loteSugerencias = items.map((r) => ({ ...r, label: this.referenciaLabelTexto(r) }));
                        })
                        .catch(() => {
                            if (version !== this.loteBusquedaVersion) return;
                            this.loteSugerencias = this.loteSugerenciasLocales(search);
                        })
                        .finally(() => {
                            if (version === this.loteBusquedaVersion) this.loteBuscando = false;
                        });
                },

                loteEditarBusqueda() {
                    const search = this.loteDraft.referencia_label;
                    this.loteCambioTipo();
                    this.loteDraft.referencia_label = search;
                    this.loteBusquedaTimer = setTimeout(() => this.loteBuscarReferencia(), 500);
                },

                loteSeleccionarReferencia(match) {
                    clearTimeout(this.loteBusquedaTimer);
                    this.loteBusquedaVersion++;
                    this.loteBuscando = false;
                    this.loteDraft.referencia_label = this.referenciaLabelTexto(match);
                    this.loteDraft.referencia_tela_id = match.id;
                    this.loteDraft.color = match.color;
                    this.loteDraft.referencia = match.referencia;
                    this.loteDraft.gramaje = match.gramaje;
                    this.loteDraft.valor_kilo = match.valor_kilo_referencia ?? this.loteDraft.valor_kilo;

                    this.loteSugerencias = [];
                },

                abrirLote() {
                    if (!this.proveedorId) {
                        return;
                    }

                    this.loteDraft = this.loteDraftVacio();
                    this.loteCambioTipo();
                    this.$refs.drawerLote.open();
                },

                cerrarLote() {
                    clearTimeout(this.loteBusquedaTimer);
                    this.loteBusquedaVersion++;
                    this.loteBuscando = false;
                    this.$refs.drawerLote.close();
                },

                confirmarLote() {
                    if (this.loteErrores.length > 0 || this.loteProcesando) {
                        return;
                    }

                    this.loteProcesando = true;

                    const cantidad = Number(this.loteDraft.cantidad);
                    const tipoTela = (this.tiposProveedor.find((t) => String(t.id) === String(this.loteDraft.tipo_tela_id)) || {}).nombre || '';
                    const nuevas = [];

                    for (let i = 0; i < cantidad; i++) {
                        nuevas.push({
                            _key: 'r' + (this._contador++),
                            id: '',
                            codigo: '',
                            tipo_tela_id: this.loteDraft.tipo_tela_id,
                            referencia_tela_id: this.loteDraft.referencia_tela_id,
                            historico: false,
                            bloqueado: false,
                            tipo_tela: tipoTela,
                            color: this.loteDraft.color,
                            referencia: this.loteDraft.referencia,
                            gramaje: this.loteDraft.gramaje,
                            valor_kilo: this.loteDraft.valor_kilo,
                            peso_inicial: '',
                            observacion: '',
                        });
                    }

                    this.filasState.push(...nuevas);

                    const valorKilo = Number(this.loteDraft.valor_kilo || 0).toFixed(2);
                    const key = 'ref:' + this.loteDraft.referencia_tela_id + '|vk:' + valorKilo;
                    this.abrirGrupo(key);

                    this.loteProcesando = false;
                    this.$refs.drawerLote.close();
                },

                medirBarra() {
                    this.$nextTick(() => {
                        if (this.$refs.root && this.$refs.barra) {
                            const rect = this.$refs.root.getBoundingClientRect();

                            this.barraLeft = rect.left;
                            this.barraWidth = rect.width;
                            this.barraAltura = this.$refs.barra.getBoundingClientRect().height || 64;
                        }
                    });
                },
            },
        });
    </script>
@endPushOnce
