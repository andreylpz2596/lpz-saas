{{--
    Mismos dos hallazgos ya documentados en ordenes-confeccion/show.blade.php:
    un <style> plano en el body es descartado por el layout de admin, y las
    clases propias de este build de Tailwind (important: ".bylopez-scope")
    solo aplican dentro de un ancestro con esa clase literal. Por eso: sin
    <style>, solo utilities, y todo envuelto en .bylopez-scope.

    Esta pantalla es exclusivamente de lectura: el movimiento es un registro
    historico ya creado por InventarioMovimientoService al recibir la
    entrega; aqui no hay ningun formulario que lo modifique.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Movimiento de inventario #{{ $movimiento->id }}</x-slot>

    @include('corteconfeccion::admin.inventario-movimientos.detalle')
</x-admin::layouts>
