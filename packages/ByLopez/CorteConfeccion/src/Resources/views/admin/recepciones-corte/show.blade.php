<x-admin::layouts>
    <x-slot:title>Recepcion {{ $recepcion->ordenCorte->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Recepcion {{ $recepcion->ordenCorte->codigo }}</p>
        <a href="{{ route('admin.corte_confeccion.ordenes_corte.show', $recepcion->orden_corte_id) }}" class="secondary-button">Ver orden</a>
    </div>

    <div class="mt-4 grid grid-cols-4 gap-4 max-md:grid-cols-1">
        {{-- F3: las recepciones nuevas solo tienen una merma total; sobrante usable, retazos y desperdicio son datos historicos. --}}
        @foreach ($recepcion->ordenCorte->merma_unica
            ? ['fecha_recepcion', 'merma_total', 'recibido_por']
            : ['fecha_recepcion', 'peso_sobrante_usable', 'peso_retasos', 'peso_desperdicio', 'merma_total', 'recibido_por'] as $field)
            <div class="rounded border p-4 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $field)) }}</p>
                <p class="font-semibold text-gray-800 dark:text-white">{{ $recepcion->{$field} }}</p>
            </div>
        @endforeach
    </div>

    @php
        $comparativo = $recepcion->detalles->map(function ($detalle) use ($recepcion) {
            $reportado = $recepcion->ordenCorte->detalles->first(
                fn ($d) => (int) $d->product_id === (int) $detalle->product_id
                    && trim((string) $d->talla) === trim((string) $detalle->talla)
            );

            $cantidadReportada = (int) ($reportado->cantidad_real_cortada ?? 0);
            $cantidadRecibida = (int) $detalle->cantidad_recibida;

            return [
                'producto' => trim(($detalle->producto_sku ?: '').' - '.($detalle->producto_nombre ?: '')),
                'talla' => $detalle->talla,
                'reportado' => $cantidadReportada,
                'recibido' => $cantidadRecibida,
                'diferencia' => $cantidadRecibida - $cantidadReportada,
                'defectuosa' => (int) $detalle->cantidad_defectuosa,
                'observacion' => $detalle->observacion,
            ];
        });
    @endphp

    <p class="mt-6 mb-3 text-base font-semibold text-gray-800 dark:text-white">Reportado por cortador vs. recibido en bodega</p>
    <div class="overflow-x-auto rounded border dark:border-gray-800">
        <table class="w-full min-w-[900px] border-collapse text-left text-sm">
            <thead>
                <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <th class="px-4 py-3">Producto</th>
                    <th class="px-4 py-3">Talla</th>
                    <th class="px-4 py-3 text-right">Reportado cortador</th>
                    <th class="px-4 py-3 text-right">Recibido bodega</th>
                    <th class="px-4 py-3 text-right">Diferencia</th>
                    <th class="px-4 py-3 text-right">Defectuosa</th>
                    <th class="px-4 py-3">Observacion</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($comparativo as $fila)
                    @php
                        $diferenciaClase = match (true) {
                            $fila['diferencia'] === 0 => 'text-gray-500 dark:text-gray-400',
                            $fila['diferencia'] < 0 => 'text-red-600 dark:text-red-400',
                            default => 'text-amber-600 dark:text-amber-400',
                        };
                    @endphp

                    <tr class="border-b text-gray-700 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-900">
                        <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white">{{ $fila['producto'] }}</td>
                        <td class="px-4 py-3">{{ $fila['talla'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $fila['reportado'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $fila['recibido'] }}</td>
                        <td class="px-4 py-3 text-right font-semibold {{ $diferenciaClase }}">{{ $fila['diferencia'] > 0 ? '+'.$fila['diferencia'] : $fila['diferencia'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $fila['defectuosa'] }}</td>
                        <td class="px-4 py-3">{{ $fila['observacion'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Sin piezas registradas</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
