<x-admin::layouts>
    <x-slot:title>Crear cortador</x-slot>

    <x-admin::form :action="route('admin.corte_confeccion.cortadores.store')">
        @include('corteconfeccion::admin.cortadores.form', ['cortador' => null])
    </x-admin::form>
</x-admin::layouts>
