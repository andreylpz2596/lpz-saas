<x-admin::layouts>
    <x-slot:title>Ordenes de corte</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Ordenes de corte</p>

        <a href="{{ route('admin.corte_confeccion.ordenes_corte.create') }}" class="primary-button">Crear orden</a>
    </div>

    <x-admin::datagrid :src="route('admin.corte_confeccion.ordenes_corte.index')" />
</x-admin::layouts>
