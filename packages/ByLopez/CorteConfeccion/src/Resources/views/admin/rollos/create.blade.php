<x-admin::layouts>
    <x-slot:title>Crear rollo</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.rollos.store') }}">
        @csrf

        @include('corteconfeccion::admin.rollos.form', ['rollo' => null])
    </form>
</x-admin::layouts>
