<x-admin::layouts>
    <x-slot:title>Entregar orden {{ $orden->codigo }}</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.entregar', $orden->id) }}">
        @csrf

        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Entregar orden {{ $orden->codigo }}</p>
            <button class="primary-button">Entregar</button>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            Peso reservado: {{ $orden->rollosCorte->isNotEmpty() ? $orden->rollosCorte->sum('peso_inicial_usado') : $orden->rollo->peso_disponible }} kg
        </p>

        <div class="mt-4 rounded border dark:border-gray-800">
            @foreach ($orden->rollosCorte as $ordenRollo)
                <div class="grid grid-cols-4 gap-3 border-b p-3 text-sm last:border-b-0 dark:border-gray-800 max-md:grid-cols-1">
                    <span>{{ $ordenRollo->rollo?->codigo }}</span>
                    <span>{{ $ordenRollo->rollo?->tipo_tela }}</span>
                    <span>{{ $ordenRollo->rollo?->color }}</span>
                    <span>{{ $ordenRollo->peso_inicial_usado }} kg</span>
                </div>
            @endforeach
        </div>

        <x-admin::form.control-group class="mt-4">
            <x-admin::form.control-group.label class="required">Peso entregado</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" step="0.001" name="peso_entregado" value="{{ old('peso_entregado', $orden->peso_entregado ?: ($orden->rollosCorte->isNotEmpty() ? $orden->rollosCorte->sum('peso_inicial_usado') : $orden->rollo->peso_disponible)) }}" />
            <x-admin::form.control-group.error control-name="peso_entregado" />
        </x-admin::form.control-group>
    </form>
</x-admin::layouts>
