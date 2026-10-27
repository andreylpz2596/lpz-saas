<x-admin::layouts>
    <x-slot:title>Crear compra de tela</x-slot>

    <form id="compra-tela-edicion" method="POST" action="{{ route('admin.corte_confeccion.compras_tela.store') }}">
        @csrf

        @include('corteconfeccion::admin.compras-tela.edit-form', ['compra' => null, 'proveedores' => $proveedores, 'codigoSugerido' => $codigoSugerido])
    </form>

    @include('corteconfeccion::admin.compras-tela.edit-styles')
</x-admin::layouts>
