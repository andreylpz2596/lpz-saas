@php
    $formatMoney = fn ($value) => number_format((float) $value, 2, ',', '.');
    $formatWeight = fn ($value) => number_format((float) $value, 3, ',', '.').' kg';

    $estadoBadgeClasses = [
        'disponible' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
        'reservado' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
        'agotado' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
        'en_corte' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        'parcialmente_usado' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        'anulado' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ];
@endphp

<x-admin::layouts>
    <x-slot:title>Compra {{ $compra->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="text-xl font-bold text-gray-800 dark:text-white">Compra {{ $compra->codigo }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $compra->fecha_compra?->format('Y-m-d') }} &middot; {{ $compra->estado }}</p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('admin.corte_confeccion.compras_tela.edit', $compra->id) }}" class="secondary-button">Editar</a>
            <a href="{{ route('admin.corte_confeccion.compras_tela.index') }}" class="transparent-button">Volver</a>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Proveedor</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $compra->proveedor_nombre }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $compra->proveedor?->nit }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Factura</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $compra->numero_factura ?: 'Sin factura' }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Rollos</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $compra->cantidad_rollos }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Peso total</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $formatWeight($compra->peso_total) }}</p>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Subtotal</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $formatMoney($compra->subtotal) }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Descuento</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $formatMoney($compra->descuento) }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Impuesto</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $formatMoney($compra->impuesto) }}</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Total factura</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $formatMoney($compra->total_factura) }}</p>
        </div>
    </div>

    <div class="mt-6 rounded border dark:border-gray-800">
        <div class="border-b p-4 dark:border-gray-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">Rollos comprados</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[1100px] border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <th class="px-4 py-3">Rollo</th>
                        <th class="px-4 py-3">Tipo de tela</th>
                        <th class="px-4 py-3">Referencia</th>
                        <th class="px-4 py-3">Color</th>
                        <th class="px-4 py-3 text-right">Gramaje</th>
                        <th class="px-4 py-3 text-right">Peso inicial</th>
                        <th class="px-4 py-3 text-right">Valor kilo</th>
                        <th class="px-4 py-3 text-right">Subtotal</th>
                        <th class="px-4 py-3 text-right">Disponible</th>
                        <th class="px-4 py-3">Estado</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($compra->rollos as $rollo)
                        @php
                            $estado = (string) $rollo->estado;
                            $badgeClasses = $estadoBadgeClasses[$estado] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                        @endphp

                        <tr class="border-b text-gray-700 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-900">
                            <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white">{{ $rollo->codigo }}</td>
                            <td class="px-4 py-3">{{ $rollo->tipo_tela }}</td>
                            <td class="px-4 py-3">{{ $rollo->referencia ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $rollo->color }}</td>
                            <td class="px-4 py-3 text-right">{{ $rollo->gramaje }}</td>
                            <td class="px-4 py-3 text-right">{{ $formatWeight($rollo->peso_inicial) }}</td>
                            <td class="px-4 py-3 text-right">{{ $formatMoney($rollo->valor_kilo) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800 dark:text-white">{{ $formatMoney($rollo->costo_total) }}</td>
                            <td class="px-4 py-3 text-right">{{ $formatWeight($rollo->peso_disponible) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses }}">
                                    {{ str_replace('_', ' ', $estado) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Sin rollos registrados
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($compra->observacion)
        <div class="mt-4 rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Observacion</p>
            <p class="mt-1 text-gray-800 dark:text-white">{{ $compra->observacion }}</p>
        </div>
    @endif
</x-admin::layouts>
