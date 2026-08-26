<x-admin::layouts>
    <x-slot:title>Rollos</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Rollos</p>

        <a href="{{ route('admin.corte_confeccion.rollos.create') }}" class="primary-button">Crear rollo</a>
    </div>

    <x-admin::datagrid :src="route('admin.corte_confeccion.rollos.index')" />
</x-admin::layouts>
