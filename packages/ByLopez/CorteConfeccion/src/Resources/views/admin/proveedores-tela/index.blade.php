<x-admin::layouts>
    <x-slot:title>Proveedores de tela</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Proveedores de tela</p>

        <a href="{{ route('admin.corte_confeccion.proveedores_tela.create') }}" class="primary-button">Crear proveedor</a>
    </div>

    <x-admin::datagrid :src="route('admin.corte_confeccion.proveedores_tela.index')" />
</x-admin::layouts>
