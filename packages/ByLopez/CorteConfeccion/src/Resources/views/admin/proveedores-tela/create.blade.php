<x-admin::layouts>
    <x-slot:title>Crear proveedor de tela</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.proveedores_tela.store') }}">
        @csrf

        @include('corteconfeccion::admin.proveedores-tela.form', ['proveedor' => null])
    </form>
</x-admin::layouts>
