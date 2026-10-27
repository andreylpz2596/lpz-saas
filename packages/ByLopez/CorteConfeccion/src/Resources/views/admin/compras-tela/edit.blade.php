<x-admin::layouts>
    <x-slot:title>Editar compra {{ $compra->codigo }}</x-slot>

    <form id="compra-tela-edicion" method="POST" action="{{ route('admin.corte_confeccion.compras_tela.update', $compra->id) }}">
        @csrf
        @method('PUT')

        @include('corteconfeccion::admin.compras-tela.edit-form', ['compra' => $compra, 'proveedores' => $proveedores])
    </form>

    @include('corteconfeccion::admin.compras-tela.edit-styles')
</x-admin::layouts>
