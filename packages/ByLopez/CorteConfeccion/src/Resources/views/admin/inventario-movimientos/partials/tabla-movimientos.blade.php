{{--
    Tabla de movimientos de inventario con la MISMA visual aprobada de
    admin/inventario-movimientos/index (mismas columnas, plantilla de grid,
    clases de cabecera/fila y HTML de cada celda via
    Support\InventarioMovimientoHtml, que tambien usa el DataGrid).

    Render servidor (sin datagrid Vue) para poder incrustarla en otras
    pantallas (pestana Movimientos de una Orden de Confeccion) sin depender del
    permiso del listado global ni de la URL de la pagina.

    Recibe: $filas (lista de objetos fila; ver InventarioMovimientoHtml::filaDesdeModelo).
--}}
<div class="overflow-x-auto">
    <div class="row datagrid-head datagrid-head-cards grid min-w-[1180px] grid-cols-[1.05fr_1.15fr_2fr_2.5fr_1.45fr_1fr_0.9fr_0.45fr] bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-gray-950 dark:text-gray-300">
        <div class="flex select-none items-center gap-2.5"><p>Fecha y hora</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Movimiento</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Producto / Variante</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Cambio de stock</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Orden confeccion / Entrega</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Taller</p></div>
        <div class="flex select-none items-center gap-2.5"><p>Usuario</p></div>
        <div></div>
    </div>

    @foreach ($filas as $fila)
        <div class="row grid min-w-[1180px] grid-cols-[1.05fr_1.15fr_2fr_2.5fr_1.45fr_1fr_0.9fr_0.45fr] items-center border-b border-slate-100 px-4 py-4 text-sm transition-all hover:bg-blue-50/40 dark:border-gray-800 dark:hover:bg-gray-950">
            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::fechaHoraHtml($fila) !!}</div>

            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::tipoMovimientoHtml($fila) !!}</div>

            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::productoVarianteHtml($fila) !!}</div>

            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::stockCambioHtml($fila) !!}</div>

            <div class="flex flex-col gap-1">
                <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::ordenConfeccionEnlace($fila) !!}</div>
                <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::entregaHtml($fila) !!}</div>
            </div>

            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::tallerHtml($fila) !!}</div>

            <div>{!! \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::usuarioHtml($fila) !!}</div>

            <div class="flex justify-end">
                @if (bouncer()->hasPermission('corte_confeccion.inventario_movimientos'))
                    <a
                        href="{{ route('admin.corte_confeccion.inventario_movimientos.show', $fila->id) }}"
                        class="icon-view flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
                        title="Ver"
                    ></a>
                @endif
            </div>
        </div>
    @endforeach
</div>
