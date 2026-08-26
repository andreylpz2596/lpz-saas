<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $rollo ? 'Editar rollo' : 'Crear rollo' }}</p>

    <button class="primary-button">Guardar</button>
</div>

<div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
    @foreach ([
        'codigo' => 'Codigo',
        'proveedor' => 'Proveedor',
        'color' => 'Color',
        'tipo_tela' => 'Tipo de tela',
        'peso_inicial' => 'Peso inicial',
        'peso_disponible' => 'Peso disponible',
        'costo_total' => 'Costo total',
        'fecha_compra' => 'Fecha compra',
    ] as $field => $label)
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="{{ in_array($field, ['codigo', 'proveedor', 'color', 'tipo_tela', 'peso_inicial', 'costo_total'], true) ? 'required' : '' }}">{{ $label }}</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="{{ $field === 'fecha_compra' ? 'date' : (str_starts_with($field, 'peso') || $field === 'costo_total' ? 'number' : 'text') }}"
                name="{{ $field }}"
                step="0.001"
                value="{{ old($field, $rollo?->{$field}) }}"
            />
            <x-admin::form.control-group.error control-name="{{ $field }}" />
        </x-admin::form.control-group>
    @endforeach

    <x-admin::form.control-group class="col-span-2 max-md:col-span-1">
        <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $rollo?->observacion) }}" />
        <x-admin::form.control-group.error control-name="observacion" />
    </x-admin::form.control-group>
</div>
