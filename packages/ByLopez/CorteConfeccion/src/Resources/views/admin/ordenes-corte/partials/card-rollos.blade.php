@php
    $pesoReservado = round((float) $orden->rollosCorte->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
    $pendientesEntrega = $orden->rollosCorte->filter(fn ($ordenRollo) => ! $ordenRollo->entregado_en)->values();

    // PRE-F4: retiro formal solo antes de en_corte (punto de no retorno) y sin dejar la orden sin rollos.
    $antesDeEnCorte = \ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine::antesDeEnCorte($orden->estado);
    $puedeAgregarRollos = ($canEditOrder ?? false) && $antesDeEnCorte;   // PRE-F4.1: desde en_corte no se agregan rollos
    $puedeRetirarRollos = $puedeAgregarRollos && $orden->rollosCorte->count() > 1;
@endphp

<div class="rounded border bg-white dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-4 border-b p-4 dark:border-gray-800 max-sm:flex-wrap">
        <div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Rollos seleccionados</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Rollos reservados para esta Orden de Corte.</p>
        </div>

        @if ($puedeAgregarRollos)
            <x-admin::modal
                ref="agregarRolloOrdenModal"
                panel-class="max-w-[720px]"
                @toggle="$event.isActive && window.bylopezCenterModal($refs.agregarRolloOrdenModal, 720)"
                @open="window.bylopezCenterModal($refs.agregarRolloOrdenModal, 720)"
            >
                <x-slot:toggle>
                    <button type="button" class="secondary-button whitespace-nowrap">Agregar rollo</button>
                </x-slot>

                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Agregar rollo</p>
                </x-slot>

                <x-slot:content>
                    <form
                        id="agregar-rollo-orden-form"
                        method="POST"
                        action="{{ route('admin.corte_confeccion.ordenes_corte.rollos.store', $orden->id) }}"
                    >
                        @csrf

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">Rollo disponible</x-admin::form.control-group.label>
                            <select
                                name="rollo_id"
                                class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                required
                            >
                                <option value="">Seleccionar rollo</option>
                                @foreach (($rollosDisponibles ?? collect()) as $rollo)
                                    <option value="{{ $rollo->id }}">
                                        {{ $rollo->codigo }} - {{ $rollo->tipo_tela }} - {{ $rollo->color }} - {{ number_format((float) $rollo->peso_disponible, 3, ',', '.') }} kg
                                    </option>
                                @endforeach
                            </select>
                            <x-admin::form.control-group.error control-name="rollo_id" />
                        </x-admin::form.control-group>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Solo se permiten rollos compatibles con el tipo de tela, color y gramaje ya seleccionados en la orden.
                        </p>
                    </form>
                </x-slot>

                <x-slot:footer>
                    <div class="flex items-center gap-2.5">
                        <button type="button" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800" @click="$refs.agregarRolloOrdenModal.close()">Cancelar</button>
                        <button type="submit" form="agregar-rollo-orden-form" class="primary-button">Guardar rollo</button>
                    </div>
                </x-slot>
            </x-admin::modal>
        @endif
    </div>

    <div class="p-4">
        @if ($pendientesEntrega->isNotEmpty())
            <div class="mb-3 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                {{ $pendientesEntrega->count() }} rollo{{ $pendientesEntrega->count() === 1 ? '' : 's' }} pendiente{{ $pendientesEntrega->count() === 1 ? '' : 's' }} de entrega fisica.
            </div>
        @endif

        <div class="overflow-x-auto rounded border dark:border-gray-800">
            <table class="w-full min-w-[980px] border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                        <th class="px-3 py-2">Codigo rollo</th>
                        <th class="px-3 py-2">Tela</th>
                        <th class="px-3 py-2">Color</th>
                        <th class="px-3 py-2">Gramaje</th>
                        <th class="px-3 py-2 text-right">Peso total / disponible</th>
                        <th class="px-3 py-2 text-right">Peso reservado</th>
                        <th class="px-3 py-2">Estado</th>
                        @if ($puedeRetirarRollos)
                            <th class="px-3 py-2 text-right">Accion</th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @forelse ($orden->rollosCorte as $ordenRollo)
                        @php
                            $rollo = $ordenRollo->rollo;
                            $entregado = (bool) $ordenRollo->entregado_en;
                        @endphp

                        <tr class="border-b last:border-b-0 dark:border-gray-800">
                            <td class="px-3 py-3 font-semibold text-gray-800 dark:text-white">{{ $rollo?->codigo }}</td>
                            <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $rollo?->tipo_tela }}</td>
                            <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $rollo?->color }}</td>
                            <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $rollo?->gramaje ?: '-' }}</td>
                            <td class="px-3 py-3 text-right text-gray-600 dark:text-gray-300">
                                {{ number_format((float) ($rollo?->peso_inicial ?? 0), 3, ',', '.') }} kg /
                                {{ number_format((float) ($rollo?->peso_disponible ?? 0), 3, ',', '.') }} kg
                            </td>
                            <td class="px-3 py-3 text-right font-semibold text-gray-800 dark:text-white">
                                {{ number_format((float) $ordenRollo->peso_inicial_usado, 3, ',', '.') }} kg
                            </td>
                            <td class="px-3 py-3">
                                @if ($entregado)
                                    <span class="label-active">Entregado</span>
                                @else
                                    <span class="label-pending">Pendiente entrega</span>
                                @endif
                            </td>
                            @if ($puedeRetirarRollos)
                                <td class="px-3 py-3 text-right">
                                    <form
                                        method="POST"
                                        action="{{ route('admin.corte_confeccion.ordenes_corte.rollos.retirar', [$orden->id, $ordenRollo->rollo_id]) }}"
                                        onsubmit="return confirm('Retirar este rollo de la orden? Quedara liberado y podra editarse desde la Compra de Tela.')"
                                    >
                                        @csrf
                                        <button type="submit" class="transparent-button text-red-600 hover:bg-gray-200 dark:hover:bg-gray-800">Retirar</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Sin rollos seleccionados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">Total rollos: <strong class="text-gray-800 dark:text-white">{{ $orden->rollosCorte->count() }}</strong></span>
            <span class="text-gray-500 dark:text-gray-400">Peso total reservado: <strong class="text-gray-800 dark:text-white">{{ number_format($pesoReservado, 3, ',', '.') }} kg</strong></span>
        </div>
    </div>
</div>
