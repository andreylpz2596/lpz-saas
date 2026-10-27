{{--
    Tarifas por referencia de UNA orden (Doc. 05). Variables:
      $orden         OrdenConfeccion u OrdenCorte
      $tarifasOrden  salida de OrdenTarifaService::resumen($orden)
      $tipo          'taller' (orden de confeccion) | 'cortador' (orden de corte)
      $ruta          nombre de la ruta POST que pacta la tarifa de una combinacion

    La orden conserva una tarifa por cada combinacion tipo de tela + referencia (una orden mixta tiene varias), copiada de
    la ficha cuando la combinacion aparecio en la orden. «Sin tarifa» = no existia tarifa vigente: nunca se asume 0 ni se
    aplica una tarifa general. El total es referencial: la cantidad PAGABLE la define la recepcion validada por Bodega.
    Quien tiene el permiso puede pactar otra tarifa solo para esa combinacion en esta orden, con motivo; no cambia la
    ficha ni otras ordenes. La autorizacion real esta en CambiarTarifaOrdenRequest; aqui solo se muestran los controles.
--}}
@include('corteconfeccion::admin.partials.moneda')

@php
    $puedePactar = \ByLopez\CorteConfeccion\Support\TarifaPrestadorGate::puedeGestionar($tipo)
        && \ByLopez\CorteConfeccion\Support\TarifaPrestadorGate::ordenAdmiteTarifa($orden);
    $formatearTarifa = fn ($valor) => '$ '.rtrim(rtrim(number_format((float) $valor, 2, ',', '.'), '0'), ',');
    $etiquetasOrigen = ['sin_tarifa' => 'Sin tarifa', 'ficha' => 'Tarifa de la ficha', 'pactada' => 'Pactada para esta orden'];
@endphp

<div class="rounded border bg-white dark:border-gray-800 dark:bg-gray-900">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b p-4 dark:border-gray-800">
        <div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Tarifas por referencia</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Valor por unidad conservado por esta orden para cada tipo de tela y referencia. Cambios posteriores en la ficha no la afectan.
            </p>
        </div>

        @if ($tarifasOrden['lineas'] !== [])
            <div class="text-right max-sm:text-left">
                <p class="text-xs text-gray-500 dark:text-gray-400">Total referencial</p>
                <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $formatearTarifa($tarifasOrden['total_referencial']) }}</p>
            </div>
        @endif
    </div>

    @if ($tarifasOrden['sin_identificar'] !== [])
        <div class="border-b border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
            <p class="font-semibold">No se pudo identificar parte de la orden</p>
            @foreach ($tarifasOrden['sin_identificar'] as $motivo)
                <p class="mt-0.5">{{ $motivo }}</p>
            @endforeach
        </div>
    @endif

    @if ($tarifasOrden['lineas'] === [])
        <p class="p-4 text-sm text-gray-500 dark:text-gray-400">Esta orden aun no tiene referencias identificadas para tarifar.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr class="border-b dark:border-gray-800">
                        <th class="px-4 py-2 font-medium">Tipo de tela</th>
                        <th class="px-4 py-2 font-medium">Referencia</th>
                        <th class="px-4 py-2 text-right font-medium">{{ $tarifasOrden['cantidad_label'] }}</th>
                        <th class="px-4 py-2 text-right font-medium">Valor por unidad</th>
                        <th class="px-4 py-2 text-right font-medium">Importe</th>
                        <th class="px-4 py-2 font-medium">Origen</th>
                        @if ($puedePactar)
                            <th class="px-4 py-2"></th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @foreach ($tarifasOrden['lineas'] as $indice => $linea)
                        <tr class="border-b align-top last:border-0 dark:border-gray-800">
                            <td class="px-4 py-2.5 text-gray-800 dark:text-white">{{ $linea['tipo_tela'] }}</td>
                            <td class="px-4 py-2.5 text-gray-800 dark:text-white">{{ $linea['referencia'] }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-800 dark:text-white">{{ number_format($linea['cantidad'], 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-gray-800 dark:text-white">
                                {{ $linea['tarifa'] !== null ? $formatearTarifa($linea['tarifa']) : 'Sin tarifa' }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-gray-800 dark:text-white">
                                {{ $linea['importe'] !== null ? $formatearTarifa($linea['importe']) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                                {{ $etiquetasOrigen[$linea['origen']] }}
                                @if ($linea['motivo'])
                                    <span class="block text-gray-500 dark:text-gray-400">{{ $linea['motivo'] }}</span>
                                @endif
                            </td>
                            @if ($puedePactar)
                                <td class="px-4 py-2.5 text-right">
                                    <button
                                        type="button"
                                        class="text-sm text-blue-600 hover:underline"
                                        data-pactar-toggle="pactar-{{ $indice }}"
                                    >
                                        {{ $linea['tarifa'] !== null ? 'Pactar otra' : 'Fijar tarifa' }}
                                    </button>
                                </td>
                            @endif
                        </tr>

                        @if ($puedePactar)
                            <tr id="pactar-{{ $indice }}" class="hidden border-b bg-gray-50 dark:border-gray-800 dark:bg-gray-950">
                                <td colspan="7" class="px-4 py-3">
                                    <form
                                        method="POST"
                                        action="{{ route($ruta, $orden->id) }}"
                                        class="flex flex-wrap items-end gap-3"
                                        data-confirm="Esta tarifa aplica solo a esta combinacion en esta orden y queda registrada en el historial. Confirmas el cambio?"
                                        data-confirm-title="Cambiar tarifa de la orden"
                                    >
                                        @csrf
                                        <input type="hidden" name="tipo_tela_id" value="{{ $linea['tipo_tela_id'] }}">
                                        <input type="hidden" name="attribute_family_id" value="{{ $linea['attribute_family_id'] }}">

                                        <label class="grid gap-1 text-sm text-gray-600 dark:text-gray-300">
                                            Valor por unidad
                                            <input
                                                type="text"
                                                name="valor_unitario"
                                                data-moneda
                                                value="{{ $linea['tarifa'] !== null ? (float) $linea['tarifa'] : '' }}"
                                                class="w-44 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            >
                                        </label>

                                        <label class="grid min-w-[240px] flex-1 gap-1 text-sm text-gray-600 dark:text-gray-300">
                                            Motivo (obligatorio)
                                            <input
                                                type="text"
                                                name="motivo"
                                                maxlength="500"
                                                placeholder="Ej.: descuento pactado por volumen"
                                                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                            >
                                        </label>

                                        <button type="submit" class="primary-button whitespace-nowrap">Guardar tarifa</button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($tarifasOrden['lineas_sin_tarifa'] > 0)
            <p class="border-t p-3 text-xs text-amber-700 dark:border-gray-800 dark:text-amber-300">
                {{ $tarifasOrden['lineas_sin_tarifa'] }} {{ $tarifasOrden['lineas_sin_tarifa'] === 1 ? 'referencia no tiene' : 'referencias no tienen' }}
                tarifa: el total no las incluye. Configurala en la ficha del {{ $tipo === 'taller' ? 'taller' : 'cortador' }} y pactala aqui para esta orden.
            </p>
        @endif
    @endif

    @if ($errors->has('valor_unitario') || $errors->has('motivo') || $errors->has('attribute_family_id'))
        <div class="border-t p-3 text-xs text-red-600 dark:border-gray-800">
            {{ $errors->first('valor_unitario') ?: ($errors->first('motivo') ?: $errors->first('attribute_family_id')) }}
        </div>
    @endif
</div>

@pushOnce('scripts', 'bylopez-pactar-tarifa-toggle')
    <script>
        document.addEventListener('click', function (evento) {
            var boton = evento.target.closest('[data-pactar-toggle]');

            if (! boton) {
                return;
            }

            var fila = document.getElementById(boton.getAttribute('data-pactar-toggle'));

            if (fila) {
                fila.classList.toggle('hidden');
            }
        });
    </script>
@endPushOnce
