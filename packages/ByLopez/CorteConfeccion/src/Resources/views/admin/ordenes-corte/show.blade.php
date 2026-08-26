<x-admin::layouts>
    <x-slot:title>Orden {{ $orden->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Orden {{ $orden->codigo }}</p>

        <div class="flex flex-wrap gap-2">
            @if ($orden->estado === 'pendiente_entrega')
                <a href="{{ route('admin.corte_confeccion.ordenes_corte.entregar_form', $orden->id) }}" class="primary-button">Entregar peso</a>
            @endif
            @if ($orden->estado === 'entregado_a_cortador')
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.en_corte', $orden->id) }}">@csrf <button class="secondary-button">Marcar en corte</button></form>
            @endif
            @if ($orden->estado === 'en_corte')
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.reportar', $orden->id) }}">@csrf <button class="secondary-button">Reportar cortador</button></form>
            @endif
            @if (in_array($orden->estado, ['entregado_a_cortador', 'en_corte', 'reportado_por_cortador'], true) && ! $orden->recepcion)
                <a href="{{ route('admin.corte_confeccion.recepciones_corte.create', $orden->id) }}" class="primary-button">Recibir corte</a>
            @endif
            @if ($orden->estado === 'recibido_bodega')
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.cerrar', $orden->id) }}">@csrf <button class="primary-button">Cerrar</button></form>
            @endif
            @if (! in_array($orden->estado, ['cerrado', 'anulado'], true))
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.cancel', $orden->id) }}">@csrf <button class="transparent-button">Anular</button></form>
            @endif
        </div>
    </div>

    <div class="mt-4 grid grid-cols-4 gap-4 max-md:grid-cols-1">
        @php
            $labels = [
                'cortador_nombre_snapshot' => 'Cortador',
                'fecha_entrega' => 'Fecha entrega',
                'fecha_solicitud' => 'Fecha solicitud',
                'peso_total_rollos' => 'Peso total rollos',
                'peso_sobrante_total' => 'Peso sobrante',
                'merma_total' => 'Merma',
                'peso_consumido_total' => 'Peso consumido',
                'total_piezas_cortadas' => 'Total camisetas cortadas',
                'peso_promedio_pieza' => 'Peso promedio pieza',
            ];
        @endphp

        @foreach (['estado', 'cortador_nombre_snapshot', 'color', 'genero_linea', 'fecha_entrega', 'fecha_solicitud', 'peso_total_rollos', 'peso_sobrante_total', 'merma_total', 'peso_consumido_total', 'total_piezas_cortadas', 'peso_promedio_pieza'] as $field)
            <div class="rounded border p-4 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $labels[$field] ?? ucfirst(str_replace('_', ' ', $field)) }}</p>
                <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->{$field} }}</p>
            </div>
        @endforeach
    </div>

    <p class="mt-6 mb-3 text-base font-semibold text-gray-800 dark:text-white">Rollos</p>
    <div class="rounded border dark:border-gray-800">
        @foreach ($orden->rollosCorte as $ordenRollo)
            <div class="grid grid-cols-7 gap-3 border-b p-3 text-sm last:border-b-0 dark:border-gray-800 max-lg:grid-cols-2 max-md:grid-cols-1">
                <span>{{ $ordenRollo->rollo?->codigo }}</span>
                <span>{{ $ordenRollo->rollo?->compraTela?->codigo ?? $ordenRollo->rollo?->compra_tela_id }}</span>
                <span>{{ $ordenRollo->rollo?->tipo_tela }}</span>
                <span>{{ $ordenRollo->rollo?->color }}</span>
                <span>{{ $ordenRollo->rollo?->referencia }}</span>
                <span>{{ $ordenRollo->rollo?->gramaje }}</span>
                <span>{{ $ordenRollo->peso_inicial_usado }} kg</span>
            </div>
        @endforeach
    </div>

    <p class="mt-6 mb-3 text-base font-semibold text-gray-800 dark:text-white">Productos</p>
    <div class="rounded border dark:border-gray-800">
        @foreach ($orden->detalles as $detalle)
            <div class="grid grid-cols-3 gap-3 border-b p-3 last:border-b-0 dark:border-gray-800 max-lg:grid-cols-2 max-md:grid-cols-1">
                <span>{{ $detalle->producto_sku }} - {{ $detalle->producto_nombre }}</span>
                <span>Cortada: {{ $detalle->cantidad_real_cortada }}</span>
                <span>{{ $detalle->observacion }}</span>
            </div>
        @endforeach
    </div>

    @php
        $porProducto = $orden->detalles
            ->groupBy(fn ($detalle) => trim(($detalle->producto_sku ?: '').' - '.($detalle->producto_nombre ?: '')) ?: 'Sin producto')
            ->map(fn ($items) => [
                'piezas' => $items->sum('cantidad_real_cortada'),
                'promedio' => $items->sum('cantidad_real_cortada') > 0 && (float) $orden->peso_consumido_total > 0
                    ? round((float) $orden->peso_consumido_total / (int) $orden->total_piezas_cortadas, 6)
                    : 0,
            ]);
    @endphp

    <p class="mt-6 mb-3 text-base font-semibold text-gray-800 dark:text-white">Resumen por producto</p>
    <div class="rounded border dark:border-gray-800">
        @foreach ($porProducto as $producto => $resumen)
            <div class="grid grid-cols-3 gap-3 border-b p-3 text-sm last:border-b-0 dark:border-gray-800 max-md:grid-cols-1">
                <span>{{ $producto }}</span>
                <span>{{ $resumen['piezas'] }} piezas</span>
                <span>{{ $resumen['promedio'] }} kg aprox. por pieza</span>
            </div>
        @endforeach
    </div>
</x-admin::layouts>
