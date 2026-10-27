@php
    $isCreate = ! isset($orden);
    $totalProductos = isset($detallesSolicitud) ? $detallesSolicitud->count() : 0;
    $cantidadTotal = isset($detallesSolicitud) ? (int) $detallesSolicitud->sum('cantidad_proyectada') : 0;
    $totalRollos = isset($orden) ? $orden->rollosCorte->count() : 0;
    $pesoReservado = isset($orden)
        ? round((float) $orden->rollosCorte->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3)
        : 0;
@endphp

<div class="rounded border bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="text-base font-semibold text-gray-800 dark:text-white">Resumen de la orden</p>
    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Datos principales y avance operativo.</p>

    <div class="mt-4 grid gap-3 text-sm">
        <div class="flex items-start justify-between gap-3 border-b pb-2 dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">Codigo</span>
            <span class="text-right font-semibold text-gray-800 dark:text-white">
                {{ $isCreate ? 'Se genera al guardar' : $orden->codigo }}
            </span>
        </div>

        <div class="flex items-start justify-between gap-3 border-b pb-2 dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">Fecha solicitud</span>
            <span class="text-right font-semibold text-gray-800 dark:text-white">
                {{ $isCreate ? 'Se genera al guardar' : ($orden->fecha_solicitud?->format('Y-m-d') ?: $orden->created_at?->format('Y-m-d')) }}
            </span>
        </div>

        <div class="flex items-start justify-between gap-3 border-b pb-2 dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">Fecha entrega</span>
            <span class="text-right font-semibold text-gray-800 dark:text-white">
                {{ $isCreate ? 'Pendiente' : ($orden->fecha_entrega?->format('Y-m-d') ?: 'Pendiente') }}
            </span>
        </div>

        <div class="flex items-start justify-between gap-3 border-b pb-2 dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">Estado</span>
            <span class="text-right">
                @if ($isCreate)
                    <span class="label-pending">Pendiente de guardar</span>
                @else
                    {!! \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::badgeHtml($orden->estado) !!}
                @endif
            </span>
        </div>

        <div class="flex items-start justify-between gap-3 border-b pb-2 dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">Cortador</span>
            <span class="text-right font-semibold text-gray-800 dark:text-white">
                {{ $isCreate ? 'Seleccionar' : $orden->cortador_nombre_snapshot }}
            </span>
        </div>

        <div class="grid grid-cols-2 gap-2 pt-1">
            <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                <p class="text-xs text-gray-500 dark:text-gray-400">Productos</p>
                <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $isCreate ? '-' : number_format($totalProductos, 0, ',', '.') }}</p>
            </div>

            <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                <p class="text-xs text-gray-500 dark:text-gray-400">Cantidad</p>
                <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $isCreate ? '-' : number_format($cantidadTotal, 0, ',', '.') }}</p>
            </div>

            <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                <p class="text-xs text-gray-500 dark:text-gray-400">Rollos</p>
                <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $isCreate ? '-' : number_format($totalRollos, 0, ',', '.') }}</p>
            </div>

            <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-950">
                <p class="text-xs text-gray-500 dark:text-gray-400">Peso</p>
                <p class="text-lg font-bold text-gray-800 dark:text-white">{{ $isCreate ? '-' : number_format($pesoReservado, 3, ',', '.') }}</p>
            </div>
        </div>
    </div>
</div>
