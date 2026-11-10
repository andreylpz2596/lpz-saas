{{--
    Historial de reasignaciones de un TALLER (ficha del taller). Variable: $reasignacionesTaller
    (Collection<Reasignacion> donde el taller es origen o destino, con relaciones cargadas).
    Enlaza a la orden del otro lado; el detalle y las acciones viven en la ficha de cada orden.
--}}
@php
    $ocultarContraparte = \ByLopez\CorteConfeccion\Support\TallerConfeccionAccess::esUsuarioTaller();
@endphp

@if ($reasignacionesTaller->isNotEmpty())
    <div class="mt-4 rounded-xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900" id="reasignaciones-taller">
        <div class="border-b p-4 dark:border-gray-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">Reasignaciones</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Prendas que este taller cedio a otro o recibio de otro.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr class="border-b dark:border-gray-800">
                        <th class="px-4 py-2 font-medium">Codigo</th>
                        <th class="px-4 py-2 font-medium">Movimiento</th>
                        <th class="px-4 py-2 text-right font-medium">Unidades</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($reasignacionesTaller as $reasignacion)
                        @php
                            $cedio = (int) $reasignacion->taller_origen_id === (int) $tallerId;
                            $estadoEnum = $reasignacion->estadoEnum();
                            $otraOrden = $cedio ? $reasignacion->ordenDestino : $reasignacion->ordenOrigen;
                            $otroTaller = $cedio ? $reasignacion->tallerDestino : $reasignacion->tallerOrigen;
                        @endphp

                        <tr class="border-b last:border-0 dark:border-gray-800">
                            <td class="px-4 py-2.5 font-medium text-gray-800 dark:text-white">
                                {{ $reasignacion->codigo }}
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $reasignacion->created_at?->format('Y-m-d H:i') }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ $cedio ? 'Cedio a' : 'Recibio de' }}
                                @if ($ocultarContraparte)
                                    <span class="font-medium text-gray-800 dark:text-white">otro taller</span>
                                @else
                                    <span class="font-medium text-gray-800 dark:text-white">{{ $otroTaller?->nombre }}</span>
                                    <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $otraOrden->id) }}" class="ml-1 text-blue-600 hover:underline dark:text-blue-400">{{ $otraOrden->codigo }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold text-gray-800 dark:text-white">{{ number_format($reasignacion->totalUnidades(), 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5"><span class="{{ $estadoEnum?->badgeClass() ?? 'label-info' }}">{{ $estadoEnum?->label() ?? $reasignacion->estado }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
