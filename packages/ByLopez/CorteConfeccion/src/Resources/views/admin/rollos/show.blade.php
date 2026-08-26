<x-admin::layouts>
    <x-slot:title>Rollo {{ $rollo->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Rollo {{ $rollo->codigo }}</p>

        <div class="flex gap-2">
            <a href="{{ route('admin.corte_confeccion.rollos.edit', $rollo->id) }}" class="secondary-button">Editar</a>
            <form method="POST" action="{{ route('admin.corte_confeccion.rollos.cancel', $rollo->id) }}">@csrf <button class="transparent-button">Anular</button></form>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
        @foreach (['proveedor', 'tipo_tela', 'referencia', 'color', 'peso_inicial', 'peso_disponible', 'costo_total', 'fecha_compra', 'estado'] as $field)
            <div class="rounded border p-4 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $field)) }}</p>
                <p class="font-semibold text-gray-800 dark:text-white">{{ $rollo->{$field} }}</p>
            </div>
        @endforeach
    </div>
</x-admin::layouts>
