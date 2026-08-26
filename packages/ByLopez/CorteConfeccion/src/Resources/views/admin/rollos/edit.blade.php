<x-admin::layouts>
    <x-slot:title>Editar rollo</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.rollos.update', $rollo->id) }}">
        @csrf
        @method('PUT')

        @include('corteconfeccion::admin.rollos.form', ['rollo' => $rollo])
    </form>
</x-admin::layouts>
