<x-admin::layouts>
    <x-slot:title>Crear compra de tela</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.compras_tela.store') }}">
        @csrf

        @include('corteconfeccion::admin.compras-tela.form', ['compra' => null, 'proveedores' => $proveedores, 'codigoSugerido' => $codigoSugerido])
    </form>
</x-admin::layouts>
