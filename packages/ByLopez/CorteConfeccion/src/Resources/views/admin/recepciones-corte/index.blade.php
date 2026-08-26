<x-admin::layouts>
    <x-slot:title>Recepciones de corte</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Recepciones de corte</p>
    </div>

    <x-admin::datagrid :src="route('admin.corte_confeccion.recepciones_corte.index')" />
</x-admin::layouts>
