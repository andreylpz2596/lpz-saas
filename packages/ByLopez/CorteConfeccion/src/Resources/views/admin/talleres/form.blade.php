@php
    $usuarioActual = $taller?->usuario;
@endphp

<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $taller ? 'Editar taller' : 'Crear taller' }}</p>

    <button class="primary-button">Guardar</button>
</div>

<div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
    @foreach ([
        'nombre' => 'Nombre',
        'nit' => 'NIT',
        'contacto' => 'Contacto',
        'telefono' => 'Telefono',
        'direccion' => 'Direccion',
    ] as $field => $label)
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="{{ $field === 'nombre' ? 'required' : '' }}">{{ $label }}</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="{{ $field }}"
                rules="{{ $field === 'nombre' ? 'required' : '' }}"
                value="{{ old($field, $taller?->{$field}) }}"
                label="{{ $label }}"
                placeholder="{{ $label }}"
            />
            <x-admin::form.control-group.error control-name="{{ $field }}" />
        </x-admin::form.control-group>
    @endforeach

    <x-admin::form.control-group>
        <x-admin::form.control-group.label class="required">Estado</x-admin::form.control-group.label>
        <select
            name="estado"
            class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
        >
            <option value="activo" @selected(old('estado', $taller?->estado ?? 'activo') === 'activo')>Activo</option>
            <option value="inactivo" @selected(old('estado', $taller?->estado) === 'inactivo')>Inactivo</option>
        </select>
        <x-admin::form.control-group.error control-name="estado" />
    </x-admin::form.control-group>

    <x-admin::form.control-group>
        <x-admin::form.control-group.label class="required">Usuario asociado</x-admin::form.control-group.label>
        <x-corteconfeccion::record-search
            name="usuario_id"
            :search-url="route('admin.corte_confeccion.usuarios.taller_confeccion')"
            :selected-id="old('usuario_id', $taller?->usuario_id)"
            :selected-label="old('usuario_id') ? null : $usuarioActual?->name"
            :selected-sublabel="old('usuario_id') ? null : $usuarioActual?->email"
            placeholder="Buscar usuario TALLER CONFECCION..."
            rules="required"
            label="Usuario asociado"
        />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Solo aparecen usuarios activos con rol TALLER CONFECCION.
        </p>
        <x-admin::form.control-group.error control-name="usuario_id" />
    </x-admin::form.control-group>

    <x-admin::form.control-group class="col-span-2 max-md:col-span-1">
        <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $taller?->observacion) }}" />
        <x-admin::form.control-group.error control-name="observacion" />
    </x-admin::form.control-group>
</div>
