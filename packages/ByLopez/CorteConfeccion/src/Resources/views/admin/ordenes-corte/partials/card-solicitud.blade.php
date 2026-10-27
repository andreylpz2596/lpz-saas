@php
    $gruposSolicitud = \ByLopez\CorteConfeccion\Support\AgrupadorDetallesCorte::agrupar($detallesSolicitud);
@endphp

<div class="rounded border dark:border-gray-800">
    <div class="border-b p-4 dark:border-gray-800">
        <p class="text-base font-semibold text-gray-800 dark:text-white">Solicitud de corte</p>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Lo que Bodega/Administrador solicito cortar. No se modifica desde aqui.</p>
    </div>

    <div class="p-4">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Rollos</p>
        <div class="mb-4 overflow-x-auto rounded border dark:border-gray-800">
            <table class="w-full min-w-[700px] border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <th class="px-3 py-2">Rollo</th>
                        <th class="px-3 py-2">Tela</th>
                        <th class="px-3 py-2">Color</th>
                        <th class="px-3 py-2">Referencia</th>
                        <th class="px-3 py-2 text-right">Peso asignado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orden->rollosCorte as $ordenRollo)
                        <tr class="border-b last:border-b-0 dark:border-gray-800">
                            <td class="px-3 py-2 font-semibold text-gray-800 dark:text-white">{{ $ordenRollo->rollo?->codigo }}</td>
                            <td class="px-3 py-2">{{ $ordenRollo->rollo?->tipo_tela }}</td>
                            <td class="px-3 py-2">{{ $ordenRollo->rollo?->color }}</td>
                            <td class="px-3 py-2">{{ $ordenRollo->rollo?->referencia }}</td>
                            <td class="px-3 py-2 text-right">{{ $ordenRollo->peso_inicial_usado }} kg</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Productos solicitados</p>

        @forelse ($gruposSolicitud as $index => $grupo)
            <details class="mb-3 rounded-lg border dark:border-gray-800" @if ($index === 0) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-950 max-md:flex-wrap">
                    <span class="min-w-0 flex-1 break-words text-base font-semibold text-gray-900 dark:text-white">
                        {{ $grupo['genero_linea'] ? $grupo['genero_linea'].' · ' : '' }}{{ $grupo['producto_nombre'] }}
                    </span>
                    <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ $grupo['cantidad_tallas'] }} talla{{ $grupo['cantidad_tallas'] === 1 ? '' : 's' }}</span>
                </summary>

                <div class="border-t p-4 dark:border-gray-800">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($grupo['filas'] as $fila)
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                Talla {{ $fila->talla ?: '—' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </details>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Sin productos solicitados.</p>
        @endforelse

        @if ($orden->observacion)
            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Observacion de la solicitud</p>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $orden->observacion }}</p>
        @endif
    </div>
</div>
