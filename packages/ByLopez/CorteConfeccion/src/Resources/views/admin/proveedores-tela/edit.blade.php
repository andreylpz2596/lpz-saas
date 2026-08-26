<x-admin::layouts>
    <x-slot:title>Editar proveedor {{ $proveedor->nombre }}</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id) }}">
        @csrf
        @method('PUT')

        @include('corteconfeccion::admin.proveedores-tela.form', ['proveedor' => $proveedor])
    </form>
</x-admin::layouts>
