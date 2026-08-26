<x-admin::layouts>
    <x-slot:title>Editar compra {{ $compra->codigo }}</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.compras_tela.update', $compra->id) }}">
        @csrf
        @method('PUT')

        @include('corteconfeccion::admin.compras-tela.form', ['compra' => $compra, 'proveedores' => $proveedores])
    </form>
</x-admin::layouts>
