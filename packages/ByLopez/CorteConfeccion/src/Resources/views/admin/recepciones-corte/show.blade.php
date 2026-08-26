<x-admin::layouts>
    <x-slot:title>Recepcion {{ $recepcion->ordenCorte->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Recepcion {{ $recepcion->ordenCorte->codigo }}</p>
        <a href="{{ route('admin.corte_confeccion.ordenes_corte.show', $recepcion->orden_corte_id) }}" class="secondary-button">Ver orden</a>
    </div>

    <div class="mt-4 grid grid-cols-4 gap-4 max-md:grid-cols-1">
        @foreach (['fecha_recepcion', 'peso_sobrante_usable', 'peso_retasos', 'peso_desperdicio', 'merma_total', 'recibido_por'] as $field)
            <div class="rounded border p-4 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $field)) }}</p>
                <p class="font-semibold text-gray-800 dark:text-white">{{ $recepcion->{$field} }}</p>
            </div>
        @endforeach
    </div>

    <p class="mt-6 mb-3 text-base font-semibold text-gray-800 dark:text-white">Piezas recibidas</p>
    <div class="rounded border dark:border-gray-800">
        @foreach ($recepcion->detalles as $detalle)
            <div class="grid grid-cols-5 gap-3 border-b p-3 last:border-b-0 dark:border-gray-800 max-md:grid-cols-1">
                <span>{{ $detalle->producto_sku }} - {{ $detalle->producto_nombre }}</span>
                <span>Talla {{ $detalle->talla }}</span>
                <span>Recibidas: {{ $detalle->cantidad_recibida }}</span>
                <span>Defectuosas: {{ $detalle->cantidad_defectuosa }}</span>
                <span>{{ $detalle->observacion }}</span>
            </div>
        @endforeach
    </div>
</x-admin::layouts>
