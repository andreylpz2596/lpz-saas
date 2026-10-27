<x-admin::layouts>
    <x-slot:title>Crear taller</x-slot>

    <x-admin::form :action="route('admin.corte_confeccion.talleres.store')">
        @include('corteconfeccion::admin.talleres.form', ['taller' => null])
    </x-admin::form>
</x-admin::layouts>
