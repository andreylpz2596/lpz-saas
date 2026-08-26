<x-admin::layouts>
    <x-slot:title>Recibir corte {{ $orden->codigo }}</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.recepciones_corte.store', $orden->id) }}">
        @csrf

        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Recibir corte {{ $orden->codigo }}</p>
            <button class="primary-button">Guardar recepcion</button>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Peso entregado: {{ $orden->peso_entregado }} kg</p>

        <div class="mt-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
            @foreach ([
                'fecha_recepcion' => 'Fecha recepcion',
                'peso_sobrante_usable' => 'Peso sobrante usable',
                'peso_retasos' => 'Peso retazos',
                'peso_desperdicio' => 'Peso desperdicio',
                'recibido_por' => 'Recibido por',
            ] as $field => $label)
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label class="required">{{ $label }}</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control
                        type="{{ $field === 'fecha_recepcion' ? 'date' : (str_starts_with($field, 'peso') ? 'number' : 'text') }}"
                        step="0.001"
                        name="{{ $field }}"
                        value="{{ old($field, $field === 'fecha_recepcion' ? now()->toDateString() : null) }}"
                    />
                    <x-admin::form.control-group.error control-name="{{ $field }}" />
                </x-admin::form.control-group>
            @endforeach
        </div>

        <div class="mt-6">
            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Piezas reales por producto y talla</p>

            @foreach ($orden->detalles as $i => $proyectado)
                <div class="mb-2 grid grid-cols-[2fr_1fr_1fr_1fr_1fr_2fr] gap-2 max-md:grid-cols-1">
                    <select name="detalles[{{ $i }}][product_id]" class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(old("detalles.$i.product_id", $proyectado->product_id) == $product->id)>{{ $product->sku }} - {{ $product->name }}</option>
                        @endforeach
                    </select>

                    <input value="{{ $proyectado->genero_linea }}" disabled class="w-full rounded-md border bg-gray-50 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    <input name="detalles[{{ $i }}][talla]" value="{{ old("detalles.$i.talla", $proyectado->talla) }}" placeholder="Talla" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    @if ($canEditCantidadCortada)
                        <input name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $proyectado->cantidad_proyectada) }}" type="number" min="0" placeholder="Recibida" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    @else
                        <input type="hidden" name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $proyectado->cantidad_real_cortada ?? 0) }}">
                        <div class="rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                            {{ old("detalles.$i.cantidad_recibida", $proyectado->cantidad_real_cortada ?? 0) }}
                        </div>
                    @endif
                    <input name="detalles[{{ $i }}][cantidad_defectuosa]" value="{{ old("detalles.$i.cantidad_defectuosa", 0) }}" type="number" min="0" placeholder="Defectuosa" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    <input name="detalles[{{ $i }}][observacion]" value="{{ old("detalles.$i.observacion") }}" placeholder="Observacion" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>
            @endforeach
        </div>

        <x-admin::form.control-group class="mt-4">
            <x-admin::form.control-group.label>Observacion general</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion') }}" />
        </x-admin::form.control-group>
    </form>
</x-admin::layouts>
